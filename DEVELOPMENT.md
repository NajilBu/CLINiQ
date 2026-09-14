# CLINiQ developer environment

Every developer runs an isolated Docker copy of CLINiQ. Source code is shared
through Git; databases, uploaded files, sessions, secrets, and backups are not.
Do not use XAMPP for this workflow.

## Requirements

- Git
- Docker Desktop with Docker Compose
- PowerShell 5.1 or newer

## First-time setup

Clone the repository, create a feature branch, and run the setup script from the
repository root:

```powershell
git clone https://github.com/NajilBu/CLINiQ.git
cd CLINiQ
git switch -c feature/short-description
.\scripts\development\setup.ps1
```

The script creates `docker/.env` from the committed example when needed, validates
the Compose configuration, builds the image, and starts the application and
MariaDB. It does not start the production backup scheduler.

If `docker/.env` was just created, replace every placeholder secret before using
anything except disposable test data. Never commit that file.

Open these URLs:

- Clinic interface: `http://localhost:8081/public/`
- Patient portal: `http://localhost:8081/patient-portal/`

Use another port when 8081 is occupied:

```powershell
.\scripts\development\setup.ps1 -Port 8092
```

## Daily development

PHP, CSS, and JavaScript files are bind-mounted into the application container,
so ordinary source edits are available after refreshing the browser. Rebuild when
the Dockerfile, PHP extensions, Apache configuration, or container entrypoint
changes:

```powershell
.\scripts\development\setup.ps1
```

To start without rebuilding:

```powershell
.\scripts\development\setup.ps1 -NoBuild
```

To stop containers while preserving the developer database and uploads:

```powershell
.\scripts\development\stop.ps1
```

Do not run `docker compose down -v` or delete `cliniq-dev-*` volumes unless the
associated disposable development data is intentionally being erased.

## Database changes

Never make an undocumented schema change directly in MariaDB. Add a dated SQL
file under `database/migrations/`, make it safe to run once, and commit it with the
code that needs it. Pending migrations run automatically whenever the app starts.

Use synthetic or anonymized test records. Do not distribute clinic production
backups or personally identifiable patient data to developer computers.

## Git workflow

Keep work on a feature branch, pull the latest shared branch before integration,
run relevant tests, and open a review before merging. Do not commit:

- `docker/.env` or other secrets
- Docker volumes or database dumps
- patient uploads and medical documents
- generated backups

Each checkout receives a deterministic project name based on its absolute folder
path. This prevents two checkouts on the same computer from silently sharing a
database or uploaded-file volume.

## Shared staging

A shared staging server is for browser testing after code is pushed. Developers
still edit their local clone and deploy reviewed commits to staging. Keep MariaDB
private; never publish port 3306. Public Cloudflare routes should expose only the
patient-facing gateway, while staff development access remains authenticated and
private.
