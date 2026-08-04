<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqInventoryWorkflow.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = cliniq_inventory_db();
    $id = (int) ($_POST['id'] ?? 0);
    $type = cliniq_inventory_item_type((string) ($_POST['category'] ?? 'Medicine'));
    try {
        $code = cliniq_inventory_item_code((string) ($_POST['item_code'] ?? ''));
        $name = trim((string) ($_POST['item_name'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $quantity = max(0, (int) ($_POST['quantity'] ?? 0));
        if ($id < 1 || $name === '' || $unit === '') {
            throw new InvalidArgumentException('Item code, name, and unit are required.');
        }

        $staffId = cliniq_inventory_staff_person_id();
        $db->beginTransaction();
        $currentStmt = $db->prepare('SELECT * FROM inventory_items WHERE item_id = ? FOR UPDATE');
        $currentStmt->execute([$id]);
        $current = $currentStmt->fetch();
        if (!$current) {
            throw new RuntimeException('Inventory item was not found.');
        }

        $stmt = $db->prepare('
            UPDATE inventory_items
            SET item_code = ?, item_name = ?, item_type = ?, description = ?,
                quantity = ?, unit = ?, reorder_level = ?, expiration_date = ?
            WHERE item_id = ?
        ');
        $stmt->execute([
            $code,
            $name,
            $type,
            trim((string) ($_POST['description'] ?? '')) ?: null,
            $quantity,
            $unit,
            max(0, (int) ($_POST['reorder_level'] ?? 0)),
            $type === 'Medicine' && !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null,
            $id,
        ]);
        $difference = $quantity - (int) $current['quantity'];
        if ($difference !== 0) {
            cliniq_inventory_record_transaction(
                $db, $id, 'Adjustment', $difference, $quantity, $staffId, null, null,
                'Quantity updated from the inventory edit form'
            );
        }
        $db->commit();
        flash_message('success', 'Inventory item updated in Cliniq_db.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $message = str_contains(strtolower($e->getMessage()), 'duplicate')
            ? 'That item code already exists.'
            : $e->getMessage();
        flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $message);
    }
    header('Location: index.php?tab=' . ($type === 'Equipment' ? 'equipment' : 'medicine'));
    exit;
}

header('Location: index.php');
