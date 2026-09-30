<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqInventoryWorkflow.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $reason = trim((string) ($_POST['archive_reason'] ?? ''));
    $db = cliniq_inventory_db();
    try {
        if ($id < 1 || $reason === '') {
            throw new InvalidArgumentException('Enter a reason before deactivating an inventory item.');
        }
        $staffId = cliniq_inventory_staff_person_id();
        $db->beginTransaction();
        $itemStmt = $db->prepare('SELECT item_id, item_name, item_type FROM inventory_items WHERE item_id = ? AND is_active = 1 FOR UPDATE');
        $itemStmt->execute([$id]);
        $item = $itemStmt->fetch();
        if (!$item) {
            throw new RuntimeException('Active inventory item was not found.');
        }
        if ($item['item_type'] === 'Equipment') {
            $loanStmt = $db->prepare('SELECT COUNT(*) FROM equipment_loans WHERE item_id = ? AND status IN ("Borrowed", "Overdue")');
            $loanStmt->execute([$id]);
            if ((int) $loanStmt->fetchColumn() > 0) {
                throw new RuntimeException('Return or record the lost equipment before deactivating it.');
            }
        }
        $db->prepare('UPDATE inventory_items SET is_active = 0 WHERE item_id = ?')->execute([$id]);
        audit_log_event('inventory', 'inventory_item_archived', $staffId, 'staff', 'inventory_item', $id, ['reason' => $reason]);
        $db->commit();
        flash_message('success', 'Inventory item deactivated.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
    }
    header('Location: index.php?tab=archived');
    exit;
}

header('Location: index.php');
