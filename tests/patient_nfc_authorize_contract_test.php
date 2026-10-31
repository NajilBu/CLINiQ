<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../patient-portal/patient-nfc-authorize.php');
if ($source === false) {
    throw new RuntimeException('Unable to read the NFC authorization endpoint.');
}

$checks = [
    'throttle identity comes from the signed-in profile ID' => str_contains($source, "\$identity = (string) (\$profile['student_id'] ?? '');"),
    'password hash is scoped to the signed-in account and person' => str_contains($source, 'SELECT password_hash FROM accounts WHERE id = ? AND person_id = ? AND account_status = "active" LIMIT 1'),
    'password is verified against the scoped account hash' => str_contains($source, "\$passwordHash === '' || !password_verify(\$password, \$passwordHash)"),
    'sanitized profile is not treated as a password-hash source' => !str_contains($source, 'student_password_is_valid($profile, $password)'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException("NFC authorization contract failed: {$label}");
    }
}

echo "NFC authorization contract passed.\n";
