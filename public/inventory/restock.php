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
    $parsedExpiration = DateTimeImmutable::createFromFormat('!Y-m-d', $expirationDate);
    if (!$parsedExpiration || $parsedExpiration->format('Y-m-d') !== $expirationDate) {
        throw new InvalidArgumentException('Enter a valid expiration date for the received batch.');
    }
    $staffId = cliniq_inventory_staff_person_id();
    $db->beginTransaction();
    $stmt = $db->prepare("
        SELECT item_id, item_code, item_name, item_type, description, unit, reorder_level
        FROM inventory_items
        WHERE item_id = ? AND item_type = 'Medicine' AND is_active = 1
        FOR UPDATE
    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) {
        throw new RuntimeException('Active medicine record was not found.');
    }

    $batchCode = cliniq_inventory_batch_code($db, (string) $item['item_code'], $expirationDate);
    $insert = $db->prepare('
        INSERT INTO inventory_items (
            item_code, item_name, item_type, description, unit,
            quantity, reorder_level, expiration_date, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
    ');
    $insert->execute([
        $batchCode,
        $item['item_name'],
        $item['item_type'],
        $item['description'],
        $item['unit'],
        $quantity,
        (int) $item['reorder_level'],
        $expirationDate,
    ]);
    $batchItemId = (int) $db->lastInsertId();
    cliniq_inventory_record_transaction(
        $db, $batchItemId, 'Stock In', $quantity, $quantity, $staffId, null, null,
        'Separate medicine batch received from ' . $item['item_code'] . '; expires ' . $expirationDate
    );
    $db->commit();
    flash_message(
        'success',
        $quantity . ' ' . $item['unit'] . ' added as batch ' . $batchCode
        . ' with expiration ' . $parsedExpiration->format('M d, Y') . '.'
    );
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
}

header('Location: index.php?tab=medicine');
exit;
