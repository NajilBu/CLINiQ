<?php

declare(strict_types=1);

if (getenv('ENROLLMENT_CHECKPOINT_E2E') !== '1') {
    throw new RuntimeException('Set ENROLLMENT_CHECKPOINT_E2E=1. This test is only for compose.registration-proof.yaml.');
}

require_once '/var/www/html/patient-portal/includes/patient-layout.php';
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$db = auth_db();

$createStudent = static function (string $suffix, string $accessStatus, ?string $enrollmentStatus) use ($db): array {
    $personId = random_int(900000000, 999999999);
    $studentId = normalize_student_id_number('26' . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT));
    $db->prepare('INSERT INTO people (id, id_number, first_name, last_name) VALUES (?, ?, ?, ?)')
        ->execute([$personId, $studentId, 'Proof', $suffix]);
    $db->prepare("UPDATE accounts SET password_hash = ?, email = ?, account_status = 'active', activated_at = NOW() WHERE person_id = ?")
        ->execute([password_hash('Proof#123', PASSWORD_DEFAULT), strtolower($suffix) . '@proof.invalid', $personId]);
    $accountLookup = $db->prepare('SELECT id FROM accounts WHERE person_id = ?');
    $accountLookup->execute([$personId]);
    $accountId = (int) $accountLookup->fetchColumn();
    $db->prepare("INSERT INTO students (person_id, year_level, section, academic_year) VALUES (?, '2', 'A', '2026-2027')")
        ->execute([$personId]);
    $db->prepare('INSERT INTO patients (person_id, access_status, guardian_or_contact_number) VALUES (?, ?, ?)')
        ->execute([$personId, $accessStatus, '09171234567']);
    if ($enrollmentStatus !== null) {
        $db->prepare("INSERT INTO student_school_year_enrollments (student_person_id, academic_year, year_level, section, enrollment_status, promotion_source) VALUES (?, '2026-2027', '2', 'A', ?, 'Proof')")
            ->execute([$personId, $enrollmentStatus]);
    }
    return ['person_id' => $personId, 'account_id' => $accountId, 'student_id' => $studentId];
};
$request = static function (string $path, array $session, string $method = 'GET', array $body = []): array {
    $headers = ['Accept: text/html, application/json'];
    if (($session['cookie'] ?? '') !== '') {
        $headers[] = 'Cookie: ' . $session['cookie'];
    }
    $options = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'follow_location' => 0,
        'max_redirects' => 0,
        'timeout' => 10,
    ];
    if ($body !== []) {
        $options['content'] = http_build_query($body);
        $options['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($options['content']);
    }
    $bodyText = file_get_contents('http://app' . $path, false, stream_context_create(['http' => $options]));
    $responseHeaders = $http_response_header ?? [];
    $statusLine = (string) ($responseHeaders[0] ?? '');
    preg_match('/\s(\d{3})\s/', $statusLine, $match);
    $status = (int) ($match[1] ?? 0);
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $bodyText];
};
$location = static function (array $response): string {
    foreach ($response['headers'] as $header) {
        if (str_starts_with(strtolower($header), 'location:')) {
            return trim(substr($header, strlen('location:')));
        }
    }
    return '';
};
$cookieFrom = static function (array $response): string {
    foreach ($response['headers'] as $header) {
        if (str_starts_with(strtolower($header), 'set-cookie:')) {
            return trim(explode(';', trim(substr($header, strlen('set-cookie:'))), 2)[0]);
        }
    }
    return '';
};
$loginAs = static function (array $student) use ($request, $cookieFrom, $location, $assert): array {
    $login = $request('/patient-portal/patient-login.php', []);
    $assert($login['status'] === 200, 'Proof login page must be available.');
    if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $login['body'], $match) !== 1) {
        throw new RuntimeException('Proof login page did not provide a CSRF token.');
    }
    $session = ['cookie' => $cookieFrom($login)];
    if ($session['cookie'] === '') {
        throw new RuntimeException('Proof login page did not create a session cookie.');
    }
    $submitted = $request('/patient-portal/patient-login.php', $session, 'POST', [
        '_csrf' => html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'),
        'student_id' => $student['student_id'],
        'password' => 'Proof#123',
    ]);
    $summary = trim((string) preg_replace('/\s+/', ' ', strip_tags($submitted['body'])));
    $assert($submitted['status'] === 302 && $location($submitted) === '/dashboard', 'Proof student login must reach the dashboard (received HTTP ' . $submitted['status'] . ' to ' . $location($submitted) . '; ' . substr($summary, 0, 240) . ').');
    $updatedCookie = $cookieFrom($submitted);
    if ($updatedCookie !== '') {
        $session['cookie'] = $updatedCookie;
    }
    $dashboard = $request('/patient-portal/patient-dashboard.php', $session);
    if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $dashboard['body'], $match) !== 1) {
        throw new RuntimeException('Proof dashboard did not provide a CSRF token.');
    }
    $session['csrf'] = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    return $session;
};

$runId = strtoupper(bin2hex(random_bytes(4)));
$pending = $createStudent('PENDING' . $runId, 'Official', 'Pending Confirmation');
$enrolled = $createStudent('ENROLLED' . $runId, 'Official', 'Enrolled');
$applicant = $createStudent('APPLICANT' . $runId, 'Applicant', null);
foreach ([$pending, $enrolled, $applicant] as $student) {
    $identity = student_find_patient_by_number($student['student_id']);
    $assert(is_array($identity), 'Synthetic proof student must be discoverable by the portal login helper.');
    $assert(student_password_is_valid($identity, 'Proof#123'), 'Synthetic proof student password must satisfy the portal login helper.');
}
$assert(student_enrollment_is_confirmed((int) $applicant['person_id']), 'Applicant without enrollment history must be treated as confirmed by the shared helper.');
$db->exec('DELETE FROM login_attempts');
$pendingSession = $loginAs($pending);
$enrolledSession = $loginAs($enrolled);
$applicantSession = $loginAs($applicant);

$db->prepare("INSERT INTO appointments (patient_id, appointment_datetime, purpose, status) VALUES (?, DATE_SUB(NOW(), INTERVAL 2 HOUR), 'Medical Consult', 'Scheduled')")
    ->execute([$applicant['person_id']]);
$overdueAppointmentId = (int) $db->lastInsertId();

$dashboard = $request('/patient-portal/patient-dashboard.php', $pendingSession);
$assert($dashboard['status'] === 200, 'A Pending Official must reach the dashboard (received HTTP ' . $dashboard['status'] . ').');
$assert(str_contains($dashboard['body'], 'Confirm your enrollment'), 'The Pending Official dashboard must show the non-blocking confirmation card.');
$assert(str_contains($dashboard['body'], 'Welcome back,'), 'The Pending Official dashboard must retain normal portal content.');
$status = (string) $db->query("SELECT status FROM appointments WHERE appointment_id = {$overdueAppointmentId}")->fetchColumn();
$assert($status === 'Scheduled', 'A pending-student dashboard request must not alter existing appointments.');

foreach ([
    '/patient-portal/patient-passport.php',
    '/patient-portal/patient-ape-status.php',
] as $path) {
    $response = $request($path, $pendingSession);
    $assert($response['status'] === 200, "Pending Official must retain portal access: {$path}");
}
$pendingAppointments = $request('/patient-portal/patient-appointment.php', $pendingSession);
$assert($pendingAppointments['status'] === 200 && str_contains($pendingAppointments['body'], 'Confirm your enrollment'), 'A Pending Official must see the booking block without losing appointment history access.');

$confirmation = $request('/patient-portal/patient-dashboard.php', $pendingSession, 'POST', [
    '_csrf' => $pendingSession['csrf'],
    'action' => 'complete_active_enrollment_confirmation',
    'enrollment_status' => 'Still Enrolled',
    'contact_number' => '09171234567',
    'contact_confirmed' => '1',
]);
$confirmationSummary = trim((string) preg_replace('/\s+/', ' ', strip_tags($confirmation['body'])));
preg_match('/student-note-danger[^>]*>(.*?)<\/div>/s', $confirmation['body'], $confirmationError);
$assert($confirmation['status'] === 302 && $location($confirmation) === '/dashboard?re_enrolled=1', 'Still Enrolled confirmation must redirect to the unlocked dashboard (received HTTP ' . $confirmation['status'] . ' to ' . $location($confirmation) . '; ' . ($confirmationError[1] ?? substr($confirmationSummary, 0, 240)) . ').');
$history = $db->prepare("SELECT enrollment_status FROM student_school_year_enrollments WHERE student_person_id = ? AND academic_year = '2026-2027'");
$history->execute([$pending['person_id']]);
$assert($history->fetchColumn() === 'Enrolled', 'Still Enrolled confirmation must update current-year history.');
$unlocked = $request('/patient-portal/patient-dashboard.php', $pendingSession);
$assert($unlocked['status'] === 200 && !str_contains($unlocked['body'], 'Confirm your enrollment'), 'Confirmed Official must remove the enrollment confirmation card.');
$appointments = $request('/patient-portal/patient-appointment.php', $pendingSession);
$assert($appointments['status'] === 200, 'Confirmed Official must regain appointment access.');

$enrolledDashboard = $request('/patient-portal/patient-dashboard.php', $enrolledSession);
$assert($enrolledDashboard['status'] === 200 && !str_contains($enrolledDashboard['body'], 'Confirm your enrollment'), 'Enrolled Official must retain normal dashboard access.');
$applicantDashboard = $request('/patient-portal/patient-dashboard.php', $applicantSession);
$assert($applicantDashboard['status'] === 200 && !str_contains($applicantDashboard['body'], 'Confirm your enrollment'), 'Applicant must not be asked for enrollment confirmation (received HTTP ' . $applicantDashboard['status'] . ').');

echo "Student enrollment checkpoint isolated HTTP proof passed ({$assertions} assertions).\n";
