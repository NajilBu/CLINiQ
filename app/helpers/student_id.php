<?php

const ID_NUMBER_FORMAT_LABEL = 'a student ID such as 01-00000 or an exactly seven-digit ID such as 0000000';
const ID_NUMBER_REGEX = '/^(?:\d{7}|\d{2}-\d{5})$/';
const STUDENT_ID_NUMBER_REGEX = '/^(?:0[1-9]|[1-9]\d)-\d{5}$/';
const ID_NUMBER_HTML_PATTERN = '(?:[0-9]{7}|[0-9]{2}-[0-9]{5})';

function normalize_id_number(?string $idNumber): string
{
    $value = strtoupper(trim((string) $idNumber));
    $compact = preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    if ($compact === '') {
        return '';
    }
    if (str_contains($value, '-')) {
        return $value;
    }
    return $compact;
}

function normalize_student_id_number(?string $idNumber): string
{
    $value = trim((string) $idNumber);
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if ($digits === '') {
        return '';
    }
    if (str_contains($value, '-')) {
        return $value;
    }
    if (strlen($digits) <= 2 || str_starts_with($digits, '00')) {
        return $digits;
    }
    return substr($digits, 0, 2) . '-' . substr($digits, 2, 5);
}

function is_valid_id_number(?string $idNumber): bool
{
    return preg_match(ID_NUMBER_REGEX, normalize_id_number($idNumber)) === 1;
}

function is_valid_student_id_number(?string $idNumber): bool
{
    return preg_match(STUDENT_ID_NUMBER_REGEX, normalize_student_id_number($idNumber)) === 1;
}

function visit_id_number_candidates(string $idNumber, bool $allowCompactStudentFallback = false): array
{
    $normalized = normalize_id_number($idNumber);
    if (!$allowCompactStudentFallback || preg_match('/^\d{7}$/', $normalized) !== 1 || str_starts_with($normalized, '00')) {
        return [$normalized, $normalized];
    }

    return [$normalized, normalize_student_id_number($normalized)];
}

function id_number_validation_message(string $subject = 'ID Number'): string
{
    return $subject . ' is required and must contain ' . ID_NUMBER_FORMAT_LABEL . '.';
}
