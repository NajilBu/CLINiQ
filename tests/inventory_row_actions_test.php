<?php

declare(strict_types=1);

$inventory = file_get_contents(__DIR__ . '/../public/inventory/index.php');
$view = file_get_contents(__DIR__ . '/../app/helpers/view.php');
$grid = file_get_contents(__DIR__ . '/../public/assets/js/ag-grid-tables.js');

$checks = [
    'inventory tables no longer define an Actions column' => !str_contains($inventory, "['headerName' => 'Actions'"),
    'active inventory rows carry popup actions' => str_contains($inventory, "'rowActionsHtml' => \$actionsHtml"),
    'archived inventory rows carry restore popup actions' => str_contains($inventory, "'rowActionsHtml' => \$restoreActions"),
    'rows use descriptive popup titles' => substr_count($inventory, "'rowActionsTitle' => 'Inventory actions — '") === 2,
    'inventory enables keyboard row actions' => str_contains($inventory, "'keyboardRows' => \$activeTab !== 'activity'"),
    'grid renderer exposes keyboard-row setting' => str_contains($view, 'data-keyboard-rows='),
    'Enter and Space open row actions' => str_contains($grid, "key !== 'Enter' && key !== ' '"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) throw new RuntimeException('Failed: ' . $label);
}

echo 'Inventory row action tests passed (' . count($checks) . " assertions).\n";
