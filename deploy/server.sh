#!/usr/bin/env bash
set -euo pipefail
exec </dev/null
revision="$1"
run_id="$2"
cd /opt/drivecore/backend
exec 9>/home/ubuntu/drivecore-deploy/deploy.lock
flock -n 9
test -z "$(git status --porcelain)"
test "$(git branch --show-current)" = main
git fetch "/home/ubuntu/drivecore-deploy/$run_id/source.bundle" HEAD
test "$(git rev-parse FETCH_HEAD)" = "$revision"
git merge-base --is-ancestor HEAD "$revision"
test -f .env
test -f /opt/drivecore/infrastructure/.env
sudo -n docker info >/dev/null
compose() {
  sudo -n docker compose --project-directory /opt/drivecore/infrastructure "$@" </dev/null
}
compose exec -T app php artisan down --retry=60 --no-interaction
compose stop worker scheduler
umask 077
install -d -m 700 /opt/drivecore/backups
compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc'             > "/opt/drivecore/backups/before-$run_id.dump"
test -s "/opt/drivecore/backups/before-$run_id.dump"
git merge --ff-only "$revision"
test "$(git rev-parse HEAD)" = "$revision"
test -f deploy/enable-tls.sh
cp deploy/compose.yaml /opt/drivecore/infrastructure/compose.yaml
sed 's|..:/var/www/html|../backend:/var/www/html|g' deploy/compose.override.yaml             > /opt/drivecore/infrastructure/compose.override.yaml
cp deploy/nginx/default.conf /opt/drivecore/infrastructure/nginx/default.conf
cp deploy/php/Dockerfile /opt/drivecore/infrastructure/php/Dockerfile
mkdir -p /opt/drivecore/infrastructure/nginx/tls /opt/drivecore/infrastructure/acme /opt/drivecore/infrastructure/letsencrypt
compose config --quiet
sudo -n docker build -t drivecore-php:8.4 /opt/drivecore/infrastructure/php
sudo -n docker run --rm --user "$(id -u):$(id -g)"             -e COMPOSER_HOME=/tmp/composer             -v /opt/drivecore/backend:/var/www/html             drivecore-php:8.4 composer install --no-interaction --prefer-dist --optimize-autoloader
compose up -d --force-recreate app
compose exec -T app php artisan config:clear --no-interaction
compose exec -T app php artisan migrate --force --no-interaction
compose exec -T app php artisan db:seed --class=RolePermissionSeeder --force --no-interaction
compose exec -T app php artisan config:cache --no-interaction
compose exec -T app php artisan drivecore:check --no-interaction
compose exec -T app php artisan up --no-interaction
compose up -d --force-recreate nginx worker scheduler
compose exec -T nginx nginx -t
curl --fail --silent --show-error --retry 10 --retry-delay 3             --retry-all-errors --max-time 10 http://127.0.0.1/up >/dev/null
worker_ready=false
for attempt in $(seq 1 10); do
  if compose exec -T worker php artisan horizon:status --no-interaction; then
    worker_ready=true
    break
  fi
  sleep 2
done
test "$worker_ready" = true
curl --fail --silent --show-error --retry 5 --retry-delay 2 --retry-all-errors --max-time 10 http://127.0.0.1/api/v1/health | python3 -c 'import json,sys; data=json.load(sys.stdin); assert data["data"]["version"] == "v1" and data["data"]["status"] == "up"'
compose ps
printf 'Deployed commit %s\n' "$revision"
rm -f "/home/ubuntu/drivecore-deploy/$run_id/source.bundle"
rm -f "/home/ubuntu/drivecore-deploy/$run_id/deploy.sh"
rmdir "/home/ubuntu/drivecore-deploy/$run_id"
