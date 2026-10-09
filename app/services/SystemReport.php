<?php

require_once __DIR__ . '/../config/database.php';

function system_report_module_labels(): array
{
    return [
        'patient_care' => 'Patient Care Transactions',
        'scheduling_referrals' => 'Scheduling and Referral Transactions',
        'inventory_loans' => 'Inventory and Loan Transactions',
        'safety' => 'Safety Transactions',
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
    return array_keys(system_report_module_labels());
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

function system_report_chart_presentation(array $rows, string $renderType): string
{
    $values = array_map(static fn(array $row): float => (float) ($row['value'] ?? 0), $rows);
    if (!$rows || !array_filter($values, static fn(float $value): bool => $value !== 0.0)) {
        return 'empty';
    }
    if (count($rows) === 1) {
        return 'summary';
    }
    return $renderType === 'line' || count($rows) <= 6 ? 'diagram' : 'summary';
}

function system_report_range(string $dateFrom, string $dateTo): array
{
    $end = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d');
    return [$dateFrom . ' 00:00:00', $end . ' 00:00:00'];
}

function system_report_ape_range(string $dateFrom, string $dateTo): array
{
    [$start, $end] = system_report_range($dateFrom, $dateTo);
    return [$dateFrom, substr($end, 0, 10), $start, $end];
}

function system_report_chart_summary_insight(array $rows): string
{
    $format = static fn(float $value): string => $value === floor($value)
        ? (string) (int) $value
        : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    $total = array_sum(array_map(static fn(array $row): float => (float) ($row['value'] ?? 0), $rows));
    $items = array_map(static fn(array $row): string => trim((string) ($row['label'] ?? 'Not specified')) . ' (' . $format((float) ($row['value'] ?? 0)) . ')', $rows);

    return count($rows) === 1
        ? ($items[0] ?? 'No category data is available.')
        : count($rows) . ' categories, total ' . $format($total) . ': ' . implode(', ', array_slice($items, 0, 4)) . (count($items) > 4 ? ', and more.' : '.');
}

function system_report_chart(string $title, array $rows, string $empty = 'No data available for this period.', int $decimals = 0, string $renderType = 'bar', bool $detailInPdf = true, string $insight = ''): array
{
    return [
        'title' => $title,
        'rows' => $rows,
        'empty' => $empty,
        'decimals' => max(0, min(2, $decimals)),
        'render_type' => $renderType,
        'presentation' => system_report_chart_presentation($rows, $renderType),
        'detail_in_pdf' => $detailInPdf,
        'insight' => trim($insight) !== '' ? $insight : system_report_chart_summary_insight($rows),
    ];
}

function system_report_visit_volume(PDO $db, string $dateFrom, string $dateTo): array
{
    $start = new DateTimeImmutable($dateFrom);
    $end = new DateTimeImmutable($dateTo);
    $days = $start->diff($end)->days + 1;
    $bucket = $days <= 31 ? 'day' : ($days <= 180 ? 'week' : 'month');
    [, $rangeEnd] = system_report_range($dateFrom, $dateTo);
    $rawRows = system_report_rows($db, 'SELECT DATE(visit_datetime) label, COUNT(*) value FROM visits WHERE visit_datetime >= ? AND visit_datetime < ? GROUP BY DATE(visit_datetime)', [$dateFrom . ' 00:00:00', $rangeEnd]);
    $dailyCounts = [];
    foreach ($rawRows as $row) {
        $dailyCounts[$row['label']] = (int) $row['value'];
    }

    $buckets = [];
    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
        $key = match ($bucket) {
            'week' => $day->modify('monday this week')->format('Y-m-d'),
            'month' => $day->format('Y-m'),
            default => $day->format('Y-m-d'),
        };
        $label = match ($bucket) {
            'week' => $day->modify('monday this week')->format('M j') . '–' . $day->modify('sunday this week')->format('M j'),
            'month' => $day->format('M Y'),
            default => $day->format('M j'),
        };
        if (!isset($buckets[$key])) {
            $buckets[$key] = ['label' => $label, 'value' => 0];
        }
        $buckets[$key]['value'] += $dailyCounts[$day->format('Y-m-d')] ?? 0;
    }

    $rows = array_values($buckets);
    $total = array_sum(array_column($rows, 'value'));
    $peak = $rows ? array_reduce($rows, static fn(array $carry, array $row): array => $row['value'] > $carry['value'] ? $row : $carry, $rows[0]) : null;
    $unit = $bucket === 'day' ? 'day' : ($bucket === 'week' ? 'week' : 'month');
    $insight = $total > 0 && $peak
        ? sprintf('%d visits across %d %s%s. Average %.1f per %s. Peak: %s (%d).', $total, count($rows), $unit, count($rows) === 1 ? '' : 's', $total / max(1, count($rows)), $unit, $peak['label'], $peak['value'])
        : 'No visits were recorded during the selected reporting period.';

    return ['title' => 'Visits by ' . ucfirst($bucket), 'rows' => $rows, 'insight' => $insight];
}

function system_report_action_summary(PDO $db): array
{
    $actions = [
        ['label' => 'Low-stock medicine', 'detail' => 'medicine item(s) are at or below reorder level.', 'icon' => 'inventory_2', 'tone' => 'amber', 'href' => '../inventory/index.php?tab=medicine&highlight=low-stock', 'sql' => "SELECT COUNT(*) FROM inventory_items WHERE is_active = 1 AND item_type = 'Medicine' AND quantity <= reorder_level"],
        ['label' => 'Overdue equipment loans', 'detail' => 'equipment loan(s) are overdue.', 'icon' => 'assignment_late', 'tone' => 'red', 'href' => '../inventory/index.php?tab=equipment&highlight=active-loans', 'sql' => 'SELECT COUNT(*) FROM equipment_loans WHERE returned_at IS NULL AND due_at < NOW()'],
        ['label' => 'High-risk pending alerts', 'detail' => 'pending alert(s) need urgent review.', 'icon' => 'warning', 'tone' => 'red', 'href' => '../alerts/index.php?status=active&risk=high-critical', 'sql' => "SELECT COUNT(*) FROM nurse_alerts WHERE status = 'Pending' AND risk_level IN ('High', 'Critical')"],
        ['label' => 'Appointments awaiting completion', 'detail' => 'appointment(s) need completion review.', 'icon' => 'event_available', 'tone' => 'amber', 'href' => '../appointments/index.php?status=For%20Confirmation', 'sql' => "SELECT COUNT(*) FROM appointments WHERE status = 'For Confirmation'"],
        ['label' => 'Incomplete referrals', 'detail' => 'referral(s) are not completed.', 'icon' => 'send', 'tone' => 'amber', 'href' => '../referrals/index.php?status=incomplete', 'sql' => "SELECT COUNT(*) FROM referrals WHERE COALESCE(status, '') <> 'Completed'"],
    ];

    foreach ($actions as $index => $action) {
        $count = (int) system_report_scalar($db, $action['sql']);
        if ($count === 0) {
            unset($actions[$index]);
            continue;
        }
        $actions[$index]['count'] = $count;
        unset($actions[$index]['sql']);
    }

    return array_values($actions);
}

function build_system_report(string $dateFrom, string $dateTo, array $modules): array
{
    $newDb = auth_db();
    $dateFrom = normalize_system_report_date($dateFrom, date('Y-m-01'));
    $dateTo = normalize_system_report_date($dateTo, date('Y-m-d'));
    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }
    $modules = normalize_system_report_modules($modules);
    $range = system_report_range($dateFrom, $dateTo);
    $apeRange = system_report_ape_range($dateFrom, $dateTo);
    $sections = [];

    if (in_array('patient_care', $modules, true)) {
        $sections['patient_care'] = [
            'title' => 'Patient Care Transactions',
            'description' => 'Visits, clinical documentation, and APE workflow activity during the selected period.',
            'metrics' => [
                system_report_metric('Clinical Visits', system_report_scalar($newDb, 'SELECT COUNT(*) FROM visits WHERE visit_datetime >= ? AND visit_datetime < ?', $range)),
                system_report_metric('Clinical Entries', system_report_scalar($newDb, 'SELECT COUNT(*) FROM visit_entries ve JOIN visits v ON v.visit_id = ve.visit_id WHERE v.visit_datetime >= ? AND v.visit_datetime < ?', $range)),
                system_report_metric('APE Records', system_report_scalar($newDb, 'SELECT COUNT(*) FROM ape_records WHERE (exam_date >= ? AND exam_date < ?) OR (exam_date IS NULL AND created_at >= ? AND created_at < ?)', $apeRange)),
                system_report_metric('APE Findings', system_report_scalar($newDb, 'SELECT COUNT(*) FROM ape_findings WHERE recorded_at >= ? AND recorded_at < ?', $range)),
            ],
            'charts' => [
                (static function () use ($newDb, $dateFrom, $dateTo): array {
                    $volume = system_report_visit_volume($newDb, $dateFrom, $dateTo);
                    return system_report_chart($volume['title'], $volume['rows'], 'No visits were recorded during the selected reporting period.', 0, 'line', false, $volume['insight']);
                })(),
                system_report_chart('APE Clearance Status', system_report_rows($newDb, "SELECT COALESCE(NULLIF(clearance_status, ''), 'Not specified') label, COUNT(*) value FROM ape_records WHERE (exam_date >= ? AND exam_date < ?) OR (exam_date IS NULL AND created_at >= ? AND created_at < ?) GROUP BY label ORDER BY value DESC", $apeRange), 'No APE clearance records were available for this period.', 0, 'donut'),
            ],
            'tables' => [
                system_report_chart('Visit Purpose', system_report_rows($newDb, "SELECT COALESCE(NULLIF(visit_purpose, ''), 'Not specified') label, COUNT(*) value FROM visits WHERE visit_datetime >= ? AND visit_datetime < ? GROUP BY label ORDER BY value DESC", $range)),
                system_report_chart('Common Complaints', system_report_rows($newDb, "SELECT COALESCE(NULLIF(chief_complaint, ''), 'Not specified') label, COUNT(*) value FROM visits WHERE visit_datetime >= ? AND visit_datetime < ? GROUP BY label ORDER BY value DESC", $range)),
            ],
        ];
    }

    if (in_array('scheduling_referrals', $modules, true)) {
        $sections['scheduling_referrals'] = [
            'title' => 'Scheduling and Referral Transactions',
            'description' => 'Appointment requests and referral transactions recorded during the selected period.',
            'metrics' => [
                system_report_metric('Appointment Requests', system_report_scalar($newDb, 'SELECT COUNT(*) FROM appointments WHERE created_at >= ? AND created_at < ?', $range)),
                system_report_metric('Pending Requests', system_report_scalar($newDb, "SELECT COUNT(*) FROM appointments WHERE status = 'Pending' AND created_at >= ? AND created_at < ?", $range)),
                system_report_metric('For Completion', system_report_scalar($newDb, "SELECT COUNT(*) FROM appointments WHERE status = 'For Confirmation' AND created_at >= ? AND created_at < ?", $range)),
                system_report_metric('Referrals', system_report_scalar($newDb, 'SELECT COUNT(*) FROM referrals WHERE referral_date >= ? AND referral_date < ?', $range)),
                system_report_metric('Completed Referrals', system_report_scalar($newDb, "SELECT COUNT(*) FROM referrals WHERE status = 'Completed' AND referral_date >= ? AND referral_date < ?", $range)),
            ],
            'charts' => [
                system_report_chart('Appointment Status', system_report_rows($newDb, "SELECT CASE WHEN status = 'For Confirmation' THEN 'For Completion' ELSE COALESCE(NULLIF(status, ''), 'Not specified') END label, COUNT(*) value FROM appointments WHERE created_at >= ? AND created_at < ? GROUP BY label ORDER BY value DESC", $range), 'No appointment requests were recorded for this period.', 0, 'donut'),
                system_report_chart('Referral Status', system_report_rows($newDb, "SELECT COALESCE(NULLIF(status, ''), 'Not specified') label, COUNT(*) value FROM referrals WHERE referral_date >= ? AND referral_date < ? GROUP BY label ORDER BY value DESC", $range), 'No referrals were recorded for this period.', 0, 'donut'),
            ],
            'tables' => [
                system_report_chart('Request Source', system_report_rows($newDb, "SELECT COALESCE(NULLIF(request_source, ''), 'Not specified') label, COUNT(*) value FROM appointments WHERE created_at >= ? AND created_at < ? GROUP BY label ORDER BY value DESC", $range)),
                system_report_chart('Referral Destination', system_report_rows($newDb, "SELECT COALESCE(NULLIF(referred_to, ''), 'Not specified') label, COUNT(*) value FROM referrals WHERE referral_date >= ? AND referral_date < ? GROUP BY label ORDER BY value DESC", $range)),
            ],
        ];
    }

    if (in_array('inventory_loans', $modules, true)) {
        $sections['inventory_loans'] = [
            'title' => 'Inventory and Loan Transactions',
            'description' => 'Inventory movements, medicine dispensing, equipment loans, and current stock exceptions.',
            'metrics' => [
                system_report_metric('Inventory Transactions', system_report_scalar($newDb, 'SELECT COUNT(*) FROM inventory_transactions WHERE created_at >= ? AND created_at < ?', $range)),
                system_report_metric('Medicine Dispensed', system_report_scalar($newDb, 'SELECT COALESCE(SUM(quantity), 0) FROM medicine_dispensings WHERE dispensed_at >= ? AND dispensed_at < ?', $range), 'units'),
                system_report_metric('Equipment Loans', system_report_scalar($newDb, 'SELECT COUNT(*) FROM equipment_loans WHERE borrowed_at >= ? AND borrowed_at < ?', $range)),
                system_report_metric('Low Stock Items', system_report_scalar($newDb, 'SELECT COUNT(*) FROM inventory_items WHERE is_active = 1 AND quantity <= reorder_level'), 'current snapshot'),
                system_report_metric('Overdue Loans', system_report_scalar($newDb, 'SELECT COUNT(*) FROM equipment_loans WHERE returned_at IS NULL AND due_at < NOW()'), 'current snapshot'),
            ],
            'charts' => [
                system_report_chart('Inventory Transactions by Type', system_report_rows($newDb, "SELECT COALESCE(NULLIF(transaction_type, ''), 'Not specified') label, COUNT(*) value FROM inventory_transactions WHERE created_at >= ? AND created_at < ? GROUP BY label ORDER BY value DESC", $range), 'No inventory transactions were recorded for this period.', 0, 'bar'),
                system_report_chart('Loan Status', system_report_rows($newDb, "SELECT COALESCE(NULLIF(status, ''), 'Not specified') label, COUNT(*) value FROM equipment_loans WHERE borrowed_at >= ? AND borrowed_at < ? GROUP BY label ORDER BY value DESC", $range), 'No equipment loans were recorded for this period.', 0, 'donut'),
            ],
            'tables' => [
                system_report_chart('Stock Condition', system_report_rows($newDb, "SELECT CASE WHEN quantity = 0 THEN 'Out of Stock' WHEN quantity <= reorder_level THEN 'Low Stock' ELSE 'Healthy Stock' END label, COUNT(*) value FROM inventory_items WHERE is_active = 1 GROUP BY label ORDER BY value DESC")),
                system_report_chart('Medicine Dispensed by Item', system_report_rows($newDb, "SELECT i.item_name label, SUM(md.quantity) value FROM medicine_dispensings md JOIN inventory_items i ON i.item_id = md.item_id WHERE md.dispensed_at >= ? AND md.dispensed_at < ? GROUP BY i.item_id, i.item_name ORDER BY value DESC", $range)),
                system_report_chart('Borrowed Equipment', system_report_rows($newDb, "SELECT i.item_name label, SUM(el.quantity) value FROM equipment_loans el JOIN inventory_items i ON i.item_id = el.item_id WHERE el.borrowed_at >= ? AND el.borrowed_at < ? GROUP BY i.item_id, i.item_name ORDER BY value DESC", $range)),
            ],
        ];
    }

    if (in_array('safety', $modules, true)) {
        $sections['safety'] = [
            'title' => 'Safety Transactions',
            'description' => 'Alerts and incident reports recorded during the selected period.',
            'metrics' => [
                system_report_metric('Alerts', system_report_scalar($newDb, 'SELECT COUNT(*) FROM nurse_alerts WHERE created_at >= ? AND created_at < ?', $range)),
                system_report_metric('Incident Reports', system_report_scalar($newDb, 'SELECT COUNT(*) FROM incident_reports WHERE reported_at >= ? AND reported_at < ?', $range)),
                system_report_metric('Pending Alerts', system_report_scalar($newDb, "SELECT COUNT(*) FROM nurse_alerts WHERE status = 'Pending' AND created_at >= ? AND created_at < ?", $range)),
                system_report_metric('High or Critical Risk', system_report_scalar($newDb, "SELECT COUNT(*) FROM nurse_alerts WHERE risk_level IN ('High', 'Critical') AND created_at >= ? AND created_at < ?", $range)),
            ],
            'charts' => [
                system_report_chart('Alert Risk Level', system_report_rows($newDb, "SELECT COALESCE(NULLIF(risk_level, ''), 'Not assessed') label, COUNT(*) value FROM nurse_alerts WHERE created_at >= ? AND created_at < ? GROUP BY label ORDER BY FIELD(label, 'Critical', 'High', 'Moderate', 'Low', 'Not assessed')", $range), 'No alerts were recorded for this period.', 0, 'donut'),
                system_report_chart('Alert Status', system_report_rows($newDb, "SELECT COALESCE(NULLIF(status, ''), 'Not specified') label, COUNT(*) value FROM nurse_alerts WHERE created_at >= ? AND created_at < ? GROUP BY label ORDER BY value DESC", $range), 'No alerts were recorded for this period.', 0, 'donut'),
            ],
            'tables' => [
                system_report_chart('Alert Incident Type', system_report_rows($newDb, "SELECT COALESCE(NULLIF(incident_type, ''), 'Not specified') label, COUNT(*) value FROM nurse_alerts WHERE created_at >= ? AND created_at < ? GROUP BY label ORDER BY value DESC", $range)),
                system_report_chart('Incident Report Status', system_report_rows($newDb, "SELECT COALESCE(NULLIF(status, ''), 'Not specified') label, COUNT(*) value FROM incident_reports WHERE reported_at >= ? AND reported_at < ? GROUP BY label ORDER BY value DESC", $range)),
            ],
        ];
    }

    return [
        'title' => 'CLINiQ Clinic Transaction Summary',
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'generated_at' => date('Y-m-d H:i:s'),
        'modules' => $modules,
        'action_summary' => system_report_action_summary($newDb),
        'transaction_summary' => [
            system_report_metric('Clinical Visits', system_report_scalar($newDb, 'SELECT COUNT(*) FROM visits WHERE visit_datetime >= ? AND visit_datetime < ?', $range)),
            system_report_metric('Clinical Entries', system_report_scalar($newDb, 'SELECT COUNT(*) FROM visit_entries ve JOIN visits v ON v.visit_id = ve.visit_id WHERE v.visit_datetime >= ? AND v.visit_datetime < ?', $range)),
            system_report_metric('APE Records', system_report_scalar($newDb, 'SELECT COUNT(*) FROM ape_records WHERE (exam_date >= ? AND exam_date < ?) OR (exam_date IS NULL AND created_at >= ? AND created_at < ?)', $apeRange)),
            system_report_metric('APE Findings', system_report_scalar($newDb, 'SELECT COUNT(*) FROM ape_findings WHERE recorded_at >= ? AND recorded_at < ?', $range)),
            system_report_metric('Appointment Requests', system_report_scalar($newDb, 'SELECT COUNT(*) FROM appointments WHERE created_at >= ? AND created_at < ?', $range)),
            system_report_metric('Inventory Transactions', system_report_scalar($newDb, 'SELECT COUNT(*) FROM inventory_transactions WHERE created_at >= ? AND created_at < ?', $range)),
            system_report_metric('Medicine Dispensed', system_report_scalar($newDb, 'SELECT COALESCE(SUM(quantity), 0) FROM medicine_dispensings WHERE dispensed_at >= ? AND dispensed_at < ?', $range), 'units'),
            system_report_metric('Equipment Loans', system_report_scalar($newDb, 'SELECT COUNT(*) FROM equipment_loans WHERE borrowed_at >= ? AND borrowed_at < ?', $range)),
            system_report_metric('Referrals', system_report_scalar($newDb, 'SELECT COUNT(*) FROM referrals WHERE referral_date >= ? AND referral_date < ?', $range)),
            system_report_metric('Alerts', system_report_scalar($newDb, 'SELECT COUNT(*) FROM nurse_alerts WHERE created_at >= ? AND created_at < ?', $range)),
            system_report_metric('Incident Reports', system_report_scalar($newDb, 'SELECT COUNT(*) FROM incident_reports WHERE reported_at >= ? AND reported_at < ?', $range)),
        ],
        'attention_summary' => [
            system_report_metric('Unaddressed Visits', system_report_scalar($newDb, "SELECT COUNT(*) FROM visits WHERE status = 'Unaddressed' AND visit_datetime >= ? AND visit_datetime < ?", $range)),
            system_report_metric('Pending Appointments', system_report_scalar($newDb, "SELECT COUNT(*) FROM appointments WHERE status = 'Pending' AND created_at >= ? AND created_at < ?", $range)),
            system_report_metric('Appointments for Completion', system_report_scalar($newDb, "SELECT COUNT(*) FROM appointments WHERE status = 'For Confirmation' AND created_at >= ? AND created_at < ?", $range)),
            system_report_metric('Low Stock Items', system_report_scalar($newDb, 'SELECT COUNT(*) FROM inventory_items WHERE is_active = 1 AND quantity <= reorder_level'), 'current snapshot'),
            system_report_metric('Overdue Loans', system_report_scalar($newDb, 'SELECT COUNT(*) FROM equipment_loans WHERE returned_at IS NULL AND due_at < NOW()'), 'current snapshot'),
            system_report_metric('Incomplete Referrals', system_report_scalar($newDb, "SELECT COUNT(*) FROM referrals WHERE COALESCE(status, '') <> 'Completed' AND referral_date >= ? AND referral_date < ?", $range)),
            system_report_metric('Pending Alerts', system_report_scalar($newDb, "SELECT COUNT(*) FROM nurse_alerts WHERE status = 'Pending' AND created_at >= ? AND created_at < ?", $range)),
            system_report_metric('High or Critical Alerts', system_report_scalar($newDb, "SELECT COUNT(*) FROM nurse_alerts WHERE risk_level IN ('High', 'Critical') AND created_at >= ? AND created_at < ?", $range)),
        ],
        'sections' => $sections,
    ];
}
