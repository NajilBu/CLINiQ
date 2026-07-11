<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/InventoryWorkflow.php';
require_login();
ensure_inventory_workflow_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?tab=medicine');
    exit;
}

$sourceId = (int) ($_POST['source_id'] ?? 0);
$quantity = max(0, (int) ($_POST['quantity'] ?? 0));
$expirationDate = trim((string) ($_POST['expiration_date'] ?? ''));
$expirationTimestamp = $expirationDate !== '' ? strtotime($expirationDate) : false;

if ($sourceId <= 0 || $quantity < 1 || $expirationTimestamp === false) {
    flash_message('error', 'Choose a medicine, quantity, and expiration date before restocking.');
    header('Location: index.php?tab=medicine');
    exit;
}

$db = db();

try {
    $db->beginTransaction();

    $sourceStmt = $db->prepare('
        SELECT *
        FROM inventory_items
        WHERE id = ?
          AND LOWER(COALESCE(category, "")) NOT LIKE "%equipment%"
        LIMIT 1
    ');
    $sourceStmt->execute([$sourceId]);
    $source = $sourceStmt->fetch();

    if (!$source) {
        throw new RuntimeException('Medicine record was not found.');
    }

    $expirationValue = date('Y-m-d', $expirationTimestamp);
    $batchStmt = $db->prepare('
        SELECT id
        FROM inventory_items
        WHERE archived_at IS NULL
          AND LOWER(COALESCE(category, "")) NOT LIKE "%equipment%"
          AND item_name = ?
          AND category <=> ?
          AND unit <=> ?
          AND reorder_level = ?
          AND expiration_date <=> ?
        LIMIT 1
    ');
    $batchStmt->execute([
        $source['item_name'],
        $source['category'],
        $source['unit'],
        (int) $source['reorder_level'],
        $expirationValue,
    ]);
    $batch = $batchStmt->fetch();

    if ($batch) {
        $db->prepare('UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?')
            ->execute([$quantity, (int) $batch['id']]);
    } else {
        $db->prepare('
            INSERT INTO inventory_items
                (item_name, category, quantity, unit, reorder_level, expiration_date)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ')->execute([
            $source['item_name'],
            $source['category'],
            $quantity,
            $source['unit'],
            (int) $source['reorder_level'],
            $expirationValue,
        ]);
    }

    $db->commit();
    flash_message('success', $quantity . ' ' . ($source['unit'] ?: 'unit') . ' of "' . $source['item_name'] . '" added as a medicine batch.');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    flash_message('error', $e->getMessage());
}

header('Location: index.php?tab=medicine');
exit;
