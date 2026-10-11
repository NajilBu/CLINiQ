<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/SystemReport.php';

$source = file_get_contents(dirname(__DIR__) . '/app/services/SystemReport.php');
if ($source === false) {
    throw new RuntimeException('Unable to read the report builder.');
}

foreach ([
    "'title' => 'Inventory'",
    'Inventory Transactions',
    'Medicine Dispensed',
    'Equipment Loans',
    'Low Stock Items',
    'Overdue Loans',
    'Inventory Transactions by Type',
    'Status of Loans Issued During Period',
] as $expected) {
    if (!str_contains($source, $expected)) {
        throw new RuntimeException("Inventory and loan transactions are missing {$expected}.");
    }
}

if (!str_contains($source, "\$overdueLoans . ' overdue'")
    || !str_contains($source, "'summary_metrics' => [")
    || !str_contains($source, "system_report_metric('Overdue Loans', \$overdueLoans, 'current snapshot')")) {
    throw new RuntimeException('Overdue loans must appear beneath equipment loans and remain available to the attention summary.');
}

$medicinePosition = strpos($source, "system_report_chart('Medicine Dispensed by Item'");
$borrowedPosition = strpos($source, "system_report_chart('Borrowed Equipment'");
$stockPosition = strpos($source, "system_report_chart('Stock Condition (Current Snapshot)'");
if ($medicinePosition === false || $borrowedPosition === false || $stockPosition === false
    || $medicinePosition > $borrowedPosition || $borrowedPosition > $stockPosition) {
    throw new RuntimeException('Inventory detail cards must show medicine, borrowed equipment, then stock condition.');
}

if (!array_key_exists('inventory', system_report_module_labels())
    || array_key_exists('loans', system_report_module_labels())) {
    throw new RuntimeException('Inventory must remain one section without a separate loans module.');
}

echo "Transaction inventory checks passed. No database writes.\n";
