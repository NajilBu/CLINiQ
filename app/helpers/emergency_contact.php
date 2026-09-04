<?php

function cliniq_format_person_name(string $name): string
{
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
    return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
}

function cliniq_format_ph_mobile(string $number): ?string
{
    $digits = preg_replace('/\D+/', '', trim($number)) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }

    if (!preg_match('/^9\d{9}$/', $digits)) {
        return null;
    }

    return sprintf('+63 %s %s %s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
}

function cliniq_validate_emergency_contact(array $input, array $allowedRelationships = []): array
{
    $guardianName = cliniq_format_person_name((string) ($input['guardian_name'] ?? ''));
    $relationship = trim((string) ($input['relationship'] ?? ''));
    $primaryRaw = trim((string) ($input['primary_contact'] ?? ''));
    $secondaryRaw = trim((string) ($input['secondary_contact'] ?? ''));

    if ($guardianName === '') {
        throw new InvalidArgumentException('Enter the guardian or next-of-kin name.');
    }
    if (mb_strlen($guardianName) < 2 || mb_strlen($guardianName) > 100
        || !preg_match("/^[\p{L}\p{M}][\p{L}\p{M} .'-]{1,99}$/u", $guardianName)) {
        throw new InvalidArgumentException('Enter a valid guardian name using letters, spaces, apostrophes, periods, or hyphens.');
    }
    if ($relationship === '') {
        throw new InvalidArgumentException('Select the guardian relationship.');
    }
    if ($allowedRelationships !== [] && !in_array($relationship, $allowedRelationships, true)) {
        throw new InvalidArgumentException('Select a valid guardian relationship.');
    }

    $primaryContact = cliniq_format_ph_mobile($primaryRaw);
    if ($primaryContact === null) {
        throw new InvalidArgumentException('Enter a valid Philippine mobile number, such as +63 912 345 6789.');
    }

    $secondaryContact = null;
    if ($secondaryRaw !== '') {
        $secondaryContact = cliniq_format_ph_mobile($secondaryRaw);
        if ($secondaryContact === null) {
            throw new InvalidArgumentException('Enter a valid secondary Philippine mobile number or leave it blank.');
        }
        if ($secondaryContact === $primaryContact) {
            throw new InvalidArgumentException('The secondary contact number must be different from the primary number.');
        }
    }

    return [
        'guardian_name' => $guardianName,
        'relationship' => $relationship,
        'primary_contact' => $primaryContact,
        'secondary_contact' => $secondaryContact,
    ];
}
