<?php

function patient_category_label(?string $value): string
{
    $normalized = strtolower(trim((string) $value));

    return match ($normalized) {
        'student', 'students' => 'Students',
        'faculty' => 'Faculty',
        'non-teaching personnel', 'ntp', 'personnel' => 'NTP',
        'clinic staff', 'staff' => 'Clinic Staff',
        default => 'Guest',
    };
}
