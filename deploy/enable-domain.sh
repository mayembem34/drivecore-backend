#!/usr/bin/env bash
set -euo pipefail
cd /opt/drivecore/infrastructure
umask 077
mkdir -p letsencrypt nginx/tls
bash /opt/drivecore/backend/deploy/prepare-acme.sh
probe="$(curl --fail --silent --show-error --resolve api.drivecore.innovize-tech.com:80:127.0.0.1 http://api.drivecore.innovize-tech.com/.well-known/acme-challenge/drivecore-readiness)"
test "$probe" = drivecore-acme-ready
sudo -n docker run --rm \
 -v "$PWD/letsencrypt:/etc/letsencrypt" \
 -v "$PWD/acme:/var/www/acme" \
 certbot/certbot:v5.4.0 certonly --non-interactive --agree-tos \
 --register-unsafely-without-email --cert-name drivecore-domain \
 --webroot --webroot-path /var/www/acme \
 --domains api.drivecore.innovize-tech.com
sed -e 's/158.180.34.27/api.drivecore.innovize-tech.com/g' -e 's/drivecore-ip/drivecore-domain/g' /opt/drivecore/backend/deploy/nginx/https.conf.template > nginx/tls/domain.conf
sudo -n docker compose exec -T nginx nginx -t
sudo -n docker compose exec -T nginx nginx -s reload
python3 - <<'ENV'
from pathlib import Path
import re
p=Path('/opt/drivecore/backend/.env')
s=p.read_text()
for key,value in {'APP_URL':'https://api.drivecore.innovize-tech.com','SESSION_SECURE_COOKIE':'true','AUTH_REQUIRE_HTTPS':'true','SESSION_DOMAIN':'null'}.items():
 entry=f'{key}={value}'
 if re.search(rf'^{key}=',s,re.M):
  s=re.sub(rf'^{key}=.*$',entry,s,flags=re.M)
 else:
  s+='\n'+entry+'\n'
p.write_text(s)
p.chmod(0o600)
ENV
sudo -n docker compose exec -T app php artisan config:cache --no-interaction
sudo -n docker compose restart worker scheduler
sudo -n install -m 644 /opt/drivecore/backend/deploy/drivecore-tls.service /etc/systemd/system/
sudo -n install -m 644 /opt/drivecore/backend/deploy/drivecore-tls.timer /etc/systemd/system/
sudo -n systemctl daemon-reload
sudo -n systemctl enable --now drivecore-tls.timer
curl --fail --silent --show-error --resolve api.drivecore.innovize-tech.com:443:127.0.0.1 https://api.drivecore.innovize-tech.com/up > /dev/null
