# CLINiQ Clinic Desktop

This folder contains the Electron shell for the clinic/staff side of CLINiQ. It opens the staff login directly. The patient portal remains a normal browser application.

## Local requirements

- Docker Desktop running the CLINiQ services.
- CLINiQ available at `http://localhost:8081/public/`.
- Node.js and npm for development or packaging.

## Install and run

From the cloned repository root:

```powershell
cd electron
npm ci
npm start
```

The desktop app checks `api/health.php` before opening `login.php`. If the CLINiQ Docker application or database is unavailable, it displays a retry screen. The desktop app intentionally uses only this local Docker address.

## Windows package

```powershell
npm run build:win
```

Build output is written to `electron/dist/` and is excluded from Git.

## Security behavior

- Node integration is disabled for clinic pages.
- Context isolation and renderer sandboxing are enabled.
- Only staff-facing routes on the configured clinic URL stay inside Electron.
- Visitor registration stays inside Electron. Emergency registration, the patient portal, and external links open in the default browser.
- Browser permission requests are denied.
- Database, SMTP, and tunnel credentials remain in the PHP application, not Electron.
