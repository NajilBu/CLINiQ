<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/InventoryWorkflow.php';
require_login();
ensure_inventory_workflow_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $archiveQuantity = max(0, (int) ($_POST['archive_quantity'] ?? 0));
    $reason = trim((string) ($_POST['archived_reason'] ?? ''));
    $user = current_user();
    $archivedBy = (int) ($user['id'] ?? 0) ?: null;
    $archiveReason = $reason !== '' ? $reason : 'Archived from inventory';

    $db = db();
    try {
        $db->beginTransaction();

        $itemStmt = $db->prepare('SELECT * FROM inventory_items WHERE id = ? AND archived_at IS NULL FOR UPDATE');
        $itemStmt->execute([$id]);
        $item = $itemStmt->fetch();

        if (!$item) {
            throw new RuntimeException('Inventory item was not found or is already archived.');
        }

        $currentQuantity = max(0, (int) $item['quantity']);
        if ($archiveQuantity < 1 || $archiveQuantity > $currentQuantity) {
            throw new RuntimeException('Choose a quantity between 1 and ' . $currentQuantity . '.');
        }

        if ($archiveQuantity >= $currentQuantity) {
            $stmt = $db->prepare('
                UPDATE inventory_items
                SET archived_at = NOW(), archived_reason = ?, archived_by = ?
                WHERE id = ? AND archived_at IS NULL
            ');
            $stmt->execute([$archiveReason, $archivedBy, $id]);
            $message = 'Inventory item archived.';
        } else {
            $update = $db->prepare('UPDATE inventory_items SET quantity = quantity - ? WHERE id = ? AND archived_at IS NULL');
            $update->execute([$archiveQuantity, $id]);

            $insert = $db->prepare('
                INSERT INTO inventory_items
                    (item_name, category, quantity, unit, reorder_level, expiration_date, archived_at, archived_reason, archived_by)
                VALUES
                    (?, ?, ?, ?, ?, ?, NOW(), ?, ?)
            ');
            $insert->execute([
                $item['item_name'],
                $item['category'],
                $archiveQuantity,
                $item['unit'],
                (int) $item['reorder_level'],
                $item['expiration_date'] ?: null,
                $archiveReason,
                $archivedBy,
            ]);

            $message = $archiveQuantity . ' ' . ($item['unit'] ?: 'unit') . ' archived from ' . $item['item_name'] . '.';
        }

        $db->commit();
        flash_message('success', $message);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        flash_message('error', $e->getMessage());
        header('Location: index.php');
        exit;
    }

    header('Location: index.php?tab=archived');
    exit;
}

header('Location: index.php');
