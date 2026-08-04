<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqInventoryWorkflow.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $db = cliniq_inventory_db();
    $itemStmt = $db->prepare('SELECT item_type FROM inventory_items WHERE item_id = ? AND is_active = 0');
    $itemStmt->execute([$id]);
    $type = (string) $itemStmt->fetchColumn();
    if ($type !== '') {
        $db->prepare('UPDATE inventory_items SET is_active = 1 WHERE item_id = ?')->execute([$id]);
        flash_message('success', 'Inventory item restored to active inventory.');
        header('Location: index.php?tab=' . ($type === 'Equipment' ? 'equipment' : 'medicine'));
    } else {
        flash_message('warning', 'Inactive inventory item was not found.');
        header('Location: index.php?tab=archived');
    }
    exit;
}

header('Location: index.php?tab=archived');
