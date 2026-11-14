#!/usr/bin/env bash
set -euo pipefail
root="${1:-/opt/drivecore/infrastructure}"
# Challenge responses are public; certificate keys remain in a separate private mount.
sudo -n install -d -m 0755 "$root/acme" "$root/acme/.well-known" "$root/acme/.well-known/acme-challenge"
printf '%s' 'drivecore-acme-ready' | sudo -n tee "$root/acme/.well-known/acme-challenge/drivecore-readiness" >/dev/null
sudo -n chmod 0644 "$root/acme/.well-known/acme-challenge/drivecore-readiness"
