<?php

function default_risk_settings(): array
{
    return [
        'fever_temp' => 37.8,
        'fever_points' => 1,
        'high_fever_temp' => 39.0,
        'high_fever_points' => 3,
        'pulse_low' => 50,
        'pulse_high' => 120,
        'pulse_points' => 2,
        'bp_high_systolic' => 140,
        'bp_high_diastolic' => 90,
        'bp_high_points' => 2,
        'bp_low_systolic' => 90,
        'bp_low_diastolic' => 60,
        'bp_low_points' => 3,
        'bp_critical_systolic' => 180,
        'bp_critical_diastolic' => 120,
        'bp_critical_points' => 4,
        'critical_symptom_points' => 4,
        'critical_keywords' => ['chest pain', 'difficulty breathing', 'fainting', 'seizure', 'severe bleeding'],
        'moderate_min' => 2,
        'high_min' => 4,
        'critical_min' => 7,
        'auto_alert_major_risk' => true,
    ];
}

function ensure_system_settings_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    db()->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(120) PRIMARY KEY,
            setting_value MEDIUMTEXT NOT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        )
    ");

    $ready = true;
}

function risk_settings_key(): string
{
    return 'risk.classification';
}

function risk_settings(): array
{
    $defaults = default_risk_settings();

    try {
        ensure_system_settings_schema();
        $stmt = db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([risk_settings_key()]);
        $raw = $stmt->fetchColumn();
        if (!$raw) {
            return $defaults;
        }

        $saved = json_decode((string) $raw, true);
        if (!is_array($saved)) {
            return $defaults;
        }

        $settings = array_merge($defaults, $saved);
        $settings['critical_keywords'] = risk_keywords_from_value($settings['critical_keywords'] ?? $defaults['critical_keywords']);
        $settings['auto_alert_major_risk'] = filter_var($settings['auto_alert_major_risk'] ?? true, FILTER_VALIDATE_BOOLEAN);

        return $settings;
    } catch (Throwable $e) {
        return $defaults;
    }
}

function save_risk_settings(array $settings, ?int $updatedBy = null): void
{
    ensure_system_settings_schema();

    $normalized = normalize_risk_settings($settings);
    $stmt = db()->prepare('
        INSERT INTO system_settings (setting_key, setting_value, updated_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
    ');
    $stmt->execute([
        risk_settings_key(),
        json_encode($normalized),
        $updatedBy,
    ]);
}

function normalize_risk_settings(array $input): array
{
    $defaults = default_risk_settings();
    $numberFields = [
        'fever_temp', 'fever_points', 'high_fever_temp', 'high_fever_points',
        'pulse_low', 'pulse_high', 'pulse_points',
        'bp_high_systolic', 'bp_high_diastolic', 'bp_high_points',
        'bp_low_systolic', 'bp_low_diastolic', 'bp_low_points',
        'bp_critical_systolic', 'bp_critical_diastolic', 'bp_critical_points',
        'critical_symptom_points', 'moderate_min', 'high_min', 'critical_min',
    ];

    $settings = $defaults;
    foreach ($numberFields as $field) {
        $raw = $input[$field] ?? $defaults[$field];
        $settings[$field] = is_numeric($raw) ? (float) $raw : $defaults[$field];
    }

    foreach ([
        'fever_points', 'high_fever_points', 'pulse_points',
        'bp_high_points', 'bp_low_points', 'bp_critical_points',
        'critical_symptom_points', 'moderate_min', 'high_min', 'critical_min',
    ] as $integerField) {
        $settings[$integerField] = max(0, (int) round($settings[$integerField]));
    }

    $settings['critical_keywords'] = risk_keywords_from_value($input['critical_keywords'] ?? $defaults['critical_keywords']);
    $settings['auto_alert_major_risk'] = !empty($input['auto_alert_major_risk']);

    if ($settings['high_fever_temp'] < $settings['fever_temp']) {
        $settings['high_fever_temp'] = $settings['fever_temp'];
    }
    if ($settings['pulse_high'] <= $settings['pulse_low']) {
        $settings['pulse_high'] = $defaults['pulse_high'];
        $settings['pulse_low'] = $defaults['pulse_low'];
    }
    if ($settings['high_min'] < $settings['moderate_min']) {
        $settings['high_min'] = $settings['moderate_min'];
    }
    if ($settings['critical_min'] < $settings['high_min']) {
        $settings['critical_min'] = $settings['high_min'];
    }

    return $settings;
}

function risk_keywords_from_value(mixed $value): array
{
    if (is_array($value)) {
        $keywords = $value;
    } else {
        $keywords = preg_split('/[\r\n,]+/', (string) $value) ?: [];
    }

    $keywords = array_map(
        fn($keyword): string => mb_strtolower(trim((string) $keyword)),
        $keywords
    );
    $keywords = array_values(array_unique(array_filter($keywords)));

    return $keywords ?: default_risk_settings()['critical_keywords'];
}

function risk_keywords_text(array $settings): string
{
    return implode("\n", risk_keywords_from_value($settings['critical_keywords'] ?? []));
}
