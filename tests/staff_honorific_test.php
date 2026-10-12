<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/SystemSettings.php';

function expect_staff_honorific(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

expect_staff_honorific(staff_honorific_options() === [
    '' => 'None',
    'Dr.' => 'Dr.',
    'Mr.' => 'Mr.',
    'Ms.' => 'Ms.',
    'Mrs.' => 'Mrs.',
], 'Honorific options must remain the approved staff-only set.');
expect_staff_honorific(normalize_staff_honorific('') === null, 'No honorific must persist as null.');
expect_staff_honorific(normalize_staff_honorific('Dr.') === 'Dr.', 'An approved honorific must persist unchanged.');
expect_staff_honorific(staff_display_name('Maria Santos', 'Dr.') === 'Dr. Maria Santos', 'Display names must prefix the legal name once.');

try {
    normalize_staff_honorific('Prof.');
    throw new RuntimeException('Unapproved honorifics must be rejected.');
} catch (InvalidArgumentException) {
}

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/services/SystemSettings.php');
$settings = file_get_contents($root . '/public/settings/index.php');
$auth = file_get_contents($root . '/app/helpers/auth.php');
$migration = file_get_contents($root . '/database/migrations/20261013_add_clinic_staff_honorific.sql');
$schema = file_get_contents($root . '/database/production_schema.sql');
$report = file_get_contents($root . '/app/services/SystemReport.php');
$auditIndex = file_get_contents($root . '/public/audit/index.php');
$auditPrint = file_get_contents($root . '/public/audit/print.php');

expect_staff_honorific($service !== false
    && str_contains($service, 'staff_role, honorific)')
    && str_contains($service, 'UPDATE clinic_staff SET staff_role = ?, honorific = ? WHERE person_id = ?')
    && !str_contains($service, 'UPDATE clinic_staff SET staff_role = ?, honorific = ?, position_title = ?')
    && str_contains($service, 'legal_name'), 'Staff create and update must keep honorific separate from legal name and preserve position title.');
expect_staff_honorific($settings !== false && str_contains($settings, 'name="honorific"') && str_contains($settings, 'placeholder="Maria Santos"') && str_contains($settings, "staffProfile['legal_name']"), 'Create and edit staff forms must use a separate honorific field.');
expect_staff_honorific($auth !== false && str_contains($auth, 'cs.honorific') && str_contains($auth, 'staff_display_name'), 'Signed-in staff headers must use the saved honorific.');
expect_staff_honorific($migration !== false && str_contains($migration, 'ADD COLUMN IF NOT EXISTS honorific VARCHAR(8) NULL'), 'The honorific migration must be additive and rerunnable.');
expect_staff_honorific($schema !== false && str_contains($schema, 'honorific VARCHAR(8) NULL'), 'The canonical schema must include the honorific.');
expect_staff_honorific($report !== false && str_contains($report, "NULLIF(cs.honorific, '')"), 'Doctor report labels must include the saved honorific.');
expect_staff_honorific($service !== false && str_contains($service, "NULLIF(cs.honorific, ''), p.first_name"), 'Staff notification recipients must include the saved honorific.');
expect_staff_honorific($auditIndex !== false && $auditPrint !== false
    && str_contains($auditIndex, "NULLIF(actor_staff.honorific, ''), p.first_name")
    && str_contains($auditPrint, "NULLIF(actor_staff.honorific, ''), p.first_name"), 'Audit screen and printout actors must include the saved honorific.');

echo "Staff honorific tests passed.\n";
