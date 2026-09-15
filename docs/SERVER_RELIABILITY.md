# CLINiQ server reliability

## Current readiness

- Container restart policies are `unless-stopped`.
- CLINiQ data uses five named external Docker volumes. `docker compose down -v`
  cannot remove external volumes.
- The Docker host must have at least 50 GB free on its Docker data drive; alert at
  25 GB free and stop elective data imports below 15 GB.
- A dedicated clinic computer, UPS, BIOS power-recovery setting, and a clinic
  server Windows account are still required before production sign-off.

## First deployment on a server

1. Use a computer reserved for CLINiQ. Do not run unrelated development Docker
   projects on it.
2. Create a standard Windows clinic-server account with a strong password and
   restrict interactive use to authorized administrators.
3. Run `scripts\reliability\ensure_cliniq_volumes.ps1` before `docker compose up -d`.
4. Run `scripts\reliability\register_cliniq_startup_task.ps1` while signed in as
   the clinic-server account. Configure Windows to sign in that account after a
   controlled power recovery, or use an approved server-managed Docker runtime.
5. In BIOS/UEFI, enable **Restore on AC Power Loss** (or the vendor equivalent).
6. Connect a correctly sized UPS. Configure its vendor software for a graceful
   Windows shutdown before battery exhaustion; connect only the server, network
   switch, and required router equipment.

## Routine operations

- Start services with `scripts\reliability\start_cliniq.ps1`.
- Never run `docker volume rm cliniq_*`, `docker system prune --volumes`, or delete
  Docker Desktop data folders on the clinic server.
- `docker compose down` is safe for maintenance. Do not use `-v`; external volume
  protection provides a second safeguard.
- Check free capacity monthly and before importing bulk APE documents.

## Power-recovery drill

After the UPS and BIOS settings are configured, perform this supervised test:

1. Confirm the latest backup is successful.
2. Shut down the server normally, restore power, and allow Windows/Docker startup.
3. Verify `docker compose ps` shows `app`, `backup`, and `database` running.
4. Open the CLINiQ health endpoint and sign in as an administrator.
5. Record date, operator, startup time, backup status, and any issue in the
   clinic operations log.

Production sign-off requires this drill to pass on the dedicated clinic server.
