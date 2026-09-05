<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqInventoryWorkflow.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?tab=equipment');
    exit;
}

$itemId = (int) ($_POST['item_id'] ?? 0);
$borrowerIdentifier = strtoupper(trim((string) ($_POST['borrower_identifier'] ?? '')));
$quantity = max(1, (int) ($_POST['borrowed_quantity'] ?? 1));
$dueAt = trim((string) ($_POST['due_at'] ?? ''));
if (isset($_POST['return_date']) || isset($_POST['return_time'])) {
    $dueAt = trim((string) ($_POST['return_date'] ?? '')) . 'T' . trim((string) ($_POST['return_time'] ?? '')) . ' ' . trim((string) ($_POST['return_period'] ?? ''));
}
$db = cliniq_inventory_db();

try {
    $dueAtDate = cliniq_inventory_return_date($dueAt);
    if ($itemId < 1 || $borrowerIdentifier === '') {
        throw new InvalidArgumentException('Equipment and an existing patient ID are required.');
    }
    $staffId = cliniq_inventory_staff_person_id();
    $db->beginTransaction();

    $patientStmt = $db->prepare('
        SELECT p.person_id, TRIM(CONCAT_WS(" ", pe.first_name, pe.middle_name, pe.last_name)) AS name
        FROM patients p JOIN people pe ON pe.id = p.person_id
        WHERE pe.id_number = ? LIMIT 1
    ');
    $patientStmt->execute([$borrowerIdentifier]);
    $patient = $patientStmt->fetch();
    if (!$patient) {
        throw new RuntimeException('That ID is not in the Cliniq_db patient list.');
    }

    $itemStmt = $db->prepare("
        SELECT item_id, item_name, quantity, unit
        FROM inventory_items
        WHERE item_id = ? AND item_type = 'Equipment' AND is_active = 1
        FOR UPDATE
    ");
    $itemStmt->execute([$itemId]);
    $item = $itemStmt->fetch();
    if (!$item) {
        throw new RuntimeException('Active equipment item was not found.');
    }
    if ((int) $item['quantity'] < $quantity) {
        throw new RuntimeException('Not enough available equipment to lend.');
    }

    $newBalance = (int) $item['quantity'] - $quantity;
    $db->prepare('UPDATE inventory_items SET quantity = ? WHERE item_id = ?')->execute([$newBalance, $itemId]);
    $loanStmt = $db->prepare('
        INSERT INTO equipment_loans (
            item_id, borrower_person_id, quantity, due_at, released_by_person_id, remarks
        ) VALUES (?, ?, ?, ?, ?, ?)
    ');
    $loanStmt->execute([
        $itemId,
        (int) $patient['person_id'],
        $quantity,
        $dueAtDate->format('Y-m-d H:i:s'),
        $staffId,
        trim((string) ($_POST['remarks'] ?? '')) ?: null,
    ]);
    $loanId = (int) $db->lastInsertId();
    cliniq_inventory_record_transaction(
        $db, $itemId, 'Loaned', -$quantity, $newBalance, $staffId, null, $loanId,
        'Equipment loaned to ' . $patient['name'] . ' (' . $borrowerIdentifier . ')'
    );
    $db->commit();
    flash_message('success', '"' . $item['item_name'] . '" borrowed by ' . $patient['name'] . '.');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
}

header('Location: index.php?tab=equipment');
exit;
