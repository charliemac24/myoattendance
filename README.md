# MYO Attendance — Core V1

MYO Attendance is a same-origin Laravel application with a React/Vite presentation layer for tenant-scoped attendance management.

## Core V1 includes

- Session authentication, email verification, password reset, and organizations
- Groups, subgroups, schedules, people, and transactional CSV import
- Opaque server-generated QR identities and camera/manual development scanning
- Configurable ordered schedule periods and multiple attendance IN/OUT cycles per organization-local date
- Server-authoritative Present/Late classification and daily attendance reports

SMS, billing, offline sync, payroll, leave/holiday management, manual corrections, exports, multiple shifts/cycles, and UI polish are out of scope for Core V1.

## Stack and local setup

Requirements: Docker Desktop, Git, and a terminal. The application container uses PHP 8.3, Laravel 12, and MySQL 8.4; the Node service uses Node 22 for Vite.

```sh
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed
```

`.env.example` ships with `APP_DEBUG=false`. For local debugging, set `APP_DEBUG=true` in your own `.env` (never commit `.env`).

Application: http://localhost:8000  
Vite development server: http://localhost:5173  
phpMyAdmin (development only): http://localhost:8080  
Mailpit (development only): http://localhost:8025

The Docker database is `mysql:3306` (`myo` / `myo_secret` / `myo_attendance`); from the host it is exposed at `127.0.0.1:3307`. Local mail is sent to Mailpit at `mailpit:1025`.

For a clean disposable database, use a separate Compose project/volume; do not destroy a developer's primary database volume.

## Create a staging Super Admin

Use the dedicated Artisan command from the application container:

```sh
docker compose exec app php artisan superadmin:create
```

It prompts for the name, email, and password (the password is hidden). For a known staging email and name:

```sh
docker compose exec app php artisan superadmin:create \
  --email=superadmin@staging.example \
  --name="Staging Super Admin"
```

If the email already belongs to an account, the command asks for confirmation before promoting it. Use `--force` for a non-interactive promotion; leave the hidden password prompt blank to keep the existing password. The command makes the account verified, assigns the platform `super_admin` role, and removes tenant organization membership.

## Verification commands

```sh
docker compose config
docker compose ps
docker compose exec app php -v
docker compose exec app php artisan migrate:status
docker compose exec -T app php artisan test
docker compose exec app vendor/bin/pint --test
docker compose run --rm node npm run build
```

The frontend has production-build support but no ESLint or TypeScript/typecheck script. Camera access requires HTTPS in deployed environments (localhost is permitted by modern browsers). Set production secrets, `APP_DEBUG=false`, HTTPS session cookies, database credentials, mail delivery, storage permissions, and any trusted-proxy settings before deployment. phpMyAdmin and Mailpit must remain development-only.

## Architecture and handoff

Laravel owns validation, tenant boundaries, authentication, schedule resolution, QR validation, attendance state, locking/idempotency, and report semantics. React adapts to the documented contracts in [docs/UI_API_CONTRACTS.md](docs/UI_API_CONTRACTS.md). See [docs/UI_INTEGRATION_RULES.md](docs/UI_INTEGRATION_RULES.md), [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), and [docs/LEGACY_DATABASE.md](docs/LEGACY_DATABASE.md).
