# Clinic feedback

The public entry is `public/clinic-feedback.php`; admin/doctor reporting is at
`public/feedback/index.php`. The homepage and staff navigation link to these pages.

## Database setup

Apply `database/migrations/20260907_create_clinic_feedback.sql` to the configured
`AUTH_DB_NAME` database after the visit migrations. It creates the new table only;
public requests never run migrations. The migration was applied to this local
workspace's configured database during implementation.

## Behavior

- Students enter their ID only: no account login, email, or password.
- After ID lookup, show a picker of the student's Active and Completed visits,
  newest first by date/time then visit ID. Display date, time, service, and status.
- Students explicitly choose a visit, including older visits. Rated visits remain
  visible with disabled "Feedback submitted" buttons. No visit is auto-selected.
- Verify student ownership and current eligibility when selecting and submitting.
  Switching visits rotates the form token to invalidate previously opened forms.
- One immutable response per visit, enforced with a unique database key.
- The selected visit is held server-side and revalidated at submission.
- A form session lasts one hour; students can look up the same eligible visit
  again after expiration. There is no age limit on the visit itself.
- ID lookup is not identity verification. No clinical notes, diagnoses, or names
  are returned by the feedback lookup. Repeated lookups are throttled per session.
- All 22 ratings are required, integer 1–7. Consent and survey metadata are
  required; comments are optional (5,000 characters maximum).
- Overall SERVPERF averages five section means equally. Reports average
  individual response scores, not service-group averages. Date filters use
  submission dates in the application's configured timezone.
- Admins and doctors can review responses; names and student IDs are omitted.
- Equipment loans alone do not qualify because they do not create a visit.

## Verification

Run with PHP 8 and the project's usual extensions, from the repository root:

```powershell
php tests/clinic_feedback_test.php
php tests/clinic_feedback_database_test.php
foreach ($case in @('render','submit','submit_old','picker','lookup','select','select_rated','select_foreign','csrf','stale','inactive','malformed','admin','forbidden','anonymous')) {
    php tests/clinic_feedback_page_test.php $case
    if ($LASTEXITCODE -ne 0) { throw "Feedback test failed: $case" }
}
```

Database and controller tests create connection-local temporary tables using
existing table definitions and synthetic records. They do not copy or alter
real patient, visit, or feedback records. The account needs permission to create
temporary tables. All test scripts reject web requests.
