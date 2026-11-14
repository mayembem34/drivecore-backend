#!/usr/bin/env bash
set -euo pipefail
cd /opt/drivecore/infrastructure
sudo -n docker run --rm \
 -v "$PWD/letsencrypt:/etc/letsencrypt" \
 -v "$PWD/acme:/var/www/acme" \
 certbot/certbot:v5.4.0 renew --quiet --non-interactive
sudo -n docker compose exec -T nginx nginx -t
sudo -n docker compose exec -T nginx nginx -s reload
