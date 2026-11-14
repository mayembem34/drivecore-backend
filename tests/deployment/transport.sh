#!/usr/bin/env bash
set -euo pipefail
source_root="$(cd "$(dirname "$0")/../.." && pwd)"
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT
mkdir -p "$fixture/backend/deploy" "$fixture/infrastructure/nginx" "$fixture/infrastructure/php" "$fixture/home/drivecore-deploy/test" "$fixture/bin"
cp -a "$source_root/deploy/." "$fixture/backend/deploy/"
printf '.env\n' > "$fixture/backend/.gitignore"
printf 'DUMMY=local-test\n' > "$fixture/backend/.env"
printf 'DUMMY=local-test\n' > "$fixture/infrastructure/.env"
git -C "$fixture/backend" init -q -b main
git -C "$fixture/backend" -c user.name=Test -c user.email=test@example.invalid add .
git -C "$fixture/backend" -c user.name=Test -c user.email=test@example.invalid commit -qm initial
base="$(git -C "$fixture/backend" rev-parse HEAD)"
printf 'new revision\n' > "$fixture/backend/revision-marker"
git -C "$fixture/backend" add revision-marker
git -C "$fixture/backend" -c user.name=Test -c user.email=test@example.invalid commit -qm target
revision="$(git -C "$fixture/backend" rev-parse HEAD)"
git -C "$fixture/backend" bundle create "$fixture/home/drivecore-deploy/test/source.bundle" HEAD
git -C "$fixture/backend" reset --hard -q "$base"
cat > "$fixture/bin/sudo" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
if [ "$1" = '-n' ]; then shift; fi
exec "$@"
MOCK
cat > "$fixture/bin/docker" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$MOCK_LOG"
# Reproduce a subprocess that consumes its inherited standard input.
if [[ "$*" == *'artisan down'* ]]; then cat >/dev/null; fi
if [[ "$*" == *'pg_dump'* ]]; then printf 'test database dump\n'; fi
MOCK
cat > "$fixture/bin/curl" <<'MOCK'
#!/usr/bin/env bash
printf '{"data":{"version":"v1","status":"up"}}\n'
MOCK
chmod +x "$fixture/bin/"*
sed -e "s|/opt/drivecore|$fixture|g" -e "s|/home/ubuntu|$fixture/home|g" "$source_root/deploy/server.sh" > "$fixture/run.sh"
export PATH="$fixture/bin:$PATH"
export MOCK_LOG="$fixture/docker.log"
printf 'exit 99\n' | bash "$fixture/run.sh" "$revision" test
[ "$(git -C "$fixture/backend" rev-parse HEAD)" = "$revision" ]
[ -s "$fixture/backups/before-test.dump" ]
grep -q 'artisan migrate --force' "$MOCK_LOG"
grep -q 'artisan up --no-interaction' "$MOCK_LOG"
grep -q 'horizon:status' "$MOCK_LOG"
printf 'Deployment completed despite a child consuming stdin.\n'
