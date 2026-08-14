<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqInventoryWorkflow.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?tab=medicine');
    exit;
}

$itemId = (int) ($_POST['source_id'] ?? 0);
$quantity = max(0, (int) ($_POST['quantity'] ?? 0));
$expirationDate = trim((string) ($_POST['expiration_date'] ?? ''));
$db = cliniq_inventory_db();

try {
    if ($itemId < 1 || $quantity < 1) {
        throw new InvalidArgumentException('Choose a medicine and enter a restock quantity.');
    }
    $staffId = cliniq_inventory_staff_person_id();
    $db->beginTransaction();
    $stmt = $db->prepare("
        SELECT item_id, item_name, quantity, unit
        FROM inventory_items
        WHERE item_id = ? AND item_type = 'Medicine' AND is_active = 1
        FOR UPDATE
    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) {
        throw new RuntimeException('Active medicine record was not found.');
    }

    $newBalance = (int) $item['quantity'] + $quantity;
    $db->prepare('UPDATE inventory_items SET quantity = ?, expiration_date = COALESCE(?, expiration_date) WHERE item_id = ?')
        ->execute([$newBalance, $expirationDate !== '' ? $expirationDate : null, $itemId]);
    cliniq_inventory_record_transaction(
        $db, $itemId, 'Stock In', $quantity, $newBalance, $staffId, null, null,
        'Medicine restock'
    );
    $db->commit();
    flash_message('success', $quantity . ' ' . $item['unit'] . ' added to "' . $item['item_name'] . '".');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
}

header('Location: index.php?tab=medicine');
exit;
