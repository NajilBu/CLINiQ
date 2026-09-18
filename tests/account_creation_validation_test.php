<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/services/AccountValidation.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throws = static function (callable $callback) use ($assert): void {
    try {
        $callback();
        $assert(false, 'Expected validation to reject the value.');
    } catch (InvalidArgumentException) {
        $assert(true, 'Validation rejected the value.');
    }
};

$assert(account_assert_institutional_email(' Student.Name@PLPASIG.EDU.PH ') === 'student.name@plpasig.edu.ph', 'Institutional email should be normalized.');
$throws(static fn() => account_assert_institutional_email('student@example.com'));
$assert(account_valid_person_name("María Dela-Cruz"), 'Unicode person names should be accepted.');
$assert(!account_valid_person_name('Maria <script>'), 'Markup must not be accepted as a person name.');
account_assert_strong_password('Strong#123', 'Strong#123');
$assert(true, 'A strong matching password should be accepted.');
$throws(static fn() => account_assert_strong_password('weak1234', 'weak1234'));
$throws(static fn() => account_assert_strong_password('Strong#123', 'Different#123'));
account_assert_valid_birthdate((new DateTimeImmutable('today'))->modify('-18 years')->format('Y-m-d'));
$assert(true, 'A realistic birthdate should be accepted.');
$throws(static fn() => account_assert_valid_birthdate((new DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d')));
$throws(static fn() => account_assert_valid_birthdate((new DateTimeImmutable('today'))->modify('-121 years')->format('Y-m-d')));

echo "Account creation validation tests passed ({$assertions} assertions).\n";
