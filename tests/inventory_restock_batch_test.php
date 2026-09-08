<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/CliniqInventoryWorkflow.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "Inventory restock batch test skipped: PDO SQLite is unavailable.\n");
    exit(0);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE inventory_items (item_code VARCHAR(40) NOT NULL UNIQUE)');
$db->exec("INSERT INTO inventory_items (item_code) VALUES ('MED-PARACETAMOL')");

$firstCode = cliniq_inventory_batch_code($db, 'MED-PARACETAMOL', '2027-03-15');
if (!preg_match('/^MED-PARACETAMOL-B270315-[A-F0-9]{4}$/', $firstCode)) {
    throw new RuntimeException('The generated batch code does not identify its source and expiration date.');
}
if (strlen($firstCode) > 40) {
    throw new RuntimeException('The generated batch code exceeds the inventory schema limit.');
}

$db->prepare('INSERT INTO inventory_items (item_code) VALUES (?)')->execute([$firstCode]);
$secondCode = cliniq_inventory_batch_code($db, $firstCode, '2027-03-15');
if ($secondCode === $firstCode || !str_starts_with($secondCode, 'MED-PARACETAMOL-B270315-')) {
    throw new RuntimeException('Repeated restocks must receive distinct batch codes without compounded suffixes.');
}

$label = cliniq_inventory_medicine_option_label([
    'item_name' => 'Paracetamol 500mg',
    'item_code' => $firstCode,
    'expiration_date' => '2027-03-15',
    'quantity' => 25,
    'unit' => 'tablets',
]);
if (!str_contains($label, $firstCode) || !str_contains($label, 'Mar 15, 2027') || !str_contains($label, '25 tablets')) {
    throw new RuntimeException('The dispensing label must identify the batch, expiration, and available quantity.');
}

$restockSource = file_get_contents(dirname(__DIR__) . '/public/inventory/restock.php');
$inventoryPageSource = file_get_contents(dirname(__DIR__) . '/public/inventory/index.php');
if (str_contains($restockSource, 'UPDATE inventory_items SET quantity')) {
    throw new RuntimeException('Restocking must not merge quantity into an existing inventory row.');
}
if (!str_contains($restockSource, 'INSERT INTO inventory_items')) {
    throw new RuntimeException('Restocking must create a separate inventory batch row.');
}
if (!str_contains($inventoryPageSource, 'foreach ($activeItems as $item)')) {
    throw new RuntimeException('Archived medicines must not be offered as restock sources.');
}

echo "Inventory restock batch test passed.\n";
