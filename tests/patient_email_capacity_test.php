<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/PatientEmail.php';

$oldBrevoAccount = patient_email_provider_identifier_from_settings([
    'host' => 'smtp-relay.brevo.com',
    'username' => 'old@smtp-brevo.com',
    'from_email' => 'noreply@plpuhs.qd.je',
]);
$universityBrevoAccount = patient_email_provider_identifier_from_settings([
    'host' => 'smtp-relay.brevo.com',
    'username' => 'university@smtp-brevo.com',
    'from_email' => 'plphealthservices@plpuhs.qd.je',
]);
if ($oldBrevoAccount === $universityBrevoAccount) {
    throw new RuntimeException('Different Brevo SMTP accounts must not share capacity state.');
}

if (patient_email_remaining_capacity(300, 299, 0, false) !== 1) {
    throw new RuntimeException('The 300-message cap must leave one slot before the limit.');
}
if (patient_email_remaining_capacity(300, 300, 0, false) !== 0) {
    throw new RuntimeException('The 300-message cap must release no 301st message.');
}
if (patient_email_remaining_capacity(300, 299, 1, false) !== 0) {
    throw new RuntimeException('In-flight reservations must count against the 300-message cap.');
}

echo "Patient email capacity test passed.\n";
