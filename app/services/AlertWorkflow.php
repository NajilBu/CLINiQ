<?php

require_once __DIR__ . '/RiskSettings.php';

function ensure_alert_workflow_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $db = db();
    $stmt = $db->query('SHOW COLUMNS FROM nurse_alerts');
    $columns = [];
    foreach ($stmt->fetchAll() as $column) {
        $columns[$column['Field']] = $column;
    }

    $addColumn = function (string $name, string $definition) use ($db, &$columns): void {
        if (!isset($columns[$name])) {
            $db->exec("ALTER TABLE nurse_alerts ADD COLUMN {$name} {$definition}");
            $columns[$name] = ['Field' => $name];
        }
    };

    $addColumn('photo_path', 'VARCHAR(255) NULL AFTER details');
    $addColumn('incident_type', 'VARCHAR(120) NULL AFTER concern');
    $addColumn('report_answers', 'MEDIUMTEXT NULL AFTER details');
    $addColumn('risk_level', "ENUM('Low','Moderate','High','Critical') NOT NULL DEFAULT 'Low' AFTER report_answers");
    $addColumn('risk_score', 'INT NOT NULL DEFAULT 0 AFTER risk_level');
    $addColumn('risk_reasons', 'TEXT NULL AFTER risk_score');
    $addColumn('response_guidance', 'TEXT NULL AFTER risk_reasons');
    $addColumn('resolution_report', 'TEXT NULL AFTER status');
    $addColumn('resolved_by', 'INT NULL AFTER resolution_report');
    $addColumn('resolved_at', 'DATETIME NULL AFTER resolved_by');

    $ready = true;
}

function save_alert_photo_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        flash_message('error', 'The alert photo could not be uploaded.');
        return null;
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        flash_message('error', 'Alert photo must be 5MB or smaller.');
        return null;
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        flash_message('error', 'Alert photo must be a JPG, PNG, or WebP image.');
        return null;
    }

    $uploadDir = dirname(__DIR__, 2) . '/public/uploads/alerts';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $filename = 'alert-' . date('Ymd-His') . '-' . bin2hex(random_bytes(5)) . '.' . $extension;
    $target = $uploadDir . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        flash_message('error', 'The alert photo could not be saved.');
        return null;
    }

    return 'uploads/alerts/' . $filename;
}

function incident_type_options(): array
{
    return dropdown_options('incident_type');
}

function incident_condition_options(): array
{
    return dropdown_options('incident_condition');
}

function incident_breathing_options(): array
{
    return dropdown_options('incident_breathing');
}

function incident_bleeding_options(): array
{
    return dropdown_options('incident_bleeding');
}

function incident_mobility_options(): array
{
    return dropdown_options('incident_mobility');
}

function incident_pain_level_options(): array
{
    return dropdown_options('incident_pain_level');
}

function collect_incident_report_answers(array $source): array
{
    return [
        'incident_type' => trim((string) ($source['incident_type'] ?? '')),
        'observed_condition' => trim((string) ($source['observed_condition'] ?? '')),
        'breathing_status' => trim((string) ($source['breathing_status'] ?? '')),
        'bleeding_status' => trim((string) ($source['bleeding_status'] ?? '')),
        'pain_level' => trim((string) ($source['pain_level'] ?? '')),
        'mobility_status' => trim((string) ($source['mobility_status'] ?? '')),
        'notes' => trim((string) ($source['notes'] ?? $source['incident_notes'] ?? $source['details'] ?? '')),
        'concern' => trim((string) ($source['concern'] ?? '')),
    ];
}

function incident_report_answers_text(array $answers): string
{
    $labels = [
        'incident_type' => 'Incident type',
        'observed_condition' => 'Observed condition',
        'breathing_status' => 'Breathing',
        'bleeding_status' => 'Bleeding',
        'pain_level' => 'Pain level',
        'mobility_status' => 'Mobility',
        'notes' => 'Reporter notes',
    ];

    $lines = [];
    foreach ($labels as $key => $label) {
        $value = trim((string) ($answers[$key] ?? ''));
        if ($value !== '') {
            $lines[] = $label . ': ' . $value;
        }
    }

    return $lines ? implode("\n", $lines) : 'No structured incident answers were submitted.';
}

function classify_reported_incident(array $answers): array
{
    $settings = risk_settings();
    $score = 0;
    $reasons = [];

    $text = strtolower(implode(' ', array_filter([
        $answers['incident_type'] ?? '',
        $answers['observed_condition'] ?? '',
        $answers['breathing_status'] ?? '',
        $answers['bleeding_status'] ?? '',
        $answers['mobility_status'] ?? '',
        $answers['pain_level'] ?? '',
        $answers['notes'] ?? '',
        $answers['concern'] ?? '',
    ])));

    $add = function (int $points, string $reason) use (&$score, &$reasons): void {
        $score += $points;
        $reasons[] = $reason . ' (+' . $points . ')';
    };

    $incidentType = strtolower((string) ($answers['incident_type'] ?? ''));
    if (str_contains($incidentType, 'breathing')) {
        $add((int) $settings['incident_type_breathing_points'], 'Incident type involves breathing difficulty');
    } elseif (str_contains($incidentType, 'unconscious') || str_contains($incidentType, 'fainting')) {
        $add((int) $settings['incident_type_unconscious_points'], 'Incident type involves fainting or loss of consciousness');
    } elseif (str_contains($incidentType, 'allergic')) {
        $add((int) $settings['incident_type_allergic_points'], 'Incident type involves a possible allergic reaction');
    } elseif (str_contains($incidentType, 'bleeding')) {
        $add((int) $settings['incident_type_bleeding_points'], 'Incident type involves bleeding or wound care');
    } elseif (str_contains($incidentType, 'injury') || str_contains($incidentType, 'fall')) {
        $add((int) $settings['incident_type_injury_points'], 'Incident type involves injury or fall');
    } elseif (str_contains($incidentType, 'fever') || str_contains($incidentType, 'illness')) {
        $add((int) $settings['incident_type_illness_points'], 'Incident type involves illness symptoms');
    }

    $condition = strtolower((string) ($answers['observed_condition'] ?? ''));
    if (str_contains($condition, 'unconscious')) {
        $add((int) $settings['condition_unconscious_points'], 'Reporter observed unconsciousness');
    } elseif (str_contains($condition, 'seizure')) {
        $add((int) $settings['condition_seizure_points'], 'Reporter observed seizure-like movement');
    } elseif (str_contains($condition, 'severe pain')) {
        $add((int) $settings['condition_severe_pain_points'], 'Reporter observed severe pain');
    } elseif (str_contains($condition, 'dizzy') || str_contains($condition, 'weak')) {
        $add((int) $settings['condition_dizzy_weak_points'], 'Reporter observed dizziness or weakness');
    }

    $breathing = strtolower((string) ($answers['breathing_status'] ?? ''));
    if (str_contains($breathing, 'not breathing')) {
        $add((int) $settings['breathing_not_normal_points'], 'Breathing is not normal');
    } elseif (str_contains($breathing, 'shortness')) {
        $add((int) $settings['breathing_shortness_points'], 'Shortness of breath reported');
    } elseif (str_contains($breathing, 'wheezing')) {
        $add((int) $settings['breathing_wheezing_points'], 'Wheezing reported');
    }

    $bleeding = strtolower((string) ($answers['bleeding_status'] ?? ''));
    if (str_contains($bleeding, 'heavy')) {
        $add((int) $settings['bleeding_heavy_points'], 'Heavy bleeding reported');
    } elseif (str_contains($bleeding, 'minor')) {
        $add((int) $settings['bleeding_minor_points'], 'Minor bleeding reported');
    }

    $mobility = strtolower((string) ($answers['mobility_status'] ?? ''));
    if (str_contains($mobility, 'cannot')) {
        $add((int) $settings['mobility_cannot_points'], 'Student cannot stand or walk');
    } elseif (str_contains($mobility, 'assistance')) {
        $add((int) $settings['mobility_assistance_points'], 'Student needs assistance moving');
    }

    if (preg_match('/\b([0-9]|10)\b/', (string) ($answers['pain_level'] ?? ''), $match)) {
        $pain = (int) $match[1];
        if ($pain >= 7) {
            $add((int) $settings['pain_severe_points'], 'Severe pain level reported');
        } elseif ($pain >= 4) {
            $add((int) $settings['pain_moderate_points'], 'Moderate pain level reported');
        }
    }

    $keywordGroups = [
        [(int) $settings['critical_keyword_points'], risk_keywords_from_value($settings['critical_keywords'] ?? [], default_risk_settings()['critical_keywords'])],
        [(int) $settings['urgent_keyword_points'], risk_keywords_from_value($settings['urgent_keywords'] ?? [], default_risk_settings()['urgent_keywords'])],
        [(int) $settings['minor_keyword_points'], risk_keywords_from_value($settings['minor_keywords'] ?? [], default_risk_settings()['minor_keywords'])],
    ];
    foreach ($keywordGroups as [$points, $keywords]) {
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                $add($points, 'Keyword indicator found: ' . $keyword);
                break;
            }
        }
    }

    if ($score >= (int) $settings['critical_min']) {
        $level = 'Critical';
    } elseif ($score >= (int) $settings['high_min']) {
        $level = 'High';
    } elseif ($score >= (int) $settings['moderate_min']) {
        $level = 'Moderate';
    } else {
        $level = 'Low';
    }

    return [
        'level' => $level,
        'score' => $score,
        'reasons' => $reasons,
        'guidance' => incident_response_guidance($level),
    ];
}

function incident_risk_reasons_text(array $classification): string
{
    $reasons = array_filter(array_map('trim', $classification['reasons'] ?? []));

    return $reasons ? implode("\n", $reasons) : 'No urgent incident indicators were detected from the submitted answers.';
}

function incident_response_guidance(string $level): string
{
    $settings = risk_settings();

    return match ($level) {
        'Critical' => (string) $settings['guidance_critical'],
        'High' => (string) $settings['guidance_high'],
        'Moderate' => (string) $settings['guidance_moderate'],
        default => (string) $settings['guidance_low'],
    };
}
