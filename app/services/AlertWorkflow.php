<?php

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
    return [
        'Breathing difficulty',
        'Fainting or unconscious',
        'Injury or fall',
        'Bleeding or wound',
        'Allergic reaction',
        'Fever or illness',
        'Other concern',
    ];
}

function incident_condition_options(): array
{
    return ['Awake and responsive', 'Dizzy or weak', 'Severe pain', 'Seizure-like movement', 'Unconscious'];
}

function incident_breathing_options(): array
{
    return ['Normal', 'Shortness of breath', 'Wheezing', 'Not breathing normally'];
}

function incident_bleeding_options(): array
{
    return ['None observed', 'Minor bleeding', 'Heavy bleeding'];
}

function incident_mobility_options(): array
{
    return ['Can walk', 'Needs assistance', 'Cannot stand or walk'];
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
        $add(4, 'Incident type involves breathing difficulty');
    } elseif (str_contains($incidentType, 'unconscious') || str_contains($incidentType, 'fainting')) {
        $add(4, 'Incident type involves fainting or loss of consciousness');
    } elseif (str_contains($incidentType, 'allergic')) {
        $add(3, 'Incident type involves a possible allergic reaction');
    } elseif (str_contains($incidentType, 'bleeding')) {
        $add(3, 'Incident type involves bleeding or wound care');
    } elseif (str_contains($incidentType, 'injury') || str_contains($incidentType, 'fall')) {
        $add(2, 'Incident type involves injury or fall');
    } elseif (str_contains($incidentType, 'fever') || str_contains($incidentType, 'illness')) {
        $add(1, 'Incident type involves illness symptoms');
    }

    $condition = strtolower((string) ($answers['observed_condition'] ?? ''));
    if (str_contains($condition, 'unconscious')) {
        $add(6, 'Reporter observed unconsciousness');
    } elseif (str_contains($condition, 'seizure')) {
        $add(5, 'Reporter observed seizure-like movement');
    } elseif (str_contains($condition, 'severe pain')) {
        $add(3, 'Reporter observed severe pain');
    } elseif (str_contains($condition, 'dizzy') || str_contains($condition, 'weak')) {
        $add(1, 'Reporter observed dizziness or weakness');
    }

    $breathing = strtolower((string) ($answers['breathing_status'] ?? ''));
    if (str_contains($breathing, 'not breathing')) {
        $add(6, 'Breathing is not normal');
    } elseif (str_contains($breathing, 'shortness')) {
        $add(4, 'Shortness of breath reported');
    } elseif (str_contains($breathing, 'wheezing')) {
        $add(3, 'Wheezing reported');
    }

    $bleeding = strtolower((string) ($answers['bleeding_status'] ?? ''));
    if (str_contains($bleeding, 'heavy')) {
        $add(5, 'Heavy bleeding reported');
    } elseif (str_contains($bleeding, 'minor')) {
        $add(1, 'Minor bleeding reported');
    }

    $mobility = strtolower((string) ($answers['mobility_status'] ?? ''));
    if (str_contains($mobility, 'cannot')) {
        $add(3, 'Student cannot stand or walk');
    } elseif (str_contains($mobility, 'assistance')) {
        $add(1, 'Student needs assistance moving');
    }

    if (preg_match('/\b([0-9]|10)\b/', (string) ($answers['pain_level'] ?? ''), $match)) {
        $pain = (int) $match[1];
        if ($pain >= 7) {
            $add(3, 'Severe pain level reported');
        } elseif ($pain >= 4) {
            $add(1, 'Moderate pain level reported');
        }
    }

    $keywordGroups = [
        5 => ['not breathing', 'unconscious', 'seizure', 'severe bleeding', 'chest pain', 'anaphylaxis', 'collapsed', 'blue lips'],
        3 => ['difficulty breathing', 'shortness of breath', 'wheezing', 'fainted', 'fainting', 'head injury', 'heavy bleeding', 'fracture', 'asthma', 'allergic reaction'],
        1 => ['dizzy', 'dizziness', 'vomiting', 'fever', 'sprain', 'cut', 'wound', 'weak', 'pain'],
    ];
    foreach ($keywordGroups as $points => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                $add($points, 'Keyword indicator found: ' . $keyword);
                break;
            }
        }
    }

    if ($score >= 10) {
        $level = 'Critical';
    } elseif ($score >= 6) {
        $level = 'High';
    } elseif ($score >= 2) {
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
    return match ($level) {
        'Critical' => 'Immediate response needed. Bring emergency kit, prioritize airway/breathing/circulation, call clinic support, notify guardian, and prepare referral or emergency transfer if needed.',
        'High' => 'Urgent nurse response needed. Go to the reported location, check vital signs, give appropriate first aid, monitor closely, and prepare referral if symptoms worsen.',
        'Moderate' => 'Prompt clinic assessment needed. Assist the student to the clinic when safe, provide first aid, observe symptoms, and document the response.',
        default => 'Routine response. Verify the student condition, provide basic assistance, and continue monitoring until the concern is closed.',
    };
}
