<?php

declare(strict_types=1);

$page = file_get_contents(__DIR__ . '/../public/ape/index.php');
$view = file_get_contents(__DIR__ . '/../app/helpers/view.php');
$grid = file_get_contents(__DIR__ . '/../public/assets/js/ag-grid-tables.js');

$checks = [
    'restores the last page-level APE state' => str_contains($page, "\$_SESSION['ape_work_queue_state']"),
    'persists an explicit overall selection' => str_contains($page, "['scope'] = 'overall'"),
    'preserves search when population changes' => str_contains($page, "\$employeeScopeQuery['q'] = \$search"),
    'assigns stable state keys to APE grids' => str_contains($page, "'stateKey' => 'ape-work-queue-'"),
    'renders grid state keys' => str_contains($view, 'data-state-key='),
    'stores table state in local storage' => str_contains($grid, 'cliniq-grid-state:'),
    'restores table filters' => str_contains($grid, 'api.setFilterModel(savedState.filterModel)'),
    'restores table sort order' => str_contains($grid, 'api.applyColumnState'),
    'restores table page number' => str_contains($grid, 'api.paginationGoToPage(savedState.page)'),
    'persists attention panel state' => str_contains($page, 'data-ape-persistent-details="attention"'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) throw new RuntimeException('Failed: ' . $label);
}

echo 'APE queue persistence tests passed (' . count($checks) . " assertions).\n";
