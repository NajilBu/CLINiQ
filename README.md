# CLINiQ

CLINiQ is a simple PHP and MySQL web application for a school clinic information management system. It is designed for a capstone prototype with electronic health records, clinic visits, APE document tracking, QR/NFC emergency health passport links, patient risk classification, and real-time nurse alerting through lightweight polling.

## Design guide

See [the CLINiQ design system](DESIGN_SYSTEM.md) for shared styles, reusable UI
components, page-family conventions, and the checklist for correcting inconsistent designs.

## Tech Stack

- PHP 8+
- MySQL or MariaDB
- Tailwind CSS
- JavaScript fetch/AJAX
- XAMPP for local development

## Quick Setup

1. Copy this folder to your XAMPP `htdocs` directory, or point Apache to this folder.
2. Copy `.env.example` to `.env` and update the database credentials.
3. Run `php scripts/database/migrate.php`.
   This imports `database/production_schema.sql` for a fresh database and applies
   only migrations that have not already been recorded.
4. Open `http://localhost/cliniq/public/` in your browser.

Default account after importing the schema:

- Email: `admin@cliniq.local`
- Password: `password`

## Main Modules

- Authentication and role-based dashboard
- Patient records
- Clinic visits
- APE document records
- Emergency passport tokens
- Nurse alerts
- Medicine inventory
- Referral records
- Reports

## Notes

The emergency passport should expose only approved emergency information. Full health records must stay behind authenticated user access.

## Electron desktop app after cloning

The desktop source, icons, and dependency lockfile are included in this repository.
Install Node.js with npm, complete the PHP/database setup above, and start Apache
and MySQL. From the cloned repository root, run:

```powershell
npm run desktop:install
npm run desktop
```

The first command downloads the pinned Electron dependencies and needs internet
access. Run it again after pulling changes to the Electron dependency lockfile.
The desktop app connects to `http://localhost/CLINiQ/public/` by default.
If your checkout is served at a different URL, set it before launching:

```powershell
$env:CLINIQ_CLINIC_URL = 'http://localhost/my-clinic/public/'
npm run desktop
```

To create a Windows installer, run `npm run desktop:build`. Output appears in
`electron/dist/`. Dependencies and generated installers are intentionally excluded
from Git; cloning includes the source, not a prebuilt executable. The desktop shell
requires the PHP/MySQL server; it does not bundle XAMPP or the database.

See [the desktop guide](electron/README.md) for additional details.
