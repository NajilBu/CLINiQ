<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/InventoryWorkflow.php';
require_login();
ensure_inventory_workflow_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $user = current_user();
    $stmt = db()->prepare('
        UPDATE inventory_items
        SET archived_at = NOW(), archived_reason = COALESCE(NULLIF(archived_reason, ""), "Archived from inventory"), archived_by = ?
        WHERE id = ? AND archived_at IS NULL
    ');
    $stmt->execute([(int)($user['id'] ?? 0) ?: null, $id]);

    flash_message('success', 'Inventory item archived.');
    header('Location: index.php');
    exit;
}

header('Location: index.php');
