<?php

const ID_NUMBER_FORMAT_LABEL = 'a student ID such as 23-00262 or a prefixed ID such as FAC-0001';
const ID_NUMBER_REGEX = '/^(?:\d{2}-\d{5}|[A-Z]{3,10}-\d{4,10})$/';
const ID_NUMBER_HTML_PATTERN = '(?:[0-9]{2}-[0-9]{5}|[A-Za-z]{3,10}-[0-9]{4,10})';

function normalize_id_number(?string $idNumber): string
{
    $compact = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $idNumber))) ?? '';
    if ($compact === '') {
        return '';
    }
    if (ctype_digit($compact)) {
        $digits = $compact;
        return strlen($digits) <= 2 ? $digits : substr($digits, 0, 2) . '-' . substr($digits, 2);
    }
    if (preg_match('/^([A-Z]{0,10})(\d{0,10})$/', $compact, $parts) !== 1) {
        return $compact;
    }
    return $parts[2] !== '' ? $parts[1] . '-' . $parts[2] : $parts[1];
}

function is_valid_id_number(?string $idNumber): bool
{
    return preg_match(ID_NUMBER_REGEX, normalize_id_number($idNumber)) === 1;
}

function id_number_validation_message(string $subject = 'ID Number'): string
{
    return $subject . ' is required and must contain ' . ID_NUMBER_FORMAT_LABEL . '.';
}
