<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqInventoryWorkflow.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?tab=medicine');
    exit;
}

$asRows = static function (string $field): array {
    $value = $_POST[$field] ?? [];
    return is_array($value) ? array_values($value) : [$value];
};
$itemIds = $asRows('source_id');
$quantities = $asRows('quantity');
$expirationDates = $asRows('expiration_date');
$db = cliniq_inventory_db();

try {
    if (count($itemIds) < 1 || count($itemIds) > 100) {
        throw new InvalidArgumentException('Add between 1 and 100 restock rows.');
    }
    $staffId = cliniq_inventory_staff_person_id();
    $redirectTab = 'medicine';
    $db->beginTransaction();
    $stmt = $db->prepare("
        SELECT item_id, item_name, item_type, description, unit, reorder_level
        FROM inventory_items
        WHERE item_id = ? AND item_type IN ('Medicine', 'Equipment') AND is_active = 1
        FOR UPDATE
    ");
    $insert = $db->prepare('
        INSERT INTO inventory_items (
            item_name, item_type, description, unit,
            quantity, reorder_level, expiration_date, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)
    ');
    $created = 0;
    for ($index = 0; $index < count($itemIds); $index++) {
        $itemId = (int) ($itemIds[$index] ?? 0);
        $quantity = max(0, (int) ($quantities[$index] ?? 0));
        $expirationDate = trim((string) ($expirationDates[$index] ?? ''));
        if ($itemId < 1 || $quantity < 1) {
            throw new InvalidArgumentException('Choose an item and enter a restock quantity for every row.');
        }
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item) {
            throw new RuntimeException('An active inventory item was not found.');
        }
        $isMedicine = $item['item_type'] === 'Medicine';
        if (!$isMedicine) $redirectTab = 'equipment';
        $parsedExpiration = $isMedicine ? DateTimeImmutable::createFromFormat('!Y-m-d', $expirationDate) : null;
        if ($isMedicine && (!$parsedExpiration || $parsedExpiration->format('Y-m-d') !== $expirationDate)) {
            throw new InvalidArgumentException('Enter a valid expiration date for every medicine restock row.');
        }
        $insert->execute([$item['item_name'], $item['item_type'], $item['description'], $item['unit'], $quantity, (int) $item['reorder_level'], $isMedicine ? $expirationDate : null]);
        $batchItemId = (int) $db->lastInsertId();
        cliniq_inventory_record_transaction($db, $batchItemId, 'Stock In', $quantity, $quantity, $staffId, null, null, $isMedicine ? 'Separate medicine batch received; expires ' . $expirationDate : 'Equipment stock restocked');
        $created++;
    }
    $db->commit();
    flash_message('success', $created . ' restock batch' . ($created === 1 ? '' : 'es') . ' created.');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
}

header('Location: index.php?tab=' . $redirectTab);
exit;
