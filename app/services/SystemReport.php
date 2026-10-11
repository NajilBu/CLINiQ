<?php

require_once __DIR__ . '/../config/database.php';

function system_report_module_labels(): array
{
    return [
        'visits' => 'Visits',
        'ape' => 'APE Workflow',
        'inventory' => 'Inventory',
        'referrals' => 'Referrals',
        'alerts' => 'Alerts and Incidents',
        'feedback' => 'Clinic Feedback',
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
    $requested = array_map(static fn($module): string => (string) $module, $modules);
    if (in_array('appointments', $requested, true)) $requested[] = 'visits';
    $selected = array_values(array_unique(array_intersect($allowed, $requested)));
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

function system_report_median(array $values): ?float
{
    $values = array_values(array_filter($values, 'is_numeric'));
    if (!$values) return null;

    sort($values, SORT_NUMERIC);
    $middle = intdiv(count($values), 2);
    return count($values) % 2 === 1
        ? (float) $values[$middle]
        : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
}

function system_report_visual_rows(array $rows, string $renderType): array
{
    if (!in_array($renderType, ['bar', 'donut', 'progress'], true) || count($rows) <= 10) {
        return $rows;
    }

    $visible = array_slice($rows, 0, 10);
    $other = array_sum(array_map(static fn(array $row): float => (float) ($row['value'] ?? 0), array_slice($rows, 10)));
    if ($other > 0) {
        $visible[] = ['label' => 'Other', 'value' => $other];
    }
    return $visible;
}

function system_report_effective_chart_type(string $renderType, array $visualRows): string
{
    return $renderType === 'donut' && count($visualRows) > 4 ? 'bar' : $renderType;
}

function system_report_chart_presentation(array $rows, string $renderType, bool $forceDiagram = false): string
{
    $values = array_map(static fn(array $row): float => (float) ($row['value'] ?? 0), $rows);
    if (!$rows || !array_filter($values, static fn(float $value): bool => $value !== 0.0)) {
        return 'empty';
    }
    if ($forceDiagram) {
        return 'diagram';
    }
    if (in_array($renderType, ['line', 'column'], true)) {
        return 'diagram';
    }
    if (count($rows) === 1) {
        return 'summary';
    }
    if ($renderType === 'bar' && count($rows) <= 11) {
        return 'diagram';
    }
    return count($rows) <= 6 ? 'diagram' : 'summary';
}

function system_report_appointment_status_rows(PDO $db, array $range): array
{
    $counts = [];
    foreach (system_report_rows($db, 'SELECT status label, COUNT(*) value FROM appointments WHERE appointment_datetime >= ? AND appointment_datetime < ? GROUP BY status', $range) as $row) {
        $counts[(string) $row['label']] = (int) $row['value'];
    }

    $statuses = [
        'Pending' => 'For Approval',
        'Scheduled' => 'Approved',
        'For Confirmation' => 'For Completion',
        'Completed' => 'Completed',
        'Declined' => 'Declined',
        'Cancelled' => 'Cancelled',
        'No Show' => 'No Show',
    ];

    return array_map(
        static fn(string $status, string $label): array => ['label' => $label, 'value' => $counts[$status] ?? 0],
        array_keys($statuses),
        array_values($statuses),
    );
}

function system_report_range(string $dateFrom, string $dateTo): array
{
    $end = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d');
    return [$dateFrom . ' 00:00:00', $end . ' 00:00:00'];
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

function system_report_chart(string $title, array $rows, string $empty = 'No data available for this period.', int $decimals = 0, string $renderType = 'bar', bool $detailInPdf = true, string $insight = '', array $options = []): array
{
    $visualRows = $options['visual_rows'] ?? system_report_visual_rows($rows, $renderType);
    if (($options['hide_zero_rows'] ?? false) === true) {
        $visualRows = array_values(array_filter($visualRows, static fn(array $row): bool => (float) ($row['value'] ?? 0) > 0));
    }
    $effectiveRenderType = system_report_effective_chart_type($renderType, $visualRows);
    $forceDiagram = (bool) ($options['force_diagram'] ?? false);
    if (count($visualRows) === 1 && in_array($title, ['Student Visitors by Year Level', 'Visitor Affiliation'], true)) {
        $forceDiagram = false;
    }
    return [
        'title' => $title,
        'rows' => $rows,
        'visual_rows' => $visualRows,
        'empty' => $empty,
        'decimals' => max(0, min(2, $decimals)),
        'render_type' => $effectiveRenderType,
        'requested_render_type' => $renderType,
        'presentation' => system_report_chart_presentation($visualRows, $effectiveRenderType, $forceDiagram),
        'detail_in_pdf' => $detailInPdf,
        'insight' => trim($insight) !== '' ? $insight : system_report_chart_summary_insight($rows),
        'axis_max' => isset($options['axis_max']) ? (float) $options['axis_max'] : null,
        'colors' => array_values(array_filter((array) ($options['colors'] ?? []), 'is_string')),
    ];
}

function system_report_visit_hours(PDO $db, array $range): array
{
    $stmt = $db->prepare('SELECT HOUR(visit_datetime) hour, COUNT(*) value FROM visits WHERE visit_datetime >= ? AND visit_datetime < ? GROUP BY HOUR(visit_datetime)');
    $stmt->execute($range);
    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(int) $row['hour']] = (int) $row['value'];
    }

    $rows = [];
    for ($hour = 0; $hour < 24; $hour++) {
        $label = $hour === 0 ? '12 AM' : ($hour < 12 ? $hour . ' AM' : ($hour === 12 ? '12 PM' : ($hour - 12) . ' PM'));
        $rows[] = ['label' => $label, 'value' => $counts[$hour] ?? 0];
    }
    return $rows;
}

function system_report_passport_access_by_day(PDO $db, string $dateFrom, string $dateTo): array
{
    [, $rangeEnd] = system_report_range($dateFrom, $dateTo);
    $rawRows = system_report_rows($db, 'SELECT DATE(accessed_at) label, COUNT(*) value FROM passport_access_logs WHERE accessed_at >= ? AND accessed_at < ? GROUP BY DATE(accessed_at)', [$dateFrom . ' 00:00:00', $rangeEnd]);
    $counts = [];
    foreach ($rawRows as $row) {
        $counts[$row['label']] = (int) $row['value'];
    }

    $rows = [];
    for ($day = new DateTimeImmutable($dateFrom); $day <= new DateTimeImmutable($dateTo); $day = $day->modify('+1 day')) {
        $rows[] = ['label' => $day->format('M j'), 'value' => $counts[$day->format('Y-m-d')] ?? 0];
    }
    return $rows;
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
    $sections = [];

    if (in_array('visits', $modules, true)) {
        $visitCount = system_report_scalar($newDb, 'SELECT COUNT(*) FROM visits WHERE visit_datetime >= ? AND visit_datetime < ?', $range);
        $registeredPatientsServed = system_report_scalar($newDb, 'SELECT COUNT(DISTINCT patient_person_id) FROM visits WHERE visit_datetime >= ? AND visit_datetime < ?', $range);
        $visitsWithVitals = system_report_scalar($newDb, 'SELECT COUNT(*) FROM visits v WHERE v.visit_datetime >= ? AND v.visit_datetime < ? AND EXISTS (SELECT 1 FROM vital_signs vs WHERE vs.visit_id = v.visit_id)', $range);
        $firstActionMinutes = system_report_median(array_column(system_report_rows($newDb, 'SELECT TIMESTAMPDIFF(MINUTE, visit_datetime, addressed_at) value FROM visits WHERE visit_datetime >= ? AND visit_datetime < ? AND addressed_at IS NOT NULL AND addressed_at >= visit_datetime', $range), 'value'));
        $completionMinutes = system_report_median(array_column(system_report_rows($newDb, 'SELECT TIMESTAMPDIFF(MINUTE, visit_datetime, completed_at) value FROM visits WHERE visit_datetime >= ? AND visit_datetime < ? AND completed_at IS NOT NULL AND completed_at >= visit_datetime', $range), 'value'));
        $vitalsCoverage = $visitCount > 0 ? ($visitsWithVitals / $visitCount) * 100 : 0;
        $appointmentStatusRows = system_report_appointment_status_rows($newDb, $range);
        $sections['visits'] = [
            'title' => 'Visits',
            'description' => 'Clinic visits recorded and appointment activity scheduled during the selected period.',
            'metrics' => [
                system_report_metric('Clinical Visits', $visitCount),
                system_report_metric('Visits with Vitals', $visitsWithVitals, sprintf('%d%% of period visits', round($vitalsCoverage))),
                system_report_metric('Median Time to First Action', $firstActionMinutes ?? 0, $firstActionMinutes === null ? 'no qualifying visits' : 'minutes', 1),
                system_report_metric('Median Time to Completion', $completionMinutes ?? 0, $completionMinutes === null ? 'no qualifying visits' : 'minutes', 1),
                system_report_metric('For Approval', system_report_scalar($newDb, "SELECT COUNT(*) FROM appointments WHERE status = 'Pending' AND appointment_datetime >= ? AND appointment_datetime < ?", $range)),
                system_report_metric('Approved', system_report_scalar($newDb, "SELECT COUNT(*) FROM appointments WHERE status = 'Scheduled' AND appointment_datetime >= ? AND appointment_datetime < ?", $range)),
                system_report_metric('For Completion', system_report_scalar($newDb, "SELECT COUNT(*) FROM appointments WHERE status = 'For Confirmation' AND appointment_datetime >= ? AND appointment_datetime < ?", $range)),
                system_report_metric('Completed', system_report_scalar($newDb, "SELECT COUNT(*) FROM appointments WHERE status = 'Completed' AND appointment_datetime >= ? AND appointment_datetime < ?", $range)),
            ],
            'summary_metrics' => [
                system_report_metric('Registered Patients Served', $registeredPatientsServed),
                system_report_metric('Clinical Entries Documented During Period', system_report_scalar($newDb, 'SELECT COUNT(*) FROM visit_entries WHERE created_at >= ? AND created_at < ?', $range)),
                system_report_metric('Visits Completed During Period', system_report_scalar($newDb, 'SELECT COUNT(*) FROM visits WHERE completed_at >= ? AND completed_at < ?', $range)),
            ],
            'charts' => [
                system_report_chart('Student Visitors by Recorded Gender', system_report_rows($newDb, "SELECT COALESCE(NULLIF(p.sex, ''), 'Not recorded') label, COUNT(DISTINCT v.patient_person_id) value FROM visits v JOIN students s ON s.person_id = v.patient_person_id JOIN people p ON p.id = v.patient_person_id WHERE v.visit_datetime >= ? AND v.visit_datetime < ? GROUP BY label ORDER BY value DESC, label", $range), 'No student visits were recorded during this period.', 0, 'donut'),
                system_report_chart('Student Visitors by Year Level', system_report_rows($newDb, "SELECT CASE WHEN student_visitors.year_level IS NULL OR TRIM(student_visitors.year_level) = '' THEN 'Not recorded' ELSE CONCAT('Year ', student_visitors.year_level) END label, COUNT(*) value FROM (SELECT DISTINCT v.patient_person_id, s.year_level FROM visits v JOIN students s ON s.person_id = v.patient_person_id WHERE v.visit_datetime >= ? AND v.visit_datetime < ?) student_visitors GROUP BY label ORDER BY FIELD(label, 'Year 1', 'Year 2', 'Year 3', 'Year 4', 'Not recorded'), label", $range), 'No student visits were recorded during this period.', 0, 'bar', true, '', ['force_diagram' => true]),
                system_report_chart('Visitor Affiliation', system_report_rows($newDb, "SELECT CASE WHEN v.patient_person_id IS NULL THEN 'Guest / walk-in' WHEN cs.person_id IS NOT NULL THEN 'Clinic staff' WHEN s.person_id IS NOT NULL THEN 'Student' WHEN se.role_classification = 'Faculty' THEN 'Faculty' WHEN se.role_classification = 'Non-Teaching Personnel' THEN 'NTP' ELSE 'Other registered patient' END label, COUNT(*) value FROM visits v LEFT JOIN students s ON s.person_id = v.patient_person_id LEFT JOIN school_employees se ON se.person_id = v.patient_person_id LEFT JOIN clinic_staff cs ON cs.person_id = v.patient_person_id WHERE v.visit_datetime >= ? AND v.visit_datetime < ? GROUP BY label ORDER BY FIELD(label, 'Student', 'Faculty', 'NTP', 'Clinic staff', 'Guest / walk-in', 'Other registered patient')", $range), 'No visits were recorded during this period.', 0, 'bar', true, '', ['force_diagram' => true]),
                system_report_chart('Appointment Status', $appointmentStatusRows, 'No appointments were scheduled for this period.', 0, 'bar', true, '', ['visual_rows' => array_values(array_filter($appointmentStatusRows, static fn(array $row): bool => (float) $row['value'] > 0)), 'force_diagram' => true]),
                system_report_chart('Visit Source', system_report_rows($newDb, "SELECT COALESCE(NULLIF(visit_source, ''), 'Not specified') label, COUNT(*) value FROM visits WHERE visit_datetime >= ? AND visit_datetime < ? GROUP BY label ORDER BY value DESC", $range)),
                system_report_chart('Visit Status (Current)', system_report_rows($newDb, "SELECT COALESCE(NULLIF(status, ''), 'Not specified') label, COUNT(*) value FROM visits WHERE visit_datetime >= ? AND visit_datetime < ? GROUP BY label ORDER BY value DESC", $range)),
                (static function () use ($newDb, $dateFrom, $dateTo): array {
                    $volume = system_report_visit_volume($newDb, $dateFrom, $dateTo);
                    return system_report_chart($volume['title'], $volume['rows'], 'No visits were recorded during the selected reporting period.', 0, 'line', false, $volume['insight']);
                })(),
                system_report_chart('Visits by Hour', system_report_visit_hours($newDb, $range), 'No visits were recorded during this period.', 0, 'column', true, '', ['hide_zero_rows' => true]),
            ],
            'tables' => [
                system_report_chart('Visit Purpose', system_report_rows($newDb, "SELECT COALESCE(NULLIF(visit_purpose, ''), 'Not specified') label, COUNT(*) value FROM visits WHERE visit_datetime >= ? AND visit_datetime < ? GROUP BY label ORDER BY value DESC", $range)),
            ],
        ];
    }

    if (in_array('ape', $modules, true)) {
        $sections['ape'] = [
            'title' => 'APE Workflow',
            'description' => 'APE examinations, findings, and workflow decisions recorded during the selected period.',
            'metrics' => [
                system_report_metric('APE Examinations', system_report_scalar($newDb, 'SELECT COUNT(*) FROM ape_records WHERE exam_date >= ? AND exam_date < ?', [$dateFrom, (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d')])),
                system_report_metric('APE Findings Recorded', system_report_scalar($newDb, 'SELECT COUNT(*) FROM ape_findings WHERE recorded_at >= ? AND recorded_at < ?', $range)),
                system_report_metric('APE Clearances Approved', system_report_scalar($newDb, "SELECT COUNT(*) FROM ape_activity_logs WHERE action IN ('Cleared patient after APE examination', 'Approved follow-up clearance') AND created_at >= ? AND created_at < ?", $range)),
                system_report_metric('APE Follow-ups Required', system_report_scalar($newDb, "SELECT COUNT(*) FROM ape_activity_logs WHERE action = 'Required follow-up after APE examination' AND created_at >= ? AND created_at < ?", $range)),
            ],
            'charts' => [
                system_report_chart('APE Workflow Events', system_report_rows($newDb, "SELECT CASE WHEN action IN ('Cleared patient after APE examination', 'Approved follow-up clearance') THEN 'Clearance Approved' WHEN action = 'Required follow-up after APE examination' THEN 'Follow-up Required' WHEN action IN ('Recorded APE examination', 'Recorded APE examination and created referral') THEN 'Examination Recorded' WHEN action = 'Resolved clinical follow-up' THEN 'Follow-up Resolved' END label, COUNT(*) value FROM ape_activity_logs WHERE action IN ('Cleared patient after APE examination', 'Approved follow-up clearance', 'Required follow-up after APE examination', 'Recorded APE examination', 'Recorded APE examination and created referral', 'Resolved clinical follow-up') AND created_at >= ? AND created_at < ? GROUP BY label ORDER BY value DESC", $range), 'No APE workflow events were recorded during this period.', 0, 'donut'),
                system_report_chart('APE Finding Outcome', system_report_rows($newDb, "SELECT COALESCE(NULLIF(result_status, ''), 'Not specified') label, COUNT(*) value FROM ape_findings WHERE recorded_at >= ? AND recorded_at < ? GROUP BY label ORDER BY value DESC", $range), 'No APE findings were recorded during this period.', 0, 'donut'),
            ],
            'tables' => [
                system_report_chart('APE Activity', system_report_rows($newDb, 'SELECT action label, COUNT(*) value FROM ape_activity_logs WHERE created_at >= ? AND created_at < ? GROUP BY action ORDER BY value DESC, label', $range), 'No APE activity was recorded during this period.'),
            ],
        ];
    }

    if (in_array('inventory', $modules, true)) {
        $overdueLoans = system_report_scalar($newDb, 'SELECT COUNT(*) FROM equipment_loans WHERE returned_at IS NULL AND due_at < NOW()');
        $sections['inventory'] = [
            'title' => 'Inventory',
            'description' => 'Medicine and equipment stock, dispensing, borrowing, returns, and overdue loans.',
            'metrics' => [
                system_report_metric('Inventory Transactions', system_report_scalar($newDb, 'SELECT COUNT(*) FROM inventory_transactions WHERE created_at >= ? AND created_at < ?', $range)),
                system_report_metric('Medicine Dispensed', system_report_scalar($newDb, 'SELECT COALESCE(SUM(quantity), 0) FROM medicine_dispensings WHERE dispensed_at >= ? AND dispensed_at < ?', $range), 'units'),
                system_report_metric('Equipment Loans', system_report_scalar($newDb, 'SELECT COUNT(*) FROM equipment_loans WHERE borrowed_at >= ? AND borrowed_at < ?', $range), $overdueLoans . ' overdue'),
                system_report_metric('Low Stock Items', system_report_scalar($newDb, 'SELECT COUNT(*) FROM inventory_items WHERE is_active = 1 AND quantity <= reorder_level'), 'current snapshot'),
            ],
            'summary_metrics' => [
                system_report_metric('Overdue Loans', $overdueLoans, 'current snapshot'),
            ],
            'charts' => [
                system_report_chart('Inventory Transactions by Type', system_report_rows($newDb, "SELECT COALESCE(NULLIF(transaction_type, ''), 'Not specified') label, COUNT(*) value FROM inventory_transactions WHERE created_at >= ? AND created_at < ? GROUP BY label ORDER BY value DESC", $range), 'No inventory transactions were recorded for this period.', 0, 'bar'),
                system_report_chart('Status of Loans Issued During Period', system_report_rows($newDb, "SELECT COALESCE(NULLIF(status, ''), 'Not specified') label, COUNT(*) value FROM equipment_loans WHERE borrowed_at >= ? AND borrowed_at < ? GROUP BY label ORDER BY value DESC", $range), 'No equipment loans were issued during this period.', 0, 'donut'),
            ],
            'tables' => [
                system_report_chart('Medicine Dispensed by Item', system_report_rows($newDb, "SELECT i.item_name label, SUM(md.quantity) value FROM medicine_dispensings md JOIN inventory_items i ON i.item_id = md.item_id WHERE md.dispensed_at >= ? AND md.dispensed_at < ? GROUP BY i.item_id, i.item_name ORDER BY value DESC", $range)),
                system_report_chart('Borrowed Equipment', system_report_rows($newDb, "SELECT i.item_name label, SUM(el.quantity) value FROM equipment_loans el JOIN inventory_items i ON i.item_id = el.item_id WHERE el.borrowed_at >= ? AND el.borrowed_at < ? GROUP BY i.item_id, i.item_name ORDER BY value DESC", $range)),
                system_report_chart('Stock Condition (Current Snapshot)', system_report_rows($newDb, "SELECT CASE WHEN quantity = 0 THEN 'Out of Stock' WHEN quantity <= reorder_level THEN 'Low Stock' ELSE 'Healthy Stock' END label, COUNT(*) value FROM inventory_items WHERE is_active = 1 GROUP BY label ORDER BY value DESC")),
            ],
        ];
    }

    if (in_array('referrals', $modules, true)) {
        $sections['referrals'] = [
            'title' => 'Referrals',
            'description' => 'Referrals created during APE, consultations, emergencies, and clinic visits.',
            'metrics' => [
                system_report_metric('Referrals', system_report_scalar($newDb, 'SELECT COUNT(*) FROM referrals WHERE referral_date >= ? AND referral_date < ?', $range)),
                system_report_metric('Period Referrals Currently Completed', system_report_scalar($newDb, "SELECT COUNT(*) FROM referrals WHERE status = 'Completed' AND referral_date >= ? AND referral_date < ?", $range)),
                system_report_metric('Linked to Visit', system_report_scalar($newDb, 'SELECT COUNT(*) FROM referrals WHERE visit_id IS NOT NULL AND referral_date >= ? AND referral_date < ?', $range)),
                system_report_metric('Unique Destinations', system_report_scalar($newDb, 'SELECT COUNT(DISTINCT referred_to) FROM referrals WHERE referral_date >= ? AND referral_date < ?', $range)),
            ],
            'charts' => [
                system_report_chart('Referred To', system_report_rows($newDb, "SELECT COALESCE(NULLIF(referred_to, ''), 'Not specified') label, COUNT(*) value FROM referrals WHERE referral_date >= ? AND referral_date < ? GROUP BY label ORDER BY value DESC", $range)),
            ],
            'tables' => [],
        ];
    }

    if (in_array('alerts', $modules, true)) {
        $sections['alerts'] = [
            'title' => 'Alerts and Incidents',
            'description' => 'Alerts, incidents, emergency risk, passport access, and resolution status.',
            'metrics' => [
                system_report_metric('Alerts', system_report_scalar($newDb, 'SELECT COUNT(*) FROM nurse_alerts WHERE created_at >= ? AND created_at < ?', $range)),
                system_report_metric('Incident Reports', system_report_scalar($newDb, 'SELECT COUNT(*) FROM incident_reports WHERE reported_at >= ? AND reported_at < ?', $range)),
                system_report_metric('Passport Accesses', system_report_scalar($newDb, 'SELECT COUNT(*) FROM passport_access_logs WHERE accessed_at >= ? AND accessed_at < ?', $range)),
                system_report_metric('Resolved', system_report_scalar($newDb, "SELECT COUNT(*) FROM nurse_alerts WHERE status = 'Resolved' AND created_at >= ? AND created_at < ?", $range)),
            ],
            'attention_metrics' => [
                system_report_metric('Pending Alerts', system_report_scalar($newDb, "SELECT COUNT(*) FROM nurse_alerts WHERE status = 'Pending' AND created_at >= ? AND created_at < ?", $range)),
                system_report_metric('High or Critical Risk', system_report_scalar($newDb, "SELECT COUNT(*) FROM nurse_alerts WHERE risk_level IN ('High', 'Critical') AND created_at >= ? AND created_at < ?", $range)),
            ],
            'charts' => [
                system_report_chart('Alert Risk Level', system_report_rows($newDb, "SELECT COALESCE(NULLIF(risk_level, ''), 'Not assessed') label, COUNT(*) value FROM nurse_alerts WHERE created_at >= ? AND created_at < ? GROUP BY label ORDER BY FIELD(label, 'Critical', 'High', 'Moderate', 'Low', 'Not assessed')", $range), 'No alerts were recorded for this period.', 0, 'bar', true, '', ['colors' => ['#b42318', '#d97706', '#d4a72c', '#58a978', '#64748b']]),
                system_report_chart('Passport Access by Day', system_report_passport_access_by_day($newDb, $dateFrom, $dateTo), 'No passport accesses were recorded for this period.', 0, 'line'),
            ],
            'tables' => [],
        ];
    }

    if (in_array('feedback', $modules, true)) {
        $feedbackDimensions = [];
        foreach (['tangibles' => 'Tangibles', 'reliability' => 'Reliability', 'responsiveness' => 'Responsiveness', 'assurance' => 'Assurance', 'empathy' => 'Empathy', 'overall' => 'Overall SERVPERF'] as $column => $label) {
            $feedbackDimensions[] = ['label' => $label, 'value' => system_report_scalar($newDb, "SELECT COALESCE(AVG({$column}), 0) FROM clinic_feedback WHERE submitted_at >= ? AND submitted_at < ?", $range)];
        }
        $excellentResponses = system_report_scalar($newDb, 'SELECT COUNT(*) FROM clinic_feedback WHERE overall >= 6 AND submitted_at >= ? AND submitted_at < ?', $range);
        $satisfactoryResponses = system_report_scalar($newDb, 'SELECT COUNT(*) FROM clinic_feedback WHERE overall >= 4 AND overall < 6 AND submitted_at >= ? AND submitted_at < ?', $range);
        $sections['feedback'] = [
            'title' => 'Clinic Feedback',
            'description' => 'Anonymous student evaluations across five SERVPERF dimensions.',
            'metrics' => [
                system_report_metric('Responses', system_report_scalar($newDb, 'SELECT COUNT(*) FROM clinic_feedback WHERE submitted_at >= ? AND submitted_at < ?', $range), $excellentResponses . ' excellent, ' . $satisfactoryResponses . ' satisfactory'),
                system_report_metric('Average Overall Score', system_report_scalar($newDb, 'SELECT COALESCE(AVG(overall), 0) FROM clinic_feedback WHERE submitted_at >= ? AND submitted_at < ?', $range), 'out of 7', 2),
                system_report_metric('Critical', system_report_scalar($newDb, 'SELECT COUNT(*) FROM clinic_feedback WHERE overall < 4 AND submitted_at >= ? AND submitted_at < ?', $range)),
                system_report_metric('Written Comments', system_report_scalar($newDb, "SELECT COUNT(*) FROM clinic_feedback WHERE NULLIF(TRIM(comments), '') IS NOT NULL AND submitted_at >= ? AND submitted_at < ?", $range)),
            ],
            'charts' => [
                system_report_chart('Average SERVPERF Scores', $feedbackDimensions, 'No feedback was submitted during this period.', 2, 'bar', true, '', ['axis_max' => 7]),
                system_report_chart('Feedback Performance', system_report_rows($newDb, "SELECT CASE WHEN overall >= 6 THEN 'Excellent' WHEN overall >= 4 THEN 'Satisfactory' ELSE 'Critical' END label, COUNT(*) value FROM clinic_feedback WHERE submitted_at >= ? AND submitted_at < ? GROUP BY label ORDER BY FIELD(label, 'Excellent', 'Satisfactory', 'Critical')", $range), 'No feedback was submitted during this period.', 0, 'donut'),
            ],
            'tables' => [
                system_report_chart('All Responses by Service', system_report_rows($newDb, "SELECT COALESCE(NULLIF(CASE WHEN service_type = 'Other' THEN service_other ELSE service_type END, ''), 'Other') label, COUNT(*) value FROM clinic_feedback WHERE submitted_at >= ? AND submitted_at < ? GROUP BY label ORDER BY value DESC, label", $range)),
            ],
        ];
    }

    $metricsByLabel = [];
    foreach ($sections as $section) {
        foreach (array_merge($section['metrics'], $section['summary_metrics'] ?? [], $section['attention_metrics'] ?? []) as $metric) {
            $metricsByLabel[$metric['label']] = $metric;
        }
    }
    $selectedMetric = static function (string $sourceLabel, ?string $displayLabel = null) use ($metricsByLabel): ?array {
        if (!isset($metricsByLabel[$sourceLabel])) return null;
        $metric = $metricsByLabel[$sourceLabel];
        if ($displayLabel !== null) $metric['label'] = $displayLabel;
        return $metric;
    };
    $transactionSummary = [];
    foreach (['Clinical Visits', 'Registered Patients Served', 'Clinical Entries Documented During Period', 'APE Examinations', 'APE Findings Recorded', 'APE Clearances Approved', 'APE Follow-ups Required', 'Approved', 'Inventory Transactions', 'Medicine Dispensed', 'Equipment Loans', 'Referrals', 'Alerts', 'Incident Reports'] as $label) {
        $metric = $selectedMetric($label);
        if ($metric !== null) $transactionSummary[] = $metric;
    }
    $attentionSummary = [];
    if (isset($sections['visits'])) {
        $attentionSummary[] = system_report_metric('Unaddressed Visits', system_report_scalar($newDb, "SELECT COUNT(*) FROM visits WHERE status = 'Unaddressed' AND visit_datetime >= ? AND visit_datetime < ?", $range));
    }
    foreach ([['For Approval', 'Appointments for Approval'], ['For Completion', 'Appointments for Completion'], ['Low Stock Items', null], ['Overdue Loans', null], ['Pending Alerts', null], ['High or Critical Risk', 'High or Critical Alerts']] as [$sourceLabel, $displayLabel]) {
        $metric = $selectedMetric($sourceLabel, $displayLabel);
        if ($metric !== null) $attentionSummary[] = $metric;
    }
    if (isset($sections['referrals'])) {
        $attentionSummary[] = system_report_metric('Period Referrals Not Currently Completed', system_report_scalar($newDb, "SELECT COUNT(*) FROM referrals WHERE COALESCE(status, '') <> 'Completed' AND referral_date >= ? AND referral_date < ?", $range));
    }

    return [
        'title' => 'CLINiQ Clinic Transaction Summary',
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'generated_at' => date('Y-m-d H:i:s'),
        'modules' => $modules,
        'action_summary' => system_report_action_summary($newDb),
        'transaction_summary' => $transactionSummary,
        'attention_summary' => $attentionSummary,
        'sections' => $sections,
    ];
}
