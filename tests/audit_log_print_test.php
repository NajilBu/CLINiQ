<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$auditPage = file_get_contents($root . '/public/audit/index.php');
$printPage = file_get_contents($root . '/public/audit/print.php');

foreach (['Print Audit Log', 'print.php', '$printQuery', 'data-no-ajax="true"'] as $expected) {
    if (!str_contains($auditPage, $expected)) {
        throw new RuntimeException("The Audit Log page is missing print integration: {$expected}");
    }
}

foreach ([
    "(\$user['role'] ?? '') !== 'admin'",
    'Audit Log Print Preview',
    'All activities matching the selected filters are included.',
    'Prepared by:',
    'Activities included:',
    'clinic_profile_logo_path',
    'class="clinic-logo"',
    'object-fit: contain',
    'window.print()',
    'table-header-group',
    'page-break-inside: avoid',
    'audit_log_metadata_summary',
] as $expected) {
    if (!str_contains($printPage, $expected)) {
        throw new RuntimeException("The printable audit view is missing: {$expected}");
    }
}

foreach (['search', 'module', 'action', 'actor', 'outcome', 'date_from', 'date_to'] as $filter) {
    if (!str_contains($printPage, "\$_GET['{$filter}']")) {
        throw new RuntimeException("The printable audit view does not preserve the {$filter} filter.");
    }
}

if (preg_match('/\bLIMIT\s+[?,0-9]/i', $printPage)) {
    throw new RuntimeException('The printable audit view must include every matching activity, not a paginated subset.');
}
if (str_contains($printPage, 'View technical details') || str_contains($printPage, 'audit_log_pretty_metadata')) {
    throw new RuntimeException('The printable audit view must not expose raw technical details.');
}
if (str_contains(file_get_contents($root . '/public/reports/index.php'), 'Print Audit Log')) {
    throw new RuntimeException('Audit printing must remain separate from the Reports page.');
}

echo "Audit log print test passed. No database writes.\n";
