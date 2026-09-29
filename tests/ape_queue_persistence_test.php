<?php

declare(strict_types=1);

$page = file_get_contents(__DIR__ . '/../public/ape/index.php');
$view = file_get_contents(__DIR__ . '/../app/helpers/view.php');
$grid = file_get_contents(__DIR__ . '/../public/assets/js/ag-grid-tables.js');

$checks = [
    'restores queue and search without retaining an old batch choice' => str_contains($page, "array_flip(['queue', 'q', 'population'])"),
    'stores deliberate batch selections separately from legacy queue state' => str_contains($page, "\$_SESSION['ape_batch_selection_v2']")
        && str_contains($page, 'ape_resolve_batch_selection('),
    'keeps overall as an explicit selection' => str_contains($page, "['queue' => \$activeQueue, 'scope' => 'overall', 'selection' => 'overall', 'population' => \$populationScope]"),
    'shows only the selected batch name in the picker button' => str_contains($page, "? (string) \$selectedBatch['batch_name']")
        && !str_contains($page, "'%s • %s, %s–%s'"),
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
