# CLINiQ external-drive backup and recovery

This procedure is required before CLINiQ is deployed with patient data. Until the
physical drive is available, external backup is intentionally unverified.

## Policy

- Use two BitLocker-encrypted external drives. Label them `CLINIQ-BACKUP-A` and
  `CLINIQ-BACKUP-B`; rotate them weekly and keep the inactive drive in a locked,
  offsite location.
- Keep 14 daily and 12 weekly copies. Create one semester archive after each
  approved term and retain it for the clinic's approved records-retention period.
- The clinic administrator owns the drive and the `BACKUP_ENCRYPTION_KEY` recovery
  record. Store that key separately from both drives in an approved password vault.
- Never store plaintext SQL exports, decrypted snapshots, or the recovery key on a
  shared drive, in the repository, or in cloud-synchronized folders.

## Prepare a drive

1. Connect the new drive, format it as NTFS if required by clinic policy, enable
   BitLocker, and save its recovery key outside the server.
2. In an elevated PowerShell window at the project root, run:

   ```powershell
   .\scripts\backup\prepare_external_backup_drive.ps1 -DriveLetter E:
   ```

3. In the project-root `.env`, set the exact prepared path:

   ```ini
   BACKUP_EXTERNAL_HOST_PATH=E:\CLINiQ-Backups
   ```

4. Restart only the services that mount the destination:

   ```powershell
   docker compose up -d --force-recreate app backup
   ```

5. In CLINiQ Settings, enable the external backup copy and select the drive root.
   CLINiQ rejects any mounted folder that does not contain the preparation marker.

## Verify the external copy

1. Run a fresh backup from Settings, or run:

   ```powershell
   docker compose exec app php /var/www/html/scripts/backup/run_backup.php --force
   ```

2. Confirm `Daily\CLINiQ_daily_<timestamp>\manifest.json` and only `.enc` payloads
   exist on the drive. There must be no `.sql` or decrypted document copies.
3. Validate the external snapshot with the maintenance container:

   ```powershell
   docker compose --profile maintenance run --rm restore-test /var/backups/external/Daily/CLINiQ_daily_<timestamp>
   ```

4. Record the date, drive label, snapshot ID, operator, verification result, and
   restored-table count in the clinic operations log. Eject the drive safely.

## Replacement-computer recovery drill

Perform this at least once before production and every six months afterward. Use
a replacement computer, never the active production server.

1. Install Docker Desktop, place the approved CLINiQ release on the replacement
   computer, and create `docker/.env` with the original database credentials,
   `APP_KEY`, and `BACKUP_ENCRYPTION_KEY`.
2. Connect and unlock the backup drive. Set the replacement project's `.env` to
   its `BACKUP_EXTERNAL_HOST_PATH`, then start Docker with `docker compose up -d`.
3. Validate the selected external snapshot using the maintenance command in the
   preceding section. Stop if it fails.
4. Decrypt into a protected, non-synchronized temporary folder:

   ```powershell
   docker compose run --rm --entrypoint php app /var/www/html/scripts/backup/decrypt_backup.php /var/backups/external/Daily/CLINiQ_daily_<timestamp> /tmp/cliniq-recovery
   docker cp cliniq-app-1:/tmp/cliniq-recovery C:\CLINiQ-Recovery
   ```

5. Have an authorized administrator import the recovered database and document
   payloads into the fresh replacement installation, then verify administrator
   sign-in, representative patient records, document availability, and reports.
6. Securely delete `C:\CLINiQ-Recovery`, record the elapsed recovery time and
   results, and keep the production system unchanged until the drill is signed off.

## Drill record

Record: date; operator; drive label; snapshot ID; backup verification result;
restore-test result and table count; recovered functions checked; elapsed time;
issues; clinic administrator approval.
