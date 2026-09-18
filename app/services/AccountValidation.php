<?php

declare(strict_types=1);

const CLINIQ_ACCOUNT_EMAIL_DOMAIN = 'plpasig.edu.ph';

function account_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function account_valid_person_name(string $value): bool
{
    $value = trim($value);
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

    return $length >= 1
        && $length <= 100
        && preg_match("/^[\\p{L} .'-]+$/u", $value) === 1;
}

function account_assert_valid_birthdate(string $value): void
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $today = new DateTimeImmutable('today');
    $oldest = $today->modify('-120 years');

    if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value || $date > $today || $date < $oldest) {
        throw new InvalidArgumentException('Enter a valid birthdate within the last 120 years and not in the future.');
    }
}

function account_assert_institutional_email(string $email): string
{
    $email = account_normalize_email($email);
    if (strlen($email) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    if (!str_ends_with($email, '@' . CLINIQ_ACCOUNT_EMAIL_DOMAIN)) {
        throw new InvalidArgumentException('Use your @' . CLINIQ_ACCOUNT_EMAIL_DOMAIN . ' institutional email address.');
    }

    return $email;
}

function account_assert_strong_password(string $password, string $confirmation, string $label = 'Password'): void
{
    if (strlen($password) < 8 || strlen($password) > 128
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/[A-Z]/', $password)
        || !preg_match('/\d/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)) {
        throw new InvalidArgumentException($label . ' must be 8–128 characters and include uppercase, lowercase, number, and special characters.');
    }
    if ($password !== $confirmation) {
        throw new InvalidArgumentException($label . ' and confirmation must match.');
    }
}

function account_assert_email_available(PDO $db, string $email, ?int $excludePersonId = null): void
{
    $sql = 'SELECT 1 FROM accounts WHERE LOWER(email) = ?';
    $params = [account_normalize_email($email)];
    if ($excludePersonId !== null) {
        $sql .= ' AND person_id <> ?';
        $params[] = $excludePersonId;
    }
    $sql .= ' LIMIT 1';
    $query = $db->prepare($sql);
    $query->execute($params);
    if ($query->fetchColumn()) {
        throw new InvalidArgumentException('This email address is already registered.');
    }
}
