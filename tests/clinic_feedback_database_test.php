<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/clinic_feedback_test.php';
$db = auth_db();
if (!clinic_feedback_ready($db)) throw new RuntimeException('Apply the feedback migration before running database tests.');
// Temporary tables shadow real tables on this connection only. No patient data is copied.
foreach (['people', 'programs', 'students', 'visits', 'clinic_feedback'] as $table) {
    $definition = $db->query("SHOW CREATE TABLE {$table}")->fetch(PDO::FETCH_NUM)[1];
    $definition = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition);
    // MariaDB temporary tables do not support foreign keys; retain columns and unique indexes.
    $definition = preg_replace('/^\s*CONSTRAINT .*FOREIGN KEY .*\n/m', '', $definition);
    $definition = preg_replace('/,\s*\) ENGINE/', "\n) ENGINE", $definition);
    $db->exec($definition);
}
$db->exec("INSERT INTO people (id, id_number, first_name, last_name) VALUES (1, '99-99999', 'Feedback', 'Test'), (2, '99-99998', 'Nonstudent', 'Test')");
$db->exec("INSERT INTO students (person_id, year_level) VALUES (1, '2')");
$db->exec("INSERT INTO visits (visit_id, patient_person_id, visit_datetime, chief_complaint, status) VALUES
    (1, 1, '2026-09-01 08:00:00', 'Test only', 'Completed'),
    (2, 1, '2026-09-01 09:00:00', 'Test only', 'Active'),
    (3, 1, '2026-09-01 10:00:00', 'Test only', 'Cancelled'),
    (4, 2, '2026-09-01 10:00:00', 'Test only', 'Active')");
check_feedback(clinic_feedback_latest($db, '99-00000') === null, 'Unknown ID should not match.');
check_feedback(clinic_feedback_latest($db, '99-99998') === null, 'Nonstudent should not match.');
check_feedback((int) clinic_feedback_latest($db, '9999999')['visit_id'] === 2, 'Latest noncancelled visit selection and ID normalization.');
$context = ['identifier' => '99-99999', 'visit_id' => 2];
clinic_feedback_submit($db, $context, $input);
check_feedback(clinic_feedback_already_sent($db, 2), 'Active visit should accept feedback.');
check_feedback((int) clinic_feedback_latest($db, '99-99999')['visit_id'] === 2, 'Must not fall back to an older unrated visit.');
rejects_feedback(fn() => clinic_feedback_submit($db, $context, $input), 'Duplicate accepted.');
$db->exec("UPDATE visits SET status = 'Completed' WHERE visit_id = 2");
rejects_feedback(fn() => clinic_feedback_submit($db, $context, $input), 'Completion must not unlock a second response.');
$db->exec("INSERT INTO visits (visit_id, patient_person_id, visit_datetime, chief_complaint, status) VALUES (5, 1, '2026-09-02 08:00:00', 'Test only', 'Unaddressed')");
check_feedback((int) clinic_feedback_latest($db, '99-99999')['visit_id'] === 5, 'New unaddressed visit must not be skipped.');
rejects_feedback(fn() => clinic_feedback_submit($db, $context, $input), 'Already-rated visit accepted after a newer visit.');
$context['visit_id'] = 5;
rejects_feedback(fn() => clinic_feedback_submit($db, $context, $input), 'Unaddressed accepted.');
$db->exec("UPDATE visits SET status = 'Completed' WHERE visit_id = 5");
clinic_feedback_submit($db, $context, $input);
check_feedback(clinic_feedback_already_sent($db, 5), 'Completed should accept feedback.');
$db->exec("INSERT INTO visits (visit_id, patient_person_id, visit_datetime, chief_complaint, status) VALUES (6, 1, '2026-09-02 08:00:00', 'Test only', 'Active')");
check_feedback((int) clinic_feedback_latest($db, '99-99999')['visit_id'] === 6, 'Equal timestamps require ID tie-breaker.');
$stored = $db->query('SELECT overall, ratings_json FROM clinic_feedback WHERE visit_id = 2')->fetch();
check_feedback(abs((float) $stored['overall'] - 6.85) < 0.000001, 'Stored score must match server calculation.');
check_feedback(count(json_decode($stored['ratings_json'], true)) === 22, 'Store all question answers.');
try {
    $db->exec('UPDATE clinic_feedback SET visit_id = 2 WHERE visit_id = 5');
    throw new RuntimeException('Unique visit constraint missing.');
} catch (PDOException $error) {
    check_feedback((int) $error->errorInfo[1] === 1062, 'Expected duplicate key rejection.');
}
$choices = clinic_feedback_visits($db, '99-99999');
check_feedback(array_map('intval', array_column($choices, 'visit_id')) === [6, 5, 2, 1], 'Picker must show owned Active/Completed visits newest first.');
check_feedback((bool) $choices[1]['feedback_submitted'] && !(bool) $choices[3]['feedback_submitted'], 'Rated and unrated visits must be distinguished.');
check_feedback(clinic_feedback_visit($db, '99-99999', 1) !== null, 'Older visit must be selectable.');
check_feedback(clinic_feedback_visit($db, '99-99999', 4) === null, 'Another person visit must not match.');
rejects_feedback(fn() => clinic_feedback_submit($db, ['identifier' => '99-99999', 'visit_id' => 4], $input), 'Another person visit accepted.');
rejects_feedback(fn() => clinic_feedback_submit($db, ['identifier' => '99-99999', 'visit_id' => 3], $input), 'Cancelled visit accepted.');
echo "Clinic feedback database tests passed using temporary tables only.\n";
