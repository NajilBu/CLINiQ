# CLINiQ deployment

The clinic application, backup scheduler, and MariaDB run as separate containers. Database data,
uploaded documents, and backups use independent Docker volumes. The database is
not published to the host network; only the application is available at
`127.0.0.1:8080` until the Cloudflare Tunnel step is completed.

## Prepare configuration

From the repository root in PowerShell:

```powershell
Copy-Item docker/.env.example docker/.env
notepad docker/.env
```

Replace all four placeholder secrets. `DB_PASS` and `MARIADB_PASSWORD` must be
identical. Keep `MARIADB_ROOT_PASSWORD` different. Set `APP_KEY` to a long random
value and keep `BACKUP_ENCRYPTION_KEY` different from it. Do not commit
`docker/.env`; both keys are required to use the application and recover encrypted backups.

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

Expected result: `status` is `ready` and the database service is `ready`.

The `backup` service checks every five minutes. Before 8:00 AM it stays idle;
at or after 8:00 AM it creates the missing daily backup. If Docker starts later,
the backup is created during that catch-up check. Sunday daily backups also create
the retained weekly copy. Semester backups remain an explicit Settings action.

New database dumps and uploaded-document payloads are encrypted individually
with authenticated AES-256-GCM before the snapshot is finalized. Manifests retain
checksums so verification checks both ciphertext integrity and recovered
plaintext. Backups created before format version 2 remain readable.

Run a privileged restore test only through the isolated maintenance container:

```powershell
docker compose --profile maintenance run --rm restore-test
```

The root database credential is blanked in the normal `app` and `backup`
containers. Only this short-lived maintenance container receives it, creates the
temporary verification database, and removes that database before exiting.

For a full recovery drill, decrypt a snapshot into a new temporary folder inside
the app container, then copy that folder to a protected administrator location:

```powershell
docker compose exec app php /var/www/html/scripts/backup/decrypt_backup.php /var/backups/cliniq/Daily/CLINiQ_daily_REPLACE_ME /tmp/cliniq-recovery
docker cp cliniq-app-1:/tmp/cliniq-recovery C:\CLINiQ-Recovery
```

Delete the plaintext recovery folder after restoration. Never place it in a
shared or cloud-synchronized folder.

## Important data locations

- `cliniq_database`: MariaDB files
- `cliniq_documents`: protected APE documents
- `cliniq_uploads`: public alert, incident, and logo uploads
- `cliniq_backups`: verified daily, weekly, and semester backups

Running `docker compose down` preserves these volumes. Do not use
`docker compose down -v` on a real installation because `-v` deletes them.

## Free public patient-portal test

Cloudflare Quick Tunnels provide a temporary public HTTPS address without an
account or domain. The address changes whenever the tunnel is recreated, so this
is suitable for testing only and must not be printed in permanent patient QR
codes.

Start the public-only gateway and tunnel:

```powershell
docker compose --profile quick-tunnel up -d gateway cloudflared
docker compose logs cloudflared --tail 50
```

Copy the `https://...trycloudflare.com` address from the log. Opening its root
redirects to the patient login. The gateway exposes only the patient portal,
required static assets and clinic logo, and the emergency passport endpoint.
Staff login, visitor registration, settings, backups, and every other route
return `404` through the public address. They remain available locally through
Electron and `http://localhost:8080/public/`.

Replace `$tunnelUrl` below with the temporary address and verify both sides of
the boundary:

```powershell
$tunnelUrl = "https://replace-this.trycloudflare.com"
(Invoke-WebRequest "$tunnelUrl/patient-portal/patient-login.php").StatusCode
try { Invoke-WebRequest "$tunnelUrl/public/login.php" } catch { $_.Exception.Response.StatusCode.value__ }
try { Invoke-WebRequest "$tunnelUrl/public/visitor-registration.php" } catch { $_.Exception.Response.StatusCode.value__ }
```

Expected results are `200`, `404`, and `404`. Stop only the free public tunnel
when testing is finished; the local app, database, and backup scheduler continue
running:

```powershell
docker compose --profile quick-tunnel stop cloudflared gateway
```
