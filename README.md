# DriveCore backend

Laravel 13 / PHP 8.4 modular backend for the web ERP and Expo mobile app.
PostgreSQL is authoritative; Redis provides cache, queues, heartbeat and Horizon metrics.

## Implemented operational API

The machine-readable contract is [`public/openapi.json`](public/openapi.json).
All operational routes use `/api/v1`, JSON errors and permission checks. Lists use
Laravel resource pagination (25 records, ordered by ID). A user has one branch
restriction per company membership; `null` grants all branches in that company.
Only the interactive bootstrap command can create a platform administrator.

- Mobile: `POST /api/v1/auth/tokens`, then Bearer token. Tokens expire after seven days.
- Web: `GET /sanctum/csrf-cookie`, then `POST /api/v1/auth/login` with cookies and
  `X-XSRF-TOKEN`. Set explicit CORS origins and Sanctum stateful domains for the frontend.
- Profile/logout: `GET /api/v1/auth/me`, `POST /api/v1/auth/logout`.
- Companies, branches and new company users; customer and vehicle records.
- UTC reservations with half-open periods and per-vehicle transaction locks.
- Draft rental creation, controlled handover and return, odometer and inspection notes.
- Private PDF/JPEG/PNG uploads (10 MiB) and authorization-protected downloads.
- Append-only application audit events for writes and workflow transitions.

The role catalog includes the ten exact SRS roles (CEO, General Manager, Business
Accounts Manager, Operations Manager, Maintenance Manager, Finance Manager, Credit
Control Manager, HR/Admin Manager, Mechanic/Technician and System Administrator),
plus `driver` and the `company-admin`, `operations`, `finance`, `viewer` compatibility
aliases. Company and branch scope is independent of role. Drivers have assigned
rental/inspection/fuel access and driver/dispatch chat; mechanics have scoped workshop
access; HR cannot administer privileged accounts. There is no public registration.

The defined backend requirements through M16.5 include quotations/private PDFs,
SQL-sorted customer/vehicle statistics, messaging and per-user notifications,
invitation-only onboarding and password recovery, TOTP for web/mobile sign-in,
rate cards and digital inspections, preventive maintenance/fuel/components,
credit control and independent approvals, AR/AP, credit notes, bank reconciliation,
fixed assets/depreciation and financial statements, CRM leads, incident management,
contract amendments/deposits and purchase requisitions. See OpenAPI 0.7.0 for request
and response contracts, permission gates and canonical units.

Web and mobile logins for enrolled users return a five-minute `challenge_token`;
complete `POST /api/v1/auth/two-factor/challenge` before using a session or bearer token.
Direct-created users must rotate their initial password. Company policy may require
privileged users to enroll in TOTP before accessing business routes. Invitation tokens
are stored hashed and queued invitation notifications are encrypted. Set `FRONTEND_URL`
to the actual web frontend for invitation/reset links, configure `MAIL_*`, and keep the
queue worker running. Global role changes for existing multi-company accounts require
platform administration; accepting an invitation preserves compatible global roles.

Approvals reject self-decisions. Threshold-gated mutations require an independently
approved exact value and consume that decision once. Quotation discounts and purchase
orders also use the shared approval engine. A returned rental creates a draft invoice;
it is not financial closure. Financial values use integer minor units; stored distances,
fuel and weights use km, L and kg, with efficiency in L/100km.

M17 printing/barcode scanning and M18 GPS are undefined placeholders and remain out of
scope. Offline synchronization and external-provider activation are separate work.
There is no destructive business-record delete API (personal/shared saved views can be deleted).

## Tests and CI

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

PR and main CI runs the suite against SQLite and PostgreSQL 17, checks dependency
advisories and formatting, and verifies PostgreSQL/Redis connectivity. Manual deployment
runs that same CI first. This project has no branch-protection rules installed by this change.

## Existing Oracle server

The running stack is in `/opt/drivecore/infrastructure`; the source checkout is
`/opt/drivecore/backend`. Repository `deploy/` templates are synchronized there by
`.github/workflows/deploy-oracle.yml`. Server `.env` files are never copied from Git.
PostgreSQL/Redis have no published ports. Keep the public IP reserved before relying
on it long term. Environment `staging` uses one Horizon process.

The workflow is manual, main-only, serial, and deploys an exact selected commit. It
requires `DEPLOY_SSH_KEY` and verified `DEPLOY_KNOWN_HOSTS` repository secrets. It stops
workers, takes a mode-600 PostgreSQL dump, fast-forwards a clean checkout, builds PHP,
installs locked dependencies, migrates, seeds roles, checks dependencies, and recreates
services. Data volumes, credentials and document data persist. A deployment failure
may leave maintenance mode enabled; inspect the failed step and forward-fix it rather
than blindly rolling back database migrations. The dump's path is logged, not its contents.

After deploying, create the first administrator interactively on the VM:

```sh
cd /opt/drivecore/infrastructure
sudo docker compose exec app php artisan drivecore:bootstrap-admin YOUR_EMAIL --name="YOUR_NAME"
```

The password is prompted privately and must not be entered into Actions logs or chat.
The command refuses to run if a platform administrator exists. No example password is seeded.

## Trusted HTTPS on the temporary public IP

Allow OCI ingress TCP 443 (keep TCP 80 for ACME HTTP-01). Select **enable_https** when
manually running deployment. `deploy/enable-tls.sh` uses official Certbot 5.4 webroot
support for Let's Encrypt short-lived IP certificates, mounts certificates privately,
configures Nginx HTTPS, updates APP_URL and secure cookies, and installs a twice-daily
renewal timer. Its first issuance registers an ACME account and accepts Let's Encrypt's
subscriber agreement. References: https://letsencrypt.org/2026/03/11/shorter-certs-certbot.html

Authentication requires HTTPS by default. Only local test configuration disables it.
The HTTP health endpoint remains available. Do not send credentials over HTTP.

```sh
systemctl status drivecore-tls.timer
journalctl -u drivecore-tls.service
```

## Private object storage

The S3 adapter is installed. Until a provider is configured, documents use private local
storage and are served only through authorized controllers. For a private S3-compatible
bucket, set FILESYSTEM_DISK=s3, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY,
AWS_DEFAULT_REGION, AWS_BUCKET, and optional AWS_ENDPOINT/AWS_USE_PATH_STYLE_ENDPOINT
in the server's private backend .env. Use a bucket-scoped principal and block public access.
Re-cache configuration and run `php artisan drivecore:check-storage`. The probe is
created and removed; existing local documents retain their recorded storage disk.
Bucket versioning/replication and backup of S3 object contents must be configured with
the provider. They are not covered by the local-document restic backup below.

## Off-server encrypted backups (prepared, not activated)

Copy `deploy/backup.env.example` to `/opt/drivecore/infrastructure/backup.env`, fill in
an off-server Restic repository and restricted provider credentials, and chmod 600.
Keep the Restic password separately recoverable; it is required to restore backups.
Run `bash /opt/drivecore/backend/deploy/backup.sh init` once for a new repository, then
`backup`, `snapshots` and `check`. These use an official pinned Restic image and encrypt
PostgreSQL dumps, private local documents and application configuration before upload.
Local predeployment dumps remain plaintext with restrictive permissions. No retention
pruning or automated backup schedule is enabled. Configure cadence after a successful
backup and restore drill. Backup consistency across DB/document writes requires a quiet
window or maintenance mode. Off-server backup success is recorded locally only after upload.

Restore into a separate disposable stack first: use Restic `restore SNAPSHOT --target PATH`,
inspect the PostgreSQL dump with `pg_restore --list`, restore it into a clean test database,
and restore document files and the original APP_KEY. Never restore over a live database
without a reviewed recovery plan. A full restore drill is not yet performed.

## Monitoring

- `/up`: framework liveness; `/api/v1/health`: API liveness.
- `/api/v1/readiness`: authorized DB/cache readiness (`companies.manage`).
- `php artisan drivecore:check --workers`: DB/cache plus scheduler/queue freshness.
- Scheduler records heartbeat and dispatches queue heartbeat every minute; stale workers
  generate warnings. Horizon snapshots every five minutes; dashboard is restricted to
  active platform administrators and HTTPS.
- Docker logs rotate at 10 MiB x 3 per container.

```sh
cd /opt/drivecore/infrastructure
sudo docker compose logs --tail=100 app worker scheduler
sudo docker compose exec app php artisan drivecore:check --workers
```

External uptime alerts, off-host log aggregation and backup-failure notifications still
need an external destination. Next.js deployment also needs the frontend repository/image;
this backend does not invent a frontend implementation or install Expo on the server.
