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
    'versions APE grid state when the default column layout changes' => str_contains($page, "'stateKey' => 'ape-work-queue-v2-'"),
    'preserves readable APE column widths instead of equal auto-fit columns' => str_contains($page, "'fitColumns' => false")
        && str_contains($page, "'headerName' => 'Next Action'")
        && str_contains($page, "'width' => 320"),
    'renders grid state keys' => str_contains($view, 'data-state-key='),
    'lets no-auto-fit grids honor their declared widths' => str_contains($grid, 'flex: shouldFitColumns ? 1 : undefined'),
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
