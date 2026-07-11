<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/InventoryWorkflow.php';
require_login();
ensure_inventory_workflow_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $db = db();
    $isFetchRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';

    try {
        $db->beginTransaction();

        $itemStmt = $db->prepare('SELECT * FROM inventory_items WHERE id = ? AND archived_at IS NOT NULL FOR UPDATE');
        $itemStmt->execute([$id]);
        $item = $itemStmt->fetch();

        if (!$item) {
            throw new RuntimeException('Archived inventory item was not found.');
        }

        $isEquipment = str_contains(strtolower((string) ($item['category'] ?? '')), 'equipment');
        $returnTab = $isEquipment ? 'equipment' : 'medicine';

        $activeMatch = $db->prepare('
            SELECT id
            FROM inventory_items
            WHERE archived_at IS NULL
              AND id <> ?
              AND item_name = ?
              AND category <=> ?
              AND unit <=> ?
              AND reorder_level = ?
              AND expiration_date <=> ?
            LIMIT 1
        ');
        $activeMatch->execute([
            $id,
            $item['item_name'],
            $item['category'],
            $item['unit'],
            (int) $item['reorder_level'],
            $item['expiration_date'] ?: null,
        ]);
        $activeItem = $activeMatch->fetch();

        if ($activeItem) {
            $merge = $db->prepare('UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?');
            $merge->execute([(int) $item['quantity'], (int) $activeItem['id']]);

            $delete = $db->prepare('DELETE FROM inventory_items WHERE id = ? AND archived_at IS NOT NULL');
            $delete->execute([$id]);
            if ($delete->rowCount() < 1) {
                throw new RuntimeException('Archived inventory item was restored, but the archived copy could not be cleared.');
            }
        } else {
            $stmt = $db->prepare('
                UPDATE inventory_items
                SET archived_at = NULL, archived_reason = NULL, archived_by = NULL
                WHERE id = ? AND archived_at IS NOT NULL
            ');
            $stmt->execute([$id]);
            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('Archived inventory item was not restored.');
            }
        }

        $db->commit();
        flash_message('success', 'Inventory item restored to active inventory.');
        header('Location: index.php?tab=' . ($isFetchRequest ? 'archived' : $returnTab));
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        flash_message('error', $e->getMessage());
        header('Location: index.php?tab=archived');
        exit;
    }

}

header('Location: index.php?tab=archived');
