<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AccountValidation.php';

function ensure_system_settings_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    auth_db()->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(120) PRIMARY KEY,
            setting_value MEDIUMTEXT NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (updated_by) REFERENCES people(id) ON DELETE SET NULL
        )
    ");

    $ready = true;
}

function ensure_staff_profiles_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    ensure_system_settings_schema();

    $db = auth_db();
    $profilePhotoColumns = $db->query("SHOW COLUMNS FROM people LIKE 'profile_photo_path'")->fetchAll();
    if (empty($profilePhotoColumns)) {
        $db->exec("ALTER TABLE people ADD COLUMN profile_photo_path VARCHAR(255) NULL AFTER last_name");
    }

    $columns = $db->query("SHOW COLUMNS FROM accounts LIKE 'email'")->fetchAll();
    if (empty($columns)) {
        $db->exec("ALTER TABLE accounts ADD COLUMN email VARCHAR(160) NULL");
        // Populate existing staff emails
        $db->exec("
            UPDATE accounts a
            JOIN people pe ON pe.id = a.person_id
            SET a.email = LOWER(CONCAT(REPLACE(pe.last_name, ' ', ''), '_', REPLACE(pe.first_name, ' ', ''), '@plpasig.edu.ph'))
            WHERE a.email IS NULL OR a.email = ''
        ");
    }

    $ready = true;
}

function ensure_patients_vitals_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $db = auth_db();
    
    // 1. Check & Update patients table
    $columnsPatients = $db->query("SHOW COLUMNS FROM patients LIKE 'height_cm'")->fetchAll();
    if (empty($columnsPatients)) {
        $db->exec("
            ALTER TABLE patients 
            ADD COLUMN height_cm DECIMAL(5,2) NULL,
            ADD COLUMN weight_kg DECIMAL(5,2) NULL,
            ADD COLUMN bmi DECIMAL(4,1) NULL
        ");
    }

    // 2. Check & Update vital_signs table
    $columnsVitals = $db->query("SHOW COLUMNS FROM vital_signs LIKE 'patient_id'")->fetchAll();
    if (empty($columnsVitals)) {
        $db->exec("
            ALTER TABLE vital_signs 
            MODIFY COLUMN visit_id BIGINT UNSIGNED NULL,
            ADD COLUMN patient_id BIGINT UNSIGNED NULL,
            ADD CONSTRAINT fk_vital_signs_patient
                FOREIGN KEY (patient_id) REFERENCES patients(person_id) ON DELETE CASCADE,
            ADD INDEX idx_vital_signs_patient (patient_id)
        ");
    }

    $ready = true;
}

function staff_profile_roles(): array
{
    return [
        'doctor' => 'Doctor',
        'nurse' => 'Nurse',
        'staff' => 'Clinic Staff',
        'admin' => 'Administrator',
        'it_expert' => 'IT Expert',
    ];
}

function normalize_staff_profile_role(string $role): string
{
    return array_key_exists($role, staff_profile_roles()) ? $role : 'staff';
}

function staff_profiles(): array
{
    ensure_staff_profiles_schema();

    return auth_db()->query('
        SELECT
            pe.id,
            pe.id_number,
            pe.profile_photo_path,
            TRIM(CONCAT_WS(" ", pe.first_name, pe.middle_name, pe.last_name)) AS name,
            a.email,
            cs.staff_role AS role,
            cs.position_title,
            a.account_status,
            a.created_at
        FROM clinic_staff cs
        JOIN people pe ON pe.id = cs.person_id
        JOIN accounts a ON a.person_id = pe.id
        ORDER BY FIELD(cs.staff_role, "doctor", "nurse", "staff", "admin", "it_expert"), pe.last_name, pe.first_name, pe.id_number
    ')->fetchAll();
}

function staff_profile_role_label(string $role): string
{
    return staff_profile_roles()[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

function create_staff_profile(array $input): void
{
    ensure_staff_profiles_schema();

    $name = trim((string) ($input['name'] ?? ''));
    $idNumber = trim((string) ($input['id_number'] ?? $input['email'] ?? ''));
    $role = normalize_staff_profile_role((string) ($input['role'] ?? 'staff'));
    $password = (string) ($input['password'] ?? '');
    $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');

    if (!account_valid_person_name($name)) {
        throw new InvalidArgumentException("Staff name may contain only letters, spaces, apostrophes, periods, and hyphens.");
    }
    if ($idNumber === '') {
        $idNumber = next_staff_profile_id_number();
    }
    if (!preg_match('/^[0-9]{7}$/', $idNumber)) {
        throw new InvalidArgumentException('Staff login ID must contain exactly seven continuous digits, for example 0000002.');
    }
    account_assert_strong_password($password, $passwordConfirmation);

    [$firstName, $middleName, $lastName] = split_staff_profile_name($name);
    $db = auth_db();
    try {
        $db->beginTransaction();

        $duplicate = $db->prepare('SELECT id FROM people WHERE id_number = ? LIMIT 1');
        $duplicate->execute([$idNumber]);
        if ($duplicate->fetchColumn()) {
            throw new InvalidArgumentException('That staff login ID already exists.');
        }

        $emailLocalPart = strtolower(preg_replace('/[^a-z0-9]+/i', '', $lastName) . '_' . preg_replace('/[^a-z0-9]+/i', '', $firstName));
        $email = account_assert_institutional_email($emailLocalPart . '@plpasig.edu.ph');
        account_assert_email_available($db, $email);

        $stmt = $db->prepare('
            INSERT INTO people (id_number, first_name, middle_name, last_name)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$idNumber, $firstName, $middleName, $lastName]);
        $personId = (int) $db->lastInsertId();

        $departmentId = staff_profile_default_department_id();
        $stmt = $db->prepare('
            INSERT INTO clinic_staff (person_id, department_id, staff_role, position_title)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$personId, $departmentId, $role, staff_profile_role_label($role)]);

        $stmt = $db->prepare('
            INSERT INTO patients (person_id, emergency_token, token_enabled)
            VALUES (?, ?, 1)
        ');
        $stmt->execute([$personId, bin2hex(random_bytes(32))]);

        $stmt = $db->prepare('
            INSERT INTO accounts (person_id, email, password_hash, account_status, activated_at)
            VALUES (?, ?, ?, "active", NOW())
            ON DUPLICATE KEY UPDATE
                email = VALUES(email),
                password_hash = VALUES(password_hash),
                account_status = VALUES(account_status),
                activated_at = VALUES(activated_at)
        ');
        $stmt->execute([$personId, $email, password_hash($password, PASSWORD_DEFAULT)]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function update_staff_profile(array $input): void
{
    ensure_staff_profiles_schema();

    $id = (int) ($input['user_id'] ?? 0);
    $name = trim((string) ($input['name'] ?? ''));
    $idNumber = trim((string) ($input['id_number'] ?? $input['email'] ?? ''));
    $role = normalize_staff_profile_role((string) ($input['role'] ?? 'staff'));

    if ($id <= 0) {
        throw new InvalidArgumentException('Select a staff profile to update.');
    }
    if ($name === '') {
        throw new InvalidArgumentException('Enter the staff member name.');
    }
    if (!preg_match('/^[0-9]{7}$/', $idNumber)) {
        throw new InvalidArgumentException('Staff login ID must contain exactly seven continuous digits, for example 0000002.');
    }

    [$firstName, $middleName, $lastName] = split_staff_profile_name($name);
    $db = auth_db();
    try {
        $db->beginTransaction();

        $duplicate = $db->prepare('SELECT id FROM people WHERE id_number = ? AND id <> ? LIMIT 1');
        $duplicate->execute([$idNumber, $id]);
        if ($duplicate->fetchColumn()) {
            throw new InvalidArgumentException('That staff login ID already exists.');
        }

        $stmt = $db->prepare('
            UPDATE people
            SET id_number = ?, first_name = ?, middle_name = ?, last_name = ?
            WHERE id = ?
        ');
        $stmt->execute([$idNumber, $firstName, $middleName, $lastName, $id]);

        $email = strtolower(str_replace(' ', '', $lastName) . '_' . str_replace(' ', '', $firstName)) . '@plpasig.edu.ph';
        $stmt = $db->prepare('UPDATE accounts SET email = ? WHERE person_id = ?');
        $stmt->execute([$email, $id]);

        $stmt = $db->prepare('UPDATE clinic_staff SET staff_role = ?, position_title = ? WHERE person_id = ?');
        $stmt->execute([$role, staff_profile_role_label($role), $id]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function reset_staff_profile_password(array $input): void
{
    ensure_staff_profiles_schema();

    $id = (int) ($input['user_id'] ?? 0);
    $password = (string) ($input['password'] ?? '');
    $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');

    if ($id <= 0) {
        throw new InvalidArgumentException('Select a staff profile before resetting the password.');
    }
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('New password must be at least 8 characters.');
    }
    if ($password !== $passwordConfirmation) {
        throw new InvalidArgumentException('New password and confirmation password must match.');
    }

    $stmt = auth_db()->prepare('UPDATE accounts SET password_hash = ? WHERE person_id = ?');
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
}

function split_staff_profile_name(string $name): array
{
    $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
    if (count($parts) === 1) {
        return [$parts[0], null, $parts[0]];
    }

    $firstName = array_shift($parts);
    $lastName = array_pop($parts);
    $middleName = $parts ? implode(' ', $parts) : null;
    return [$firstName, $middleName, $lastName];
}

function next_staff_profile_id_number(): string
{
    $next = (int) auth_db()->query("
        SELECT COALESCE(MAX(CAST(id_number AS UNSIGNED)), 0) + 1
        FROM people
        WHERE id_number REGEXP '^[0-9]{7}$'
    ")->fetchColumn();

    if ($next > 9999999) {
        throw new RuntimeException('No seven-digit staff login IDs remain available.');
    }

    return str_pad((string) $next, 7, '0', STR_PAD_LEFT);
}

function staff_profile_default_department_id(): ?int
{
    $stmt = auth_db()->prepare('SELECT id FROM departments WHERE department_code = ? LIMIT 1');
    $stmt->execute(['UHS']);
    $departmentId = (int) $stmt->fetchColumn();
    return $departmentId > 0 ? $departmentId : null;
}

function cliniq_setting_read(string $key, array $default = []): array
{
    try {
        ensure_system_settings_schema();
        $stmt = auth_db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $raw = $stmt->fetchColumn();
        if (!$raw) {
            return $default;
        }

        $saved = json_decode((string) $raw, true);
        return is_array($saved) ? array_merge($default, $saved) : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function cliniq_setting_write(string $key, array $value, ?int $updatedBy = null): void
{
    ensure_system_settings_schema();

    $stmt = auth_db()->prepare('
        INSERT INTO system_settings (setting_key, setting_value, updated_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
    ');
    $stmt->execute([
        $key,
        json_encode($value),
        $updatedBy,
    ]);
}

function cliniq_legal_document_fields(string $document): array
{
    return $document === 'terms'
        ? ['effective_date' => 'Effective date', 'system_owner' => 'System owner', 'contact_office' => 'Contact office', 'contact_email' => 'Contact email', 'contact_phone' => 'Contact phone']
        : ['effective_date' => 'Effective date', 'controller' => 'Personal information controller', 'clinic_address' => 'Clinic address', 'privacy_contact' => 'Data Protection Officer / privacy contact'];
}

function cliniq_legal_markdown_html(string $markdown): string
{
    $html = ''; $paragraph = []; $list = null;
    $flush = static function () use (&$html, &$paragraph): void { if ($paragraph !== []) { $html .= '<p>' . e(implode(' ', array_map('trim', $paragraph))) . '</p>'; $paragraph = []; } };
    foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') { $flush(); if ($list !== null) { $html .= '</' . $list . '>'; $list = null; } continue; }
        if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $match)) { $flush(); if ($list !== null) { $html .= '</' . $list . '>'; $list = null; } $html .= '<h' . strlen($match[1]) . '>' . e($match[2]) . '</h' . strlen($match[1]) . '>'; continue; }
        if (preg_match('/^[-*]\s+(.+)$/', $line, $match)) { $flush(); if ($list !== 'ul') { if ($list !== null) { $html .= '</' . $list . '>'; } $list = 'ul'; $html .= '<ul>'; } $html .= '<li>' . e($match[1]) . '</li>'; continue; }
        $paragraph[] = $line;
    }
    $flush(); if ($list !== null) { $html .= '</' . $list . '>'; }
    return $html;
}

function cliniq_sanitize_legal_html(string $html): string
{
    $html = strip_tags($html, '<h1><h2><h3><p><ul><ol><li><strong><em><br><a>');
    $html = preg_replace('/<(h[1-3]|p|ul|ol|li|strong|em|br)\b[^>]*>/i', '<$1>', $html) ?? $html;
    return preg_replace_callback('/<a\b([^>]*)>/i', static function (array $match): string {
        if (!preg_match('/href\s*=\s*(["\'])(.*?)\1/i', $match[1], $href)) { return '<a>'; }
        $url = trim(html_entity_decode($href[2], ENT_QUOTES, 'UTF-8'));
        return preg_match('/^(?:https:\/\/|[A-Za-z0-9._\/-]+\.php(?:\?[^\s]*)?)$/', $url) ? '<a href="' . e($url) . '" target="_blank" rel="noopener">' : '<a>';
    }, $html) ?? '';
}

function cliniq_legal_document_normalize(string $document, mixed $value): array
{
    $fields = cliniq_legal_document_fields($document);
    if (is_array($value)) {
        $details = is_array($value['details'] ?? null) ? $value['details'] : [];
        $normalizedDetails = [];
        foreach (array_keys($fields) as $key) {
            $normalizedDetails[$key] = trim((string) ($details[$key] ?? ''));
        }
        return ['title' => trim((string) ($value['title'] ?? ($document === 'terms' ? 'Terms of Use' : 'Privacy Notice'))), 'details' => $normalizedDetails, 'body_html' => cliniq_sanitize_legal_html((string) ($value['body_html'] ?? ''))];
    }
    $legacy = trim((string) $value);
    $title = $document === 'terms' ? 'Terms of Use' : 'Privacy Notice';
    if (preg_match('/^#\s+(.+)$/m', $legacy, $match)) { $title = trim($match[1]); }
    $details = array_fill_keys(array_keys($fields), '');
    foreach ($fields as $key => $label) { if (preg_match('/\*\*' . preg_quote($label, '/') . ':\*\*\s*(.+)$/mi', $legacy, $match)) { $details[$key] = trim($match[1]); } }
    $body = preg_replace('/^#\s+.+$|^\*\*[^\n]+:\*\*.*$/m', '', $legacy) ?? $legacy;
    return ['title' => $title, 'details' => $details, 'body_html' => cliniq_legal_markdown_html($body)];
}

function default_cliniq_legal_documents(): array
{
    $defaults = ['terms' => [], 'privacy' => [], 'version' => '2026-09-19', 'updated_at' => null];
    foreach (['terms', 'privacy'] as $document) {
        $path = __DIR__ . '/../../docs/legal/' . ($document === 'terms' ? 'terms-of-use.md' : 'privacy-notice.md');
        $defaults[$document] = cliniq_legal_document_normalize($document, is_file($path) ? (string) file_get_contents($path) : '');
    }
    return $defaults;
}

function cliniq_legal_documents(): array
{
    $defaults = default_cliniq_legal_documents();
    $saved = cliniq_setting_read('legal.documents', $defaults);
    foreach (['terms', 'privacy'] as $document) {
        $saved[$document] = cliniq_legal_document_normalize($document, $saved[$document] ?? $defaults[$document]);
    }
    $saved['version'] = trim((string) ($saved['version'] ?? $defaults['version'])) ?: $defaults['version'];
    $saved['updated_at'] = $saved['updated_at'] ?? null;
    return $saved;
}

function save_cliniq_legal_documents(array $input, ?int $updatedBy = null): array
{
    $current = cliniq_legal_documents();
    $terms = cliniq_legal_document_normalize('terms', $input['terms'] ?? []);
    $privacy = cliniq_legal_document_normalize('privacy', $input['privacy'] ?? []);
    if ($terms['title'] === '' || $privacy['title'] === '' || $terms['body_html'] === '' || $privacy['body_html'] === '') {
        throw new InvalidArgumentException('Both the Terms of Use and Privacy Notice are required.');
    }
    if (mb_strlen($terms['body_html']) > 100000 || mb_strlen($privacy['body_html']) > 100000) {
        throw new InvalidArgumentException('Each legal document must be 100,000 characters or fewer.');
    }
    $settings = ['terms' => $terms, 'privacy' => $privacy, 'version' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
    cliniq_setting_write('legal.documents', $settings, $updatedBy);
    return $settings + ['previous_version' => $current['version']];
}

function cliniq_backup_external_settings(): array
{
    $saved = cliniq_setting_read('backup.external_destination', [
        'enabled' => false,
        'folder' => '',
    ]);

    return [
        'enabled' => filter_var($saved['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'folder' => trim((string) ($saved['folder'] ?? '')),
    ];
}

function save_cliniq_backup_external_settings(array $input, ?int $updatedBy = null): array
{
    $folder = trim((string) ($input['folder'] ?? ''));
    $folder = str_replace('\\', '/', $folder);
    $folder = trim($folder, " /");

    if (strlen($folder) > 180) {
        throw new InvalidArgumentException('The external backup folder name is too long.');
    }
    if ($folder !== '' && (str_starts_with($folder, '/') || preg_match('/^[A-Za-z]:/', $folder) === 1)) {
        throw new InvalidArgumentException('Enter a folder relative to the connected backup drive, not a full drive path.');
    }
    foreach (explode('/', $folder) as $segment) {
        if ($segment === '..' || preg_match('/[<>:"|?*\x00]/', $segment) === 1) {
            throw new InvalidArgumentException('The external backup folder contains an invalid name.');
        }
    }

    $settings = [
        'enabled' => !empty($input['enabled']),
        'folder' => $folder,
    ];
    cliniq_setting_write('backup.external_destination', $settings, $updatedBy);
    return $settings;
}

function cliniq_clinic_server_settings(): array
{
    return cliniq_setting_read('clinic.server', [
        'configured' => false,
        'hostname' => '',
        'local_ip' => '',
    ]);
}

function save_cliniq_clinic_server_settings(array $input, ?int $updatedBy = null): array
{
    $hostname = trim((string) ($input['hostname'] ?? ''));
    $localIp = trim((string) ($input['local_ip'] ?? ''));
    if ($hostname === '' || strlen($hostname) > 63 || preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/', $hostname) !== 1) {
        throw new InvalidArgumentException('The clinic server computer name is invalid.');
    }
    if (filter_var($localIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $localIp === '127.0.0.1') {
        throw new InvalidArgumentException('The clinic server must have a non-loopback IPv4 address.');
    }

    $settings = [
        'configured' => true,
        'hostname' => $hostname,
        'local_ip' => $localIp,
    ];
    cliniq_setting_write('clinic.server', $settings, $updatedBy);
    return $settings;
}

function clear_cliniq_clinic_server_settings(?int $updatedBy = null): void
{
    cliniq_setting_write('clinic.server', [
        'configured' => false,
        'hostname' => '',
        'local_ip' => '',
    ], $updatedBy);
}

function default_ape_required_documents(): array
{
    return [
        'Lab Request Form',
        'UHS Consent Form',
        'UHS Medical Record',
        'UHS Dental Record',
        'Referral Form',
    ];
}

function normalize_ape_required_documents(array $documents): array
{
    $normalized = [];
    $seen = [];

    foreach ($documents as $document) {
        if (!is_scalar($document)) {
            continue;
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $document)));
        if ($name === '') {
            continue;
        }
        if (mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Each required document name must be 120 characters or fewer.');
        }

        $key = mb_strtolower($name);
        if (isset($seen[$key])) {
            throw new InvalidArgumentException("The required document '{$name}' is listed more than once.");
        }

        $seen[$key] = true;
        $normalized[] = $name;
    }

    if (!$normalized) {
        throw new InvalidArgumentException('Keep at least one required APE document.');
    }
    if (count($normalized) > 20) {
        throw new InvalidArgumentException('A maximum of 20 required APE documents is allowed.');
    }

    return $normalized;
}

function ape_required_documents(): array
{
    $defaults = default_ape_required_documents();
    $settings = cliniq_setting_read('ape_required_documents', ['documents' => $defaults]);

    try {
        return normalize_ape_required_documents((array) ($settings['documents'] ?? $defaults));
    } catch (Throwable $e) {
        return $defaults;
    }
}

function save_ape_required_documents(array $documents, ?int $updatedBy = null): array
{
    $normalized = normalize_ape_required_documents($documents);
    cliniq_setting_write('ape_required_documents', ['documents' => $normalized], $updatedBy);
    return $normalized;
}

function ensure_dropdown_options_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $db = auth_db();
    $columns = [
        ['patients', 'sex', "VARCHAR(20) NULL"],
        ['clinic_visits', 'status', "VARCHAR(40) NOT NULL DEFAULT 'Unaddressed'"],
        ['clinic_visits', 'visit_source', "VARCHAR(80) NOT NULL DEFAULT 'Staff Recorded'"],
        ['nurse_alerts', 'risk_level', "VARCHAR(40) NOT NULL DEFAULT 'Low'"],
        ['nurse_alerts', 'status', "VARCHAR(40) NOT NULL DEFAULT 'Pending'"],
        ['incident_reports', 'status', "VARCHAR(40) NOT NULL DEFAULT 'New'"],
        ['inventory_loans', 'status', "VARCHAR(40) NOT NULL DEFAULT 'Borrowed'"],
        ['inventory_loans', 'return_condition', "VARCHAR(40) NULL"],
        ['referrals', 'status', "VARCHAR(40) NOT NULL DEFAULT 'Completed'"],
        ['appointments', 'status', "VARCHAR(40) NOT NULL DEFAULT 'Pending'"],
        ['ape_records', 'requirement_status', "VARCHAR(80) NOT NULL DEFAULT 'Not Checked'"],
        ['ape_records', 'workflow_status', "VARCHAR(80) NOT NULL DEFAULT 'Submitted'"],
        ['ape_records', 'verification_status', "VARCHAR(80) NOT NULL DEFAULT 'Pending'"],
        ['ape_records', 'clearance_status', "VARCHAR(80) NOT NULL DEFAULT 'Pending'"],
        ['ape_records', 'result_status', "VARCHAR(80) NOT NULL DEFAULT 'Pending'"],
    ];

    foreach ($columns as [$table, $column, $definition]) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
            $meta = $stmt ? $stmt->fetch() : false;
            $type = strtolower((string) ($meta['Type'] ?? ''));
            if ($type !== '' && str_starts_with($type, 'enum(')) {
                $db->exec("ALTER TABLE {$table} MODIFY {$column} {$definition}");
            }
        } catch (Throwable $e) {
            // Editable dropdowns should not block the page on installs with limited schema privileges.
        }
    }

    $ready = true;
}

function default_dropdown_option_groups(): array
{
    return [
        'person_category' => [
            'label' => 'Person Category',
            'description' => 'Used for visitor and emergency patient registration.',
            'options' => ['Student', 'Staff', 'Faculty', 'Guest'],
        ],
        'year_level' => [
            'label' => 'Year Level',
            'description' => 'Used for student-facing and visitor forms.',
            'options' => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
        ],
        'department' => [
            'label' => 'Course / Department Suggestions',
            'description' => 'Used as suggestions for visitor course or department fields.',
            'options' => ['College of Computer Studies', 'College of Nursing', 'Arts & Sciences', 'Administrative Office', 'Guest / Visitor'],
        ],
        'student_program' => [
            'label' => 'Student Program',
            'description' => 'Used on the student registration program dropdown.',
            'options' => ['BSIT'],
        ],
        'student_section' => [
            'label' => 'Student Section',
            'description' => 'Used on the student registration section dropdown.',
            'options' => ['A', 'B', 'C', 'D', 'E'],
        ],
        'blood_type' => [
            'label' => 'Blood Type',
            'description' => 'Used on the student health passport.',
            'options' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown'],
        ],
        'guardian_relationship' => [
            'label' => 'Guardian Relationship',
            'description' => 'Used on the student emergency contact form.',
            'options' => ['Mother', 'Father', 'Parent', 'Sibling', 'Spouse', 'Relative', 'Guardian', 'Other'],
        ],
        'appointment_purpose' => [
            'label' => 'Appointment Purpose',
            'description' => 'Used on the student appointment request form.',
            'options' => ['Medical Consult', 'Dental'],
        ],
        'visit_status' => [
            'label' => 'Visit Status',
            'description' => 'Used on clinic visit forms and filters.',
            'options' => ['Unaddressed', 'Active', 'Completed', 'Cancelled'],
        ],
        'visit_purpose' => [
            'label' => 'Visit Purpose',
            'description' => 'Used for clinic visit purpose filters and records.',
            'options' => ['Medical Consult', 'Health Monitoring', 'Pain Management', 'Dental Consult', 'Wound Care', 'APE', 'Emergency', 'Other'],
        ],
        'visit_source' => [
            'label' => 'Visit Source',
            'description' => 'Used internally for how a clinic visit was created.',
            'options' => ['Self Logbook', 'Staff Recorded', 'Nurse Emergency'],
        ],
        'referral_type' => [
            'label' => 'Referral Type',
            'description' => 'Used on treatment and emergency visit records.',
            'options' => ['None', 'Advised to Go Home', 'Barangay Health Center', 'Public Hospital', 'Private Hospital', 'Specialist Referral'],
        ],
        'inventory_return_condition' => [
            'label' => 'Inventory Return Condition',
            'description' => 'Used when returning borrowed equipment.',
            'options' => ['Good', 'Defective', 'Lost'],
        ],
        'incident_type' => [
            'label' => 'Incident Type',
            'description' => 'Used on emergency incident and nurse alert reports.',
            'options' => ['Breathing difficulty', 'Fainting or unconscious', 'Injury or fall', 'Bleeding or wound', 'Allergic reaction', 'Fever or illness', 'Other concern'],
        ],
        'incident_condition' => [
            'label' => 'Incident Student Condition',
            'description' => 'Used on emergency incident reports.',
            'options' => ['Awake and responsive', 'Dizzy or weak', 'Severe pain', 'Seizure-like movement', 'Unconscious'],
        ],
        'incident_breathing' => [
            'label' => 'Incident Breathing',
            'description' => 'Used on emergency incident reports.',
            'options' => ['Normal', 'Shortness of breath', 'Wheezing', 'Not breathing normally'],
        ],
        'incident_bleeding' => [
            'label' => 'Incident Bleeding',
            'description' => 'Used on emergency incident reports.',
            'options' => ['None observed', 'Minor bleeding', 'Heavy bleeding'],
        ],
        'incident_pain_level' => [
            'label' => 'Incident Pain Level',
            'description' => 'Used on emergency incident reports.',
            'options' => ['0 - No pain', '1-3 - Mild pain', '4-6 - Moderate pain', '7-10 - Severe pain'],
        ],
        'incident_mobility' => [
            'label' => 'Incident Mobility',
            'description' => 'Used on emergency incident reports.',
            'options' => ['Can walk', 'Needs assistance', 'Cannot stand or walk'],
        ],
        'ape_requirement_status' => [
            'label' => 'APE Requirement Status',
            'description' => 'Used when creating and reviewing APE records.',
            'options' => ['Not Checked', 'Checked', 'Needs Correction'],
        ],
        'ape_verification_status' => [
            'label' => 'APE Verification Status',
            'description' => 'Used when creating and reviewing APE records.',
            'options' => ['Pending', 'Verified', 'Needs Correction'],
        ],
        'ape_workflow_status' => [
            'label' => 'APE Workflow Status',
            'description' => 'Used for APE workflow states.',
            'options' => ['Registered', 'Batch Assigned', 'Requirements Checked', 'Submitted', 'Reviewed', 'Scheduled', 'Exam Done', 'Follow-up Required', 'Cleared'],
        ],
    ];
}

function dropdown_option_slug(string $label): string
{
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $label), '-'));
    return $slug !== '' ? $slug : 'option-' . bin2hex(random_bytes(4));
}

function normalize_dropdown_options(array $options): array
{
    $normalized = [];
    $seen = [];

    foreach ($options as $index => $option) {
        if (is_array($option)) {
            $label = trim((string) ($option['label'] ?? $option['value'] ?? ''));
            $id = trim((string) ($option['id'] ?? ''));
            $active = array_key_exists('active', $option) ? filter_var($option['active'], FILTER_VALIDATE_BOOLEAN) : true;
        } else {
            $label = trim((string) $option);
            $id = '';
            $active = true;
        }

        if ($label === '') {
            continue;
        }

        $id = $id !== '' ? $id : dropdown_option_slug($label);
        if (isset($seen[$id])) {
            $id .= '-' . ($index + 1);
        }
        $seen[$id] = true;

        $normalized[] = [
            'id' => $id,
            'label' => mb_substr($label, 0, 120),
            'active' => $active,
        ];
    }

    return $normalized;
}

function dropdown_option_groups(): array
{
    $defaults = default_dropdown_option_groups();
    $saved = cliniq_setting_read('clinic.dropdown_options', []);
    $groups = [];

    foreach ($defaults as $key => $definition) {
        $groups[$key] = [
            'label' => $definition['label'],
            'description' => $definition['description'],
            'options' => normalize_dropdown_options($saved[$key]['options'] ?? $definition['options']),
        ];
    }

    return $groups;
}

function dropdown_options(string $groupKey, bool $activeOnly = true): array
{
    $groups = dropdown_option_groups();
    $options = $groups[$groupKey]['options'] ?? [];

    if ($activeOnly) {
        $options = array_values(array_filter($options, fn(array $option): bool => !empty($option['active'])));
    }

    return array_values(array_map(fn(array $option): string => $option['label'], $options));
}

function save_dropdown_option_groups(array $groups, ?int $updatedBy = null): void
{
    $defaults = default_dropdown_option_groups();
    $payload = [];

    foreach ($defaults as $key => $definition) {
        $payload[$key] = [
            'options' => normalize_dropdown_options($groups[$key]['options'] ?? $definition['options']),
        ];
    }

    cliniq_setting_write('clinic.dropdown_options', $payload, $updatedBy);
}

function add_dropdown_option(string $groupKey, string $label, ?int $updatedBy = null): void
{
    $groups = dropdown_option_groups();
    if (!isset($groups[$groupKey])) {
        throw new InvalidArgumentException('Select a valid dropdown group.');
    }

    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('Enter the dropdown option label.');
    }

    $groups[$groupKey]['options'][] = [
        'id' => dropdown_option_slug($label) . '-' . bin2hex(random_bytes(2)),
        'label' => $label,
        'active' => true,
    ];

    save_dropdown_option_groups($groups, $updatedBy);
}

function update_dropdown_option(string $groupKey, string $optionId, string $label, bool $active, ?int $updatedBy = null): void
{
    $groups = dropdown_option_groups();
    if (!isset($groups[$groupKey])) {
        throw new InvalidArgumentException('Select a valid dropdown group.');
    }

    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('Enter the dropdown option label.');
    }

    foreach ($groups[$groupKey]['options'] as &$option) {
        if ($option['id'] === $optionId) {
            $option['label'] = $label;
            $option['active'] = $active;
            save_dropdown_option_groups($groups, $updatedBy);
            return;
        }
    }

    throw new InvalidArgumentException('Select a valid dropdown option.');
}

function delete_dropdown_option(string $groupKey, string $optionId, ?int $updatedBy = null): void
{
    $groups = dropdown_option_groups();
    if (!isset($groups[$groupKey])) {
        throw new InvalidArgumentException('Select a valid dropdown group.');
    }

    $groups[$groupKey]['options'] = array_values(array_filter(
        $groups[$groupKey]['options'],
        fn(array $option): bool => $option['id'] !== $optionId
    ));

    save_dropdown_option_groups($groups, $updatedBy);
}

function default_clinic_profile_settings(): array
{
    return [
        'system_name' => 'CLINiQ',
        'institution_name' => 'Pamantasan ng Lungsod ng Pasig',
        'department' => 'University Health Services',
        'contact_email' => 'clinic@plpasig.edu.ph',
        'physical_address' => 'Alcalde Jose Street, Brgy. Kapasigan, Pasig City, Metro Manila, Philippines, 1600',
        'system_purpose' => 'School clinic information management system for patient records, visits, APE workflow, emergency alerts, appointments, inventory, referrals, and reports.',
        'logo_path' => 'assets/img/clinic-logo.png',
        'alert_sound' => 'urgent-pulse',
        'custom_alert_sound_path' => '',
        'custom_alert_sound_name' => '',
    ];
}

function clinic_alert_sound_options(): array
{
    return [
        'urgent-pulse' => 'Urgent Pulse',
        'double-chime' => 'Double Chime',
        'rapid-siren' => 'Rapid Siren',
    ];
}

function clinic_profile_alert_sound_path(?array $profile = null): string
{
    $soundPath = str_replace('\\', '/', trim((string) (($profile ?? [])['custom_alert_sound_path'] ?? '')));
    if (
        $soundPath === ''
        || str_contains($soundPath, '..')
        || str_starts_with($soundPath, '/')
        || !preg_match('#^uploads/settings/[A-Za-z0-9._-]+\.(mp3|wav|ogg)$#i', $soundPath)
    ) {
        return '';
    }

    return $soundPath;
}

function clinic_profile_settings(): array
{
    return normalize_clinic_profile_settings(cliniq_setting_read('clinic.profile', default_clinic_profile_settings()));
}

function normalize_clinic_profile_settings(array $input): array
{
    $defaults = default_clinic_profile_settings();
    $settings = [];

    foreach ($defaults as $key => $default) {
        $value = trim((string) ($input[$key] ?? $default));
        $settings[$key] = $value !== '' ? mb_substr($value, 0, $key === 'system_purpose' ? 500 : 255) : $default;
    }

    if (!filter_var($settings['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $settings['contact_email'] = $defaults['contact_email'];
    }
    if (!array_key_exists($settings['alert_sound'], clinic_alert_sound_options())) {
        $settings['alert_sound'] = $settings['alert_sound'] === 'custom'
            && clinic_profile_alert_sound_path($settings) !== ''
            ? 'custom'
            : $defaults['alert_sound'];
    }
    $settings['logo_path'] = clinic_profile_logo_path($settings);
    $settings['custom_alert_sound_path'] = clinic_profile_alert_sound_path($settings);
    $settings['custom_alert_sound_name'] = $settings['custom_alert_sound_path'] === ''
        ? ''
        : mb_substr(basename(trim((string) ($input['custom_alert_sound_name'] ?? 'Custom alert sound'))), 0, 255);

    return $settings;
}

function save_clinic_profile_settings(array $input, ?int $updatedBy = null): void
{
    cliniq_setting_write('clinic.profile', normalize_clinic_profile_settings($input), $updatedBy);
}

function clinic_profile_logo_path(?array $profile = null): string
{
    $defaults = default_clinic_profile_settings();
    $logoPath = str_replace('\\', '/', trim((string) (($profile ?? [])['logo_path'] ?? $defaults['logo_path'])));

    if (
        $logoPath === ''
        || str_contains($logoPath, '..')
        || str_starts_with($logoPath, '/')
        || !preg_match('#^(assets|uploads)/[A-Za-z0-9._/-]+\.(png|jpe?g|webp)$#i', $logoPath)
    ) {
        return $defaults['logo_path'];
    }

    return $logoPath;
}

function save_uploaded_clinic_logo(array $file): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Choose a valid logo image before saving.');
    }

    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Logo image must be 5 MB or smaller.');
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new InvalidArgumentException('Logo upload could not be verified.');
    }

    $mimeType = function_exists('mime_content_type') ? (string) mime_content_type($temporaryPath) : '';
    $allowedTypes = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
    if (!isset($allowedTypes[$mimeType])) {
        throw new InvalidArgumentException('Logo must be a PNG, JPG, or WebP image.');
    }

    $uploadDirectory = dirname(__DIR__, 2) . '/public/uploads/settings';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Unable to create the logo upload folder.');
    }

    $fileName = 'clinic-logo-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$mimeType];
    $destination = $uploadDirectory . '/' . $fileName;
    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException('Unable to save the uploaded logo.');
    }

    return 'uploads/settings/' . $fileName;
}

function save_uploaded_clinic_alert_sound(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return [];
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Choose a valid MP3, WAV, or OGG alert sound before saving.');
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Custom alert sound must be 5 MB or smaller.');
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new InvalidArgumentException('Alert sound upload could not be verified.');
    }

    $mimeType = function_exists('mime_content_type') ? (string) mime_content_type($temporaryPath) : '';
    $allowedTypes = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/wave' => 'wav',
        'audio/vnd.wave' => 'wav',
        'audio/ogg' => 'ogg',
        'application/ogg' => 'ogg',
    ];
    if (!isset($allowedTypes[$mimeType])) {
        throw new InvalidArgumentException('Custom alert sound must be a valid MP3, WAV, or OGG audio file.');
    }

    $uploadDirectory = dirname(__DIR__, 2) . '/public/uploads/settings';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Unable to create the alert sound upload folder.');
    }

    $fileName = 'clinic-alert-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$mimeType];
    if (!move_uploaded_file($temporaryPath, $uploadDirectory . '/' . $fileName)) {
        throw new RuntimeException('Unable to save the custom alert sound.');
    }

    return [
        'path' => 'uploads/settings/' . $fileName,
        'name' => mb_substr(basename((string) ($file['name'] ?? 'Custom alert sound')), 0, 255),
    ];
}

function cliniq_theme_presets(): array
{
    return [
        'green' => [
            'label' => 'Green',
            'primary' => '#3F7D52',
            'primary_fixed' => '#e8f6ec',
            'primary_container' => '#23422C',
            'surface' => '#f4fbf6',
            'surface_container_low' => '#edf8f0',
            'outline_variant' => '#c7dccd',
            'accent' => '#e4f4e8',
            'focus_rgb' => '63, 125, 82',
            'shadow_rgb' => '35, 66, 44',
        ],
        'blue' => [
            'label' => 'Blue',
            'primary' => '#00478d',
            'primary_fixed' => '#d6e3ff',
            'primary_container' => '#003d7c',
            'surface' => '#f8f9fa',
            'surface_container_low' => '#eef4ff',
            'outline_variant' => '#c2c6d4',
            'accent' => '#e8f1ff',
            'focus_rgb' => '0, 71, 141',
            'shadow_rgb' => '0, 61, 124',
        ],
        'emerald' => [
            'label' => 'Emerald',
            'primary' => '#059669',
            'primary_fixed' => '#d1fae5',
            'primary_container' => '#047857',
            'surface' => '#f6fffb',
            'surface_container_low' => '#ecfdf5',
            'outline_variant' => '#bbf7d0',
            'accent' => '#dff8ed',
            'focus_rgb' => '5, 150, 105',
            'shadow_rgb' => '4, 120, 87',
        ],
        'purple' => [
            'label' => 'Purple',
            'primary' => '#7c3aed',
            'primary_fixed' => '#ede9fe',
            'primary_container' => '#6d28d9',
            'surface' => '#fbf9ff',
            'surface_container_low' => '#f5f3ff',
            'outline_variant' => '#ddd6fe',
            'accent' => '#f0ebff',
            'focus_rgb' => '124, 58, 237',
            'shadow_rgb' => '109, 40, 217',
        ],
        'rose' => [
            'label' => 'Rose',
            'primary' => '#e11d48',
            'primary_fixed' => '#ffe4e6',
            'primary_container' => '#be123c',
            'surface' => '#fff8fa',
            'surface_container_low' => '#fff1f2',
            'outline_variant' => '#fecdd3',
            'accent' => '#ffe8ec',
            'focus_rgb' => '225, 29, 72',
            'shadow_rgb' => '190, 18, 60',
        ],
        'amber' => [
            'label' => 'Amber',
            'primary' => '#d97706',
            'primary_fixed' => '#fef3c7',
            'primary_container' => '#b45309',
            'surface' => '#fffdf7',
            'surface_container_low' => '#fffbeb',
            'outline_variant' => '#fde68a',
            'accent' => '#fff4d6',
            'focus_rgb' => '217, 119, 6',
            'shadow_rgb' => '180, 83, 9',
        ],
    ];
}

function default_cliniq_theme_key(): string
{
    return 'green';
}

function cliniq_theme_settings(): array
{
    $saved = cliniq_setting_read('clinic.theme', [
        'theme' => default_cliniq_theme_key(),
        'custom_color' => '#3F7D52',
        'dark_mode' => false,
    ]);

    return [
        'theme' => cliniq_normalize_theme_key((string) ($saved['theme'] ?? default_cliniq_theme_key())),
        'custom_color' => cliniq_normalize_hex_color((string) ($saved['custom_color'] ?? '#3F7D52')) ?? '#3F7D52',
        'dark_mode' => filter_var($saved['dark_mode'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ];
}

function cliniq_normalize_theme_key(string $theme): string
{
    return $theme === 'custom' || array_key_exists($theme, cliniq_theme_presets())
        ? $theme
        : default_cliniq_theme_key();
}

function cliniq_normalize_hex_color(string $color): ?string
{
    $color = trim($color);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        return null;
    }

    return strtoupper($color);
}

function cliniq_hex_rgb(string $color): array
{
    $hex = ltrim($color, '#');
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function cliniq_mix_hex_colors(string $foreground, string $background, float $foregroundWeight): string
{
    $foregroundRgb = cliniq_hex_rgb($foreground);
    $backgroundRgb = cliniq_hex_rgb($background);
    $foregroundWeight = max(0, min(1, $foregroundWeight));
    $mixed = [];

    for ($index = 0; $index < 3; $index++) {
        $mixed[] = (int) round(
            ($foregroundRgb[$index] * $foregroundWeight)
            + ($backgroundRgb[$index] * (1 - $foregroundWeight))
        );
    }

    return sprintf('#%02X%02X%02X', $mixed[0], $mixed[1], $mixed[2]);
}

function cliniq_custom_theme(string $color): array
{
    $primary = cliniq_normalize_hex_color($color) ?? '#3F7D52';
    $primaryContainer = cliniq_mix_hex_colors($primary, '#000000', 0.78);
    $primaryRgb = cliniq_hex_rgb($primary);
    $shadowRgb = cliniq_hex_rgb($primaryContainer);

    return [
        'label' => 'Custom',
        'primary' => $primary,
        'primary_fixed' => cliniq_mix_hex_colors($primary, '#FFFFFF', 0.14),
        'primary_container' => $primaryContainer,
        'surface' => cliniq_mix_hex_colors($primary, '#FFFFFF', 0.03),
        'surface_container_low' => cliniq_mix_hex_colors($primary, '#FFFFFF', 0.07),
        'outline_variant' => cliniq_mix_hex_colors($primary, '#FFFFFF', 0.24),
        'accent' => cliniq_mix_hex_colors($primary, '#FFFFFF', 0.11),
        'focus_rgb' => implode(', ', $primaryRgb),
        'shadow_rgb' => implode(', ', $shadowRgb),
    ];
}

function save_cliniq_theme_settings(string $theme, ?int $updatedBy = null, string $customColor = '#3F7D52', bool $darkMode = false): void
{
    $theme = cliniq_normalize_theme_key($theme);
    $customColor = cliniq_normalize_hex_color($customColor);
    if ($theme === 'custom' && $customColor === null) {
        throw new InvalidArgumentException('Choose a valid custom color before applying the theme.');
    }

    cliniq_setting_write('clinic.theme', [
        'theme' => $theme,
        'custom_color' => $customColor ?? '#3F7D52',
        'dark_mode' => $darkMode,
    ], $updatedBy);
}

function active_cliniq_theme(): array
{
    $themes = cliniq_theme_presets();
    $settings = cliniq_theme_settings();
    $key = $settings['theme'];
    $theme = $key === 'custom'
        ? cliniq_custom_theme($settings['custom_color'])
        : $themes[$key];

    return ['key' => $key, 'dark_mode' => $settings['dark_mode']] + $theme;
}

// ── Mail / SMTP Settings ─────────────────────────────────────────────────────

function default_mail_settings(): array
{
    return [
        'host'       => '',
        'port'       => '587',
        'encryption' => 'tls',
        'username'   => '',
        'password'   => '',   // stored encrypted
        'from_email' => '',
        'from_name'  => 'CLINiQ Clinic',
    ];
}

/**
 * Simple reversible encryption for the SMTP password stored in the DB.
 * Uses APP_KEY from .env (falls back to a static salt if not set).
 */
function cliniq_mail_encrypt(string $value): string
{
    if ($value === '') {
        return '';
    }
    $key = substr(hash('sha256', env_value('APP_KEY', 'cliniq-secret-key-change-me'), true), 0, 32);
    $iv  = openssl_random_pseudo_bytes(16);
    $enc = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . '::' . $enc);
}

function cliniq_mail_decrypt(string $encoded): string
{
    if ($encoded === '') {
        return '';
    }
    try {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || !str_contains($decoded, '::')) {
            return '';
        }
        [$iv, $enc] = explode('::', $decoded, 2);
        $key = substr(hash('sha256', env_value('APP_KEY', 'cliniq-secret-key-change-me'), true), 0, 32);
        $plain = openssl_decrypt($enc, 'AES-256-CBC', $key, 0, $iv);
        return $plain !== false ? $plain : '';
    } catch (Throwable) {
        return '';
    }
}

function mail_settings(): array
{
    $saved = cliniq_setting_read('mail.smtp', default_mail_settings());
    // Decrypt the stored password for use at runtime.
    if (!empty($saved['password'])) {
        $saved['password'] = cliniq_mail_decrypt((string) $saved['password']);
    }
    return $saved;
}

function save_mail_settings(array $input, ?int $updatedBy = null): void
{
    $defaults = default_mail_settings();
    $data = [];
    foreach ($defaults as $key => $default) {
        $data[$key] = trim((string) ($input[$key] ?? $default));
    }
    $data['port'] = max(1, min(65535, (int) ($data['port'] ?: 587)));
    if (!in_array($data['encryption'], ['tls', 'ssl'], true)) {
        $data['encryption'] = 'tls';
    }
    // Encrypt password before storing. If the form sent an empty password,
    // keep the existing encrypted one.
    if ($data['password'] !== '') {
        $data['password'] = cliniq_mail_encrypt($data['password']);
    } else {
        $existing = cliniq_setting_read('mail.smtp', $defaults);
        $data['password'] = $existing['password'] ?? '';
    }
    cliniq_setting_write('mail.smtp', $data, $updatedBy);
}

function mail_settings_configured(): bool
{
    $s = cliniq_setting_read('mail.smtp', []);
    return !empty($s['host']) && !empty($s['username']) && !empty($s['password']);
}

/**
 * Automated patient-facing email formats. Action destinations are deliberately
 * not editable: the system supplies the signed reset URL or portal URL.
 */
function cliniq_mail_template_definitions(): array
{
    return [
        'patient_password_reset' => [
            'label' => 'Patient Password Reset',
            'description' => 'Sent after a patient requests a secure password reset link.',
            'icon' => 'lock_reset',
            'action_hint' => 'The button opens the one-time password reset link.',
            'allowed_placeholders' => ['{{patient_name}}', '{{clinic_name}}', '{{expiry_minutes}}'],
            'required_placeholders' => ['{{patient_name}}', '{{clinic_name}}', '{{expiry_minutes}}'],
            'default' => [
                'subject' => '[{{clinic_name}}] Reset your patient portal password',
                'heading' => 'Reset your password, {{patient_name}}',
                'message' => 'We received a request to reset your patient portal password. Use the secure button below within {{expiry_minutes}} minutes. If you did not request this, you can ignore this email.',
                'button_label' => 'Reset Password',
                'footer' => 'For your security, this link can only be used once. Contact {{clinic_name}} if you need assistance.',
            ],
        ],
        'student_re_enrollment' => [
            'label' => 'Student Enrollment Declaration',
            'description' => 'Sent when a new APE cycle asks students to submit their current enrollment status.',
            'icon' => 'school',
            'action_hint' => 'The button opens the patient portal login page.',
            'allowed_placeholders' => ['{{patient_name}}', '{{clinic_name}}'],
            'required_placeholders' => ['{{patient_name}}', '{{clinic_name}}'],
            'default' => [
                'subject' => '[{{clinic_name}}] Update your enrollment status — new school year',
                'heading' => 'Update Enrollment Status, {{patient_name}}',
                'message' => 'A new school year has started at {{clinic_name}}. Log in to submit your current enrollment status and continue accessing your health records and clinic services.',
                'button_label' => 'Update Enrollment Status',
                'footer' => 'If you are not currently enrolled, select the reason that best describes your status.',
            ],
        ],
        'employee_re_employment' => [
            'label' => 'Employee Employment Confirmation',
            'description' => 'Sent when a new APE cycle asks faculty or personnel to confirm employment.',
            'icon' => 'badge',
            'action_hint' => 'The button opens the patient portal login page.',
            'allowed_placeholders' => ['{{patient_name}}', '{{clinic_name}}'],
            'required_placeholders' => ['{{patient_name}}', '{{clinic_name}}'],
            'default' => [
                'subject' => '[{{clinic_name}}] Confirm you are still employed — new school year',
                'heading' => 'Confirm Employment, {{patient_name}}',
                'message' => 'A new school year has started at {{clinic_name}}. Log in to confirm that you are still employed and continue accessing your health records and clinic services.',
                'button_label' => 'Confirm Employment',
                'footer' => 'If you are no longer employed, ignore this email and your account will remain inactive.',
            ],
        ],
    ];
}

function cliniq_mail_template(string $key): array
{
    $definitions = cliniq_mail_template_definitions();
    if (!isset($definitions[$key])) {
        throw new InvalidArgumentException('Unknown email notification format.');
    }
    $default = $definitions[$key]['default'];
    $saved = cliniq_setting_read('mail.template.' . $key, $default);
    return array_intersect_key(array_merge($default, $saved), $default);
}

function cliniq_mail_templates(): array
{
    $templates = [];
    foreach (cliniq_mail_template_definitions() as $key => $definition) {
        $templates[$key] = $definition + ['template' => cliniq_mail_template($key)];
    }
    return $templates;
}

function validate_cliniq_mail_template(string $key, array $input): array
{
    $definitions = cliniq_mail_template_definitions();
    if (!isset($definitions[$key])) {
        throw new InvalidArgumentException('Unknown email notification format.');
    }

    $limits = ['subject' => 180, 'heading' => 180, 'message' => 4000, 'button_label' => 60, 'footer' => 1000];
    $template = [];
    foreach ($limits as $field => $limit) {
        $value = trim((string) ($input[$field] ?? ''));
        if ($field !== 'footer' && $value === '') {
            throw new InvalidArgumentException(ucwords(str_replace('_', ' ', $field)) . ' is required.');
        }
        if (mb_strlen($value) > $limit) {
            throw new InvalidArgumentException(ucwords(str_replace('_', ' ', $field)) . " must not exceed {$limit} characters.");
        }
        if ($field === 'subject' && preg_match('/[\r\n]/', $value)) {
            throw new InvalidArgumentException('Subject must be a single line.');
        }
        $template[$field] = $value;
    }

    $combined = implode("\n", $template);
    preg_match_all('/\{\{[a-z_]+\}\}/', $combined, $matches);
    $used = array_values(array_unique($matches[0] ?? []));
    $unsupported = array_diff($used, $definitions[$key]['allowed_placeholders']);
    if ($unsupported !== []) {
        throw new InvalidArgumentException('Unsupported placeholder: ' . implode(', ', $unsupported));
    }
    $missing = array_filter(
        $definitions[$key]['required_placeholders'],
        static fn(string $placeholder): bool => !str_contains($combined, $placeholder)
    );
    if ($missing !== []) {
        throw new InvalidArgumentException('Required placeholder missing: ' . implode(', ', $missing));
    }
    if (preg_match('/\{\{|\}\}/', preg_replace('/\{\{[a-z_]+\}\}/', '', $combined))) {
        throw new InvalidArgumentException('A placeholder is incomplete. Use the exact placeholder pills shown in the editor.');
    }

    return $template;
}

function save_cliniq_mail_template(string $key, array $input, ?int $updatedBy = null): void
{
    cliniq_setting_write('mail.template.' . $key, validate_cliniq_mail_template($key, $input), $updatedBy);
}

function reset_cliniq_mail_template(string $key, ?int $updatedBy = null): void
{
    $definitions = cliniq_mail_template_definitions();
    if (!isset($definitions[$key])) {
        throw new InvalidArgumentException('Unknown email notification format.');
    }
    cliniq_setting_write('mail.template.' . $key, $definitions[$key]['default'], $updatedBy);
}

/**
 * @return array<int,array{account_id:int,name:string,email:string,type:string,status:string}>
 */
function cliniq_mail_recipients(): array
{
    $rows = auth_db()->query("
        SELECT
            a.id AS account_id,
            TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS name,
            a.email,
            CASE
                WHEN cs.person_id IS NOT NULL THEN 'Clinic Staff'
                WHEN s.person_id IS NOT NULL THEN 'Student'
                WHEN se.role_classification = 'Faculty' THEN 'Faculty'
                WHEN se.person_id IS NOT NULL THEN 'Non-Teaching Personnel'
                ELSE 'Patient'
            END AS type,
            a.account_status AS status
        FROM accounts a
        JOIN people p ON p.id = a.person_id
        LEFT JOIN clinic_staff cs ON cs.person_id = p.id
        LEFT JOIN students s ON s.person_id = p.id
        LEFT JOIN school_employees se ON se.person_id = p.id
        WHERE a.email IS NOT NULL
          AND TRIM(a.email) <> ''
        ORDER BY name, a.email
    ")->fetchAll();

    return array_values(array_filter(array_map(
        static function (array $row): array {
            return [
                'account_id' => (int) $row['account_id'],
                'name' => trim((string) $row['name']),
                'email' => strtolower(trim((string) $row['email'])),
                'type' => (string) $row['type'],
                'status' => (string) $row['status'],
            ];
        },
        $rows
    ), static fn(array $row): bool => filter_var($row['email'], FILTER_VALIDATE_EMAIL) !== false));
}
