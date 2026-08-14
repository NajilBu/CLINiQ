<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqInventoryWorkflow.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = cliniq_inventory_db();
    $type = cliniq_inventory_item_type((string) ($_POST['category'] ?? 'Medicine'));
    try {
        $code = cliniq_inventory_item_code((string) ($_POST['item_code'] ?? ''));
        $name = trim((string) ($_POST['item_name'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $quantity = max(0, (int) ($_POST['quantity'] ?? 0));
        $reorderLevel = max(0, (int) ($_POST['reorder_level'] ?? 0));
        if ($name === '' || $unit === '') {
            throw new InvalidArgumentException('Item name and unit are required.');
        }

        $staffId = cliniq_inventory_staff_person_id();
        $db->beginTransaction();
        $stmt = $db->prepare('
            INSERT INTO inventory_items (
                item_code, item_name, item_type, description, unit,
                quantity, reorder_level, expiration_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $code,
            $name,
            $type,
            trim((string) ($_POST['description'] ?? '')) ?: null,
            $unit,
            $quantity,
            $reorderLevel,
            $type === 'Medicine' && !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null,
        ]);
        $itemId = (int) $db->lastInsertId();
        if ($quantity > 0) {
            cliniq_inventory_record_transaction(
                $db, $itemId, 'Stock In', $quantity, $quantity, $staffId, null, null,
                'Initial inventory quantity'
            );
        }
        $db->commit();
        flash_message('success', '"' . $name . '" added to Cliniq_db inventory.');
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
