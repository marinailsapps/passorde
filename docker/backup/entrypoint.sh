#!/bin/sh
# Writes the crontab from BACKUP_SCHEDULE (default 02:30, after the 02:00
# client sync), then runs the command (crond in the foreground).
set -eu

schedule="${BACKUP_SCHEDULE:-30 2 * * *}"
mkdir -p /etc/crontabs
# Output goes to the container log (docker compose logs backup).
printf '%s /usr/local/bin/backup.sh > /proc/1/fd/1 2>&1\n' "$schedule" > /etc/crontabs/root
echo "backup: scheduled '${schedule}' (${TZ:-UTC})"

exec "$@"
