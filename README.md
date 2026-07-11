# CLINiQ

CLINiQ is a simple PHP and MySQL web application for a school clinic information management system. It is designed for a capstone prototype with electronic health records, clinic visits, APE document tracking, QR/NFC emergency health passport links, patient risk classification, and real-time nurse alerting through lightweight polling.

## Tech Stack

- PHP 8+
- MySQL or MariaDB
- Tailwind CSS
- JavaScript fetch/AJAX
- XAMPP for local development

## Quick Setup

1. Copy this folder to your XAMPP `htdocs` directory, or point Apache to this folder.
2. Create a MySQL database named `cliniq`.
3. Import `database/schema.sql` using phpMyAdmin.
4. Copy `.env.example` to `.env` and update the database credentials.
5. Open `http://localhost/cliniq/public/` in your browser.

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
