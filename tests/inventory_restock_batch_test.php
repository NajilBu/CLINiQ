<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/CliniqInventoryWorkflow.php';

$label = cliniq_inventory_medicine_option_label([
    'item_name' => 'Paracetamol 500mg',
    'expiration_date' => '2027-03-15',
    'quantity' => 25,
    'unit' => 'tablets',
]);
if ($label !== 'Paracetamol 500mg (25 tablets)') {
    throw new RuntimeException('The medicine option label must use the current name, quantity, and unit format.');
}

$restockSource = file_get_contents(dirname(__DIR__) . '/public/inventory/restock.php');
$inventoryPageSource = file_get_contents(dirname(__DIR__) . '/public/inventory/index.php');
$schemaSource = file_get_contents(dirname(__DIR__) . '/database/production_schema.sql');
if (str_contains($restockSource, 'UPDATE inventory_items SET quantity')) {
    throw new RuntimeException('Restocking must not merge quantity into an existing inventory row.');
}
if (!str_contains($restockSource, 'INSERT INTO inventory_items')) {
    throw new RuntimeException('Restocking must create a separate inventory batch row.');
}
if (!str_contains($restockSource, "item_type = 'Medicine' AND is_active = 1")) {
    throw new RuntimeException('Only an active medicine may be used as a restock source.');
}
if (!str_contains($inventoryPageSource, 'foreach ($activeItems as $item)')) {
    throw new RuntimeException('Archived medicines must not be offered as restock sources.');
}
if (preg_match('/CREATE TABLE inventory_items\s*\([^;]*\bitem_code\b/is', $schemaSource)) {
    throw new RuntimeException('The current inventory schema must not restore the removed item_code column.');
}

echo "Inventory restock batch test passed.\n";
