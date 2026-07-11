<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/InventoryWorkflow.php';
require_login();
ensure_inventory_workflow_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?tab=equipment');
    exit;
}

$itemId = (int) ($_POST['item_id'] ?? 0);
$borrowerName = trim((string) ($_POST['borrower_name'] ?? ''));
$borrowerIdentifier = trim((string) ($_POST['borrower_identifier'] ?? ''));
$quantity = max(1, (int) ($_POST['borrowed_quantity'] ?? 1));
$dueAt = trim((string) ($_POST['due_at'] ?? ''));
$dueAtTimestamp = $dueAt !== '' ? strtotime($dueAt) : false;
$user = current_user();

if ($itemId <= 0 || $borrowerName === '') {
    flash_message('error', 'Borrower and equipment details are required.');
    header('Location: index.php?tab=equipment');
    exit;
}

$db = db();

try {
    $db->beginTransaction();

    $stmt = $db->prepare('
        SELECT id, item_name, quantity
        FROM inventory_items
        WHERE id = ? AND archived_at IS NULL AND LOWER(COALESCE(category, "")) LIKE "%equipment%"
        FOR UPDATE
    ');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();

    if (!$item) {
        throw new RuntimeException('Equipment item was not found.');
    }

    if ((int) $item['quantity'] < $quantity) {
        throw new RuntimeException('Not enough available equipment to lend.');
    }

    $db->prepare('UPDATE inventory_items SET quantity = quantity - ? WHERE id = ?')->execute([$quantity, $itemId]);
    $db->prepare('
        INSERT INTO inventory_loans (
            item_id, borrower_name, borrower_identifier, borrowed_quantity, due_at, borrowed_by
        ) VALUES (?, ?, ?, ?, ?, ?)
    ')->execute([
        $itemId,
        $borrowerName,
        $borrowerIdentifier !== '' ? $borrowerIdentifier : null,
        $quantity,
        $dueAtTimestamp ? date('Y-m-d H:i:s', $dueAtTimestamp) : null,
        (int) ($user['id'] ?? 0) ?: null,
    ]);

    $db->commit();
    flash_message('success', '"' . $item['item_name'] . '" borrowed by ' . $borrowerName . '.');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    flash_message('error', $e->getMessage());
}

header('Location: index.php?tab=equipment');
exit;
