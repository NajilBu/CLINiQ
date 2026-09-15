<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/public/patient-accounts/index.php');
$service = file_get_contents($root . '/app/services/PatientAccountService.php');
$auth = file_get_contents($root . '/app/helpers/auth.php');
$cycle = file_get_contents($root . '/app/services/ApeCycleService.php');
$schema = file_get_contents($root . '/database/production_schema.sql');
$migration = file_get_contents($root . '/database/migrations/20260915_add_account_status_reason.sql');

foreach ([$page, $service, $auth, $cycle, $schema, $migration] as $source) {
    if ($source === false) {
        throw new RuntimeException('Patient account filter test source could not be read.');
    }
}

foreach ([
    'name="account_search"',
    'name="account_type"',
    'name="account_status"',
    'this.form.requestSubmit()',
    "\$account['status_reason']",
    'Reason not recorded',
] as $expected) {
    if (!str_contains($page, $expected)) {
        throw new RuntimeException("Patient account filtering or inactive-reason display is missing: {$expected}");
    }
}

$recentAccountsTable = explode('<section class="clinic-card overflow-hidden" id="recentPatientAccounts">', $page, 2)[1] ?? '';
$recentAccountsTable = explode('</table>', $recentAccountsTable, 2)[0] ?? '';
if ($recentAccountsTable === ''
    || preg_match('/data-recent-account-sort="birthdate"|>\s*Birthdate\s*</i', $recentAccountsTable)) {
    throw new RuntimeException('Birthdate must not appear in the recent patient accounts table.');
}

foreach ([$service, $auth, $cycle, $schema, $migration] as $source) {
    if (!str_contains($source, 'status_reason')) {
        throw new RuntimeException('An account status transition or schema source does not maintain the inactive reason.');
    }
}

echo "Patient account filters and inactive reasons test passed. No database writes.\n";
