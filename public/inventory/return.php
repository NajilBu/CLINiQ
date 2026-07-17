<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/InventoryWorkflow.php';
require_login();
ensure_inventory_workflow_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?tab=equipment');
    exit;
}

$loanId = (int) ($_POST['loan_id'] ?? 0);
$condition = trim((string) ($_POST['return_condition'] ?? 'Good'));
$notes = trim((string) ($_POST['return_notes'] ?? ''));
$validConditions = dropdown_options('inventory_return_condition');
$user = current_user();

if (!in_array($condition, $validConditions, true)) {
    $condition = $validConditions[0] ?? 'Good';
}

$db = db();

try {
    $db->beginTransaction();

    $stmt = $db->prepare('
        SELECT
            l.*,
            i.item_name,
            i.category,
            i.unit,
            i.reorder_level,
            i.expiration_date,
            i.archived_at
        FROM inventory_loans l
        INNER JOIN inventory_items i ON i.id = l.item_id
        WHERE l.id = ? AND l.status = "Borrowed"
        FOR UPDATE
    ');
    $stmt->execute([$loanId]);
    $loan = $stmt->fetch();

    if (!$loan) {
        throw new RuntimeException('Active loan was not found.');
    }

    $status = $condition === 'Lost' ? 'Lost' : 'Returned';

    $db->prepare('
        UPDATE inventory_loans
        SET status = ?, return_condition = ?, return_notes = ?, returned_at = NOW(), returned_by = ?
        WHERE id = ?
    ')->execute([
        $status,
        $condition,
        $notes !== '' ? $notes : null,
        (int) ($user['id'] ?? 0) ?: null,
        $loanId,
    ]);

    if ($condition === 'Good') {
        $returnedQuantity = (int) $loan['borrowed_quantity'];

        if (empty($loan['archived_at'])) {
            $db->prepare('UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?')
                ->execute([$returnedQuantity, (int) $loan['item_id']]);
        } else {
            $activeItem = $db->prepare('
                SELECT id
                FROM inventory_items
                WHERE archived_at IS NULL
                  AND item_name = ?
                  AND category <=> ?
                  AND unit <=> ?
                  AND reorder_level = ?
                  AND expiration_date <=> ?
                LIMIT 1
            ');
            $activeItem->execute([
                $loan['item_name'],
                $loan['category'],
                $loan['unit'],
                (int) $loan['reorder_level'],
                $loan['expiration_date'] ?: null,
            ]);
            $activeMatch = $activeItem->fetch();

            if ($activeMatch) {
                $db->prepare('UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?')
                    ->execute([$returnedQuantity, (int) $activeMatch['id']]);
            } else {
                $db->prepare('
                    INSERT INTO inventory_items
                        (item_name, category, quantity, unit, reorder_level, expiration_date)
                    VALUES
                        (?, ?, ?, ?, ?, ?)
                ')->execute([
                    $loan['item_name'],
                    $loan['category'],
                    $returnedQuantity,
                    $loan['unit'],
                    (int) $loan['reorder_level'],
                    $loan['expiration_date'] ?: null,
                ]);
            }
        }
    }

    $db->commit();
    flash_message(
        'success',
        $condition === 'Good'
            ? '"' . $loan['item_name'] . '" returned to available equipment.'
            : '"' . $loan['item_name'] . '" return recorded as ' . strtolower($condition) . '.'
    );
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    flash_message('error', $e->getMessage());
}

header('Location: index.php?tab=equipment');
exit;
