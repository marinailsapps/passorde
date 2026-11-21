#!/bin/sh
# Deploys one image tag on the server (run by hand, or over SSH from your own
# pipeline). Idempotent: running it twice with the same tag is harmless.
#
#   /srv/passorde/deploy/deploy.sh 1a2b3c4          # tag = short git SHA
#
# Steps: check that .env states PASSORDE_PROFILE, pull the app and backup images, start Postgres
# if it is not running, check the installation profile with the new image (against the database as it is, not
# migrated yet: ProfileCheck reads the older names of the profile too), record the tag in .env
# (PASSORDE_TAG), stop the scheduler and clear its locks, stop the queue worker, recreate the app containers (the app
# migrates and every container runs `optimize` on start, so the queue and the
# scheduler come up with the new code and config), reload Caddy, check /up
# inside and outside, print the running tag. Every deploy is appended to
# releases.log, which rollback.sh reads.
#
# DEPLOY_DIR (default: the directory above this script) holds .env and
# compose.prod.yml; .env sets COMPOSE_FILE so plain `docker compose` uses it.
# DEPLOY_CURL_OPTS adds curl options for the outside check (-k on a laptop).
# DEPLOY_AFTER_CHANGE, DEPLOY_PULL_OPTIONAL, DEPLOY_PROFILE_NO_DATABASE: optional switches for the
# operator or a script that calls deploy.sh (rollback.sh sets none of them); see where each is read.
set -eu

usage() {
	echo "usage: deploy.sh <tag>" >&2
	exit 64
}

tag=${1:-}
[ -n "$tag" ] && [ $# -eq 1 ] || usage
case "$tag" in
	-* | *[!A-Za-z0-9._-]*) usage ;;
esac

dir=${DEPLOY_DIR:-$(cd "$(dirname "$0")/.." && pwd)}
cd "$dir"
if [ ! -f .env ]; then
	echo "deploy: no .env in $dir (see docs/RUNBOOK.md \"Server bootstrap\")" >&2
	exit 1
fi

say() {
	echo "deploy: $*"
}

# Production refuses to boot without PASSORDE_PROFILE (the installation profile): fail
# here, before anything is pulled or switched, so that the running version keeps running.
if ! grep -Eq '^PASSORDE_PROFILE=.+' .env; then
	echo "deploy: .env does not state PASSORDE_PROFILE. Add PASSORDE_PROFILE=/var/www/html/profile (the profile directory" >&2
	echo "deploy: PASSORDE_PROFILE_DIR, holding profile.php, is mounted there) or PASSORDE_PROFILE=none for the neutral" >&2
	echo "deploy: defaults (docs/RUNBOOK.md \"Installation profile\"). Nothing was changed." >&2
	exit 1
fi

previous=$(sed -n 's/^PASSORDE_TAG=//p' .env | tail -n 1)
switched=0

on_error() {
	code=$?
	if [ "$code" -ne 0 ]; then
		echo "deploy: FAILED (exit $code) while deploying ${tag}." >&2
		if [ -n "${DEPLOY_AFTER_CHANGE:-}" ]; then
			# Set by whoever changed the database before calling deploy.sh: its text says what happens next.
			if [ "$switched" = 0 ]; then
				echo "deploy: deploy.sh itself switched nothing (${DEPLOY_AFTER_CHANGE})." >&2
			else
				echo "deploy: .env names ${tag} now (${DEPLOY_AFTER_CHANGE})." >&2
			fi
		elif [ "$switched" = 0 ]; then
			echo "deploy: nothing was changed; ${previous:-the previous tag} is still running." >&2
		elif [ -n "$previous" ] && [ "$previous" != "$tag" ]; then
			echo "deploy: to go back: $dir/deploy/rollback.sh ${previous}" >&2
		fi
	fi
}
trap on_error EXIT

say "pulling images for ${tag}"
# The shell value wins over .env: nothing changes if the tag cannot be pulled.
# DEPLOY_PULL_OPTIONAL=1 (an optional switch for the operator, or a script calling deploy.sh,
# when the images are already on the server; rollback.sh does not set it): a registry that
# cannot be reached is then no reason to stop.
if ! PASSORDE_TAG="$tag" docker compose pull --quiet app queue scheduler backup; then
	[ -n "${DEPLOY_PULL_OPTIONAL:-}" ] || exit 1
	say "pull failed: using the images of ${tag} already on the server"
fi

# Migrations only add (tables, columns): an older image runs on a newer database, so going back
# is only an image change (rollback.sh). A rename of what the code reads would break that: see
# docs/RUNBOOK.md "Older names" for how an installation renames with passorde:rename-legacy.

# The profile check below reads the database: start Postgres when it is not running (the first deploy
# of a new installation). A running one is left as it is.
docker compose up -d --wait --no-recreate postgres >/dev/null

# The new image must start, and reads the profile and checks it against the live database
# before anything switches: a profile the new version refuses stops the deploy here.
# DEPLOY_PROFILE_NO_DATABASE=1 (an optional switch for the operator, or a script that already ran
# that check before it changed anything; rollback.sh does not set it) checks the profile alone. An image built before the check existed (an older
# build of main) has no such command: said, and the deploy goes on.
if ! PASSORDE_TAG="$tag" docker compose run --rm --no-deps -T -e AUTORUN_ENABLED=false app php artisan --version >/dev/null; then
	echo "deploy: the image ${tag} does not start (above). Nothing was changed." >&2
	exit 1
fi
if PASSORDE_TAG="$tag" docker compose run --rm --no-deps -T -e AUTORUN_ENABLED=false app php artisan list --raw 2>/dev/null | grep -Eq '^passorde:check-profile( |$)'; then
	say "checking the installation profile with ${tag}"
	only=""
	[ -n "${DEPLOY_PROFILE_NO_DATABASE:-}" ] && only="--no-database"
	# shellcheck disable=SC2086 # $only is empty or one option
	if ! PASSORDE_TAG="$tag" docker compose run --rm --no-deps -T -e AUTORUN_ENABLED=false app php artisan passorde:check-profile $only; then
		echo "deploy: the installation profile does not pass passorde:check-profile with ${tag} (above). Fix the profile" >&2
		echo "deploy: directory or .env, then deploy again." >&2
		exit 1
	fi
else
	say "${tag} has no passorde:check-profile (a build older than the profile): profile not checked"
fi

# PASSORDE_TAG is the one line of .env this script owns. The file is rewritten
# in place (not replaced), so it keeps its owner, group www-data and mode 640.
if grep -q '^PASSORDE_TAG=' .env; then
	sed "s/^PASSORDE_TAG=.*/PASSORDE_TAG=${tag}/" .env > .env.tmp
	cat .env.tmp > .env
	rm -f .env.tmp
else
	printf '\nPASSORDE_TAG=%s\n' "$tag" >> .env
fi
switched=1
say "tag ${previous:-none} -> ${tag}"

# A scheduled command killed with the old scheduler leaves its overlap lock in
# the shared storage volume: stop the scheduler, then clear the locks, or the
# heartbeat (and so the healthchecks below) can stay blocked.
docker compose stop scheduler >/dev/null 2>&1 || true
docker compose exec -T app php artisan schedule:clear-cache >/dev/null 2>&1 || true

# The old worker must not run the old job code against the schema the new app is about to
# migrate (a migration may change what they read): stop it first, letting its current
# job finish (stop_grace_period 60s). Queued jobs wait in the jobs table for the new worker,
# which starts only once the new app has migrated (compose.prod.yml depends_on).
docker compose stop queue >/dev/null 2>&1 || true

# Forced, so that a redeploy of the same tag (after editing .env) also gets
# fresh containers: the workers keep the old config in memory, and the config
# cache is rebuilt on start. Postgres and Caddy are not recreated; the second
# `up` only starts Caddy if it is not running.
say "starting containers (the app migrates and caches on start)"
docker compose up -d --remove-orphans --wait --wait-timeout 300 \
	--force-recreate app queue scheduler backup
docker compose up -d --wait caddy

# compose.prod.yml mounts the Caddyfile: a changed file needs a reload, not a restart.
docker compose exec -T caddy caddy reload --config /etc/caddy/Caddyfile >/dev/null 2>&1 \
	|| say "caddy reload failed (check docker compose logs caddy)"

say "checking /up"
docker compose exec -T app curl -fsS -o /dev/null http://localhost:8080/up
url=$(sed -n 's/^APP_URL=//p' .env | tail -n 1 | tr -d '"')
if [ -n "$url" ]; then
	attempt=0
	# shellcheck disable=SC2086 # DEPLOY_CURL_OPTS is a list of options
	until curl -fsS -o /dev/null --max-time 10 ${DEPLOY_CURL_OPTS:-} "${url%/}/up"; do
		attempt=$((attempt + 1))
		[ "$attempt" -lt 12 ] || exit 1
		sleep 5
	done
fi

running=$(docker compose exec -T app printenv APP_VERSION | tr -d '\r')
printf '%s %s (previous %s)\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$tag" "${previous:-none}" >> releases.log
docker compose ps --format 'table {{.Service}}\t{{.Image}}\t{{.Status}}'
say "running ${running} (tag ${tag})"
