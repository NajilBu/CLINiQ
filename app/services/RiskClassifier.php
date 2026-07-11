<?php

require_once __DIR__ . '/RiskSettings.php';

function classify_patient_risk(array $data): array
{
    $settings = risk_settings();
    $score = 0;
    $reasons = [];

    $temperature = isset($data['temperature']) ? (float) $data['temperature'] : null;
    $pulse = isset($data['pulse_rate']) ? (int) $data['pulse_rate'] : null;
    $bloodPressure = trim((string) ($data['blood_pressure'] ?? ''));
    $symptoms = strtolower((string) ($data['symptoms'] ?? ''));

    if ($temperature !== null && $temperature >= (float) $settings['high_fever_temp']) {
        $score += (int) $settings['high_fever_points'];
        $reasons[] = 'High fever: temperature is ' . risk_number($settings['high_fever_temp']) . ' C or higher (+' . (int) $settings['high_fever_points'] . ')';
    } elseif ($temperature !== null && $temperature >= (float) $settings['fever_temp']) {
        $score += (int) $settings['fever_points'];
        $reasons[] = 'Fever: temperature is ' . risk_number($settings['fever_temp']) . ' C or higher (+' . (int) $settings['fever_points'] . ')';
    }

    if ($pulse !== null && ($pulse > (int) $settings['pulse_high'] || $pulse < (int) $settings['pulse_low'])) {
        $score += (int) $settings['pulse_points'];
        $reasons[] = 'Abnormal pulse rate: pulse is above ' . (int) $settings['pulse_high'] . ' BPM or below ' . (int) $settings['pulse_low'] . ' BPM (+' . (int) $settings['pulse_points'] . ')';
    }

    if ($bloodPressure !== '' && preg_match('/(\d{2,3})\s*\/\s*(\d{2,3})/', $bloodPressure, $matches)) {
        $systolic = (int) $matches[1];
        $diastolic = (int) $matches[2];

        if ($systolic >= (int) $settings['bp_critical_systolic'] || $diastolic >= (int) $settings['bp_critical_diastolic']) {
            $score += (int) $settings['bp_critical_points'];
            $reasons[] = 'Severely elevated blood pressure: ' . (int) $settings['bp_critical_systolic'] . '/' . (int) $settings['bp_critical_diastolic'] . ' mmHg or higher (+' . (int) $settings['bp_critical_points'] . ')';
        } elseif ($systolic < (int) $settings['bp_low_systolic'] || $diastolic < (int) $settings['bp_low_diastolic']) {
            $score += (int) $settings['bp_low_points'];
            $reasons[] = 'Low blood pressure: below ' . (int) $settings['bp_low_systolic'] . '/' . (int) $settings['bp_low_diastolic'] . ' mmHg (+' . (int) $settings['bp_low_points'] . ')';
        } elseif ($systolic >= (int) $settings['bp_high_systolic'] || $diastolic >= (int) $settings['bp_high_diastolic']) {
            $score += (int) $settings['bp_high_points'];
            $reasons[] = 'Elevated blood pressure: ' . (int) $settings['bp_high_systolic'] . '/' . (int) $settings['bp_high_diastolic'] . ' mmHg or higher (+' . (int) $settings['bp_high_points'] . ')';
        }
    }

    foreach (risk_keywords_from_value($settings['critical_keywords'] ?? []) as $criticalSymptom) {
        if (str_contains($symptoms, $criticalSymptom)) {
            $score += (int) $settings['critical_symptom_points'];
            $reasons[] = ucfirst($criticalSymptom) . ': critical symptom keyword found (+' . (int) $settings['critical_symptom_points'] . ')';
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
        'score' => $score,
        'level' => $level,
        'reasons' => $reasons,
    ];
}

function risk_reasons_text(array $risk): string
{
    $reasons = array_filter(array_map('trim', $risk['reasons'] ?? []));

    return $reasons
        ? implode("\n", $reasons)
        : 'No configured high-risk indicators were detected from the submitted symptoms and vitals.';
}

function risk_classification_basis(): array
{
    $settings = risk_settings();

    return [
        'Temperature ' . risk_number($settings['fever_temp']) . ' C or higher = +' . (int) $settings['fever_points'] . '; ' . risk_number($settings['high_fever_temp']) . ' C or higher = +' . (int) $settings['high_fever_points'],
        'Pulse above ' . (int) $settings['pulse_high'] . ' BPM or below ' . (int) $settings['pulse_low'] . ' BPM = +' . (int) $settings['pulse_points'],
        'Blood pressure ' . (int) $settings['bp_high_systolic'] . '/' . (int) $settings['bp_high_diastolic'] . ' mmHg or higher = +' . (int) $settings['bp_high_points'],
        'Blood pressure below ' . (int) $settings['bp_low_systolic'] . '/' . (int) $settings['bp_low_diastolic'] . ' mmHg = +' . (int) $settings['bp_low_points'],
        'Blood pressure ' . (int) $settings['bp_critical_systolic'] . '/' . (int) $settings['bp_critical_diastolic'] . ' mmHg or higher = +' . (int) $settings['bp_critical_points'],
        'Critical symptom keyword = +' . (int) $settings['critical_symptom_points'] . ' each: ' . implode(', ', risk_keywords_from_value($settings['critical_keywords'] ?? [])),
        'Score 0-' . max(0, (int) $settings['moderate_min'] - 1) . ' = Low; ' . (int) $settings['moderate_min'] . '-' . max((int) $settings['moderate_min'], (int) $settings['high_min'] - 1) . ' = Moderate; ' . (int) $settings['high_min'] . '-' . max((int) $settings['high_min'], (int) $settings['critical_min'] - 1) . ' = High; ' . (int) $settings['critical_min'] . ' or higher = Critical',
    ];
}

function risk_number(float|int|string $value): string
{
    $number = (float) $value;
    return rtrim(rtrim(number_format($number, 1, '.', ''), '0'), '.');
}

function risk_escalation_guidance(string $level): string
{
    return match ($level) {
        'Critical' => 'Critical cases must be treated as immediate-response cases. Staff should prioritize the patient, document actions, and prepare an incident or referral report when needed.',
        'High' => 'High-risk cases should be prioritized for nurse assessment, close monitoring, treatment documentation, and possible referral or incident reporting.',
        'Moderate' => 'Moderate-risk cases should be assessed and monitored by clinic staff before completion.',
        default => 'Low-risk cases follow the regular clinic assessment workflow unless staff observations require escalation.',
    };
}
