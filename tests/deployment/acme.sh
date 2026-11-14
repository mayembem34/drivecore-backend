#!/usr/bin/env bash
set -euo pipefail
source_root="$(cd "$(dirname "$0")/../.." && pwd)"
fixture="$(mktemp -d)"
container=""
cleanup() {
 if [ -n "$container" ]; then docker rm -f "$container" >/dev/null; fi
 sudo -n rm -rf "$fixture"
}
trap cleanup EXIT
umask 077
mkdir -p "$fixture/acme"
# Exercise the same permissions helper used by the actual deploy and TLS scripts.
bash "$source_root/deploy/prepare-acme.sh" "$fixture"
container="$(docker run -d --add-host app:127.0.0.1 -p 127.0.0.1::80 \
 -v "$source_root/deploy/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro" \
 -v "$fixture/acme:/var/www/acme:ro" nginx:stable-bookworm)"
address="$(docker port "$container" 80/tcp)"
response="$(curl --fail --silent --show-error --retry 10 --retry-connrefused --retry-delay 1 "http://$address/.well-known/acme-challenge/drivecore-readiness")"
[ "$response" = drivecore-acme-ready ]
printf 'Nginx served the public ACME challenge from a restrictive-umask fixture.\n'
