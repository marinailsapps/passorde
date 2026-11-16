#!/bin/sh
# Nightly backup (compose.prod.yml "backup" service, 02:30 Europe/Paris):
#
#   1. pg_dump -Fc of the app database
#   2. that dump plus the server .env and the installation profile → restic repository on S3-compatible storage
#   3. keep 30 daily and 12 monthly snapshots, and every one tagged "keep"; prune (and check) on Sundays
#   4. record the result in ops_heartbeats ("backup"), which ops:daily-digest reads
#
# Run it by hand: docker compose exec backup backup.sh
# A snapshot to keep past the retention (before a risky change, docs/RUNBOOK.md "Backups"):
#   docker compose exec backup backup.sh --keep pre-switch
# tags it "keep" and "pre-switch": restic forget never removes a snapshot tagged "keep". Drop the
# tag when it is no longer needed: restic tag --tag pre-switch --remove keep
# Environment (from .env through compose.prod.yml): PGHOST PGPORT PGDATABASE
# PGUSER PGPASSWORD BACKUP_S3_ENDPOINT BACKUP_S3_BUCKET BACKUP_S3_KEY
# BACKUP_S3_SECRET RESTIC_PASSWORD, optional BACKUP_S3_REGION and BACKUP_NAME (default
# passorde: the restic host and the folder of the repository in the bucket).
set -eu

keep=""
if [ "${1:-}" = "--keep" ]; then
	keep=${2:-}
	case "$keep" in
		"" | -* | *[!a-z0-9-]*)
			echo "usage: backup.sh [--keep <name: a-z, 0-9, ->]" >&2
			exit 64
			;;
	esac
fi

# A fixed path, so restic sees the same files every night (and dedupes them).
workdir=${BACKUP_WORKDIR:-/var/backups/passorde}
backup_name=${BACKUP_NAME:-passorde}
env_file=${BACKUP_ENV_FILE:-/backup/.env}
log=$(mktemp)

# Writes the outcome where the app can read it. Never fails the script itself.
record() {
	status=$1
	detail=$2
	if [ "$status" = ok ]; then
		sql="insert into ops_heartbeats (name, last_ok_at, meta, updated_at)
		     values ('backup', now(), json_build_object('snapshot', :'detail'), now())
		     on conflict (name) do update set last_ok_at = excluded.last_ok_at, meta = excluded.meta, updated_at = now();"
	else
		sql="insert into ops_heartbeats (name, last_failed_at, last_error, updated_at)
		     values ('backup', now(), :'detail', now())
		     on conflict (name) do update set last_failed_at = excluded.last_failed_at, last_error = excluded.last_error, updated_at = now();"
	fi
	printf '%s\n' "$sql" | psql -q -v ON_ERROR_STOP=1 -v detail="$detail" >/dev/null 2>&1 \
		|| echo "backup: could not record the result in ops_heartbeats" >&2
}

# On any failure (set -e), record what failed before cleaning up.
on_exit() {
	code=$?
	if [ "$code" -ne 0 ]; then
		echo "backup: FAILED during ${step:-?} (exit $code)" >&2
		cat "$log" >&2 2>/dev/null || true
		record failed "${step:-?} (exit ${code}): $(tail -n 3 "$log" 2>/dev/null | tr '\n' ' ' | cut -c1-400)"
	fi
	rm -rf "$workdir" "$log"
	exit "$code"
}
trap on_exit EXIT

# restic retries an unreachable or refusing bucket for a long time: give every
# step a deadline, after which it fails like any other error.
within() {
	limit=$1
	shift
	timeout -s TERM "$limit" "$@"
}

step="configuration"
for name in BACKUP_S3_ENDPOINT BACKUP_S3_BUCKET BACKUP_S3_KEY BACKUP_S3_SECRET RESTIC_PASSWORD PGDATABASE; do
	eval "value=\${$name:-}"
	if [ -z "$value" ]; then
		echo "$name is not set in .env" | tee "$log" >&2
		exit 2
	fi
done

export RESTIC_PASSWORD
export RESTIC_REPOSITORY="s3:${BACKUP_S3_ENDPOINT%/}/${BACKUP_S3_BUCKET}/${backup_name}"
export AWS_ACCESS_KEY_ID="$BACKUP_S3_KEY"
export AWS_SECRET_ACCESS_KEY="$BACKUP_S3_SECRET"
if [ -n "${BACKUP_S3_REGION:-}" ]; then
	export AWS_DEFAULT_REGION="$BACKUP_S3_REGION"
fi

echo "backup: start $(date '+%Y-%m-%d %H:%M:%S %Z')"
rm -rf "$workdir"
mkdir -p "$workdir"

step="pg_dump"
# Named after the database, so the same name every night.
dump="$workdir/${PGDATABASE:-passorde}.dump"
within 1800 pg_dump -Fc --no-owner -f "$dump" 2>"$log"
cp "$env_file" "$workdir/env"
# The installation profile (PASSORDE_PROFILE_DIR, mounted by compose.prod.yml), when there is one.
profile_dir=${BACKUP_PROFILE_DIR:-/backup/profile}
if [ -f "$profile_dir/profile.php" ]; then
	cp -R "$profile_dir" "$workdir/profile"
fi

step="restic repository"
if within "${BACKUP_PROBE_TIMEOUT:-600}" restic cat config >/dev/null 2>"$log"; then
	:
else
	code=$?
	# 10: the repository does not exist yet (first night). Anything else is an error.
	[ "$code" -eq 10 ] || exit "$code"
	echo "backup: no repository yet, initialising ${RESTIC_REPOSITORY}"
	within 600 restic init >"$log" 2>&1
fi

step="restic backup"
if [ -n "$keep" ]; then
	within 7200 restic backup --host "$backup_name" --tag nightly --tag keep --tag "$keep" "$workdir" >"$log" 2>&1
else
	within 7200 restic backup --host "$backup_name" --tag nightly "$workdir" >"$log" 2>&1
fi
snapshot=$(sed -n 's/^snapshot \([0-9a-f]*\) saved$/\1/p' "$log")
echo "backup: snapshot ${snapshot:-?} saved${keep:+, kept (tags keep, ${keep})} ($(du -h "$dump" | cut -f1) dump)"

step="restic forget"
if [ "$(date +%u)" = 7 ] || [ "${BACKUP_PRUNE:-}" = 1 ]; then
	within 3600 restic forget --host "$backup_name" --keep-daily 30 --keep-monthly 12 --keep-tag keep --prune >"$log" 2>&1
	step="restic check"
	within 3600 restic check >"$log" 2>&1
	echo "backup: pruned and checked"
else
	within 1800 restic forget --host "$backup_name" --keep-daily 30 --keep-monthly 12 --keep-tag keep >"$log" 2>&1
fi

record ok "${snapshot:-?}"
echo "backup: done $(date '+%Y-%m-%d %H:%M:%S %Z')"
