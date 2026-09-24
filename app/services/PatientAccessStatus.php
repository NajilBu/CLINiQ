<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/PatientNotification.php';
require_once __DIR__ . '/PatientEmail.php';

function patient_access_statuses(): array
{
    return ['Applicant', 'Official'];
}

function patient_access_status_normalize(mixed $value, string $default = 'Applicant'): string
{
    $normalized = ucfirst(strtolower(trim((string) $value)));
    if ($normalized === '') {
        return $default;
    }
    if (!in_array($normalized, patient_access_statuses(), true)) {
        throw new InvalidArgumentException('Access status must be Applicant or Official.');
    }
    return $normalized;
}

function patient_has_official_access(array $patient): bool
{
    return patient_access_status_normalize($patient['access_status'] ?? 'Official', 'Official') === 'Official';
}

function patient_access_status_set(
    PDO $db,
    int $personId,
    string $status,
    ?int $actorPersonId = null,
    string $source = 'manual'
): array {
    if ($personId < 1) {
        throw new InvalidArgumentException('Choose a valid patient account.');
    }

    $status = patient_access_status_normalize($status);
    $lookup = $db->prepare('SELECT pt.access_status, EXISTS (SELECT 1 FROM students s WHERE s.person_id = pt.person_id) AS is_student FROM patients pt WHERE pt.person_id = ? FOR UPDATE');
    $lookup->execute([$personId]);
    $patient = $lookup->fetch();
    if (!$patient) {
        throw new RuntimeException('Patient profile not found.');
    }
    $current = patient_access_status_normalize($patient['access_status'] ?? 'Official', 'Official');
    $isStudent = (int) ($patient['is_student'] ?? 0) === 1;
    if (!$isStudent && $status === 'Applicant') {
        throw new InvalidArgumentException('Applicant access is available only to students.');
    }
    if ($isStudent && $current === 'Official' && $status === 'Applicant') {
        throw new InvalidArgumentException('An Official student cannot be changed back to Applicant.');
    }
    if ($current === $status) {
        return ['changed' => false, 'previous_status' => $current, 'access_status' => $status];
    }

    $update = $db->prepare('UPDATE patients SET access_status = ? WHERE person_id = ?');
    $update->execute([$status, $personId]);

    $isOfficial = $status === 'Official';
    patient_notification_create(
        $db,
        $personId,
        $actorPersonId,
        'account',
        $isOfficial ? 'Official portal access unlocked' : 'Portal access changed to Applicant',
        $isOfficial
            ? 'Your Health Passport and appointment booking are now available.'
            : 'Health Passport and appointment booking are unavailable while your account is under Applicant access.',
        'patient-dashboard.php',
        'patient_access',
        $personId
    );
    if ($status === 'Applicant') {
        patient_email_queue_notification($personId, 'patient_access_restricted', 'access_restrictions', 'Your patient portal access changed', 'Your patient portal access is currently restricted while your clinic account is under Applicant review. Please contact the clinic if you need assistance.', 'patient_access', $personId, $actorPersonId, null, $status);
    }
    audit_log_event(
        'accounts',
        $isOfficial ? 'patient_access_promoted' : 'patient_access_changed_to_applicant',
        $actorPersonId,
        $actorPersonId ? 'staff' : 'system',
        'patient',
        $personId,
        ['previous_status' => $current, 'access_status' => $status, 'source' => $source]
    );

    return ['changed' => true, 'previous_status' => $current, 'access_status' => $status];
}

function patient_access_promote_after_ape_clearance(
    PDO $db,
    int $personId,
    int $apeId,
    ?int $actorPersonId = null
): bool {
    $result = patient_access_status_set($db, $personId, 'Official', $actorPersonId, 'ape_clearance:' . $apeId);
    return (bool) $result['changed'];
}
