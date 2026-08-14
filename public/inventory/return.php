<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqInventoryWorkflow.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?tab=equipment');
    exit;
}

$loanId = (int) ($_POST['loan_id'] ?? 0);
$condition = trim((string) ($_POST['return_condition'] ?? 'Good'));
$notes = trim((string) ($_POST['return_notes'] ?? ''));
$db = cliniq_inventory_db();

try {
    $staffId = cliniq_inventory_staff_person_id();
    $db->beginTransaction();
    $stmt = $db->prepare('
        SELECT l.*, i.item_name, i.quantity AS available_quantity, i.unit
        FROM equipment_loans l
        JOIN inventory_items i ON i.item_id = l.item_id
        WHERE l.loan_id = ? AND l.status IN ("Borrowed", "Overdue")
        FOR UPDATE
    ');
    $stmt->execute([$loanId]);
    $loan = $stmt->fetch();
    if (!$loan) {
        throw new RuntimeException('Active equipment loan was not found.');
    }

    $loanRemarks = trim('Condition: ' . $condition . ($notes !== '' ? "\n" . $notes : ''));
    $status = $condition === 'Lost' ? 'Cancelled' : 'Returned';
    $db->prepare('
        UPDATE equipment_loans
        SET status = ?, returned_at = NOW(), received_by_person_id = ?, remarks = ?
        WHERE loan_id = ?
    ')->execute([$status, $staffId, $loanRemarks, $loanId]);

    if ($condition !== 'Lost') {
        $returnedBalance = (int) $loan['available_quantity'] + (int) $loan['quantity'];
        $db->prepare('UPDATE inventory_items SET quantity = ? WHERE item_id = ?')
            ->execute([$returnedBalance, (int) $loan['item_id']]);
        cliniq_inventory_record_transaction(
            $db, (int) $loan['item_id'], 'Returned', (int) $loan['quantity'],
            $returnedBalance, $staffId, null, $loanId, $loanRemarks
        );

        if ($condition === 'Defective') {
            $finalBalance = $returnedBalance - (int) $loan['quantity'];
            $db->prepare('UPDATE inventory_items SET quantity = ? WHERE item_id = ?')
                ->execute([$finalBalance, (int) $loan['item_id']]);
            cliniq_inventory_record_transaction(
                $db, (int) $loan['item_id'], 'Damaged', -(int) $loan['quantity'],
                $finalBalance, $staffId, null, $loanId, $loanRemarks
            );
        }
    }

    $db->commit();
    flash_message('success', $condition === 'Good'
        ? '"' . $loan['item_name'] . '" returned to available equipment.'
        : '"' . $loan['item_name'] . '" return recorded as ' . strtolower($condition) . '.');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    flash_message('error', $e->getMessage());
}

header('Location: index.php?tab=equipment');
exit;
