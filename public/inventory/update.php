<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/InventoryWorkflow.php';
require_login();
ensure_inventory_workflow_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare(
        'UPDATE inventory_items SET item_name=?, category=?, quantity=?, unit=?, reorder_level=?, expiration_date=? WHERE id=? AND archived_at IS NULL'
    );
    $stmt->execute([
        trim($_POST['item_name'] ?? ''),
        trim($_POST['category'] ?? ''),
        (int)($_POST['quantity'] ?? 0),
        trim($_POST['unit'] ?? 'pcs'),
        (int)($_POST['reorder_level'] ?? 0),
        $_POST['expiration_date'] ?: null,
        $id,
    ]);

    flash_message('success', 'Inventory item updated.');
    header('Location: index.php');
    exit;
}

header('Location: index.php');
