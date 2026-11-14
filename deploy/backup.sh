#!/usr/bin/env bash
set -euo pipefail
cd /opt/drivecore/infrastructure
exec 9>/home/ubuntu/drivecore-deploy/backup.lock
flock -n 9
umask 077
test -s backup.env || { echo 'Configure private backup.env first.' >&2; exit 1; }
test "$(stat -c %a backup.env)" = 600 || { echo 'backup.env must have mode 600.' >&2; exit 1; }
install -d -m 700 /opt/drivecore/backups
compose() { sudo -n docker compose "$@"; }
restic() {
 sudo -n docker run --rm --env-file backup.env \
  -v /opt/drivecore/backups:/backups:ro \
  -v /opt/drivecore/backend/storage/app/private:/documents:ro \
  -v /opt/drivecore/backend/.env:/configuration/backend.env:ro \
  -v /opt/drivecore/infrastructure/.env:/configuration/infrastructure.env:ro \
  restic/restic:0.18.1 "$@"
}
case "${1:-backup}" in
 init) restic init ;;
 snapshots) restic snapshots ;;
 check) restic check ;;
 backup)
  dump="/opt/drivecore/backups/snapshot-$(date -u +%Y%m%dT%H%M%SZ).dump"
  compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$dump"
  test -s "$dump"
  compose exec -T postgres pg_restore --list < "$dump" > /dev/null
  restic backup --host drivecore-dev --tag drivecore /backups /documents /configuration
  date -u +%FT%TZ > /opt/drivecore/backups/last-offserver-success
  ;;
 *) echo 'Usage: backup.sh [init|backup|snapshots|check]' >&2; exit 2 ;;
esac
