<?php

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

    $addColumn('status', "ENUM('Unaddressed','Active','Completed','Cancelled') NOT NULL DEFAULT 'Unaddressed' AFTER risk_score");
    $addColumn('risk_reasons', "TEXT NULL AFTER risk_score");
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
            WHEN visit_source = 'Nurse Emergency' OR risk_level IN ('Critical', 'High') THEN 'Active'
            WHEN COALESCE(action_taken, '') <> '' AND LOWER(action_taken) NOT LIKE '%awaiting%' THEN 'Completed'
            ELSE 'Unaddressed'
        END
    ");
}

function visit_statuses(): array
{
    return ['Unaddressed', 'Active', 'Completed', 'Cancelled'];
}

function visit_purposes(): array
{
    return ['Medical Consult', 'Health Monitoring', 'Pain Management', 'Dental Consult', 'Wound Care', 'APE', 'Emergency', 'Other'];
}

function visit_sources(): array
{
    return ['Self Logbook', 'Staff Recorded', 'Nurse Emergency'];
}

function visit_referral_options(): array
{
    return ['None', 'Advised to Go Home', 'Barangay Health Center', 'Public Hospital', 'Private Hospital', 'Specialist Referral'];
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

function escalate_major_risk_visit(PDO $db, int $visitId, int $patientId, string $riskLevel, int $riskScore, string $riskReasons): void
{
    if (!in_array($riskLevel, ['High', 'Critical'], true)) {
        return;
    }
    if (function_exists('risk_settings') && empty(risk_settings()['auto_alert_major_risk'])) {
        return;
    }

    $marker = 'Risk escalation for clinic visit #' . $visitId;
    $existing = $db->prepare("
        SELECT id
        FROM nurse_alerts
        WHERE patient_id = ?
          AND status IN ('Pending', 'In Progress')
          AND details LIKE ?
        LIMIT 1
    ");
    $existing->execute([$patientId, '%' . $marker . '%']);
    if ($existing->fetchColumn()) {
        return;
    }

    $user = current_user();
    $details = trim($marker . "\n" .
        'Risk level: ' . $riskLevel . "\n" .
        'Risk score: ' . $riskScore . "\n" .
        'Basis: ' . $riskReasons . "\n" .
        'Escalation: ' . risk_escalation_guidance($riskLevel)
    );

    $stmt = $db->prepare("
        INSERT INTO nurse_alerts
            (patient_id, reporter_name, reporter_role, location, concern, details, status)
        VALUES (?, ?, ?, ?, ?, ?, 'Pending')
    ");
    $stmt->execute([
        $patientId,
        $user['name'] ?? 'CLINiQ risk classifier',
        'System risk escalation',
        'Clinic Logbook',
        $riskLevel . ' risk clinic visit requires attention',
        $details,
    ]);
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
    $type = trim((string) ($post['dispensing_type'] ?? 'Medicine'));
    $type = $type === 'Equipment' ? 'Equipment' : 'Medicine';
    $itemId = (int) ($post['dispensed_inventory_item_id'] ?? 0);
    $quantityText = trim((string) ($post['dispensed_quantity'] ?? ''));

    if ($itemId <= 0 && $quantityText === '') {
        return [];
    }

    if ($itemId <= 0) {
        throw new InvalidArgumentException('Select the inventory item before saving.');
    }

    $quantity = (int) $quantityText;
    if ($quantity <= 0) {
        throw new InvalidArgumentException('Enter a valid dispensed quantity.');
    }

    return [
        'type' => $type,
        'item_id' => $itemId,
        'quantity' => $quantity,
    ];
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

    return [
        'type' => 'Equipment',
        'item_id' => (int) $item['id'],
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

    if (($request['type'] ?? 'Medicine') === 'Equipment') {
        return loan_visit_equipment($db, $request, $borrowerName, $borrowerIdentifier);
    }

    return dispense_visit_medicine($db, $request);
}

function visit_dispensing_note(array $dispensed): string
{
    if (!$dispensed) {
        return '';
    }

    if (($dispensed['type'] ?? 'Medicine') === 'Equipment') {
        return 'Borrowed equipment: ' . $dispensed['item_name'] . ' - Qty: ' . (int) $dispensed['quantity'] . ' ' . $dispensed['unit'];
    }

    return 'Dispensed medicine: ' . $dispensed['item_name'] . ' - Qty: ' . (int) $dispensed['quantity'] . ' ' . $dispensed['unit'];
}
