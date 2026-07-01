<?php

function classify_patient_risk(array $data): array
{
    $score = 0;
    $reasons = [];

    $temperature = isset($data['temperature']) ? (float) $data['temperature'] : null;
    $pulse = isset($data['pulse_rate']) ? (int) $data['pulse_rate'] : null;
    $symptoms = strtolower((string) ($data['symptoms'] ?? ''));

    if ($temperature !== null && $temperature >= 39.0) {
        $score += 3;
        $reasons[] = 'High fever';
    } elseif ($temperature !== null && $temperature >= 37.8) {
        $score += 1;
        $reasons[] = 'Fever';
    }

    if ($pulse !== null && ($pulse > 120 || $pulse < 50)) {
        $score += 2;
        $reasons[] = 'Abnormal pulse rate';
    }

    foreach (['chest pain', 'difficulty breathing', 'fainting', 'seizure', 'severe bleeding'] as $criticalSymptom) {
        if (str_contains($symptoms, $criticalSymptom)) {
            $score += 4;
            $reasons[] = ucfirst($criticalSymptom);
        }
    }

    if ($score >= 7) {
        $level = 'Critical';
    } elseif ($score >= 4) {
        $level = 'High';
    } elseif ($score >= 2) {
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
