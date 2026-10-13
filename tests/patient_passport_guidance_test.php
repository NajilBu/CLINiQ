<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = file_get_contents($root . '/patient-portal/patient-dashboard.php');
$passport = file_get_contents($root . '/patient-portal/patient-passport.php');

if ($dashboard === false || $passport === false) throw new RuntimeException('Unable to read Passport guidance sources.');
if (!str_contains($dashboard, "student_portal_url('health-passport', ['focus' => 'emergency'])")) throw new RuntimeException('Dashboard Passport action must identify the Emergency section.');
if (!str_contains($passport, '$passportFocusEmergency') || !str_contains($passport, 'data-passport-complete')) throw new RuntimeException('Passport must explain redirected completion work.');
if (!str_contains($passport, 'openEmergencyCompletion(false)') || !str_contains($passport, 'data-passport-group="emergency"')) throw new RuntimeException('Phone redirects must open the Emergency section without forcing keyboard focus.');
if (!str_contains($passport, 'Enter your allergies, or type') || !str_contains($passport, 'Enter emergency instructions')) throw new RuntimeException('Required emergency details must be enforced server-side.');

echo "Patient Passport guidance test passed.\n";
