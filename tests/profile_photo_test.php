<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function profile_photo_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$migration = file_get_contents($root . '/database/migrations/20260910_add_profile_photos.sql');
$service = file_get_contents($root . '/app/services/ProfilePhoto.php');
$patientLayout = file_get_contents($root . '/patient-portal/includes/patient-layout.php');
$staffLayout = file_get_contents($root . '/app/helpers/view.php');
$patientDashboard = file_get_contents($root . '/patient-portal/patient-dashboard.php');
$patientRegistry = file_get_contents($root . '/public/patients/index.php');
$gateway = file_get_contents($root . '/docker/public-gateway.conf');

profile_photo_assert(str_contains((string) $migration, 'profile_photo_path'), 'Profile-photo migration is missing.');
profile_photo_assert(str_contains((string) $service, 'CLINIQ_PROFILE_PHOTO_MAX_BYTES'), 'Upload size validation is missing.');
profile_photo_assert(str_contains((string) $service, "'image/jpeg'"), 'JPEG validation is missing.');
profile_photo_assert(str_contains((string) $service, "'image/png'"), 'PNG validation is missing.');
profile_photo_assert(str_contains((string) $service, "'image/webp'"), 'WebP validation is missing.');
profile_photo_assert(str_contains((string) $patientLayout, 'patient-profile-photo-input'), 'Patient photo picker is missing.');
profile_photo_assert(str_contains((string) $staffLayout, 'staff-profile-photo-input'), 'Staff photo picker is missing.');
profile_photo_assert(str_contains((string) $patientLayout, 'data-profile-photo-preview'), 'Patient photo preview is missing.');
profile_photo_assert(str_contains((string) $staffLayout, 'data-profile-photo-preview'), 'Staff photo preview is missing.');
profile_photo_assert(str_contains((string) $patientLayout, 'data-profile-photo-confirmation'), 'Patient custom upload confirmation is missing.');
profile_photo_assert(str_contains((string) $staffLayout, 'data-profile-photo-confirmation'), 'Staff custom upload confirmation is missing.');
profile_photo_assert(str_contains((string) $patientLayout, 'Confirm &amp; Save'), 'Patient confirmation action is missing.');
profile_photo_assert(str_contains((string) $staffLayout, 'Confirm &amp; Save'), 'Staff confirmation action is missing.');
profile_photo_assert(!str_contains((string) $patientLayout, 'window.confirm'), 'Patient profile photo must not use a native confirmation dialog.');
profile_photo_assert(!str_contains((string) $staffLayout, 'window.confirm'), 'Staff profile photo must not use a native confirmation dialog.');
profile_photo_assert(str_contains((string) $patientRegistry, "profile_photo_normalize_path(\$patient['profile_photo_path'] ?? null)"), 'Patient registry does not validate saved profile-photo paths.');
profile_photo_assert(str_contains((string) $patientRegistry, 'class="avatar-photo"'), 'Patient registry profile-photo image is missing.');
profile_photo_assert(str_contains((string) $patientRegistry, 'initials($displayName)'), 'Patient registry initials fallback is missing.');
profile_photo_assert(!str_contains((string) $patientDashboard, 'mailto:'), 'Patient email must not open an external email application.');
profile_photo_assert(str_contains((string) $gateway, '/public/uploads/profile-photos/'), 'Public passport photo gateway route is missing.');

echo "Profile photo checks passed.\n";
