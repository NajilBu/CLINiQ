<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/SystemReport.php';

$labels = system_report_module_labels();
if (($labels['inventory'] ?? null) !== 'Inventory' || array_key_exists('loans', $labels)) {
    throw new RuntimeException('Medicine and equipment must use one Inventory report module.');
}

if (normalize_system_report_modules(['loans']) !== ['inventory']) {
    throw new RuntimeException('Legacy equipment-loan report links must open the combined Inventory module.');
}

$source = file_get_contents(dirname(__DIR__) . '/app/services/SystemReport.php');
if ($source === false) {
    throw new RuntimeException('Unable to read the report builder.');
}

$sectionStart = strpos($source, "if (in_array('inventory', \$modules, true))");
$nextSection = strpos($source, "if (in_array('referrals', \$modules, true))");
if ($sectionStart === false || $nextSection === false || $nextSection <= $sectionStart) {
    throw new RuntimeException('Combined Inventory report section is missing.');
}
$section = substr($source, $sectionStart, $nextSection - $sectionStart);

foreach ([
    'Active Medicine',
    'Active Equipment',
    'Medicine Dispensed',
    'Equipment Loans',
    'Equipment Items Borrowed',
    'Currently Borrowed',
    'Overdue Loans',
    'Medicine Dispensed by Item',
    'Loan Status',
    'Borrowed Equipment',
] as $expected) {
    if (!str_contains($section, $expected)) {
        throw new RuntimeException("Combined Inventory report is missing {$expected}.");
    }
}

if (str_contains($source, "if (in_array('loans', \$modules, true))")
    || str_contains($source, "\$sections['loans']")) {
    throw new RuntimeException('Equipment Loans must not remain as a separate report section.');
}

echo "System report inventory checks passed. No database writes.\n";
