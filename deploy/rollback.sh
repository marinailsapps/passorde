#!/bin/sh
# Points the server back to an earlier image tag.
#
#   /srv/passorde/deploy/rollback.sh                       # the tag deployed before the current one
#   /srv/passorde/deploy/rollback.sh 1a2b3c4               # a given tag (see releases.log or your registry)
#
# Migrations only add (columns, tables): the older app finds nothing to run on start and ignores
# what it does not know, so going back is only an image change, done by deploy.sh <tag>
# (docs/RUNBOOK.md "Rollback"). An installation that renamed tables or columns with
# passorde:rename-legacy undoes that first with the image running now (docs/RUNBOOK.md "Older names").
set -eu

dir=${DEPLOY_DIR:-$(cd "$(dirname "$0")/.." && pwd)}
scripts=$(cd "$(dirname "$0")" && pwd)
tag=${1:-}
current=$(sed -n 's/^PASSORDE_TAG=//p' "$dir/.env" | tail -n 1)

if [ -z "$tag" ]; then
	if [ ! -f "$dir/releases.log" ]; then
		echo "rollback: no releases.log in $dir; give the tag to go back to" >&2
		exit 64
	fi
	# Lines are "<time> <tag> (previous <tag>)": the latest deploy of another tag.
	tag=$(awk -v current="$current" '$2 != current { tag = $2 } END { print tag }' "$dir/releases.log")
	if [ -z "$tag" ]; then
		echo "rollback: no earlier tag in releases.log" >&2
		exit 1
	fi
fi
case "$tag" in
	-* | *[!A-Za-z0-9._-]*)
		echo "usage: rollback.sh [<tag>]" >&2
		exit 64
		;;
esac

echo "rollback: from ${current:-?} to ${tag}"
DEPLOY_DIR="$dir" exec "$scripts/deploy.sh" "$tag"
