<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$list = file_get_contents($root . '/public/appointments/index.php');
$record = file_get_contents($root . '/public/appointments/view.php');

if (!str_contains($list, "appointments/view.php?id=")
    || !str_contains($list, 'View Record')
    || !str_contains($list, "if (\$filterStatus === 'Completed' && \$status === 'Completed')")
    || !str_contains($list, "'rowActionsHtml' => \$actions . \$historyHtml")) {
    throw new RuntimeException('Only the Completed tab appointment actions modal may link to the read-only record.');
}

if (!str_contains($record, 'require_login();')
    || !str_contains($record, 'WHERE a.appointment_id = ?')
    || !str_contains($record, 'Read-only details for this clinic appointment.')
    || str_contains($record, '<form')
    || str_contains($record, 'method="post"')) {
    throw new RuntimeException('The appointment record page must be authenticated, scoped, and read-only.');
}

echo "Appointment read-only record checks passed. No database writes.\n";
