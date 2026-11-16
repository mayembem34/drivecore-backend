#!/usr/bin/env bash
set -euo pipefail
python3 - "${1:-/opt/drivecore/backend/.env}" <<'PY'
from pathlib import Path
import re
import sys
path = Path(sys.argv[1])
text = path.read_text()
settings = {
    'SANCTUM_STATEFUL_DOMAINS': ['drivecore.innovize-tech.com', 'www.drivecore.innovize-tech.com', 'api.drivecore.innovize-tech.com'],
    'CORS_ALLOWED_ORIGINS': ['https://drivecore.innovize-tech.com', 'https://www.drivecore.innovize-tech.com'],
}
for key, required in settings.items():
    match = re.search(rf'^{key}=(.*)$', text, re.M)
    existing = match.group(1).strip().strip('"').strip("'") if match else ''
    values = [value.strip() for value in existing.split(',') if value.strip()]
    if not match and key == 'SANCTUM_STATEFUL_DOMAINS':
        values = ['localhost', 'localhost:3000', '127.0.0.1', '127.0.0.1:8000']
    if not match and key == 'CORS_ALLOWED_ORIGINS':
        values = ['http://localhost:3000']
    value = ','.join(dict.fromkeys(values + required))
    entry = f'{key}={value}'
    text = re.sub(rf'^{key}=.*$', lambda _: entry, text, flags=re.M) if match else text + '\n' + entry + '\n'
if not re.search(r'^SESSION_COOKIE=', text, re.M):
    text += '\nSESSION_COOKIE=drivecore-session\n'
path.write_text(text)
path.chmod(0o600)
print('Frontend session allowlists configured. Existing cookie name preserved when explicitly set.')
PY
