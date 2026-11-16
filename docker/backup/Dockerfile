# Nightly backup container (compose.prod.yml "backup"): pg_dump 16 and restic,
# with busybox crond running deploy/backup.sh on BACKUP_SCHEDULE (Europe/Paris).
# Built next to the app image (tools/prod-local/build.sh, or your own pipeline).
FROM postgres:16-alpine

RUN apk add --no-cache restic tzdata ca-certificates

COPY deploy/backup.sh /usr/local/bin/backup.sh
COPY deploy/restore.sh /usr/local/bin/restore.sh
COPY docker/backup/entrypoint.sh /usr/local/bin/backup-entrypoint.sh
RUN chmod 0755 /usr/local/bin/backup.sh /usr/local/bin/restore.sh /usr/local/bin/backup-entrypoint.sh

ENV TZ=Europe/Paris
ENTRYPOINT ["/usr/local/bin/backup-entrypoint.sh"]
CMD ["crond", "-f", "-l", "6"]
