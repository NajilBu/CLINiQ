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

Docker is the supported team-development environment. After cloning, run:

```powershell
.\scripts\development\setup.ps1
```

Then open `http://localhost:8081/public/`. Each checkout receives an isolated
MariaDB database and uploaded-file volumes, while source files are mounted for
live editing. See [the developer environment guide](DEVELOPMENT.md) for setup,
branching, database migration, and data-safety instructions.

The production schema creates no default users or passwords. Create the initial
clinic administrator through the controlled clinic setup process and use a
unique password.

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
Install Node.js with npm and start the Docker development environment described
above. From the cloned repository root, run:

```powershell
npm run desktop:install
npm run desktop
```

The first command downloads the pinned Electron dependencies and needs internet
access. Run it again after pulling changes to the Electron dependency lockfile.
Point the desktop app at the Docker development URL before launching:

```powershell
$env:CLINIQ_CLINIC_URL = 'http://localhost:8081/public/'
npm run desktop
```

To create a Windows installer, run `npm run desktop:build`. Output appears in
`electron/dist/`. Dependencies and generated installers are intentionally excluded
from Git; cloning includes the source, not a prebuilt executable. The desktop shell
requires the Docker application and database; it does not bundle them.

See [the desktop guide](electron/README.md) for additional details.
