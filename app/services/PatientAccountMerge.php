<?php
declare(strict_types=1);

require_once __DIR__ . '/PatientAccountService.php';
require_once __DIR__ . '/BackupService.php';

function patient_duplicate_key(string $value): string
{
    return preg_replace('/[^\pL\pN]/u', '', mb_strtolower(trim($value))) ?? '';
}

function patient_duplicate_accounts(PDO $db): array
{
    return $db->query("SELECT a.id AS account_id, p.id AS person_id, p.id_number,
        CONCAT_WS(' ', p.first_name, NULLIF(p.middle_name, ''), p.last_name) AS full_name,
        a.email, a.account_status, IF(s.person_id IS NULL, 'Employee', 'Student') AS patient_type
        FROM accounts a JOIN people p ON p.id=a.person_id JOIN patients pt ON pt.person_id=p.id
        LEFT JOIN students s ON s.person_id=p.id
        WHERE NOT EXISTS (SELECT 1 FROM clinic_staff cs WHERE cs.person_id=p.id)
        ORDER BY p.last_name, p.first_name, a.id")->fetchAll(PDO::FETCH_ASSOC);
}

function patient_duplicate_pairs(array $accounts): array
{
    $buckets = [];
    foreach ($accounts as $account) {
        foreach (['id_number' => 'ID number', 'email' => 'Email', 'full_name' => 'Name (review required)'] as $field => $reason) {
            $value = $field === 'email' ? mb_strtolower(trim((string) $account[$field])) : patient_duplicate_key((string) $account[$field]);
            if ($value !== '') $buckets[$reason][$value][] = (int) $account['account_id'];
        }
    }
    $pairs = [];
    foreach ($buckets as $reason => $groups) {
        foreach ($groups as $ids) {
            sort($ids);
            foreach ($ids as $i => $left) {
                foreach (array_slice($ids, $i + 1) as $right) {
                    $key = "$left:$right";
                    $pairs[$key] ??= ['left' => $left, 'right' => $right, 'reasons' => []];
                    $pairs[$key]['reasons'][] = $reason;
                }
            }
        }
    }
    return array_values($pairs);
}

function patient_merge_identifier(string $name): string
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) throw new RuntimeException('Unsupported database identifier.');
    return '`' . $name . '`';
}

function patient_merge_profiles(PDO $db, int $accountId, bool $lock = false): array
{
    $q = $db->prepare('SELECT * FROM accounts WHERE id=?' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([$accountId]);
    $account = $q->fetch(PDO::FETCH_ASSOC);
    if (!$account) throw new InvalidArgumentException('Account is no longer available.');
    $q = $db->prepare('SELECT 1 FROM clinic_staff WHERE person_id=?');
    $q->execute([$account['person_id']]);
    if ($q->fetchColumn()) throw new InvalidArgumentException('Staff accounts cannot be merged.');
    $result = ['accounts' => $account];
    foreach (['people', 'patients', 'students', 'school_employees'] as $table) {
        $column = $table === 'people' ? 'id' : 'person_id';
        $q = $db->prepare("SELECT * FROM $table WHERE $column=?" . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$account['person_id']]);
        $result[$table] = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    if (!$result['patients']) throw new InvalidArgumentException('Both accounts must have patient profiles.');
    return $result;
}

function patient_merge_fields(array $profiles): array
{
    $fields = [];
    foreach (['people', 'patients', 'students', 'school_employees'] as $table) {
        foreach ($profiles[$table] as $column => $value) {
            if (in_array($column, ['id', 'person_id', 'id_number', 'created_at', 'updated_at', 'emergency_token', 'token_enabled'], true)) continue;
            $fields[$table . '.' . $column] = $value;
        }
    }
    return $fields;
}

function patient_merge_references(PDO $db): array
{
    $references = $db->query("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE()
        AND REFERENCED_TABLE_NAME IN ('people','patients','students','accounts','school_employees')
        ORDER BY TABLE_NAME,COLUMN_NAME")->fetchAll(PDO::FETCH_ASSOC);
    // This legacy audit column exists without a foreign key in deployed databases.
    $hasViewer = false;
    foreach ($references as $reference) {
        if ($reference['TABLE_NAME'] === 'passport_access_logs' && $reference['COLUMN_NAME'] === 'viewer_person_id') $hasViewer = true;
    }
    if (!$hasViewer) {
        $exists = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='passport_access_logs' AND COLUMN_NAME='viewer_person_id'")->fetchColumn();
        if ($exists) $references[] = ['TABLE_NAME'=>'passport_access_logs', 'COLUMN_NAME'=>'viewer_person_id', 'REFERENCED_TABLE_NAME'=>'people'];
    }
    return $references;
}

function patient_merge_review(PDO $db, int $keepId, int $removeId, bool $lock = false): array
{
    if ($keepId < 1 || $removeId < 1 || $keepId === $removeId) throw new InvalidArgumentException('Select two different accounts.');
    // Stable lock order prevents concurrent reversed merges from deadlocking.
    $ids = [$keepId, $removeId]; sort($ids);
    $profiles = [];
    foreach ($ids as $id) $profiles[$id] = patient_merge_profiles($db, $id, $lock);
    $keep = $profiles[$keepId]; $remove = $profiles[$removeId];
    if ((bool) $keep['students'] !== (bool) $remove['students'] || (bool) $keep['school_employees'] !== (bool) $remove['school_employees']) {
        throw new InvalidArgumentException('Patient types differ. Correct their profiles before merging.');
    }
    $candidate = false;
    foreach (patient_duplicate_pairs(patient_duplicate_accounts($db)) as $pair) {
        if ([$pair['left'], $pair['right']] === $ids) $candidate = true;
    }
    if (!$candidate) throw new InvalidArgumentException('These accounts no longer match the duplicate checks.');
    $counts = [];
    foreach (patient_merge_references($db) as $ref) {
        if (in_array($ref['TABLE_NAME'], ['accounts','patients','students','school_employees'], true)) continue;
        $table = patient_merge_identifier($ref['TABLE_NAME']); $column = patient_merge_identifier($ref['COLUMN_NAME']);
        $q = $db->prepare("SELECT COUNT(*) FROM $table WHERE $column=?");
        $q->execute([$ref['REFERENCED_TABLE_NAME'] === 'accounts' ? $removeId : $remove['people']['id']]);
        $counts[$ref['TABLE_NAME'] . '.' . $ref['COLUMN_NAME']] = (int) $q->fetchColumn();
    }
    $fingerprint = hash('sha256', json_encode([$keep, $remove, $counts], JSON_THROW_ON_ERROR));
    return compact('keep', 'remove', 'counts', 'fingerprint');
}

function merge_patient_accounts(int $keepId, int $removeId, array $choices, string $fingerprint, array $actor): void
{
    if (!can_manage_patient_accounts($actor)) throw new RuntimeException('Only administrators may merge accounts.');
    $db = auth_db();
    patient_merge_review($db, $keepId, $removeId);
    // A completed, verified snapshot is mandatory before modifying patient data.
    $backup = cliniq_backup_run('daily', true);
    cliniq_backup_verify($backup['last_success_path'], false);
    patient_merge_transaction($db, $keepId, $removeId, $choices, $fingerprint, $actor, basename($backup['last_success_path']));
}

/** Internal transaction; the public workflow above enforces and verifies the backup. */
function patient_merge_transaction(PDO $db, int $keepId, int $removeId, array $choices, string $fingerprint, array $actor, string $backupName): void
{
    if (!can_manage_patient_accounts($actor)) throw new RuntimeException('Only administrators may merge accounts.');
    $db->beginTransaction();
    try {
        $review = patient_merge_review($db, $keepId, $removeId, true);
        if (!hash_equals($review['fingerprint'], $fingerprint)) throw new InvalidArgumentException('Records changed. Review the accounts again before merging.');
        $keep = $review['keep']; $remove = $review['remove'];
        $keepPerson = (int) $keep['people']['id']; $removePerson = (int) $remove['people']['id'];
        $otherFields = patient_merge_fields($remove);
        foreach (patient_merge_fields($keep) as $key => $value) {
            if ($value === ($otherFields[$key] ?? null)) continue;
            if (!in_array($choices[$key] ?? '', ['keep', 'duplicate'], true)) throw new InvalidArgumentException('Choose a value for every differing profile field.');
            if ($choices[$key] !== 'duplicate') continue;
            [$table, $column] = explode('.', $key);
            $pk = $table === 'people' ? 'id' : 'person_id';
            $q = $db->prepare('UPDATE ' . patient_merge_identifier($table) . ' SET ' . patient_merge_identifier($column) . "=? WHERE $pk=?");
            $q->execute([$otherFields[$key] ?? null, $keepPerson]);
        }
        foreach (patient_merge_references($db) as $ref) {
            if (in_array($ref['TABLE_NAME'], ['accounts','patients','students','school_employees'], true)) continue;
            $table = patient_merge_identifier($ref['TABLE_NAME']); $column = patient_merge_identifier($ref['COLUMN_NAME']);
            $accountRef = $ref['REFERENCED_TABLE_NAME'] === 'accounts';
            $q = $db->prepare("UPDATE $table SET $column=? WHERE $column=?");
            $q->execute([$accountRef ? $keepId : $keepPerson, $accountRef ? $removeId : $removePerson]);
        }
        // Reset links issued to either identity must not grant access to the retained login.
        $q = $db->prepare('UPDATE patient_password_resets SET used_at=COALESCE(used_at,NOW()) WHERE account_id=?');
        $q->execute([$keepId]);
        $q = $db->prepare('UPDATE student_remembered_devices SET revoked_at=COALESCE(revoked_at,NOW()) WHERE account_id=?');
        $q->execute([$keepId]);
        $metadata = ['retained_account_id' => $keepId, 'removed_account_id' => $removeId,
            'retained_person_id' => $keepPerson, 'removed_person_id' => $removePerson,
            'profile_choices' => $choices, 'previous_profiles' => [patient_merge_fields($keep), patient_merge_fields($remove)],
            'removed_id_number' => $remove['people']['id_number'], 'removed_email' => $remove['accounts']['email'],
            'transferred' => $review['counts'], 'backup' => $backupName];
        if (!audit_log_event('accounts', 'merge_patient_accounts', (int) ($actor['person_id'] ?? $actor['id']), 'admin', 'accounts', $keepId, $metadata)) {
            throw new RuntimeException('Unable to record the merge audit. No changes were saved.');
        }
        // All dependent history was reassigned; only duplicate identity/profile rows remain.
        foreach (['students', 'school_employees', 'patients', 'accounts'] as $table) {
            $q = $db->prepare("DELETE FROM $table WHERE person_id=?"); $q->execute([$removePerson]);
        }
        $q = $db->prepare('DELETE FROM people WHERE id=?'); $q->execute([$removePerson]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof PDOException) throw new RuntimeException('The accounts contain conflicting linked records. No changes were saved. Resolve duplicate APE/enrollment records before merging.', 0, $e);
        throw $e;
    }
}
