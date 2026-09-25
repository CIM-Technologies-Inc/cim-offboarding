# CIM Offboarding Deployment Guide

This guide covers local Docker testing, migration verification, seeder execution, DBeaver access, and the developer-to-QA-to-production workflow.

## 1. Local Prerequisites

Install:

- Docker Desktop
- Git

PHP, Composer, Node.js, and MySQL do not need to be installed locally when using Docker.

## 2. Pull the Latest Code

Run these commands from the project directory:

```powershell
git status
git pull --ff-only origin main
git log -1 --oneline
```

Do not pull over uncommitted changes that you still need. Commit or stash them first.

## 3. Build Frontend Assets

The Docker image builds the Vite assets itself, so no local `npm run build` is needed. A local `public/build` folder is ignored by the Docker build.

If `registry.npmjs.org` is blocked on your network (the build fails at `npm ci` with `Exit handler never called!` or `ECONNRESET`), build through a mirror:

```powershell
docker compose build --build-arg NPM_REGISTRY=https://registry.npmmirror.com/
docker compose up -d
```

## 4. Test Migrations Locally From a Clean Database

The following command deletes the local Docker database volume. Use this only when testing from a clean database:

```powershell
docker compose down -v
docker compose build --no-cache app
docker compose up -d
docker compose ps
```

Wait until `mysql` and `app` show `healthy`. The first start also generates `APP_KEY` automatically when `.env` does not set one; no `key:generate` step is needed.

The app container automatically runs migrations because the Compose app service has `RUN_MIGRATIONS=true`.

Check the migration state:

```powershell
docker compose exec -u www-data app php artisan migrate:status
```

Never use `migrate:fresh` in production.

## 5. Admin Account

On startup the app container creates the default admin account automatically, but only when no user with the admin role exists yet. Restarts never reset an existing admin's password. Set `SEED_ADMIN=false` in `.env` to turn this off.

To re-run the seeder by hand (this resets the `admin` password to the default):

```powershell
docker compose exec -u www-data app php artisan db:seed --class=AdminUserSeeder --force
```

The current development credentials are:

```text
Username: admin
Password: 123456
```

Change this password before production. Do not use this fixed credential strategy for a real production account.

To verify the account in MySQL:

```powershell
docker exec cim-offboarding-mysql-1 mysql `
  -ucim_offboarding `
  -pchange-this-database-password `
  -Dcim_db `
  -e "SELECT username,email,role FROM users WHERE username='admin';"
```

## 6. Open the Local Application

Open:

```text
http://localhost:8080
```

Check the services:

```powershell
docker compose ps
docker compose logs -f app queue scheduler
```

Stop the services without deleting the database:

```powershell
docker compose down
```

Do not use `docker compose down -v` unless you intentionally want to delete the local database.

## 7. Open the Database in DBeaver

The database is already migrated when the app container is healthy; DBeaver only needs to connect. Create a MySQL connection with:

```text
Host:     127.0.0.1
Port:     3307
Database: cim_db   (the DB_DATABASE value in .env; cim_offboarding if not set)
User:     cim_offboarding
Password: change-this-database-password
```

If DBeaver reports `Public Key Retrieval is not allowed`, add these driver properties:

```text
allowPublicKeyRetrieval=true
useSSL=false
```

Or use this JDBC URL:

```text
jdbc:mysql://127.0.0.1:3307/cim_db?allowPublicKeyRetrieval=true&useSSL=false
```

## 8. Production Environment

On the production server, create a production-only `.env` file. Never commit it:

```bash
cp .env.docker.example .env
```

Set secure values for:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-real-domain.example
DB_DATABASE=cim_db
MYSQL_APP_PASSWORD=use-a-long-random-password
MYSQL_ROOT_PASSWORD=use-a-different-long-random-password
```

Configure the real SMTP server and remove the QA email redirect:

```dotenv
MAIL_REDIRECT_TO=
```

Rotate any SMTP credential that was ever stored in a development `.env` file before production deployment.

Put the server behind HTTPS and back up these Docker volumes:

- `mysql-data`: application database
- `app-storage`: uploaded signatures, photos, and other application files

## 9. First Production Start

From the project directory on the production server:

```bash
docker compose build --pull
docker compose up -d
docker compose ps
```

Check the application response:

```bash
curl -I http://localhost:8080
```

Check migrations:

```bash
docker compose exec -u www-data app php artisan migrate:status
```

The first start creates the default `admin` / `123456` account. Sign in and change that password immediately, or set `SEED_ADMIN=false` in `.env` before the first start if the admin account will be created another way.

## 10. Production Updates

For an approved update:

```bash
git pull --ff-only origin main
docker compose build --pull
docker compose up -d
docker compose ps
```

The app, queue, and scheduler use the same updated image. Migrations run during app startup in the current Compose setup.

For a more controlled release, run the migration explicitly before starting the services:

```bash
docker compose run --rm --no-deps app php artisan migrate --force
docker compose up -d
```

Run only approved, idempotent seeders. Do not run `migrate:fresh` in production.

## 11. Developer to QA to Main Workflow

The intended flow is:

```text
Developer branch -> Pull Request -> CI checks -> QA testing and approval -> merge to main -> production deployment
```

Developer workflow:

```bash
git checkout -b feature/short-description
# make and test changes
git add .
git commit -m "Describe the change"
git push -u origin feature/short-description
```

Then open a pull request into `main`.

The GitHub Actions workflow at `.github/workflows/ci-cd.yml` runs for pull requests and pushes to `main`. It:

1. Installs PHP dependencies.
2. Runs the Laravel tests.
3. Installs frontend dependencies.
4. Builds the frontend assets.
5. Builds the Docker image.
6. Publishes the image to GHCR only after a push to `main`.

Recommended branch protection for `main`:

- Require pull requests.
- Require the CI status check to pass.
- Require at least one QA or team approval.
- Dismiss stale approvals after new commits.
- Block direct pushes to `main`.
- Require the branch to be up to date before merging.

The pull request checklist is stored in `.github/pull_request_template.md`.

## 12. Useful Commands

```powershell
# Service status
docker compose ps

# Application logs
docker compose logs -f app

# Queue logs
docker compose logs -f queue

# Scheduler logs
docker compose logs -f scheduler

# Run migrations
docker compose exec -u www-data app php artisan migrate --force

# Run one specific seeder
docker compose exec -u www-data app php artisan db:seed --class=AdminUserSeeder --force

# Stop services and preserve data
docker compose down

# Stop services and delete local data
# WARNING: destructive
docker compose down -v
```
