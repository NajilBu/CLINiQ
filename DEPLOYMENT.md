# CLINiQ deployment

The clinic application and MariaDB run as separate containers. Database data,
uploaded documents, and backups use independent Docker volumes. The database is
not published to the host network; only the application is available at
`127.0.0.1:8080` until the Cloudflare Tunnel step is completed.

## Prepare configuration

From the repository root in PowerShell:

```powershell
Copy-Item docker/.env.example docker/.env
notepad docker/.env
```

Replace all three placeholder secrets. `DB_PASS` and `MARIADB_PASSWORD` must be
identical. Keep `MARIADB_ROOT_PASSWORD` different. Set `APP_KEY` to a long random
value. Do not commit `docker/.env`.

## Build and start

```powershell
docker compose config
docker compose build
docker compose up -d
docker compose ps
```

The application startup waits for MariaDB, imports the production schema for a
fresh volume, and applies pending migrations before Apache begins serving.

Check the local health endpoint:

```powershell
Invoke-RestMethod http://127.0.0.1:8080/public/api/health.php
```

Expected result: `status` is `ok` and `database` is `ready`.

## Important data locations

- `cliniq_database`: MariaDB files
- `cliniq_documents`: protected APE documents
- `cliniq_uploads`: public alert, incident, and logo uploads
- `cliniq_backups`: verified daily, weekly, and semester backups

Running `docker compose down` preserves these volumes. Do not use
`docker compose down -v` on a real installation because `-v` deletes them.
