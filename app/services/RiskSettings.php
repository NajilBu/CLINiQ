<?php

require_once __DIR__ . '/SystemSettings.php';

function default_risk_settings(): array
{
    return [
        'incident_type_breathing_points' => 4,
        'incident_type_unconscious_points' => 4,
        'incident_type_allergic_points' => 3,
        'incident_type_bleeding_points' => 3,
        'incident_type_injury_points' => 2,
        'incident_type_illness_points' => 1,
        'condition_unconscious_points' => 6,
        'condition_seizure_points' => 5,
        'condition_severe_pain_points' => 3,
        'condition_dizzy_weak_points' => 1,
        'breathing_not_normal_points' => 6,
        'breathing_shortness_points' => 4,
        'breathing_wheezing_points' => 3,
        'bleeding_heavy_points' => 5,
        'bleeding_minor_points' => 1,
        'mobility_cannot_points' => 3,
        'mobility_assistance_points' => 1,
        'pain_severe_points' => 3,
        'pain_moderate_points' => 1,
        'critical_keyword_points' => 5,
        'urgent_keyword_points' => 3,
        'minor_keyword_points' => 1,
        'critical_keywords' => ['not breathing', 'unconscious', 'seizure', 'severe bleeding', 'chest pain', 'anaphylaxis', 'collapsed', 'blue lips'],
        'urgent_keywords' => ['difficulty breathing', 'shortness of breath', 'wheezing', 'fainted', 'fainting', 'head injury', 'heavy bleeding', 'fracture', 'asthma', 'allergic reaction'],
        'minor_keywords' => ['dizzy', 'dizziness', 'vomiting', 'fever', 'sprain', 'cut', 'wound', 'weak', 'pain'],
        'moderate_min' => 2,
        'high_min' => 6,
        'critical_min' => 10,
        'guidance_low' => 'Routine response. Verify the student condition, provide basic assistance, and continue monitoring until the concern is closed.',
        'guidance_moderate' => 'Prompt clinic assessment needed. Assist the student to the clinic when safe, provide first aid, observe symptoms, and document the response.',
        'guidance_high' => 'Urgent nurse response needed. Go to the reported location, check vital signs, give appropriate first aid, monitor closely, and prepare referral if symptoms worsen.',
        'guidance_critical' => 'Immediate response needed. Bring emergency kit, prioritize airway/breathing/circulation, call clinic support, notify guardian, and prepare referral or emergency transfer if needed.',
    ];
}

function risk_settings_key(): string
{
    return 'incident.risk.classification';
}

function risk_settings(): array
{
    $defaults = default_risk_settings();
    $settings = cliniq_setting_read(risk_settings_key(), $defaults);
    return normalize_risk_settings($settings);
}

function save_risk_settings(array $settings, ?int $updatedBy = null): void
{
    cliniq_setting_write(risk_settings_key(), normalize_risk_settings($settings), $updatedBy);
}

function normalize_risk_settings(array $input): array
{
    $defaults = default_risk_settings();
    $settings = $defaults;

    $numberFields = [
        'incident_type_breathing_points',
        'incident_type_unconscious_points',
        'incident_type_allergic_points',
        'incident_type_bleeding_points',
        'incident_type_injury_points',
        'incident_type_illness_points',
        'condition_unconscious_points',
        'condition_seizure_points',
        'condition_severe_pain_points',
        'condition_dizzy_weak_points',
        'breathing_not_normal_points',
        'breathing_shortness_points',
        'breathing_wheezing_points',
        'bleeding_heavy_points',
        'bleeding_minor_points',
        'mobility_cannot_points',
        'mobility_assistance_points',
        'pain_severe_points',
        'pain_moderate_points',
        'critical_keyword_points',
        'urgent_keyword_points',
        'minor_keyword_points',
        'moderate_min',
        'high_min',
        'critical_min',
    ];

    foreach ($numberFields as $field) {
        $raw = $input[$field] ?? $defaults[$field];
        $settings[$field] = max(0, (int) round(is_numeric($raw) ? (float) $raw : (float) $defaults[$field]));
    }

    foreach (['critical_keywords', 'urgent_keywords', 'minor_keywords'] as $field) {
        $settings[$field] = risk_keywords_from_value($input[$field] ?? $defaults[$field], $defaults[$field]);
    }

    foreach (['guidance_low', 'guidance_moderate', 'guidance_high', 'guidance_critical'] as $field) {
        $value = trim((string) ($input[$field] ?? $defaults[$field]));
        $settings[$field] = $value !== '' ? mb_substr($value, 0, 500) : $defaults[$field];
    }

    if ($settings['high_min'] < $settings['moderate_min']) {
        $settings['high_min'] = $settings['moderate_min'];
    }
    if ($settings['critical_min'] < $settings['high_min']) {
        $settings['critical_min'] = $settings['high_min'];
    }

    return $settings;
}

function risk_keywords_from_value(mixed $value, array $fallback = []): array
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

    return $keywords ?: $fallback;
}

function risk_keywords_text(array $settings, string $key = 'critical_keywords'): string
{
    $defaults = default_risk_settings();
    return implode("\n", risk_keywords_from_value($settings[$key] ?? [], $defaults[$key] ?? []));
}
