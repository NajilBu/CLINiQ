# CLINiQ Clinic Desktop

This folder contains the Electron shell for the clinic/staff side of CLINiQ. It opens the staff login directly. The patient portal remains a normal browser application.

## Local requirements

- Apache and MySQL running through XAMPP.
- CLINiQ available at `http://localhost/CLINiQ/public/`.
- Node.js and npm for development or packaging.

## Install and run

```powershell
cd C:\xampp\htdocs\CLINiQ\electron
npm install
npm start
```

The desktop app checks `api/health.php` before opening `login.php`. If Apache or MySQL is unavailable, it displays a retry screen.

## Use a different local URL

Set `CLINIQ_CLINIC_URL` before starting Electron:

```powershell
$env:CLINIQ_CLINIC_URL = 'http://127.0.0.1:8080/'
npm start
```

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
