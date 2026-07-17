<?php

require_once __DIR__ . '/SystemSettings.php';

function ensure_visit_workflow_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $db = db();
    $stmt = $db->query("SHOW COLUMNS FROM clinic_visits");
    $columns = [];
    foreach ($stmt->fetchAll() as $column) {
        $columns[$column['Field']] = $column;
    }

    $addColumn = function (string $name, string $definition) use ($db, &$columns): void {
        if (!isset($columns[$name])) {
            $db->exec("ALTER TABLE clinic_visits ADD COLUMN {$name} {$definition}");
            $columns[$name] = ['Field' => $name];
        }
    };

    $addColumn('status', "ENUM('Unaddressed','Active','Completed','Cancelled') NOT NULL DEFAULT 'Unaddressed' AFTER pulse_rate");
    $addColumn('visit_purpose', "VARCHAR(80) NULL AFTER status");
    $addColumn('visit_source', "ENUM('Self Logbook','Staff Recorded','Nurse Emergency') NOT NULL DEFAULT 'Staff Recorded' AFTER visit_purpose");
    $addColumn('attended_by', "INT NULL AFTER recorded_by");
    $addColumn('updated_at', "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");

    $db->exec("
        CREATE TABLE IF NOT EXISTS visit_treatment_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            visit_id INT NOT NULL,
            symptoms_note TEXT NULL,
            diagnosis TEXT NULL,
            management_treatment TEXT NULL,
            referral_type VARCHAR(120) NULL,
            remarks TEXT NULL,
            amendment_reason TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (visit_id) REFERENCES clinic_visits(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        )
    ");
    $entryStmt = $db->query("SHOW COLUMNS FROM visit_treatment_entries");
    $entryColumns = [];
    foreach ($entryStmt->fetchAll() as $column) {
        $entryColumns[$column['Field']] = $column;
    }
    if (!isset($entryColumns['amendment_reason'])) {
        $db->exec("ALTER TABLE visit_treatment_entries ADD COLUMN amendment_reason TEXT NULL AFTER remarks");
    }
    if (!isset($entryColumns['dispensed_inventory_item_id'])) {
        $db->exec("ALTER TABLE visit_treatment_entries ADD COLUMN dispensed_inventory_item_id INT NULL AFTER amendment_reason");
    }
    if (!isset($entryColumns['dispensed_quantity'])) {
        $db->exec("ALTER TABLE visit_treatment_entries ADD COLUMN dispensed_quantity INT NULL AFTER dispensed_inventory_item_id");
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS visit_treatment_dispensings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            treatment_entry_id INT NOT NULL,
            visit_id INT NOT NULL,
            inventory_item_id INT NOT NULL,
            item_type VARCHAR(40) NOT NULL DEFAULT 'Medicine',
            quantity INT NOT NULL,
            inventory_loan_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (treatment_entry_id) REFERENCES visit_treatment_entries(id) ON DELETE CASCADE,
            FOREIGN KEY (visit_id) REFERENCES clinic_visits(id) ON DELETE CASCADE
        )
    ");

    backfill_legacy_visit_statuses($db);
    $db->exec("
        UPDATE clinic_visits
        SET attended_by = recorded_by
        WHERE attended_by IS NULL
          AND recorded_by IS NOT NULL
          AND status IN ('Active', 'Completed')
    ");

    $ready = true;
}

function backfill_legacy_visit_statuses(PDO $db): void
{
    $totals = $db->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status <> 'Unaddressed' THEN 1 ELSE 0 END) AS with_status
        FROM clinic_visits
    ")->fetch();

    if ((int) ($totals['total'] ?? 0) === 0 || (int) ($totals['with_status'] ?? 0) > 0) {
        return;
    }

    $db->exec("
        UPDATE clinic_visits
        SET status = CASE
            WHEN LOWER(COALESCE(action_taken, '')) LIKE '%cancel%' THEN 'Cancelled'
            WHEN visit_source = 'Nurse Emergency' THEN 'Active'
            WHEN COALESCE(action_taken, '') <> '' AND LOWER(action_taken) NOT LIKE '%awaiting%' THEN 'Completed'
            ELSE 'Unaddressed'
        END
    ");
}

function visit_statuses(): array
{
    return dropdown_options('visit_status');
}

function visit_purposes(): array
{
    return dropdown_options('visit_purpose');
}

function visit_sources(): array
{
    return dropdown_options('visit_source');
}

function visit_referral_options(): array
{
    return dropdown_options('referral_type');
}

function visit_status_badge_class(string $status): string
{
    return match ($status) {
        'Active' => 'badge-active',
        'Completed' => 'badge-completed',
        'Cancelled' => 'badge-cancelled',
        default => 'badge-pending',
    };
}

function normalize_visit_status(?string $status, string $fallback = 'Active'): string
{
    $status = trim((string) $status);
    return in_array($status, visit_statuses(), true) ? $status : $fallback;
}

function normalize_visit_purpose(?string $purpose): ?string
{
    $purpose = trim((string) $purpose);
    return $purpose === '' ? null : mb_substr($purpose, 0, 80);
}

function normalize_visit_source(?string $source, string $fallback = 'Staff Recorded'): string
{
    $source = trim((string) $source);
    return in_array($source, visit_sources(), true) ? $source : $fallback;
}

function treatment_entry_has_content(array $data): bool
{
    foreach (['symptoms_note', 'diagnosis', 'management_treatment', 'referral_type', 'remarks'] as $key) {
        if (trim((string) ($data[$key] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

function treatment_entry_dispensing_map(array $entryIds): array
{
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds))));
    if (!$entryIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
    $stmt = db()->prepare("
        SELECT d.*, i.item_name, i.unit
        FROM visit_treatment_dispensings d
        JOIN inventory_items i ON i.id = d.inventory_item_id
        WHERE d.treatment_entry_id IN ({$placeholders})
        ORDER BY d.id
    ");
    $stmt->execute($entryIds);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['treatment_entry_id']][] = $row;
    }

    $legacyStmt = db()->prepare("
        SELECT t.id AS treatment_entry_id, t.dispensed_inventory_item_id AS inventory_item_id,
               t.dispensed_quantity AS quantity, i.item_name, i.unit,
               CASE WHEN LOWER(COALESCE(i.category, '')) LIKE '%equipment%' THEN 'Equipment' ELSE 'Medicine' END AS item_type
        FROM visit_treatment_entries t
        JOIN inventory_items i ON i.id = t.dispensed_inventory_item_id
        WHERE t.id IN ({$placeholders})
          AND t.dispensed_inventory_item_id IS NOT NULL
          AND t.dispensed_quantity IS NOT NULL
    ");
    $legacyStmt->execute($entryIds);
    foreach ($legacyStmt->fetchAll() as $row) {
        $entryId = (int) $row['treatment_entry_id'];
        if (!empty($map[$entryId])) {
            continue;
        }
        $map[$entryId][] = [
            'treatment_entry_id' => $entryId,
            'inventory_item_id' => (int) $row['inventory_item_id'],
            'item_type' => (string) $row['item_type'],
            'quantity' => (int) $row['quantity'],
            'item_name' => (string) $row['item_name'],
            'unit' => (string) $row['unit'],
            'inventory_loan_id' => null,
        ];
    }

    return $map;
}

function visit_medicine_inventory_options(): array
{
    return db()->query("
        SELECT id, item_name, category, quantity, unit, expiration_date
        FROM inventory_items
        WHERE archived_at IS NULL
          AND LOWER(COALESCE(category, '')) NOT LIKE '%equipment%'
        ORDER BY item_name
    ")->fetchAll();
}

function visit_equipment_inventory_options(): array
{
    return db()->query("
        SELECT id, item_name, category, quantity, unit
        FROM inventory_items
        WHERE archived_at IS NULL
          AND LOWER(COALESCE(category, '')) LIKE '%equipment%'
        ORDER BY item_name
    ")->fetchAll();
}

function visit_patient_borrower(PDO $db, int $patientId): array
{
    $stmt = $db->prepare('
        SELECT student_number, first_name, last_name
        FROM patients
        WHERE id = ?
        LIMIT 1
    ');
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch() ?: [];

    return [
        'name' => trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? '')) ?: 'Clinic patient',
        'identifier' => trim((string) ($patient['student_number'] ?? '')),
    ];
}

function visit_dispensing_request(array $post): array
{
    $types = $post['dispensing_type'] ?? [];
    $itemIds = $post['dispensed_inventory_item_id'] ?? [];
    $quantities = $post['dispensed_quantity'] ?? [];

    if (!is_array($types)) {
        $types = [$types];
    }
    if (!is_array($itemIds)) {
        $itemIds = [$itemIds];
    }
    if (!is_array($quantities)) {
        $quantities = [$quantities];
    }

    $requests = [];
    $rowCount = max(count($types), count($itemIds), count($quantities));
    for ($i = 0; $i < $rowCount; $i++) {
        $type = trim((string) ($types[$i] ?? 'Medicine'));
        $type = $type === 'Equipment' ? 'Equipment' : 'Medicine';
        $itemId = (int) ($itemIds[$i] ?? 0);
        $quantityText = trim((string) ($quantities[$i] ?? ''));

        if ($itemId <= 0 && $quantityText === '') {
            continue;
        }
        if ($itemId <= 0) {
            throw new InvalidArgumentException('Select the inventory item for every dispensing row.');
        }

        $quantity = (int) $quantityText;
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Enter a valid quantity for every dispensed item.');
        }

        $requests[] = [
            'type' => $type,
            'item_id' => $itemId,
            'quantity' => $quantity,
        ];
    }

    return $requests;
}

function dispense_visit_medicine(PDO $db, array $dispensing): array
{
    if (!$dispensing) {
        return [];
    }

    $stmt = $db->prepare("
        SELECT id, item_name, quantity, unit
        FROM inventory_items
        WHERE id = ?
          AND archived_at IS NULL
          AND LOWER(COALESCE(category, '')) NOT LIKE '%equipment%'
        FOR UPDATE
    ");
    $stmt->execute([(int) $dispensing['item_id']]);
    $item = $stmt->fetch();

    if (!$item) {
        throw new RuntimeException('Selected medicine is no longer available in inventory.');
    }

    $quantity = (int) $dispensing['quantity'];
    if ((int) $item['quantity'] < $quantity) {
        throw new RuntimeException('Not enough stock for ' . $item['item_name'] . '. Available: ' . (int) $item['quantity'] . ' ' . $item['unit'] . '.');
    }

    $db->prepare('UPDATE inventory_items SET quantity = quantity - ? WHERE id = ?')
        ->execute([$quantity, (int) $item['id']]);

    return [
        'type' => 'Medicine',
        'item_id' => (int) $item['id'],
        'item_name' => (string) $item['item_name'],
        'quantity' => $quantity,
        'unit' => (string) $item['unit'],
    ];
}

function loan_visit_equipment(PDO $db, array $request, string $borrowerName, string $borrowerIdentifier = ''): array
{
    if (!$request) {
        return [];
    }

    $stmt = $db->prepare("
        SELECT id, item_name, quantity, unit
        FROM inventory_items
        WHERE id = ?
          AND archived_at IS NULL
          AND LOWER(COALESCE(category, '')) LIKE '%equipment%'
        FOR UPDATE
    ");
    $stmt->execute([(int) $request['item_id']]);
    $item = $stmt->fetch();

    if (!$item) {
        throw new RuntimeException('Selected equipment is no longer available in inventory.');
    }

    $quantity = (int) $request['quantity'];
    if ((int) $item['quantity'] < $quantity) {
        throw new RuntimeException('Not enough available equipment for ' . $item['item_name'] . '. Available: ' . (int) $item['quantity'] . ' ' . $item['unit'] . '.');
    }

    $db->prepare('UPDATE inventory_items SET quantity = quantity - ? WHERE id = ?')
        ->execute([$quantity, (int) $item['id']]);
    $db->prepare('
        INSERT INTO inventory_loans (
            item_id, borrower_name, borrower_identifier, borrowed_quantity, borrowed_by
        ) VALUES (?, ?, ?, ?, ?)
    ')->execute([
        (int) $item['id'],
        $borrowerName !== '' ? $borrowerName : 'Clinic patient',
        $borrowerIdentifier !== '' ? $borrowerIdentifier : null,
        $quantity,
        (int) (current_user()['id'] ?? 0) ?: null,
    ]);
    $loanId = (int) $db->lastInsertId();

    return [
        'type' => 'Equipment',
        'item_id' => (int) $item['id'],
        'loan_id' => $loanId,
        'item_name' => (string) $item['item_name'],
        'quantity' => $quantity,
        'unit' => (string) $item['unit'],
    ];
}

function process_visit_inventory_request(PDO $db, array $request, string $borrowerName = '', string $borrowerIdentifier = ''): array
{
    if (!$request) {
        return [];
    }

    $dispensed = [];
    foreach ($request as $row) {
        if (($row['type'] ?? 'Medicine') === 'Equipment') {
            $dispensed[] = loan_visit_equipment($db, $row, $borrowerName, $borrowerIdentifier);
        } else {
            $dispensed[] = dispense_visit_medicine($db, $row);
        }
    }

    return $dispensed;
}

function save_visit_treatment_dispensings(PDO $db, int $treatmentEntryId, int $visitId, array $dispensed): void
{
    if (!$dispensed) {
        return;
    }

    $stmt = $db->prepare('
        INSERT INTO visit_treatment_dispensings (
            treatment_entry_id, visit_id, inventory_item_id, item_type, quantity, inventory_loan_id
        ) VALUES (?, ?, ?, ?, ?, ?)
    ');

    foreach ($dispensed as $item) {
        $stmt->execute([
            $treatmentEntryId,
            $visitId,
            (int) $item['item_id'],
            (string) ($item['type'] ?? 'Medicine'),
            (int) $item['quantity'],
            isset($item['loan_id']) ? (int) $item['loan_id'] : null,
        ]);
    }
}
