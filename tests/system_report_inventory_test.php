<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/SystemReport.php';

$source = file_get_contents(dirname(__DIR__) . '/app/services/SystemReport.php');
if ($source === false) {
    throw new RuntimeException('Unable to read the report builder.');
}

foreach ([
    'Inventory and Loan Transactions',
    'Inventory Transactions',
    'Medicine Dispensed',
    'Equipment Loans',
    'Low Stock Items',
    'Overdue Loans',
    'Inventory Transactions by Type',
    'Loan Status',
] as $expected) {
    if (!str_contains($source, $expected)) {
        throw new RuntimeException("Inventory and loan transactions are missing {$expected}.");
    }
}

if (array_key_exists('inventory', system_report_module_labels())
    || array_key_exists('loans', system_report_module_labels())) {
    throw new RuntimeException('Inventory and loans must remain within one transaction group.');
}

echo "Transaction inventory checks passed. No database writes.\n";
