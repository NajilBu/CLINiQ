<?php

require_once __DIR__ . '/../config/database.php';

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
    $idNumber = strtoupper(trim((string) ($input['id_number'] ?? $input['email'] ?? '')));
    $role = normalize_staff_profile_role((string) ($input['role'] ?? 'staff'));
    $password = (string) ($input['password'] ?? '');

    if ($name === '') {
        throw new InvalidArgumentException('Enter the staff member name.');
    }
    if ($idNumber === '') {
        $idNumber = next_staff_profile_id_number();
    }
    if (!preg_match('/^STAFF-[0-9]{4}$/', $idNumber)) {
        throw new InvalidArgumentException('Staff login ID must use the format STAFF-0001.');
    }
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('Password must be at least 8 characters.');
    }

    [$firstName, $middleName, $lastName] = split_staff_profile_name($name);
    $db = auth_db();
    try {
        $db->beginTransaction();

        $duplicate = $db->prepare('SELECT id FROM people WHERE id_number = ? LIMIT 1');
        $duplicate->execute([$idNumber]);
        if ($duplicate->fetchColumn()) {
            throw new InvalidArgumentException('That staff login ID already exists.');
        }

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

        $email = strtolower(str_replace(' ', '', $lastName) . '_' . str_replace(' ', '', $firstName)) . '@plpasig.edu.ph';
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
    $idNumber = strtoupper(trim((string) ($input['id_number'] ?? $input['email'] ?? '')));
    $role = normalize_staff_profile_role((string) ($input['role'] ?? 'staff'));

    if ($id <= 0) {
        throw new InvalidArgumentException('Select a staff profile to update.');
    }
    if ($name === '') {
        throw new InvalidArgumentException('Enter the staff member name.');
    }
    if (!preg_match('/^STAFF-[0-9]{4}$/', $idNumber)) {
        throw new InvalidArgumentException('Staff login ID must use the format STAFF-0001.');
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

    if ($id <= 0) {
        throw new InvalidArgumentException('Select a staff profile before resetting the password.');
    }
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('New password must be at least 8 characters.');
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
        SELECT COALESCE(MAX(CAST(SUBSTRING(id_number, 7) AS UNSIGNED)), 0) + 1
        FROM people
        WHERE id_number REGEXP '^STAFF-[0-9]{4}$'
    ")->fetchColumn();

    return 'STAFF-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
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
            'options' => ['General Checkup', 'Dental Consultation', 'Medical Consultation', 'APE Follow-up', 'Clearance Submission'],
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
            'options' => ['Not Checked', 'Pre-Verified', 'Needs Correction'],
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
        'department' => 'University Health Services',
        'contact_email' => 'clinic@plpasig.edu.ph',
        'physical_address' => 'Alcalde Jose Street, Brgy. Kapasigan, Pasig City, Metro Manila, Philippines, 1600',
        'system_purpose' => 'School clinic information management system for patient records, visits, APE workflow, emergency alerts, appointments, inventory, referrals, and reports.',
    ];
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

    return $settings;
}

function save_clinic_profile_settings(array $input, ?int $updatedBy = null): void
{
    cliniq_setting_write('clinic.profile', normalize_clinic_profile_settings($input), $updatedBy);
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
    $saved = cliniq_setting_read('clinic.theme', ['theme' => default_cliniq_theme_key()]);
    return ['theme' => cliniq_normalize_theme_key((string) ($saved['theme'] ?? default_cliniq_theme_key()))];
}

function cliniq_normalize_theme_key(string $theme): string
{
    return array_key_exists($theme, cliniq_theme_presets()) ? $theme : default_cliniq_theme_key();
}

function save_cliniq_theme_settings(string $theme, ?int $updatedBy = null): void
{
    cliniq_setting_write('clinic.theme', ['theme' => cliniq_normalize_theme_key($theme)], $updatedBy);
}

function active_cliniq_theme(): array
{
    $themes = cliniq_theme_presets();
    $key = cliniq_theme_settings()['theme'];
    return ['key' => $key] + $themes[$key];
}
