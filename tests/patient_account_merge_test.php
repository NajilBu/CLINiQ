<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/services/PatientAccountMerge.php';
function merge_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$sample = [
    ['account_id'=>1,'id_number'=>'23-001','email'=>'a@example.test','full_name'=>'Test Person'],
    ['account_id'=>2,'id_number'=>'23001','email'=>'A@example.test','full_name'=>'TEST PERSON'],
    ['account_id'=>3,'id_number'=>'23-002','email'=>'','full_name'=>'Different Person'],
];
$pairs = patient_duplicate_pairs($sample);
merge_check(count($pairs) === 1 && count($pairs[0]['reasons']) === 3, 'Duplicate cross-referencing failed.');
if (!in_array('--database', $argv, true)) { echo "Duplicate matching checks passed.\n"; exit; }
$db = auth_db();
// Every real table is shadowed first. No fixture query can reach real patient rows.
$tables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    $name = patient_merge_identifier($table);
    $ddl = $db->query("SHOW CREATE TABLE $name")->fetch(PDO::FETCH_NUM)[1];
    $ddl = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl);
    $ddl = preg_replace('/^\s*CONSTRAINT .*FOREIGN KEY .*\n/m', '', $ddl);
    $ddl = preg_replace('/,\s*\) ENGINE/', "\n) ENGINE", $ddl);
    $db->exec($ddl);
}
$db->exec("INSERT INTO people (id,id_number,first_name,last_name) VALUES (1,'99-001','Merge','Fixture'),(2,'99001','Merge','Fixture')");
$db->exec("INSERT INTO accounts (id,person_id,email) VALUES (1,1,'one@example.test'),(2,2,'two@example.test')");
$db->exec("INSERT INTO patients (person_id,allergies) VALUES (1,'A'),(2,'B')");
$db->exec("INSERT INTO students (person_id,section) VALUES (1,'A'),(2,'B')");
$db->exec("INSERT INTO visits (visit_id,patient_person_id,visit_datetime,chief_complaint,status) VALUES (1,2,NOW(),'Fixture','Completed')");
$db->exec("INSERT INTO student_remembered_devices (account_id,token_hash,expires_at) VALUES (2,REPEAT('a',64),DATE_ADD(NOW(),INTERVAL 1 DAY))");
$db->exec("INSERT INTO patient_password_resets (account_id,token_hash,expires_at) VALUES (2,REPEAT('b',64),DATE_ADD(NOW(),INTERVAL 1 DAY))");
$actor = ['role'=>'admin','person_id'=>1];
$review = patient_merge_review($db,1,2);
$choices = array_fill_keys(array_keys(patient_merge_fields($review['keep'])), 'keep');
$choices['patients.allergies'] = 'duplicate';
try { patient_merge_transaction($db,1,2,$choices,'outdated',$actor,'fixture-only'); throw new RuntimeException('Stale review accepted'); }
catch (InvalidArgumentException $e) { merge_check(str_contains($e->getMessage(),'Records changed'), 'Unexpected stale error'); }
merge_check((int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn() === 2, 'Stale merge changed accounts');
// Force a real unique-key collision, after clinical records have started transferring.
$db->exec("INSERT INTO student_school_year_enrollments (student_person_id,academic_year) VALUES (1,'2099-2100'),(2,'2099-2100')");
$review = patient_merge_review($db,1,2);
try { patient_merge_transaction($db,1,2,$choices,$review['fingerprint'],$actor,'fixture-only'); throw new LogicException('Conflict accepted'); }
catch (RuntimeException $e) { merge_check(str_contains($e->getMessage(),'conflicting linked records'), 'Unexpected collision error: '.$e->getMessage()); }
merge_check((int)$db->query('SELECT patient_person_id FROM visits WHERE visit_id=1')->fetchColumn() === 2, 'Conflict lost visit ownership');
merge_check($db->query('SELECT allergies FROM patients WHERE person_id=1')->fetchColumn() === 'A', 'Conflict did not roll back profile');
$db->exec('DELETE FROM student_school_year_enrollments WHERE student_person_id=1');
$review = patient_merge_review($db,1,2);
patient_merge_transaction($db,1,2,$choices,$review['fingerprint'],$actor,'fixture-only');
merge_check((int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn() === 1, 'Duplicate account remains');
merge_check((int)$db->query('SELECT COUNT(*) FROM people')->fetchColumn() === 1, 'Duplicate person remains');
merge_check((int)$db->query('SELECT patient_person_id FROM visits WHERE visit_id=1')->fetchColumn() === 1, 'Visit not transferred');
merge_check((int)$db->query('SELECT student_person_id FROM student_school_year_enrollments')->fetchColumn() === 1, 'Enrollment not transferred');
merge_check($db->query('SELECT allergies FROM patients WHERE person_id=1')->fetchColumn() === 'B', 'Profile choice not applied');
merge_check((int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === 1, 'Audit missing');
merge_check((int)$db->query('SELECT COUNT(*) FROM student_remembered_devices WHERE revoked_at IS NULL')->fetchColumn() === 0, 'Remembered token not revoked');
merge_check((int)$db->query('SELECT COUNT(*) FROM patient_password_resets WHERE used_at IS NULL')->fetchColumn() === 0, 'Reset token not revoked');
try { patient_merge_transaction($db,1,2,[],'',['role'=>'nurse'],'fixture-only'); throw new LogicException('Non-admin accepted'); }
catch (RuntimeException $e) { merge_check(str_contains($e->getMessage(),'administrators'), 'Unexpected authorization error'); }
echo "Merge checks passed: matching, stale review, conflict rollback, history transfer, profile choices, audit, token revocation, authorization. Temporary tables only.\n";
