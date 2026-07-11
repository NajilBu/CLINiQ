# CLINiQ Project Structure Guide

This file explains what every folder and important file does, and what should be placed inside each part of the project.

## Root Files

### `README.md`

Main project guide.

Put here:

- Project overview
- Tech stack
- Setup instructions
- Default login account
- Basic development notes

Do not put detailed source code explanations here. Use this file only as the quick starting guide.

### `.env`

Local environment configuration.

Put here:

- Local app URL
- Local database host
- Local database name
- Local database username
- Local database password

Example:

```text
APP_URL=http://localhost/cliniq/public
DB_HOST=127.0.0.1
DB_NAME=cliniq
DB_USER=root
DB_PASS=
```

Do not upload real online database passwords to GitHub. This file is ignored by `.gitignore`.

### `.env.example`

Sample environment configuration.

Put here:

- Example database settings
- Example app URL
- Safe placeholder values

This file is used by other developers as a guide when creating their own `.env` file.

### `.gitignore`

List of files and folders Git should ignore.

Put here:

- `.env`
- uploaded private files
- dependency folders
- temporary files

This prevents private or unnecessary files from being committed.

### `PROJECT_STRUCTURE.md`

This documentation file.

Put here:

- Explanation of every folder
- Explanation of key files
- Rules for where new code should go

Update this file whenever the project structure changes.

## `app/`

The `app` folder contains the private backend logic of the system. Files in this folder should not be directly opened in the browser.

Put here:

- Configuration files
- Helper functions
- Business logic
- Service classes/functions
- Reusable backend code

Do not put public pages, images, CSS, or JavaScript files here.

## `app/config/`

This folder contains configuration files used by the whole system.

### `app/config/env.php`

Reads values from the `.env` file.

Put here:

- Environment loading functions
- App URL helper
- Safe default values for configuration

Do not put database queries or page logic here.

### `app/config/database.php`

Creates the database connection using PDO.

Put here:

- MySQL connection logic
- PDO configuration
- Database connection function

Do not put SQL queries for pages here unless they are directly related to creating the database connection.

## `app/helpers/`

This folder contains reusable helper functions used by many pages.

Put here:

- Authentication helpers
- View/layout helpers
- Text escaping helpers
- Redirect helpers
- Shared utility functions

Do not put module-specific logic here if it only belongs to one module.

### `app/helpers/auth.php`

Handles login-related functions.

Put here:

- Session start
- Current user function
- Login attempt function
- Logout function
- Require login function
- Role-checking functions, if added later

Possible future additions:

```php
function require_role(array $roles): void
```

Use that later if some pages should only be opened by admin or nurse users.

### `app/helpers/view.php`

Handles shared layout and display helpers.

Put here:

- `render_header()`
- `render_footer()`
- HTML escaping function
- Shared navigation layout

Do not put database insert/update logic here.

## `app/services/`

This folder contains business logic that is more than a simple helper.

Put here:

- Patient risk classification
- QR token generation logic
- OCR processing logic
- Report calculations
- Inventory stock rules
- Reusable workflows

### `app/services/RiskClassifier.php`

Contains the rule-based patient risk classification function.

Put here:

- Risk scoring rules
- Urgency level rules
- Reasons why a patient is classified as Low, Moderate, High, or Critical

Example responsibilities:

- Add points for high fever
- Add points for abnormal pulse rate
- Add points for emergency symptoms
- Return risk score and risk level

Do not put form HTML here.

## `database/`

The `database` folder contains database-related files.

Put here:

- SQL schema
- Seed data
- Database migrations, if added later
- Backup sample data

### `database/schema.sql`

Main database structure.

Put here:

- `CREATE DATABASE`
- `CREATE TABLE`
- Foreign keys
- Default admin account
- Starter reference data

Update this file whenever a new table or column is needed.

Important tables currently included:

- `users`
- `patients`
- `clinic_visits`
- `ape_records`
- `nurse_alerts`
- `inventory_items`
- `referrals`
- `passport_access_logs`

## `public/`

The `public` folder contains files that can be accessed from the browser.

Put here:

- Public PHP pages
- Module pages
- CSS
- JavaScript
- Public API endpoints

This is the folder that should be opened through the browser:

```text
http://localhost/cliniq/public/
```

Do not put private configuration files here.

## `public/index.php`

Entry page of the system.

Purpose:

- Redirect logged-in users to the dashboard
- Redirect guests to the login page

Do not put dashboard content here.

## `public/login.php`

Login page.

Put here:

- Login form
- Login validation call
- Login error message

Do not put registration or user management here unless the project requires public registration, which is not recommended for this clinic system.

## `public/logout.php`

Logout script.

Purpose:

- Destroy the user session
- Redirect back to login

This file should stay simple.

## `public/dashboard.php`

Main dashboard after login.

Put here:

- Summary cards
- Pending nurse alerts
- Daily visit count
- Low-stock count
- Quick links to modules

Do not put long forms here. Put full forms inside their module folders.

## `public/emergency.php`

Emergency Health Passport page.

Purpose:

- Open patient emergency profile using QR/NFC token
- Show only approved emergency information
- Log passport access

Allowed information:

- Patient name
- Student number
- Course/section
- Blood type
- Allergies
- Existing medical conditions
- Emergency instructions
- Guardian contact

Do not show:

- Full clinic visit history
- Private diagnosis notes
- APE document files
- Medicine logs
- Full patient record

The QR/NFC should point to this page using a secure token:

```text
https://yourdomain.com/emergency.php?token=RANDOM_TOKEN
```

## `public/patients/`

Patient record module.

Put here:

- Patient list page
- Add patient page
- Edit patient page
- View patient profile page
- Emergency token management page

Current files:

- `index.php` lists patients
- `create.php` adds a new patient

Recommended future files:

- `show.php`
- `edit.php`
- `delete.php`
- `passport.php`

## `public/visits/`

Clinic visit module.

Put here:

- Record clinic visit page
- List clinic visits
- View visit details
- Update visit action taken
- Risk classification result display

Current files:

- `index.php` lists clinic visits
- `create.php` records a visit and applies risk classification

Recommended future files:

- `show.php`
- `edit.php`
- `print.php`

## `public/alerts/`

Real-Time Nurse Alerting module.

Put here:

- Alert submission page
- Alert dashboard/list
- Alert status update page
- Alert details page

Current files:

- `index.php` lists nurse alerts
- `create.php` submits an emergency alert

Recommended future files:

- `show.php`
- `update-status.php`

## `public/api/`

Simple API endpoints used by JavaScript.

Put here:

- AJAX endpoints
- Alert polling endpoint
- JSON responses

Current file:

- `alerts.php` returns pending alerts as JSON

Important rule:

API files should return JSON, not full HTML pages.

Possible future files:

- `patients-search.php`
- `inventory-status.php`
- `alert-status.php`

## `public/inventory/`

Medicine and clinical item inventory module.

Put here:

- Inventory list
- Add item page
- Edit item page
- Stock-in and stock-out records
- Low-stock monitoring
- Expiration monitoring

Current file:

- `index.php` shows inventory items

Recommended future files:

- `create.php`
- `edit.php`
- `stock-in.php`
- `stock-out.php`

## `public/ape/`

Annual Physical Examination module.

Put here:

- APE document list
- APE upload workflow
- Verification workflow

Current files:

- `index.php` lists APE records

## `public/referrals/`

External Referrals module.

Put here:

- Outgoing referrals
- External health facility tracking

Current files:

- `index.php` lists referrals

## `public/reports/`

Reports module.

Put here:

- Clinic visit reports
- Medicine usage reports
- Emergency alert reports
- Referral reports
- APE verification reports
- Printable summaries

Current file:

- `index.php` placeholder for reports

Recommended future files:

- `visits.php`
- `medicine-usage.php`
- `alerts.php`
- `referrals.php`
- `ape.php`

## `public/assets/`

Static public files.

Put here:

- CSS
- JavaScript
- public images
- icons

Do not put uploaded medical documents here.

## `public/assets/css/`

Stylesheets.

### `public/assets/css/app.css`

Main custom CSS file.

Put here:

- Layout styling
- Dashboard styling
- Form styling
- Emergency passport styling
- Custom Tailwind adjustments

Do not put JavaScript here.

## `public/assets/js/`

JavaScript files.

### `public/assets/js/app.js`

Main custom JavaScript file.

Put here:

- Dashboard alert polling
- Small UI interactions
- Fetch/AJAX calls
- Client-side form helpers

Do not put database credentials or private logic here. JavaScript is visible to users in the browser.

## `uploads/`

Storage folder for uploaded files.

Put here:

- APE document uploads
- Scanned forms
- Supporting clinic documents, if required

Important rules:

- Do not allow users to run PHP files from uploads.
- Validate uploaded file types.
- Rename uploaded files using safe generated filenames.
- Store file paths in the database.
- Keep sensitive uploaded files protected.

Current file:

- `.gitkeep` keeps the empty folder in Git

## Recommended Development Rules

### 1. Put public pages in `public/`

If it is opened by the browser, it belongs in `public/`.

Example:

```text
public/patients/create.php
```

### 2. Put reusable backend logic in `app/`

If the same logic is used by many pages, put it in `app/helpers/` or `app/services/`.

Example:

```text
app/services/RiskClassifier.php
```

### 3. Put database changes in `database/schema.sql`

If you add a new table, update the schema file.

### 4. Keep emergency passport data limited

The emergency page must only show emergency-approved information.

### 5. Use PDO prepared statements

Always use prepared statements when inserting user input into the database.

Good:

```php
$stmt = db()->prepare('SELECT * FROM patients WHERE id = ?');
$stmt->execute([$id]);
```

Avoid directly placing user input inside SQL strings.

### 6. Escape displayed text

Use `e()` when displaying text from the database.

Good:

```php
<?= e($patient['first_name']) ?>
```

### 7. Keep modules small

Each module should have its own folder:

```text
patients/
visits/
alerts/
inventory/
reports/
```

This makes the project easier to understand and defend.

## Suggested Next Development Order

1. Finish patient profile view and edit pages.
2. Finish clinic visit records and risk classification display.
3. Improve nurse alert status updates.
4. Add inventory create/edit and stock movement.
5. Add APE upload and OCR placeholder workflow.
6. Add QR code generation for emergency passport links.
7. Add reports and printable pages.
8. Add user roles and access restrictions.
