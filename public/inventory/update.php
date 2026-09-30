<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqInventoryWorkflow.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = cliniq_inventory_db();
    $id = (int) ($_POST['id'] ?? 0);
    $type = 'Medicine';
    try {
        $name = trim((string) ($_POST['item_name'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $quantity = max(0, (int) ($_POST['quantity'] ?? 0));
        $adjustmentReason = trim((string) ($_POST['adjustment_reason'] ?? ''));
        if ($id < 1 || $name === '' || $unit === '') {
            throw new InvalidArgumentException('Item name and unit are required.');
        }

        $staffId = cliniq_inventory_staff_person_id();
        $db->beginTransaction();
        $currentStmt = $db->prepare('SELECT * FROM inventory_items WHERE item_id = ? AND is_active = 1 FOR UPDATE');
        $currentStmt->execute([$id]);
        $current = $currentStmt->fetch();
        if (!$current) {
            throw new RuntimeException('Active inventory item was not found.');
        }
        $type = (string) $current['item_type'];
        $difference = $quantity - (int) $current['quantity'];
        if ($difference !== 0 && $adjustmentReason === '') {
            throw new InvalidArgumentException('Explain the quantity adjustment before saving.');
        }
        if ($difference !== 0 && $type === 'Equipment') {
            $loanStmt = $db->prepare('SELECT COUNT(*) FROM equipment_loans WHERE item_id = ? AND status IN ("Borrowed", "Overdue")');
            $loanStmt->execute([$id]);
            if ((int) $loanStmt->fetchColumn() > 0) {
                throw new RuntimeException('Available quantity cannot be adjusted while this equipment has an open loan. Process the return or loss first.');
            }
        }
        $expiration = trim((string) ($_POST['expiration_date'] ?? ''));
        if ($type === 'Medicine' && $expiration !== '') {
            $parsedExpiration = DateTimeImmutable::createFromFormat('!Y-m-d', $expiration);
            if (!$parsedExpiration || $parsedExpiration->format('Y-m-d') !== $expiration) {
                throw new InvalidArgumentException('Enter a valid expiration date.');
            }
        } else {
            $expiration = null;
        }

        $stmt = $db->prepare('
            UPDATE inventory_items
            SET item_name = ?, description = ?,
                quantity = ?, unit = ?, reorder_level = ?, expiration_date = ?
            WHERE item_id = ?
        ');
        $stmt->execute([
            $name,
            trim((string) ($_POST['description'] ?? '')) ?: null,
            $quantity,
            $unit,
            max(0, (int) ($_POST['reorder_level'] ?? 0)),
            $expiration,
            $id,
        ]);
        if ($difference !== 0) {
            cliniq_inventory_record_transaction(
                $db, $id, 'Adjustment', $difference, $quantity, $staffId, null, null,
                $adjustmentReason
            );
        }
        audit_log_event('inventory', 'inventory_item_updated', $staffId, 'staff', 'inventory_item', $id, [
            'quantity_change' => $difference,
            'adjustment_reason' => $difference !== 0 ? $adjustmentReason : null,
        ]);
        $db->commit();
        flash_message('success', 'Inventory item updated.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
    }
    header('Location: index.php?tab=' . ($type === 'Equipment' ? 'equipment' : 'medicine'));
    exit;
}

header('Location: index.php');
