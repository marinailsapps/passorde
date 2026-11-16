#!/bin/sh
# Restores a backup snapshot into a NEW database next to the live one, and the
# .env and installation profile of that night into /restore/<snapshot>/ (<install directory>/restore on the
# server). Nothing live is touched: the output says how to switch over.
#
#   docker compose exec backup restic snapshots          # list (see RUNBOOK)
#   docker compose exec backup restore.sh latest         # or a snapshot id
#   docker compose exec backup restore.sh 1a2b3c4d passorde_restore
#
# Same environment as backup.sh (BACKUP_NAME included).
set -eu

snapshot=${1:-}
if [ -z "$snapshot" ]; then
	echo "usage: restore.sh <snapshot id|latest> [new database name]" >&2
	exit 64
fi
target=${2:-${PGDATABASE:-passorde}_restore_$(date +%Y%m%d_%H%M)}
backup_name=${BACKUP_NAME:-passorde}
live=${PGDATABASE:?PGDATABASE is not set}

: "${BACKUP_S3_ENDPOINT:?BACKUP_S3_ENDPOINT is not set}"
: "${BACKUP_S3_BUCKET:?BACKUP_S3_BUCKET is not set}"
: "${BACKUP_S3_KEY:?BACKUP_S3_KEY is not set}"
: "${BACKUP_S3_SECRET:?BACKUP_S3_SECRET is not set}"
: "${RESTIC_PASSWORD:?RESTIC_PASSWORD is not set}"

export RESTIC_PASSWORD
export RESTIC_REPOSITORY="s3:${BACKUP_S3_ENDPOINT%/}/${BACKUP_S3_BUCKET}/${backup_name}"
export AWS_ACCESS_KEY_ID="$BACKUP_S3_KEY"
export AWS_SECRET_ACCESS_KEY="$BACKUP_S3_SECRET"
if [ -n "${BACKUP_S3_REGION:-}" ]; then
	export AWS_DEFAULT_REGION="$BACKUP_S3_REGION"
fi

case "$target" in
	*[!a-z0-9_]* | "")
		echo "restore: the database name may only contain a-z, 0-9 and _" >&2
		exit 64
		;;
esac

if [ "$target" = "$live" ]; then
	echo "restore: refusing to restore over the live database '$live'; pick another name" >&2
	exit 64
fi

if [ "$(psql -d postgres -tAc "select 1 from pg_database where datname = '$target'")" = 1 ]; then
	echo "restore: database '$target' already exists; drop it or pick another name" >&2
	exit 1
fi

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

echo "restore: fetching snapshot $snapshot"
restic restore "$snapshot" --host "$backup_name" --target "$tmp"
# backup.sh names the dump after the database (<PGDATABASE>.dump).
dump=$(find "$tmp" -name '*.dump' -type f | head -n 1)
env=$(find "$tmp" -name env -type f | head -n 1)
profile=$(find "$tmp" -path '*/profile/profile.php' -type f | head -n 1)
if [ -z "$dump" ]; then
	echo "restore: no .dump file in snapshot $snapshot" >&2
	exit 1
fi
id=$(restic snapshots "$snapshot" --host "$backup_name" --json | sed -n 's/.*"short_id":"\([0-9a-f]*\)".*/\1/p' | head -n 1)
id=${id:-$snapshot}

echo "restore: creating database $target"
createdb --maintenance-db=postgres "$target"
pg_restore --no-owner --role="$PGUSER" --exit-on-error -d "$target" "$dump"

if [ -n "$env" ]; then
	mkdir -p "/restore/$id"
	cp "$env" "/restore/$id/.env"
	chmod 600 "/restore/$id/.env"
fi
# The installation profile of that night (backups from before it was saved have none).
if [ -n "$profile" ]; then
	mkdir -p "/restore/$id"
	rm -rf "/restore/$id/profile"
	cp -R "$(dirname "$profile")" "/restore/$id/profile"
fi

orders=$(psql -d "$target" -tAc "select count(*) from orders" 2>/dev/null || echo '?')
last=$(psql -d "$target" -tAc "select max(created_at) from orders" 2>/dev/null || echo '?')

cat <<EOT

restore: snapshot $id restored into database '$target' ($orders orders, last created $last).
The .env of that night is in restore/$id/.env (on the server: <install directory>/restore/$id/.env),
and its installation profile, when it was saved, in restore/$id/profile/ (compare it with PASSORDE_PROFILE_DIR).
The live database '$live' was not touched.

Next steps (in the install directory, see docs/RUNBOOK.md "Restoring a backup"):

  1. Look at the data first if needed:
       docker compose exec postgres psql -U $PGUSER -d $target
  2. Stop everything that writes:
       docker compose stop caddy app queue scheduler
  3. Keep the current database aside and put the restored one in its place:
       docker compose exec postgres psql -U $PGUSER -d postgres \\
         -c 'ALTER DATABASE "$live" RENAME TO "${live}_before_restore_$(date +%Y%m%d)"' \\
         -c 'ALTER DATABASE "$target" RENAME TO "$live"'
  4. Only if the server .env was lost: compare it with restore/$id/.env and copy back what is missing
     (APP_KEY must be the one of the backup, or every session and encrypted value is lost).
  5. Start again and check:
       docker compose up -d
       docker compose exec app php artisan migrate --force
       curl -fsS https://<your domain>/up
  6. Orders and changes made after the backup are gone from the server. Orders still waiting on a
     phone are sent as usual; ask the sales people to check "Mes commandes" for the others.
  7. Once satisfied, drop the old database:
       docker compose exec postgres dropdb -U $PGUSER ${live}_before_restore_$(date +%Y%m%d)
EOT
