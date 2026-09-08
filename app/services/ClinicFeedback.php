<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/student_id.php';

function clinic_feedback_sections(): array
{
    return [
        'Tangibles' => [
            'T1' => 'The campus clinic has modern medical and dental equipment.',
            'T2' => "The clinic's physical facilities (waiting rooms, treatment areas) are clean and comfortable.",
            'T3' => 'Clinic employees and medical staff look clean, professional, and neat.',
            'T4' => 'Digital platforms (online booking, health portals, signage) are easy for students to use.',
        ],
        'Reliability' => [
            'R1' => 'When the clinic promises to provide a service by a certain time, it does so.',
            'R2' => 'The clinic staff shows a sincere interest in solving student health problems.',
            'R3' => 'The clinic performs its medical and dental services correctly the first time.',
            'R4' => 'The clinic provides its services at the exact times it promises to students.',
            'R5' => 'The clinic maintains error-free medical charts, prescriptions, and dental records.',
        ],
        'Responsiveness' => [
            'RES1' => 'Clinic staff tell students exactly when medical or dental services will be performed.',
            'RES2' => 'Clinic employees give students prompt and speedy medical or dental care.',
            'RES3' => 'Medical and dental staff are always willing and ready to help students.',
            'RES4' => 'Clinic employees are never too busy to respond to student requests or questions.',
        ],
        'Assurance' => [
            'A1' => 'The behavior of clinic employees instills confidence and trust in students.',
            'A2' => 'Students feel completely safe and secure during their medical and dental treatments.',
            'A3' => 'Clinic employees and doctors are consistently polite and courteous to students.',
            'A4' => 'Medical and dental staff have the adequate knowledge to answer student questions.',
        ],
        'Empathy' => [
            'E1' => 'The clinic gives students individual, one-on-one attention during consultations.',
            'E2' => 'The clinic operates during hours that are highly convenient for all students.',
            'E3' => 'Clinic employees give students personal, warm, and caring attention.',
            'E4' => 'The clinic staff keeps the best interest of the student at heart.',
            'E5' => 'The clinic staff understands the specific health needs and stresses of college students.',
        ],
    ];
}

function clinic_feedback_services(): array
{
    return [
        'General Medical Consultation (Check-up, illness, sick leave validation)',
        'Dental Service (Cleaning, filling, extraction, oral check-up etc.)',
        'Mandatory Health Clearance / Annual Physical Examination (APE)',
        'Emergency Care / First Aid / Minor Injury Treatment',
        'Other',
    ];
}

function clinic_feedback_scores(array $ratings): array
{
    $scores = [];
    $expected = [];
    foreach (clinic_feedback_sections() as $section => $questions) {
        $sum = 0;
        foreach ($questions as $code => $question) {
            $expected[] = $code;
            $value = $ratings[$code] ?? null;
            if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-7]$/D', (string) $value)) {
                throw new InvalidArgumentException('Please answer all 22 statements with a whole-number rating from 1 to 7.');
            }
            $sum += (int) $value;
        }
        $scores[$section] = $sum / count($questions);
    }
    if (array_diff(array_keys($ratings), $expected)) {
        throw new InvalidArgumentException('The survey contains an unknown rating. Please reload the form.');
    }
    // Equal weighting for the five dimensions, not for the 22 questions.
    $scores['Overall'] = array_sum($scores) / 5;
    return $scores;
}

function clinic_feedback_tier(float $score): string
{
    return $score >= 6 ? 'Excellent' : ($score >= 4 ? 'Satisfactory' : 'Critical');
}

function clinic_feedback_eligible(string $status): bool
{
    return in_array($status, ['Active', 'Completed'], true);
}

function clinic_feedback_default_service(string $purpose): string
{
    $services = clinic_feedback_services();
    if (preg_match('/dental|tooth/i', $purpose)) return $services[1];
    if (preg_match('/\bAPE\b|physical exam|health clearance/i', $purpose)) return $services[2];
    if (preg_match('/emergency|first aid|injury/i', $purpose)) return $services[3];
    if (preg_match('/consult|check.up|medical/i', $purpose)) return $services[0];
    return 'Other';
}

function clinic_feedback_ready(PDO $db): bool
{
    $query = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $query->execute(['clinic_feedback']);
    return (bool) $query->fetchColumn();
}

function clinic_feedback_latest(PDO $db, string $identifier, bool $lock = false): ?array
{
    $query = $db->prepare("SELECT v.visit_id, v.patient_person_id, v.visit_datetime, v.visit_purpose, v.status,
            s.year_level, pr.program_code
        FROM visits v
        JOIN people p ON p.id = v.patient_person_id
        JOIN students s ON s.person_id = p.id
        LEFT JOIN programs pr ON pr.id = s.program_id
        WHERE p.id_number = ? AND v.status <> 'Cancelled'
        ORDER BY v.visit_datetime DESC, v.visit_id DESC LIMIT 1" . ($lock ? ' FOR UPDATE' : ''));
    $query->execute([normalize_id_number($identifier)]);
    return $query->fetch() ?: null;
}

function clinic_feedback_already_sent(PDO $db, int $visitId): bool
{
    $query = $db->prepare('SELECT 1 FROM clinic_feedback WHERE visit_id = ?');
    $query->execute([$visitId]);
    return (bool) $query->fetchColumn();
}

function clinic_feedback_visits(PDO $db, string $identifier): array
{
    $query = $db->prepare("SELECT v.visit_id, v.visit_datetime, v.visit_purpose, v.status,
            (f.feedback_id IS NOT NULL) AS feedback_submitted
        FROM visits v
        JOIN people p ON p.id = v.patient_person_id
        JOIN students s ON s.person_id = p.id
        LEFT JOIN clinic_feedback f ON f.visit_id = v.visit_id
        WHERE p.id_number = ? AND v.status IN ('Active', 'Completed')
        ORDER BY v.visit_datetime DESC, v.visit_id DESC");
    $query->execute([normalize_id_number($identifier)]);
    return $query->fetchAll();
}

function clinic_feedback_visit(PDO $db, string $identifier, int $visitId, bool $lock = false): ?array
{
    $query = $db->prepare("SELECT v.visit_id, v.visit_datetime, v.visit_purpose, v.status,
            s.year_level, pr.program_code
        FROM visits v
        JOIN people p ON p.id = v.patient_person_id
        JOIN students s ON s.person_id = p.id
        LEFT JOIN programs pr ON pr.id = s.program_id
        WHERE p.id_number = ? AND v.visit_id = ?" . ($lock ? ' FOR UPDATE' : ''));
    $query->execute([normalize_id_number($identifier), $visitId]);
    return $query->fetch() ?: null;
}

function clinic_feedback_text(array $input, string $key, int $max, bool $required = true): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) || ($required && trim($value) === '') || mb_strlen($value) > $max) {
        throw new InvalidArgumentException('Please complete ' . str_replace('_', ' ', $key) . ' (maximum ' . $max . ' characters).');
    }
    return trim($value);
}

function clinic_feedback_validate(array $input): array
{
    if (($input['consent'] ?? '') !== '1') {
        throw new InvalidArgumentException('Your consent is required to submit feedback.');
    }
    $service = clinic_feedback_text($input, 'service_type', 160);
    if (!in_array($service, clinic_feedback_services(), true)) {
        throw new InvalidArgumentException('Select a valid service.');
    }
    $term = clinic_feedback_text($input, 'academic_term', 80);
    if (!in_array($term, ['1st Semester', '2nd Semester', 'Mid-Year Term', 'Other'], true)) {
        throw new InvalidArgumentException('Select a valid academic term.');
    }
    $year = clinic_feedback_text($input, 'year_level', 80);
    if (!in_array($year, ['1st Year', '2nd Year', '3rd Year', '4th Year', 'Other'], true)) {
        throw new InvalidArgumentException('Select a valid year level.');
    }
    $ratings = $input['ratings'] ?? [];
    if (!is_array($ratings)) {
        throw new InvalidArgumentException('Please answer the service evaluation.');
    }
    $scores = clinic_feedback_scores($ratings);
    return [
        'service_type' => $service,
        'service_other' => $service === 'Other' ? clinic_feedback_text($input, 'service_other', 160) : null,
        'academic_term' => $term,
        'term_other' => $term === 'Other' ? clinic_feedback_text($input, 'term_other', 80) : null,
        'year_level' => $year,
        'year_other' => $year === 'Other' ? clinic_feedback_text($input, 'year_other', 80) : null,
        'program' => clinic_feedback_text($input, 'program', 160),
        'comments' => clinic_feedback_text($input, 'comments', 5000, false),
        'ratings' => array_map('intval', $ratings),
        'scores' => $scores,
    ];
}

function clinic_feedback_submit(PDO $db, array $context, array $input): void
{
    $data = clinic_feedback_validate($input);
    $db->beginTransaction();
    try {
        $visit = clinic_feedback_visit($db, (string) $context['identifier'], (int) $context['visit_id'], true);
        if (!$visit || !clinic_feedback_eligible($visit['status'])) {
            throw new InvalidArgumentException('The selected visit is no longer available for feedback. Please select another visit.');
        }
        if (clinic_feedback_already_sent($db, (int) $visit['visit_id'])) {
            throw new InvalidArgumentException('Feedback has already been submitted for this visit.');
        }
        $stmt = $db->prepare('INSERT INTO clinic_feedback
            (visit_id, service_type, service_other, academic_term, term_other, year_level, year_other,
             program, comments, ratings_json, tangibles, reliability, responsiveness, assurance, empathy, overall)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $visit['visit_id'], $data['service_type'], $data['service_other'],
            $data['academic_term'], $data['term_other'], $data['year_level'], $data['year_other'],
            $data['program'], $data['comments'], json_encode($data['ratings'], JSON_THROW_ON_ERROR),
            $data['scores']['Tangibles'], $data['scores']['Reliability'], $data['scores']['Responsiveness'],
            $data['scores']['Assurance'], $data['scores']['Empathy'], $data['scores']['Overall'],
        ]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
            throw new InvalidArgumentException('Feedback has already been submitted for this visit.');
        }
        throw $error;
    }
}
