<?php

require_once __DIR__ . '/../config/database.php';

function cliniq_inventory_db(): PDO
{
    return auth_db();
}

function cliniq_inventory_staff_person_id(?array $user = null): int
{
    $user ??= current_user();
    $personId = (int) ($user['person_id'] ?? 0);
    if ($personId < 1) {
        throw new RuntimeException('The logged-in staff account is not linked to Cliniq_db.');
    }

    $stmt = cliniq_inventory_db()->prepare('SELECT 1 FROM clinic_staff WHERE person_id = ? LIMIT 1');
    $stmt->execute([$personId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('The logged-in account is not a clinic staff profile.');
    }

    return $personId;
}

function cliniq_inventory_item_type(string $value): string
{
    return str_contains(strtolower(trim($value)), 'equipment') ? 'Equipment' : 'Medicine';
}

function cliniq_inventory_item_code(string $value): string
{
    $value = strtoupper(trim($value));
    if ($value === '' || !preg_match('/^[A-Z0-9][A-Z0-9-]{1,39}$/', $value)) {
        throw new InvalidArgumentException('Item code must use 2 to 40 letters, numbers, or hyphens.');
    }
    return $value;
}

function cliniq_inventory_batch_code(PDO $db, string $sourceCode, string $expirationDate): string
{
    $baseCode = strtoupper(trim($sourceCode));
    $baseCode = preg_replace('/-B\d{6}-[A-F0-9]{4}$/', '', $baseCode) ?: 'MED';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expirationDate);
    if (!$date || $date->format('Y-m-d') !== $expirationDate) {
        throw new InvalidArgumentException('Enter a valid expiration date for the received batch.');
    }

    $suffixPrefix = '-B' . $date->format('ymd') . '-';
    $baseCode = rtrim(substr($baseCode, 0, 40 - strlen($suffixPrefix) - 4), '-');
    $baseCode = $baseCode !== '' ? $baseCode : 'MED';
    $exists = $db->prepare('SELECT 1 FROM inventory_items WHERE item_code = ? LIMIT 1');

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = $baseCode . $suffixPrefix . strtoupper(bin2hex(random_bytes(2)));
        $exists->execute([$candidate]);
        if (!$exists->fetchColumn()) {
            return $candidate;
        }
    }

    throw new RuntimeException('A unique batch code could not be generated. Please try again.');
}

function cliniq_inventory_medicine_option_label(array $medicine): string
{
    $expiration = trim((string) ($medicine['expiration_date'] ?? ''));
    $expirationLabel = $expiration !== ''
        ? date('M d, Y', strtotime($expiration))
        : 'No expiry recorded';

    return trim((string) ($medicine['item_name'] ?? 'Medicine'))
        . ' — ' . trim((string) ($medicine['item_code'] ?? 'No batch code'))
        . ' — Expires ' . $expirationLabel
        . ' (' . (int) ($medicine['quantity'] ?? 0) . ' ' . trim((string) ($medicine['unit'] ?? 'unit')) . ')';
}

/** @return array<int,array<string,mixed>> */
function cliniq_inventory_items(?string $type = null, ?bool $active = null): array
{
    $where = [];
    $params = [];
    if ($type !== null) {
        $where[] = 'i.item_type = ?';
        $params[] = cliniq_inventory_item_type($type);
    }
    if ($active !== null) {
        $where[] = 'i.is_active = ?';
        $params[] = $active ? 1 : 0;
    }

    $sql = "
        SELECT i.*, i.item_id AS id, i.item_type AS category,
               CASE WHEN i.is_active = 0 THEN i.updated_at ELSE NULL END AS archived_at,
               NULL AS archived_reason,
               NULL AS archived_by,
               NULL AS archived_by_name
        FROM inventory_items i
        " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
        ORDER BY i.is_active DESC, i.updated_at DESC, i.item_name
    ";
    $stmt = cliniq_inventory_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function cliniq_inventory_available_medicines(): array
{
    $stmt = cliniq_inventory_db()->query("
        SELECT item_id AS id, item_id, item_code, item_name, item_type AS category,
               quantity, unit, reorder_level, expiration_date
        FROM inventory_items
        WHERE item_type = 'Medicine' AND is_active = 1
        ORDER BY item_name, expiration_date IS NULL, expiration_date, item_id
    ");
    return $stmt->fetchAll();
}

/** @return array<int,array{item_id:int,quantity:int,remarks:?string}> */
function cliniq_inventory_dispensing_rows(array $source): array
{
    $itemIds = $source['dispensed_inventory_item_id'] ?? [];
    $quantities = $source['dispensed_quantity'] ?? [];
    $remarks = $source['dispensing_remarks'] ?? [];
    if (!is_array($itemIds)) {
        $itemIds = [$itemIds];
    }
    if (!is_array($quantities)) {
        $quantities = [$quantities];
    }
    if (!is_array($remarks)) {
        $remarks = [$remarks];
    }

    $rows = [];
    foreach ($itemIds as $index => $rawItemId) {
        $itemId = (int) $rawItemId;
        $quantity = (int) ($quantities[$index] ?? 0);
        if ($itemId < 1 && $quantity < 1) {
            continue;
        }
        if ($itemId < 1 || $quantity < 1) {
            throw new InvalidArgumentException('Select a medicine and enter a quantity greater than zero.');
        }
        $rows[] = [
            'item_id' => $itemId,
            'quantity' => $quantity,
            'remarks' => trim((string) ($remarks[$index] ?? '')) ?: null,
        ];
    }
    return $rows;
}

function cliniq_inventory_record_transaction(
    PDO $db,
    int $itemId,
    string $type,
    int $quantityChange,
    int $balanceAfter,
    ?int $staffPersonId,
    ?int $dispensingId = null,
    ?int $loanId = null,
    ?string $notes = null
): int {
    $validTypes = ['Stock In', 'Dispensed', 'Loaned', 'Returned', 'Adjustment', 'Expired', 'Damaged'];
    if (!in_array($type, $validTypes, true) || $quantityChange === 0 || $balanceAfter < 0) {
        throw new InvalidArgumentException('Invalid inventory transaction.');
    }

    $stmt = $db->prepare('
        INSERT INTO inventory_transactions (
            item_id, transaction_type, quantity_change, balance_after,
            dispensing_id, loan_id, notes, performed_by_person_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $itemId,
        $type,
        $quantityChange,
        $balanceAfter,
        $dispensingId ?: null,
        $loanId ?: null,
        trim((string) $notes) ?: null,
        $staffPersonId ?: null,
    ]);
    return (int) $db->lastInsertId();
}

/** @param array<int,array{item_id:int,quantity:int,remarks:?string}> $rows */
function cliniq_inventory_dispense_medicines(
    PDO $db,
    int $entryId,
    array $rows,
    ?int $staffPersonId
): array {
    if (!$rows) {
        return [];
    }
    if ($entryId < 1) {
        throw new InvalidArgumentException('A treatment entry is required before dispensing medicine.');
    }

    $created = [];
    foreach ($rows as $row) {
        $itemId = (int) ($row['item_id'] ?? 0);
        $quantity = (int) ($row['quantity'] ?? 0);
        if ($itemId < 1 || $quantity < 1) {
            throw new InvalidArgumentException('Select a medicine and enter a valid quantity.');
        }

        $itemStmt = $db->prepare("
            SELECT item_id, item_code, item_name, quantity, unit
            FROM inventory_items
            WHERE item_id = ? AND item_type = 'Medicine' AND is_active = 1
            FOR UPDATE
        ");
        $itemStmt->execute([$itemId]);
        $item = $itemStmt->fetch();
        if (!$item) {
            throw new RuntimeException('The selected medicine is unavailable.');
        }
        if ((int) $item['quantity'] < $quantity) {
            throw new RuntimeException('Not enough stock for ' . $item['item_name'] . '. Available: ' . (int) $item['quantity'] . ' ' . $item['unit'] . '.');
        }

        $newBalance = (int) $item['quantity'] - $quantity;
        $db->prepare('UPDATE inventory_items SET quantity = ? WHERE item_id = ?')
            ->execute([$newBalance, $itemId]);
        $dispenseStmt = $db->prepare('
            INSERT INTO medicine_dispensings (
                entry_id, item_id, quantity, remarks, dispensed_by_person_id
            ) VALUES (?, ?, ?, ?, ?)
        ');
        $dispenseStmt->execute([
            $entryId,
            $itemId,
            $quantity,
            trim((string) ($row['remarks'] ?? '')) ?: null,
            $staffPersonId ?: null,
        ]);
        $dispensingId = (int) $db->lastInsertId();
        cliniq_inventory_record_transaction(
            $db,
            $itemId,
            'Dispensed',
            -$quantity,
            $newBalance,
            $staffPersonId,
            $dispensingId,
            null,
            'Dispensed during treatment entry #' . $entryId
        );
        $created[] = $dispensingId;
    }
    return $created;
}

/** @return array<int,array<int,array<string,mixed>>> */
function cliniq_inventory_entry_dispensings(array $entryIds): array
{
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds), fn(int $id): bool => $id > 0)));
    if (!$entryIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
    $stmt = cliniq_inventory_db()->prepare("
        SELECT d.dispensing_id AS id, d.dispensing_id, d.entry_id, d.item_id,
               d.quantity, d.remarks, d.dispensed_at,
               i.item_name, i.item_type, i.unit,
               TRIM(CONCAT_WS(' ', pe.first_name, pe.middle_name, pe.last_name)) AS dispensed_by_name
        FROM medicine_dispensings d
        JOIN inventory_items i ON i.item_id = d.item_id
        LEFT JOIN people pe ON pe.id = d.dispensed_by_person_id
        WHERE d.entry_id IN ({$placeholders})
        ORDER BY d.dispensed_at, d.dispensing_id
    ");
    $stmt->execute($entryIds);
    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[(int) $row['entry_id']][] = $row;
    }
    return $grouped;
}

/** @return array<int,array<string,mixed>> */
function cliniq_inventory_transactions(?int $limit = null): array
{
    $sql = "
        SELECT t.*, i.item_code, i.item_name, i.item_type, i.unit,
               TRIM(CONCAT_WS(' ', pe.first_name, pe.middle_name, pe.last_name)) AS performed_by_name
        FROM inventory_transactions t
        JOIN inventory_items i ON i.item_id = t.item_id
        LEFT JOIN people pe ON pe.id = t.performed_by_person_id
        ORDER BY t.created_at DESC, t.transaction_id DESC
    ";
    if ($limit !== null) {
        $limit = max(1, $limit);
        $sql .= " LIMIT {$limit}";
    }
    return cliniq_inventory_db()->query($sql)->fetchAll();
}

function cliniq_inventory_status_badge(array $item): string
{
    $quantity = (int) ($item['quantity'] ?? 0);
    $reorderLevel = (int) ($item['reorder_level'] ?? 0);
    $expirationDate = $item['expiration_date'] ?? null;
    $isExpiring = $expirationDate && strtotime((string) $expirationDate) <= strtotime('+30 days');
    $isEquipment = str_contains(strtolower((string) ($item['category'] ?? $item['item_type'] ?? '')), 'equipment');

    if ($quantity === 0) {
        return '<span class="badge badge-critical">Out of Stock</span>';
    }
    if ($quantity <= $reorderLevel) {
        return '<span class="badge badge-pending">' . ($isEquipment ? 'Below Minimum' : 'Low Stock') . '</span>';
    }
    if ($isExpiring) {
        return '<span class="badge badge-high">Expiring Soon</span>';
    }

    return '<span class="badge badge-completed">In Stock</span>';
}

if (!function_exists('inventory_status_badge')) {
    function inventory_status_badge(array $item): string
    {
        return cliniq_inventory_status_badge($item);
    }
}

if (!function_exists('inventory_loan_status_badge')) {
    function inventory_loan_status_badge(string $status): string
    {
        return match ($status) {
            'Returned' => '<span class="badge badge-completed">Returned</span>',
            'Lost' => '<span class="badge badge-critical">Lost</span>',
            default => '<span class="badge badge-in-progress">Borrowed</span>',
        };
    }
}

if (!function_exists('inventory_return_condition_badge')) {
    function inventory_return_condition_badge(?string $condition): string
    {
        if (!$condition) {
            return '<span class="text-xs font-bold text-slate-300 uppercase">-</span>';
        }

        return match ($condition) {
            'Good' => '<span class="badge badge-completed">Good</span>',
            'Lost' => '<span class="badge badge-critical">Lost</span>',
            default => '<span class="badge badge-pending">Defective</span>',
        };
    }
}
