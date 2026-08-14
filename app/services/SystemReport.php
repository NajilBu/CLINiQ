<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AlertWorkflow.php';

function system_report_module_labels(): array
{
    return [
        'patients' => 'Patients',
        'accounts' => 'Accounts',
        'visits' => 'Visits and Vital Signs',
        'appointments' => 'Appointments',
        'inventory' => 'Inventory and Medicine',
        'loans' => 'Equipment Loans',
        'ape' => 'Annual Physical Examination',
        'referrals' => 'Referrals',
        'alerts' => 'Alerts and Incidents',
    ];
}

function normalize_system_report_date(?string $value, string $fallback): string
{
    $value = trim((string) $value);
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
}

function normalize_system_report_modules(array $modules): array
{
    $allowed = array_keys(system_report_module_labels());
    $selected = array_values(array_intersect($allowed, array_map('strval', $modules)));
    return $selected ?: $allowed;
}

function normalize_system_report_remarks(array $remarks, array $modules): array
{
    $normalized = [];
    foreach ($modules as $module) {
        $value = trim((string) ($remarks[$module] ?? ''));
        $normalized[$module] = mb_substr($value, 0, 1500);
    }
    return $normalized;
}

function system_report_scalar(PDO $db, string $sql, array $params = []): float
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (float) ($stmt->fetchColumn() ?: 0);
}

function system_report_rows(PDO $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return array_map(static fn(array $row): array => [
        'label' => trim((string) ($row['label'] ?? '')) ?: 'Not specified',
        'value' => (float) ($row['value'] ?? 0),
    ], $stmt->fetchAll());
}

function system_report_metric(string $label, float|int $value, string $note = '', int $decimals = 0): array
{
    return ['label' => $label, 'value' => $value, 'note' => $note, 'decimals' => $decimals];
}

function system_report_chart(string $title, array $rows, string $empty = 'No data available for this period.'): array
{
    return ['title' => $title, 'rows' => $rows, 'empty' => $empty];
}

function build_system_report(string $dateFrom, string $dateTo, array $modules): array
{
    $newDb = auth_db();
    ensure_alert_workflow_schema();
    $dateFrom = normalize_system_report_date($dateFrom, date('Y-m-01'));
    $dateTo = normalize_system_report_date($dateTo, date('Y-m-d'));
    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }
    $modules = normalize_system_report_modules($modules);
    $range = [$dateFrom, $dateTo];
    $sections = [];

    if (in_array('patients', $modules, true)) {
        $sections['patients'] = [
            'title' => 'Patients',
            'description' => 'Current patient registry and demographic distribution. Snapshot values are not limited by the reporting dates.',
            'metrics' => [
                system_report_metric('Registered Patients', system_report_scalar($newDb, 'SELECT COUNT(*) FROM patients')),
                system_report_metric('Active Patients', system_report_scalar($newDb, "SELECT COUNT(*) FROM patients pt JOIN accounts a ON a.person_id = pt.person_id WHERE a.account_status = 'active'")),
                system_report_metric('Students', system_report_scalar($newDb, 'SELECT COUNT(*) FROM patients pt JOIN students s ON s.person_id = pt.person_id')),
                system_report_metric('Employees and Staff', system_report_scalar($newDb, 'SELECT COUNT(*) FROM patients pt LEFT JOIN school_employees se ON se.person_id = pt.person_id LEFT JOIN clinic_staff cs ON cs.person_id = pt.person_id WHERE se.person_id IS NOT NULL OR cs.person_id IS NOT NULL')),
            ],
            'charts' => [
                system_report_chart('Patient Type', system_report_rows($newDb, "
                    SELECT CASE
                        WHEN s.person_id IS NOT NULL THEN 'Student'
                        WHEN se.role_classification = 'Faculty' THEN 'Faculty'
                        WHEN se.role_classification = 'School Personnel' THEN 'School Personnel'
                        WHEN cs.person_id IS NOT NULL THEN 'Clinic Staff'
                        ELSE 'Other'
                    END AS label, COUNT(*) AS value
                    FROM patients pt
                    LEFT JOIN students s ON s.person_id = pt.person_id
                    LEFT JOIN school_employees se ON se.person_id = pt.person_id
                    LEFT JOIN clinic_staff cs ON cs.person_id = pt.person_id
                    GROUP BY label ORDER BY value DESC, label
                ")),
                system_report_chart('Sex', system_report_rows($newDb, "SELECT COALESCE(NULLIF(p.sex, ''), 'Not specified') label, COUNT(*) value FROM patients pt JOIN people p ON p.id = pt.person_id GROUP BY label ORDER BY value DESC, label")),
                system_report_chart('Student Program', system_report_rows($newDb, "SELECT COALESCE(pr.program_code, 'Not assigned') label, COUNT(*) value FROM patients pt JOIN students s ON s.person_id = pt.person_id LEFT JOIN programs pr ON pr.id = s.program_id GROUP BY label ORDER BY value DESC, label")),
            ],
        ];
    }

    if (in_array('accounts', $modules, true)) {
        $sections['accounts'] = [
            'title' => 'Accounts',
            'description' => 'Current account activation and role distribution. Passwords and credentials are never included.',
            'metrics' => [
                system_report_metric('All Accounts', system_report_scalar($newDb, 'SELECT COUNT(*) FROM accounts')),
                system_report_metric('Active', system_report_scalar($newDb, "SELECT COUNT(*) FROM accounts WHERE account_status = 'active'")),
                system_report_metric('Inactive', system_report_scalar($newDb, "SELECT COUNT(*) FROM accounts WHERE account_status = 'inactive'")),
                system_report_metric('Suspended', system_report_scalar($newDb, "SELECT COUNT(*) FROM accounts WHERE account_status = 'suspended'")),
            ],
            'charts' => [
                system_report_chart('Account Status', system_report_rows($newDb, 'SELECT account_status label, COUNT(*) value FROM accounts GROUP BY account_status ORDER BY value DESC')),
                system_report_chart('Account Classification', system_report_rows($newDb, "
                    SELECT CASE
                        WHEN s.person_id IS NOT NULL THEN 'Student'
                        WHEN se.person_id IS NOT NULL THEN se.role_classification
                        WHEN cs.person_id IS NOT NULL THEN CONCAT('Clinic ', UPPER(LEFT(cs.staff_role, 1)), SUBSTRING(cs.staff_role, 2))
                        ELSE 'Patient'
                    END label, COUNT(*) value
                    FROM accounts a
                    LEFT JOIN students s ON s.person_id = a.person_id
                    LEFT JOIN school_employees se ON se.person_id = a.person_id
                    LEFT JOIN clinic_staff cs ON cs.person_id = a.person_id
                    GROUP BY label ORDER BY value DESC, label
                ")),
            ],
        ];
    }

    if (in_array('visits', $modules, true)) {
        $visitCount = system_report_scalar($newDb, 'SELECT COUNT(*) FROM visits WHERE DATE(visit_datetime) BETWEEN ? AND ?', $range);
        $sections['visits'] = [
            'title' => 'Visits and Vital Signs',
            'description' => 'Clinic visit activity, workflow states, complaints, sources, and recorded measurements in the selected period.',
            'metrics' => [
                system_report_metric('Visits', $visitCount),
                system_report_metric('Completed Visits', system_report_scalar($newDb, "SELECT COUNT(*) FROM visits WHERE status = 'Completed' AND DATE(visit_datetime) BETWEEN ? AND ?", $range)),
                system_report_metric('Clinical Entries', system_report_scalar($newDb, 'SELECT COUNT(*) FROM visit_entries ve JOIN visits v ON v.visit_id = ve.visit_id WHERE DATE(v.visit_datetime) BETWEEN ? AND ?', $range)),
                system_report_metric('Vital Measurements', system_report_scalar($newDb, 'SELECT COUNT(*) FROM vital_signs vs JOIN visits v ON v.visit_id = vs.visit_id WHERE DATE(v.visit_datetime) BETWEEN ? AND ?', $range)),
                system_report_metric('Average Temperature', system_report_scalar($newDb, 'SELECT AVG(vs.temperature) FROM vital_signs vs JOIN visits v ON v.visit_id = vs.visit_id WHERE DATE(v.visit_datetime) BETWEEN ? AND ?', $range), 'Celsius', 1),
                system_report_metric('Average Pulse Rate', system_report_scalar($newDb, 'SELECT AVG(vs.pulse_rate) FROM vital_signs vs JOIN visits v ON v.visit_id = vs.visit_id WHERE DATE(v.visit_datetime) BETWEEN ? AND ?', $range), 'beats per minute', 1),
            ],
            'charts' => [
                system_report_chart('Visits by Day', system_report_rows($newDb, "SELECT DATE_FORMAT(visit_datetime, '%b %e') label, COUNT(*) value FROM visits WHERE DATE(visit_datetime) BETWEEN ? AND ? GROUP BY DATE(visit_datetime), label ORDER BY DATE(visit_datetime)", $range)),
                system_report_chart('Visit Status', system_report_rows($newDb, "SELECT COALESCE(NULLIF(status, ''), 'Unaddressed') label, COUNT(*) value FROM visits WHERE DATE(visit_datetime) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC", $range)),
                system_report_chart('Visit Purpose', system_report_rows($newDb, "SELECT COALESCE(NULLIF(visit_purpose, ''), 'Not specified') label, COUNT(*) value FROM visits WHERE DATE(visit_datetime) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC LIMIT 10", $range)),
                system_report_chart('Visit Source', system_report_rows($newDb, "SELECT COALESCE(NULLIF(visit_source, ''), 'Not specified') label, COUNT(*) value FROM visits WHERE DATE(visit_datetime) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC", $range)),
                system_report_chart('Common Complaints', system_report_rows($newDb, "SELECT COALESCE(NULLIF(chief_complaint, ''), 'Not specified') label, COUNT(*) value FROM visits WHERE DATE(visit_datetime) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC LIMIT 10", $range)),
            ],
        ];
    }

    if (in_array('appointments', $modules, true)) {
        $sections['appointments'] = [
            'title' => 'Appointments',
            'description' => 'Appointment requests and schedule outcomes in the selected period.',
            'metrics' => [
                system_report_metric('Appointments', system_report_scalar($newDb, 'SELECT COUNT(*) FROM appointments WHERE DATE(appointment_datetime) BETWEEN ? AND ?', $range)),
                system_report_metric('Scheduled', system_report_scalar($newDb, "SELECT COUNT(*) FROM appointments WHERE status IN ('Pending', 'Approved', 'Scheduled') AND DATE(appointment_datetime) BETWEEN ? AND ?", $range)),
                system_report_metric('Completed', system_report_scalar($newDb, "SELECT COUNT(*) FROM appointments WHERE status = 'Completed' AND DATE(appointment_datetime) BETWEEN ? AND ?", $range)),
                system_report_metric('Cancelled or No-show', system_report_scalar($newDb, "SELECT COUNT(*) FROM appointments WHERE status IN ('Cancelled', 'No Show', 'No-show') AND DATE(appointment_datetime) BETWEEN ? AND ?", $range)),
            ],
            'charts' => [
                system_report_chart('Appointment Status', system_report_rows($newDb, "SELECT COALESCE(NULLIF(status, ''), 'Not specified') label, COUNT(*) value FROM appointments WHERE DATE(appointment_datetime) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC", $range)),
                system_report_chart('Appointment Purpose', system_report_rows($newDb, "SELECT COALESCE(NULLIF(purpose, ''), 'Not specified') label, COUNT(*) value FROM appointments WHERE DATE(appointment_datetime) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC LIMIT 10", $range)),
                system_report_chart('Request Source', system_report_rows($newDb, "SELECT COALESCE(NULLIF(request_source, ''), 'Not specified') label, COUNT(*) value FROM appointments WHERE DATE(appointment_datetime) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC", $range)),
                system_report_chart('Appointments by Day', system_report_rows($newDb, "SELECT DATE_FORMAT(appointment_datetime, '%b %e') label, COUNT(*) value FROM appointments WHERE DATE(appointment_datetime) BETWEEN ? AND ? GROUP BY DATE(appointment_datetime), label ORDER BY DATE(appointment_datetime)", $range)),
            ],
        ];
    }

    if (in_array('inventory', $modules, true)) {
        $sections['inventory'] = [
            'title' => 'Inventory and Medicine',
            'description' => 'Current stock health plus inventory movements and medicine dispensing activity in the selected period.',
            'metrics' => [
                system_report_metric('Active Items', system_report_scalar($newDb, 'SELECT COUNT(*) FROM inventory_items WHERE is_active = 1')),
                system_report_metric('Units in Stock', system_report_scalar($newDb, 'SELECT COALESCE(SUM(quantity), 0) FROM inventory_items WHERE is_active = 1')),
                system_report_metric('Low Stock Items', system_report_scalar($newDb, 'SELECT COUNT(*) FROM inventory_items WHERE is_active = 1 AND quantity <= reorder_level')),
                system_report_metric('Medicine Dispensed', system_report_scalar($newDb, 'SELECT COALESCE(SUM(quantity), 0) FROM medicine_dispensings WHERE DATE(dispensed_at) BETWEEN ? AND ?', $range), 'units'),
            ],
            'charts' => [
                system_report_chart('Items by Type', system_report_rows($newDb, "SELECT COALESCE(NULLIF(item_type, ''), 'Not specified') label, COUNT(*) value FROM inventory_items WHERE is_active = 1 GROUP BY label ORDER BY value DESC")),
                system_report_chart('Stock Condition', system_report_rows($newDb, "SELECT CASE WHEN quantity = 0 THEN 'Out of Stock' WHEN quantity <= reorder_level THEN 'Low Stock' ELSE 'Healthy Stock' END label, COUNT(*) value FROM inventory_items WHERE is_active = 1 GROUP BY label ORDER BY value DESC")),
                system_report_chart('Inventory Transactions', system_report_rows($newDb, "SELECT COALESCE(NULLIF(transaction_type, ''), 'Not specified') label, COUNT(*) value FROM inventory_transactions WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC", $range)),
                system_report_chart('Medicine Dispensed by Item', system_report_rows($newDb, "SELECT i.item_name label, SUM(md.quantity) value FROM medicine_dispensings md JOIN inventory_items i ON i.item_id = md.item_id WHERE DATE(md.dispensed_at) BETWEEN ? AND ? GROUP BY i.item_id, i.item_name ORDER BY value DESC LIMIT 10", $range)),
            ],
        ];
    }

    if (in_array('loans', $modules, true)) {
        $sections['loans'] = [
            'title' => 'Equipment Loans',
            'description' => 'Equipment borrowing, return, and overdue activity in the selected period.',
            'metrics' => [
                system_report_metric('Loans Created', system_report_scalar($newDb, 'SELECT COUNT(*) FROM equipment_loans WHERE DATE(borrowed_at) BETWEEN ? AND ?', $range)),
                system_report_metric('Items Borrowed', system_report_scalar($newDb, 'SELECT COALESCE(SUM(quantity), 0) FROM equipment_loans WHERE DATE(borrowed_at) BETWEEN ? AND ?', $range)),
                system_report_metric('Currently Borrowed', system_report_scalar($newDb, "SELECT COUNT(*) FROM equipment_loans WHERE status IN ('Borrowed', 'Active')")),
                system_report_metric('Overdue', system_report_scalar($newDb, "SELECT COUNT(*) FROM equipment_loans WHERE returned_at IS NULL AND due_at < NOW()")),
            ],
            'charts' => [
                system_report_chart('Loan Status', system_report_rows($newDb, "SELECT COALESCE(NULLIF(status, ''), 'Not specified') label, COUNT(*) value FROM equipment_loans WHERE DATE(borrowed_at) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC", $range)),
                system_report_chart('Borrowed Equipment', system_report_rows($newDb, "SELECT i.item_name label, SUM(el.quantity) value FROM equipment_loans el JOIN inventory_items i ON i.item_id = el.item_id WHERE DATE(el.borrowed_at) BETWEEN ? AND ? GROUP BY i.item_id, i.item_name ORDER BY value DESC LIMIT 10", $range)),
            ],
        ];
    }

    if (in_array('ape', $modules, true)) {
        $sections['ape'] = [
            'title' => 'Annual Physical Examination',
            'description' => 'APE compliance, workflow, clearance, requirements, and findings recorded in the selected period.',
            'metrics' => [
                system_report_metric('Cycles Started', system_report_scalar($newDb, 'SELECT COUNT(*) FROM ape_cycles WHERE DATE(started_at) BETWEEN ? AND ?', $range)),
                system_report_metric('APE Records', system_report_scalar($newDb, 'SELECT COUNT(*) FROM ape_records WHERE DATE(COALESCE(exam_date, created_at)) BETWEEN ? AND ?', $range)),
                system_report_metric('Cleared', system_report_scalar($newDb, "SELECT COUNT(*) FROM ape_records WHERE clearance_status = 'Cleared' AND DATE(COALESCE(exam_date, created_at)) BETWEEN ? AND ?", $range)),
                system_report_metric('Follow-up Required', system_report_scalar($newDb, 'SELECT COUNT(*) FROM ape_records WHERE follow_up_required = 1 AND DATE(COALESCE(exam_date, created_at)) BETWEEN ? AND ?', $range)),
                system_report_metric('Documents Uploaded', system_report_scalar($newDb, 'SELECT COUNT(*) FROM ape_documents WHERE DATE(uploaded_at) BETWEEN ? AND ?', $range)),
                system_report_metric('Compliance', system_report_scalar($newDb, "SELECT COALESCE(ROUND(100 * SUM(ar.clearance_status = 'Cleared') / NULLIF(COUNT(ar.ape_id), 0), 1), 0) FROM ape_records ar JOIN ape_cycles ac ON ac.ape_cycle_id = ar.ape_cycle_id WHERE DATE(ac.started_at) BETWEEN ? AND ?", $range), 'percent cleared', 1),
            ],
            'charts' => [
                system_report_chart('APE Cycle Status', system_report_rows($newDb, "SELECT status label, COUNT(*) value FROM ape_cycles WHERE DATE(started_at) BETWEEN ? AND ? GROUP BY status ORDER BY FIELD(status, 'Active', 'Closed', 'Archived')", $range)),
                system_report_chart('APE Workflow Status', system_report_rows($newDb, "SELECT COALESCE(NULLIF(workflow_status, ''), 'Not specified') label, COUNT(*) value FROM ape_records WHERE DATE(COALESCE(exam_date, created_at)) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC", $range)),
                system_report_chart('Requirement Status', system_report_rows($newDb, "SELECT COALESCE(NULLIF(status, ''), 'Not specified') label, COUNT(*) value FROM ape_requirements WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC", $range)),
                system_report_chart('Clearance Status', system_report_rows($newDb, "SELECT COALESCE(NULLIF(clearance_status, ''), 'Not specified') label, COUNT(*) value FROM ape_records WHERE DATE(COALESCE(exam_date, created_at)) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC", $range)),
                system_report_chart('Finding Result', system_report_rows($newDb, "SELECT COALESCE(NULLIF(result_status, ''), 'Not specified') label, COUNT(*) value FROM ape_findings WHERE DATE(recorded_at) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC", $range)),
            ],
        ];
    }

    if (in_array('referrals', $modules, true)) {
        $sections['referrals'] = [
            'title' => 'Referrals',
            'description' => 'External and internal patient referrals created during the selected period.',
            'metrics' => [
                system_report_metric('Referrals', system_report_scalar($newDb, 'SELECT COUNT(*) FROM referrals WHERE DATE(referral_date) BETWEEN ? AND ?', $range)),
                system_report_metric('Pending', system_report_scalar($newDb, "SELECT COUNT(*) FROM referrals WHERE status = 'Pending' AND DATE(referral_date) BETWEEN ? AND ?", $range)),
                system_report_metric('Completed', system_report_scalar($newDb, "SELECT COUNT(*) FROM referrals WHERE status = 'Completed' AND DATE(referral_date) BETWEEN ? AND ?", $range)),
                system_report_metric('Unique Destinations', system_report_scalar($newDb, 'SELECT COUNT(DISTINCT referred_to) FROM referrals WHERE DATE(referral_date) BETWEEN ? AND ?', $range)),
            ],
            'charts' => [
                system_report_chart('Referral Status', system_report_rows($newDb, "SELECT COALESCE(NULLIF(status, ''), 'Not specified') label, COUNT(*) value FROM referrals WHERE DATE(referral_date) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC", $range)),
                system_report_chart('Referred To', system_report_rows($newDb, "SELECT COALESCE(NULLIF(referred_to, ''), 'Not specified') label, COUNT(*) value FROM referrals WHERE DATE(referral_date) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC LIMIT 10", $range)),
                system_report_chart('Referrals by Day', system_report_rows($newDb, "SELECT DATE_FORMAT(referral_date, '%b %e') label, COUNT(*) value FROM referrals WHERE DATE(referral_date) BETWEEN ? AND ? GROUP BY DATE(referral_date), label ORDER BY DATE(referral_date)", $range)),
            ],
        ];
    }

    if (in_array('alerts', $modules, true)) {
        $sections['alerts'] = [
            'title' => 'Alerts and Incidents',
            'description' => 'Emergency reports, assessed risk, and resolution status in the selected period.',
            'metrics' => [
                system_report_metric('Alerts', system_report_scalar($newDb, 'SELECT COUNT(*) FROM nurse_alerts WHERE DATE(created_at) BETWEEN ? AND ?', $range)),
                system_report_metric('Pending', system_report_scalar($newDb, "SELECT COUNT(*) FROM nurse_alerts WHERE status = 'Pending' AND DATE(created_at) BETWEEN ? AND ?", $range)),
                system_report_metric('Resolved', system_report_scalar($newDb, "SELECT COUNT(*) FROM nurse_alerts WHERE status = 'Resolved' AND DATE(created_at) BETWEEN ? AND ?", $range)),
                system_report_metric('High or Critical Risk', system_report_scalar($newDb, "SELECT COUNT(*) FROM nurse_alerts WHERE risk_level IN ('High', 'Critical') AND DATE(created_at) BETWEEN ? AND ?", $range)),
            ],
            'charts' => [
                system_report_chart('Risk Level', system_report_rows($newDb, "SELECT COALESCE(NULLIF(risk_level, ''), 'Not specified') label, COUNT(*) value FROM nurse_alerts WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY label ORDER BY FIELD(label, 'Critical', 'High', 'Moderate', 'Low')", $range)),
                system_report_chart('Alert Status', system_report_rows($newDb, "SELECT COALESCE(NULLIF(status, ''), 'Not specified') label, COUNT(*) value FROM nurse_alerts WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC", $range)),
                system_report_chart('Incident Type', system_report_rows($newDb, "SELECT COALESCE(NULLIF(incident_type, ''), 'Not specified') label, COUNT(*) value FROM nurse_alerts WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY label ORDER BY value DESC LIMIT 10", $range)),
                system_report_chart('Alerts by Day', system_report_rows($newDb, "SELECT DATE_FORMAT(created_at, '%b %e') label, COUNT(*) value FROM nurse_alerts WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at), label ORDER BY DATE(created_at)", $range)),
            ],
        ];
    }

    return [
        'title' => 'CLINiQ System Analytics Report',
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'generated_at' => date('Y-m-d H:i:s'),
        'modules' => $modules,
        'sections' => $sections,
    ];
}
