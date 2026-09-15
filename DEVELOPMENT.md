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

## Team handoff checklist

1. Make each change on a feature branch. Use XAMPP or the isolated development
   Compose project for local testing; do not point test writes at the team's
   production database. If using an existing local database, make a verified,
   protected backup before applying additive migrations or testing write forms.
2. Commit application code, matching dated migrations, and relevant tests
   together. Stage intended paths selectively, review `git diff --cached` and
   `git diff --cached --name-only`, and run `git diff --check` plus the relevant
   PHP/JavaScript tests. Do not import or commit a database dump as a shortcut.
3. Match the reviewed team branch's deployment contract: clinic app on host
   `127.0.0.1:8081`, public gateway on internal port `8081`, and the named
   Cloudflare route pointing to `http://gateway:8081`. A host still using the
   older `8080` configuration needs a coordinated update. If a local Docker
   installation already uses `8081`, run the separate development project on
   another port such as `8092`.
4. Push the branch for review, not directly into an active host checkout. The
   host operator keeps `docker/.env`, `docker/cloudflared/tunnel-token`, external
   backup paths, and Docker volumes on that computer; none come from Git.
5. Before pulling the reviewed change on the host, verify a recent backup and
   restore test. After pulling, the host operator checks Compose, rebuilds and
   recreates only the required services, then verifies local clinic access,
   the permanent patient hostname, private-route `404` responses, email links,
   and a phone QR/NFC scan. See `DEPLOYMENT.md` for the host commands.

Removing a sensitive file in a new commit does not erase it from earlier Git
commits. If patient data or credentials were pushed, stop sharing that history
and arrange an approved history cleanup and credential review before merging.

Each checkout receives a deterministic project name based on its absolute folder
path. This prevents two checkouts on the same computer from silently sharing a
database or uploaded-file volume.

## Shared staging

A shared staging server is for browser testing after code is pushed. Developers
still edit their local clone and deploy reviewed commits to staging. Keep MariaDB
private; never publish port 3306. Public Cloudflare routes should expose only the
patient-facing gateway, while staff development access remains authenticated and
private.
