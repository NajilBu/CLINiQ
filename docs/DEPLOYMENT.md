# CLINiQ deployment

The clinic application, backup scheduler, and MariaDB run as separate containers. Database data,
uploaded documents, and backups use independent Docker volumes. The database is
not published to the host network; only the application is available at
`127.0.0.1:8081` until the Cloudflare Tunnel step is completed. This branch
uses host port `8081` and gateway port `8081`; a host still running the older
`8080` configuration needs a coordinated desktop and Cloudflare origin update.

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
On the production host, set `PATIENT_PORTAL_URL` to the permanent HTTPS origin
followed by `/patient-portal` (for example,
`https://patients.example.edu/patient-portal`). Do not commit the host's
`docker/.env` or copy a developer's settings onto the host.

## Build and start

```powershell
.\scripts\reliability\ensure_cliniq_volumes.ps1
docker compose config --quiet
docker compose build
docker compose up -d
docker compose ps
```

The application startup waits for MariaDB, imports the production schema for a
fresh volume, and applies pending migrations before Apache begins serving.

Check the local health endpoint:

```powershell
Invoke-RestMethod http://127.0.0.1:8081/public/api/health.php
```

Expected result: `status` is `ready` and the database service is `ready`.

## Updating an existing team host

Do not update the team's running host directly from an unreviewed feature
branch. First confirm that the current backup has passed the restore test, and
that the host operator has a recovery plan. Keep the existing Docker volumes,
project-root `.env`, `docker/.env`, external-drive path, and tunnel token; these
are host-owned and must not be replaced by a developer's files.

After the reviewed commit is pulled, the host operator runs the following
commands. They rebuild the application image and refresh the services without
deleting any database or document volume:

```powershell
docker compose config --quiet
docker compose build app
docker compose --profile public-portal up -d --force-recreate app backup gateway cloudflared
docker compose --profile public-portal ps
```

Then check the local health endpoint above, the permanent patient hostname and
its private-route `404` responses below, and the backup restore test. If the
tunnel token file or permanent hostname mapping is missing, do not run the
public-portal update until the host operator restores that configuration.

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

## External-drive backup and recovery

Do not enable an external backup copy until a BitLocker-encrypted removable drive
has been prepared. The Compose default is a local development placeholder and is
not disaster recovery. Follow [the external-drive backup and recovery runbook](docs/EXTERNAL_DRIVE_BACKUP_AND_RECOVERY.md)
to prepare the drive, mount it into Docker, verify an encrypted copy, and perform
the replacement-computer recovery drill.

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

## Permanent Cloudflare patient portal

On the production host only, place the named tunnel token in
`docker/cloudflared/tunnel-token`. Keep it private; both Git and the Docker build
context exclude that directory. In the Cloudflare dashboard, attach the
permanent patient hostname to this tunnel and set its origin service URL to
`http://gateway:8081` (the gateway's internal Docker network address, not the
public hostname or app host port `8081`). Keep the existing hostname and token when
deploying application-only changes.

Start the public-only gateway and named tunnel after the app is healthy:

```powershell
docker compose --profile public-portal up -d gateway cloudflared
docker compose --profile public-portal ps
```

Opening the permanent hostname's root redirects to patient login. The gateway
exposes only the patient portal, required static assets and clinic logo, and
the emergency passport endpoint. Staff login, visitor registration, settings,
backups, and every other route return `404` through the public address. Staff
access stays local through Electron and `http://localhost:8081/public/`.

Replace `$patientOrigin` with the real permanent HTTPS origin and verify both
sides of the boundary after each deployment:

```powershell
$patientOrigin = "https://patients.example.edu"
(Invoke-WebRequest "$patientOrigin/patient-portal/patient-login.php").StatusCode
try { Invoke-WebRequest "$patientOrigin/public/login.php" } catch { $_.Exception.Response.StatusCode.value__ }
try { Invoke-WebRequest "$patientOrigin/public/visitor-registration.php" } catch { $_.Exception.Response.StatusCode.value__ }
```

Expected results are `200`, `404`, and `404`. Check QR/NFC links and patient
email links on a phone; old printed tags containing a temporary tunnel URL must
be reissued. If these checks fail, keep the previous working deployment and
review the app, gateway, and cloudflared logs before changing DNS or volumes.

## Temporary free patient-portal test

For a short test on a developer laptop, use the separate `cloudflared-quick`
service. It needs no Cloudflare account, domain, or tunnel token and does not
replace the production `cloudflared` service. Do not run both tunnel modes on
the same host unless you intentionally want two public addresses for this
patient portal.

After the local app and database are healthy, the operator runs:

```powershell
docker compose --profile quick-tunnel up -d gateway cloudflared-quick
docker compose logs --tail 50 cloudflared-quick
```

Copy the new `https://...trycloudflare.com` URL from the quick-tunnel log. Check
its patient login and confirm that `/public/login.php` returns `404`; the same
patient-only gateway is used by both tunnel modes. Stop only the temporary
tunnel when finished:

```powershell
docker compose --profile quick-tunnel stop cloudflared-quick
```

Cloudflare assigns a random URL each time this tunnel is recreated. This is a
testing address, not the team's permanent domain: do not print it on permanent
QR/NFC tags, and reissue any test QR/NFC links after the URL changes. The named
tunnel and its host-only token remain the deployment path for the permanent
patient hostname.

QR/NFC links generated while viewing the portal through the quick URL use that
current browser origin. Patient email links instead use `PATIENT_PORTAL_URL`
from the local `docker/.env`. If testing email links, the laptop operator may
temporarily set that host-only value to the current quick URL plus
`/patient-portal`, then restore it afterward. Never commit that temporary URL
or copy the local environment file to the team's permanent-domain host.
