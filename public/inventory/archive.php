<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqInventoryWorkflow.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = cliniq_inventory_db()->prepare('UPDATE inventory_items SET is_active = 0 WHERE item_id = ? AND is_active = 1');
    $stmt->execute([$id]);
    flash_message($stmt->rowCount() ? 'success' : 'warning', $stmt->rowCount() ? 'Inventory item deactivated.' : 'Active inventory item was not found.');
    header('Location: index.php?tab=archived');
    exit;
}

header('Location: index.php');
