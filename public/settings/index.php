<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/SystemSettings.php';
require_once __DIR__ . '/../../app/services/RiskSettings.php';
require_once __DIR__ . '/../../app/services/ApeCycleService.php';

require_login();
ensure_system_settings_schema();
ensure_dropdown_options_schema();

$user = current_user() ?? [];
$canManageSettings = in_array($user['role'] ?? '', ['admin', 'doctor', 'it_expert'], true);
$canManageStaffProfiles = in_array($user['role'] ?? '', ['admin', 'doctor', 'it_expert'], true);
$canManagePatientAccounts = in_array($user['role'] ?? '', ['admin', 'doctor'], true);
$canManageApeCycles = in_array($user['role'] ?? '', ['admin', 'doctor'], true);
$dropdownCategoryLabels = [
    'students' => 'Students & Visitors',
    'visits' => 'Clinic Visits',
    'incidents' => 'Incident Reports',
    'ape' => 'APE',
    'inventory' => 'Inventory',
];
$dropdownGroupCategories = [
    'person_category' => 'students',
    'year_level' => 'students',
    'department' => 'students',
    'student_program' => 'students',
    'student_section' => 'students',
    'blood_type' => 'students',
    'guardian_relationship' => 'students',
    'appointment_purpose' => 'students',
    'visit_status' => 'visits',
    'visit_purpose' => 'visits',
    'visit_source' => 'visits',
    'referral_type' => 'visits',
    'incident_type' => 'incidents',
    'incident_condition' => 'incidents',
    'incident_breathing' => 'incidents',
    'incident_bleeding' => 'incidents',
    'incident_pain_level' => 'incidents',
    'incident_mobility' => 'incidents',
    'ape_requirement_status' => 'ape',
    'ape_verification_status' => 'ape',
    'ape_workflow_status' => 'ape',
    'inventory_return_condition' => 'inventory',
];
ensure_staff_profiles_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $updatedBy = (int) ($user['id'] ?? 0) ?: null;

    if ($action === 'save_profile') {
        if (!$canManageSettings) {
            flash_message('error', 'You do not have permission to update clinic profile settings.');
            header('Location: index.php?tab=general');
            exit;
        }
        try {
            $profileInput = $_POST;
            $currentProfile = clinic_profile_settings();
            $profileInput['logo_path'] = clinic_profile_logo_path($currentProfile);
            $uploadedLogoPath = save_uploaded_clinic_logo($_FILES['logo_file'] ?? []);
            if ($uploadedLogoPath !== '') {
                $profileInput['logo_path'] = $uploadedLogoPath;
            }
            if ((string) ($_POST['save_intent'] ?? '') === 'logo' && $uploadedLogoPath === '') {
                throw new InvalidArgumentException('Choose a PNG, JPG, or WebP logo before saving.');
            }
            save_clinic_profile_settings($profileInput, $updatedBy);
            flash_message('success', $uploadedLogoPath !== '' ? 'Clinic profile and system logo saved.' : 'Clinic profile settings saved.');
        } catch (Throwable $e) {
            flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
        }
        header('Location: index.php?tab=general');
        exit;
    }

    if ($action === 'save_theme') {
        if (!$canManageSettings) {
            flash_message('error', 'You do not have permission to update the system theme.');
            header('Location: index.php?tab=general');
            exit;
        }
        try {
            save_cliniq_theme_settings(
                (string) ($_POST['theme'] ?? default_cliniq_theme_key()),
                $updatedBy,
                (string) ($_POST['custom_color'] ?? '#3F7D52')
            );
            flash_message('success', 'System color theme updated.');
        } catch (InvalidArgumentException $e) {
            flash_message('warning', $e->getMessage());
        }
        header('Location: index.php?tab=general');
        exit;
    }

    if (in_array($action, ['create_staff_profile', 'update_staff_profile', 'reset_staff_password'], true)) {
        if (!$canManageStaffProfiles) {
            flash_message('error', 'Only administrators, doctors, or IT experts can manage staff profiles.');
            header('Location: index.php?tab=account');
            exit;
        }

        try {
            if ($action === 'create_staff_profile') {
                create_staff_profile($_POST);
                flash_message('success', 'Staff profile created. The user can now sign in with their own account.');
            } elseif ($action === 'update_staff_profile') {
                update_staff_profile($_POST);
                if ((int) ($_POST['user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
                    $_SESSION['user']['name'] = trim((string) ($_POST['name'] ?? $user['name']));
                    $_SESSION['user']['id_number'] = strtoupper(trim((string) ($_POST['id_number'] ?? $user['id_number'])));
                    [$firstName, $middleName, $lastName] = split_staff_profile_name($_SESSION['user']['name']);
                    $_SESSION['user']['email'] = strtolower(str_replace(' ', '', $lastName) . '_' . str_replace(' ', '', $firstName)) . '@plpasig.edu.ph';
                    $_SESSION['user']['role'] = normalize_staff_profile_role((string) ($_POST['role'] ?? $user['role']));
                }
                flash_message('success', 'Staff profile updated.');
            } else {
                reset_staff_profile_password($_POST);
                flash_message('success', 'Staff password reset. Share the new password only with that staff member.');
            }
        } catch (Throwable $e) {
            $message = str_contains(strtolower($e->getMessage()), 'duplicate') ? 'That staff login ID is already assigned to another profile.' : $e->getMessage();
            flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $message);
        }

        header('Location: index.php?tab=account');
        exit;
    }

    if ($action === 'update_password') {
        $newPassword = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['password_confirmation'] ?? '');

        if (strlen($newPassword) < 8) {
            flash_message('error', 'New password must be at least 8 characters.');
        } elseif ($newPassword !== $confirmPassword) {
            flash_message('error', 'New password confirmation does not match.');
        } else {
            $update = auth_db()->prepare('UPDATE accounts SET password_hash = ? WHERE person_id = ?');
            $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $user['id']]);
            flash_message('success', 'Your password has been updated.');
        }

        header('Location: index.php?tab=account');
        exit;
    }

    if ($action === 'save_risk') {
        if (!$canManageSettings) {
            flash_message('error', 'You do not have permission to update risk settings.');
            header('Location: index.php?tab=clinical');
            exit;
        }
        save_risk_settings($_POST, $updatedBy);
        flash_message('success', 'Incident risk classification settings saved.');
        header('Location: index.php?tab=clinical');
        exit;
    }

    if ($action === 'reset_risk') {
        if (!$canManageSettings) {
            flash_message('error', 'You do not have permission to reset risk settings.');
            header('Location: index.php?tab=maintenance');
            exit;
        }
        save_risk_settings(default_risk_settings(), $updatedBy);
        flash_message('success', 'Incident risk classification settings were restored to the default CLINiQ rules.');
        header('Location: index.php?tab=clinical');
        exit;
    }

    if (in_array($action, ['start_ape_cycle', 'close_ape_cycle', 'archive_ape_cycle', 'reset_school_year', 'update_ape_cycle_schedule'], true)) {
        if (!$canManageApeCycles) {
            flash_message('error', 'Only administrators and doctors can manage annual APE cycles.');
            header('Location: index.php?tab=general');
            exit;
        }

        $actorPersonId = (int) ($user['person_id'] ?? 0) ?: null;
        try {
            if ($action === 'start_ape_cycle') {
                $cycle = start_ape_cycle(
                    (string) ($_POST['academic_year'] ?? ''),
                    (string) ($_POST['compliance_start'] ?? ''),
                    (string) ($_POST['compliance_end'] ?? ''),
                    $actorPersonId,
                    (string) ($_POST['exam_schedule_date'] ?? '')
                );
                flash_message('success', sprintf(
                    'APE cycle %s started. %d active patient record(s) created and %d existing record(s) linked.',
                    $cycle['academic_year'],
                    (int) ($cycle['created_records'] ?? 0),
                    (int) ($cycle['adopted_records'] ?? 0)
                ));
            } elseif ($action === 'close_ape_cycle') {
                $cycle = close_ape_cycle((int) ($_POST['ape_cycle_id'] ?? 0), $actorPersonId);
                flash_message('success', "APE cycle {$cycle['academic_year']} closed. Its records remain available for reports and patient history.");
            } elseif ($action === 'archive_ape_cycle') {
                $cycle = archive_ape_cycle((int) ($_POST['ape_cycle_id'] ?? 0), $actorPersonId);
                flash_message('success', "APE cycle {$cycle['academic_year']} archived.");
            } elseif ($action === 'update_ape_cycle_schedule') {
                update_ape_cycle_schedule((int) ($_POST['ape_cycle_id'] ?? 0), (string) ($_POST['exam_schedule_date'] ?? ''));
                flash_message('success', 'Exam schedule date updated.');
            } elseif ($action === 'reset_school_year') {
                $result = reset_school_year_accounts();
                $msg = "{$result['reset']} account(s) reset to inactive.";
                if ($result['emailed'] > 0) {
                    $msg .= " {$result['emailed']} notification email(s) sent.";
                }
                if ($result['failed'] > 0) {
                    $msg .= " {$result['failed']} email(s) failed (check server mail config).";
                }
                flash_message('success', $msg);
            }
        } catch (Throwable $e) {
            flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
        }

        header('Location: index.php?tab=ape-cycle');
        exit;
    }

    if (in_array($action, ['add_dropdown_option', 'update_dropdown_option', 'delete_dropdown_option', 'reset_dropdown_group'], true)) {
        if (!$canManageSettings) {
            flash_message('error', 'You do not have permission to manage dropdown options.');
            header('Location: index.php?tab=dropdowns');
            exit;
        }

        $groupKey = (string) ($_POST['group_key'] ?? '');
        try {
            if ($action === 'add_dropdown_option') {
                add_dropdown_option($groupKey, (string) ($_POST['label'] ?? ''), $updatedBy);
                flash_message('success', 'Dropdown option added.');
            } elseif ($action === 'update_dropdown_option') {
                update_dropdown_option(
                    $groupKey,
                    (string) ($_POST['option_id'] ?? ''),
                    (string) ($_POST['label'] ?? ''),
                    !empty($_POST['active']),
                    $updatedBy
                );
                flash_message('success', 'Dropdown option updated.');
            } elseif ($action === 'delete_dropdown_option') {
                delete_dropdown_option($groupKey, (string) ($_POST['option_id'] ?? ''), $updatedBy);
                flash_message('success', 'Dropdown option deleted from future forms.');
            } else {
                $groups = dropdown_option_groups();
                $defaults = default_dropdown_option_groups();
                if (!isset($groups[$groupKey], $defaults[$groupKey])) {
                    throw new InvalidArgumentException('Select a valid dropdown group.');
                }
                $groups[$groupKey]['options'] = normalize_dropdown_options($defaults[$groupKey]['options']);
                save_dropdown_option_groups($groups, $updatedBy);
                flash_message('success', 'Dropdown group restored to its defaults.');
            }
        } catch (Throwable $e) {
            flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
        }

        $redirectCategory = $dropdownGroupCategories[$groupKey] ?? ($_POST['category'] ?? '');
        $redirectUrl = 'index.php?tab=dropdowns&group=' . urlencode($groupKey);
        if ($redirectCategory !== '') {
            $redirectUrl .= '&category=' . urlencode((string) $redirectCategory);
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'save_mail_settings') {
        if (!$canManageSettings) {
            flash_message('error', 'Only administrators, doctors, or IT experts can update mail settings.');
            header('Location: index.php?tab=email');
            exit;
        }
        try {
            save_mail_settings($_POST, (int) ($user['person_id'] ?? 0) ?: null);
            flash_message('success', 'SMTP settings saved.');
        } catch (Throwable $e) {
            flash_message('error', $e->getMessage());
        }
        header('Location: index.php?tab=email');
        exit;
    }

    if ($action === 'save_mail_template' || $action === 'reset_mail_template') {
        if (!$canManageSettings) {
            flash_message('error', 'Only administrators, doctors, or IT experts can update email notification formats.');
            header('Location: index.php?tab=email');
            exit;
        }
        try {
            $templateKey = trim((string) ($_POST['template_key'] ?? ''));
            if ($action === 'reset_mail_template') {
                reset_cliniq_mail_template($templateKey, (int) ($user['person_id'] ?? 0) ?: null);
                flash_message('success', 'Email notification format restored to its default.');
            } else {
                save_cliniq_mail_template($templateKey, $_POST, (int) ($user['person_id'] ?? 0) ?: null);
                flash_message('success', 'Email notification format saved.');
            }
        } catch (Throwable $e) {
            flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
        }
        header('Location: index.php?tab=email');
        exit;
    }

    if ($action === 'send_test_email') {
        if (!$canManageSettings) {
            flash_message('error', 'Only administrators, doctors, or IT experts can send test emails.');
            header('Location: index.php?tab=email');
            exit;
        }
        require_once __DIR__ . '/../../app/helpers/mail.php';
        $toEmail = trim((string) ($_POST['test_email'] ?? ($user['email'] ?? '')));
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            flash_message('error', 'Enter a valid email address to send the test to.');
        } else {
            $profile = clinic_profile_settings();
            $sent = send_cliniq_email(
                $toEmail,
                'CLINiQ Admin',
                '[CLINiQ] SMTP Test Email',
                cliniq_custom_email_body(
                    'Your CLINiQ SMTP configuration is working correctly. This is a test message.',
                    (string) ($profile['system_name'] ?? 'CLINiQ')
                )
            );
            if ($sent) {
                flash_message('success', "Test email sent to {$toEmail}. Check the inbox.");
            } else {
                flash_message('error', 'Failed to send test email. Check your SMTP credentials and server error log.');
            }
        }
        header('Location: index.php?tab=email');
        exit;
    }

    if ($action === 'send_custom_email') {
        if (!$canManageSettings) {
            flash_message('error', 'Only administrators, doctors, or IT experts can send custom emails.');
            header('Location: index.php?tab=email');
            exit;
        }

        try {
            if (!mail_settings_configured()) {
                throw new InvalidArgumentException('Configure the clinic email before composing a message.');
            }

            $recipientIds = array_values(array_unique(array_filter(array_map(
                'intval',
                is_array($_POST['recipient_ids'] ?? null) ? $_POST['recipient_ids'] : []
            ), static fn(int $id): bool => $id > 0)));
            $subject = trim((string) ($_POST['subject'] ?? ''));
            $message = trim((string) ($_POST['message'] ?? ''));

            if ($recipientIds === []) {
                throw new InvalidArgumentException('Select at least one email recipient.');
            }
            if (count($recipientIds) > 200) {
                throw new InvalidArgumentException('Select no more than 200 recipients per message.');
            }
            if ($subject === '' || mb_strlen($subject) > 180) {
                throw new InvalidArgumentException('Enter a subject containing no more than 180 characters.');
            }
            if ($message === '' || mb_strlen($message) > 10000) {
                throw new InvalidArgumentException('Enter a message containing no more than 10,000 characters.');
            }

            $selectedIdMap = array_fill_keys($recipientIds, true);
            $recipients = array_values(array_filter(
                cliniq_mail_recipients(),
                static fn(array $recipient): bool => isset($selectedIdMap[(int) $recipient['account_id']])
            ));
            if ($recipients === []) {
                throw new InvalidArgumentException('The selected accounts do not have valid email addresses.');
            }

            require_once __DIR__ . '/../../app/helpers/mail.php';
            $profile = clinic_profile_settings();
            $body = cliniq_custom_email_body($message, (string) ($profile['system_name'] ?? 'CLINiQ'));
            $sentCount = 0;
            $failedCount = 0;
            foreach ($recipients as $recipient) {
                try {
                    $sent = send_cliniq_email(
                        (string) $recipient['email'],
                        (string) ($recipient['name'] ?: 'CLINiQ Recipient'),
                        $subject,
                        $body
                    );
                } catch (Throwable $e) {
                    error_log('[CLINiQ Mail] Custom email failed: ' . $e->getMessage());
                    $sent = false;
                }
                $sent ? $sentCount++ : $failedCount++;
            }

            if ($sentCount > 0) {
                $summary = "Custom email sent to {$sentCount} recipient(s).";
                if ($failedCount > 0) {
                    $summary .= " {$failedCount} delivery attempt(s) failed.";
                }
                flash_message($failedCount > 0 ? 'warning' : 'success', $summary);
            } else {
                flash_message('error', "Email could not be delivered to the selected {$failedCount} recipient(s). Check the SMTP configuration.");
            }
        } catch (Throwable $e) {
            flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
        }

        header('Location: index.php?tab=email');
        exit;
    }

    flash_message('warning', 'Unknown settings action.');
    header('Location: index.php?tab=general');
    exit;
}

$clinicProfile = clinic_profile_settings();
$systemLogoPath = clinic_profile_logo_path($clinicProfile);
$systemLogoUrl = app_url($systemLogoPath);
$systemLogoExtension = strtolower((string) pathinfo($systemLogoPath, PATHINFO_EXTENSION));
$systemLogoDownloadName = 'cliniq-system-logo.' . (in_array($systemLogoExtension, ['png', 'jpg', 'jpeg', 'webp'], true) ? $systemLogoExtension : 'png');
$themePresets = cliniq_theme_presets();
$themeSettings = cliniq_theme_settings();
$activeTheme = $themeSettings['theme'];
$customThemeColor = $themeSettings['custom_color'];
$staffProfiles = staff_profiles();
$staffRoles = staff_profile_roles();
$settings = risk_settings();
$mailSettings = mail_settings();
$mailConfigured = mail_settings_configured();
$mailNotificationTemplates = cliniq_mail_templates();
$mailRecipients = $canManageSettings ? cliniq_mail_recipients() : [];
$activeApePatientCount = 0;
$excludedApePatientCount = 0;
$apeCurrentCycle = null;
if ($canManageApeCycles) {
    try {
        ensure_ape_cycle_schema();
        $apePatientCounts = auth_db()->query("
            SELECT
                SUM(CASE WHEN a.account_status = 'active' THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN a.account_status <> 'active' THEN 1 ELSE 0 END) AS excluded_count
            FROM patients pt
            INNER JOIN accounts a ON a.person_id = pt.person_id
        ")->fetch() ?: [];
        $activeApePatientCount = (int) ($apePatientCounts['active_count'] ?? 0);
        $excludedApePatientCount = (int) ($apePatientCounts['excluded_count'] ?? 0);
        $apeCurrentCycle = ape_cycle_current();
    } catch (Throwable $e) {
        flash_message('error', $e->getMessage());
    }
}
$apeCycleProgress = $apeCurrentCycle['progress'] ?? [
    'total' => 0,
    'not_started' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'cleared' => 0,
    'follow_up' => 0,
    'compliance_percent' => 0,
];
$hasActiveApeCycle = ($apeCurrentCycle['status'] ?? '') === 'Active';
$apeYearStart = (int) date('Y');
if ((int) date('n') < 6) {
    $apeYearStart--;
}
$defaultApeSchoolYear = $apeYearStart . '-' . ($apeYearStart + 1);
$defaultApeStartDate = $apeYearStart . '-06-01';
$defaultApeEndDate = ($apeYearStart + 1) . '-05-31';
$dropdownGroups = dropdown_option_groups();
$currentDropdownGroup = (string) ($_GET['group'] ?? array_key_first($dropdownGroups));
if (!isset($dropdownGroups[$currentDropdownGroup])) {
    $currentDropdownGroup = (string) array_key_first($dropdownGroups);
}
$currentDropdownCategory = (string) ($_GET['category'] ?? ($dropdownGroupCategories[$currentDropdownGroup] ?? 'students'));
if (!isset($dropdownCategoryLabels[$currentDropdownCategory])) {
    $currentDropdownCategory = $dropdownGroupCategories[$currentDropdownGroup] ?? 'students';
}
$dropdownGroupsByCategory = [];
foreach ($dropdownGroups as $groupKey => $group) {
    $category = $dropdownGroupCategories[$groupKey] ?? 'students';
    $dropdownGroupsByCategory[$category][$groupKey] = $group;
}
if (!isset($dropdownGroupsByCategory[$currentDropdownCategory])) {
    $currentDropdownCategory = array_key_first($dropdownGroupsByCategory);
}
if (!isset($dropdownGroupsByCategory[$currentDropdownCategory][$currentDropdownGroup])) {
    $currentDropdownGroup = (string) array_key_first($dropdownGroupsByCategory[$currentDropdownCategory]);
}
$currentTab = $_GET['tab'] ?? 'general';
if (!in_array($currentTab, ['general', 'account', 'ape-cycle', 'dropdowns', 'clinical', 'email', 'maintenance'], true)) {
    $currentTab = 'general';
}
if ($currentTab === 'ape-cycle' && !$canManageApeCycles) {
    $currentTab = 'general';
}

function profile_setting_value(array $settings, string $key): string
{
    return e((string) ($settings[$key] ?? ''));
}

function risk_setting_value(array $settings, string $key): string
{
    return e((string) ($settings[$key] ?? ''));
}

render_header('System Settings');

render_clinic_command_header(
    'Clinic Configuration',
    'System Settings',
    'Manage clinic profile, interface theme, staff access, dropdown options, and incident alert risk rules.',
    '<span class="badge badge-in-progress">Authorized Settings</span>'
);
?>

<?php if (!$canManageSettings): ?>
    <div class="rounded-2xl bg-red-50 border border-red-100 text-red-700 px-5 py-4 font-bold">
        Clinic configuration can be changed only by administrators, doctors, or IT experts. You can still update your own password.
    </div>
<?php endif; ?>

<div class="settings-shell">
    <aside class="settings-tabs">
        <a href="index.php?tab=general" class="settings-tab-link <?= $currentTab === 'general' ? 'active' : '' ?> text-decoration-none" data-settings-tab="general" data-no-ajax="true">
            <span class="material-symbols-outlined">corporate_fare</span>
            <span>Clinic Profile</span>
        </a>
        <a href="index.php?tab=account" class="settings-tab-link <?= $currentTab === 'account' ? 'active' : '' ?> text-decoration-none" data-settings-tab="account" data-no-ajax="true">
            <span class="material-symbols-outlined">admin_panel_settings</span>
            <span>Staff Profiles</span>
        </a>
        <?php if ($canManagePatientAccounts): ?>
            <a href="<?= e(app_url('patient-accounts/index.php')) ?>" class="settings-tab-link text-decoration-none" data-no-ajax="true">
                <span class="material-symbols-outlined">manage_accounts</span>
                <span>Patient Accounts</span>
            </a>
        <?php endif; ?>
        <?php if ($canManageApeCycles): ?>
            <a href="index.php?tab=ape-cycle" class="settings-tab-link <?= $currentTab === 'ape-cycle' ? 'active' : '' ?> text-decoration-none" data-settings-tab="ape-cycle" data-no-ajax="true">
                <span class="material-symbols-outlined">event_repeat</span>
                <span>APE Cycle</span>
            </a>
        <?php endif; ?>
        <a href="index.php?tab=dropdowns" class="settings-tab-link <?= $currentTab === 'dropdowns' ? 'active' : '' ?> text-decoration-none" data-settings-tab="dropdowns" data-no-ajax="true">
            <span class="material-symbols-outlined">list_alt</span>
            <span>Dropdowns</span>
        </a>
        <a href="index.php?tab=clinical" class="settings-tab-link <?= $currentTab === 'clinical' ? 'active' : '' ?> text-decoration-none" data-settings-tab="clinical" data-no-ajax="true">
            <span class="material-symbols-outlined">emergency</span>
            <span>Incident Risk</span>
        </a>
        <a href="index.php?tab=email" class="settings-tab-link <?= $currentTab === 'email' ? 'active' : '' ?> text-decoration-none" data-settings-tab="email" data-no-ajax="true">
            <span class="material-symbols-outlined">mail</span>
            <span>Email</span>
        </a>
        <a href="index.php?tab=maintenance" class="settings-tab-link <?= $currentTab === 'maintenance' ? 'active' : '' ?> text-decoration-none" data-settings-tab="maintenance" data-no-ajax="true">
            <span class="material-symbols-outlined">restart_alt</span>
            <span>Maintenance</span>
        </a>
    </aside>

    <section class="settings-panel">
        <div class="settings-panel-body">
            <div id="settings-general" class="settings-tab-panel <?= $currentTab === 'general' ? 'active' : '' ?> space-y-6">
                <section>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Clinic Identity</h2>
                    <p class="text-xs font-bold text-slate-500 mb-5">These details appear in the CLINiQ shell and shared access headers.</p>
                    <form method="post" class="settings-section space-y-5" enctype="multipart/form-data" id="clinicProfileForm" data-no-ajax="true">
                        <input type="hidden" name="action" value="save_profile">
                        <input type="hidden" name="logo_path" value="<?= profile_setting_value($clinicProfile, 'logo_path') ?>">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="settings-field">
                                <label class="clinic-label" for="system_name">System Name</label>
                                <input class="settings-input" id="system_name" name="system_name" value="<?= profile_setting_value($clinicProfile, 'system_name') ?>" <?= !$canManageSettings ? 'readonly' : '' ?> required>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label" for="institution_name">Institution / School Name</label>
                                <input class="settings-input" id="institution_name" name="institution_name" value="<?= profile_setting_value($clinicProfile, 'institution_name') ?>" <?= !$canManageSettings ? 'readonly' : '' ?> required>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label" for="department">Department</label>
                                <input class="settings-input" id="department" name="department" value="<?= profile_setting_value($clinicProfile, 'department') ?>" <?= !$canManageSettings ? 'readonly' : '' ?> required>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label" for="contact_email">Contact Email</label>
                                <input class="settings-input" id="contact_email" name="contact_email" type="email" value="<?= profile_setting_value($clinicProfile, 'contact_email') ?>" <?= !$canManageSettings ? 'readonly' : '' ?> required>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label" for="physical_address">Physical Address</label>
                                <textarea class="settings-textarea settings-textarea-compact" id="physical_address" name="physical_address" <?= !$canManageSettings ? 'readonly' : '' ?> required><?= profile_setting_value($clinicProfile, 'physical_address') ?></textarea>
                            </div>
                            <div class="settings-field md:col-span-2">
                                <label class="clinic-label" for="system_purpose">System Purpose</label>
                                <textarea class="settings-textarea" id="system_purpose" name="system_purpose" <?= !$canManageSettings ? 'readonly' : '' ?> required><?= profile_setting_value($clinicProfile, 'system_purpose') ?></textarea>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button class="btn btn-primary" <?= !$canManageSettings ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="primary" data-confirm-title="Save clinic profile?" data-confirm-message="This updates the shared system name and clinic identity used by CLINiQ." data-confirm-toast="Saving clinic profile...">
                                <span class="material-symbols-outlined text-[18px]">save</span>
                                Save Profile
                            </button>
                        </div>
                    </form>
                </section>

                <section data-logo-placeholder>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">System Logo</h2>
                    <p class="text-xs font-bold text-slate-500 mb-5">Choose the logo shown in the staff shell, patient portal, visit gateway, and shared access pages.</p>
                    <div class="settings-section settings-logo-layout">
                        <div class="settings-logo-preview-card">
                            <span class="clinic-label">Logo preview</span>
                            <a
                                href="<?= e($systemLogoUrl) ?>"
                                class="settings-logo-preview"
                                data-file-preview
                                data-preview-type="image"
                                data-preview-title="<?= e($systemLogoDownloadName) ?>"
                                data-logo-preview-link
                                aria-label="View or download the current system logo"
                            >
                                <img src="<?= e($systemLogoUrl) ?>" alt="Current <?= e($clinicProfile['department']) ?> logo" data-logo-preview>
                            </a>
                            <p class="settings-help m-0 text-center">Click the logo to view or download it</p>
                        </div>

                        <div class="settings-logo-controls">
                            <label class="settings-logo-dropzone" for="settingsLogoInput" data-logo-dropzone>
                                <input id="settingsLogoInput" name="logo_file" form="clinicProfileForm" type="file" accept="image/png,image/jpeg,image/webp" data-logo-input <?= !$canManageSettings ? 'disabled' : '' ?>>
                                <span class="material-symbols-outlined">add_photo_alternate</span>
                                <strong>Drop a logo here or choose an image</strong>
                                <span>PNG, JPG, or WebP &bull; square image recommended &bull; up to 5 MB</span>
                            </label>
                            <p class="settings-logo-file-name" data-logo-file-name>No new image selected.</p>
                            <div class="flex flex-wrap justify-end gap-3">
                                <button type="button" class="btn btn-ghost hidden" data-logo-reset>Reset Preview</button>
                                <button type="submit" form="clinicProfileForm" name="save_intent" value="logo" class="btn btn-primary is-locked" <?= !$canManageSettings ? 'disabled' : '' ?> aria-disabled="true" data-logo-ready="0" data-logo-save data-confirm-submit data-confirm-type="primary" data-confirm-title="Save system logo?" data-confirm-message="This will update the logo used across the staff and patient screens." data-confirm-toast="Saving logo...">
                                    <span class="material-symbols-outlined text-[18px]">save</span>
                                    Save Logo
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <section>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">System Color Theme</h2>
                    <p class="text-xs font-bold text-slate-500 mb-5">Choose the primary color used by navigation, buttons, focus rings, and active settings controls.</p>
                    <form method="post" class="settings-section" data-no-ajax="true">
                        <input type="hidden" name="action" value="save_theme">
                        <div class="settings-theme-grid">
                            <?php foreach ($themePresets as $themeKey => $theme): ?>
                                <label class="settings-theme-option <?= $activeTheme === $themeKey ? 'active' : '' ?>">
                                    <input type="radio" name="theme" value="<?= e($themeKey) ?>" <?= $activeTheme === $themeKey ? 'checked' : '' ?> <?= !$canManageSettings ? 'disabled' : '' ?>>
                                    <span class="settings-theme-swatch" style="--theme-swatch: <?= e($theme['primary']) ?>">
                                        <span class="material-symbols-outlined">check</span>
                                    </span>
                                    <span><?= e($theme['label']) ?></span>
                                </label>
                            <?php endforeach; ?>
                            <label class="settings-theme-option settings-theme-custom-option <?= $activeTheme === 'custom' ? 'active' : '' ?>" id="customThemeOption">
                                <input type="radio" name="theme" value="custom" id="customThemeRadio" <?= $activeTheme === 'custom' ? 'checked' : '' ?> <?= !$canManageSettings ? 'disabled' : '' ?>>
                                <span class="settings-theme-swatch settings-theme-custom-swatch" id="customThemeSwatch" style="--theme-swatch: <?= e($customThemeColor) ?>">
                                    <span class="material-symbols-outlined">check</span>
                                    <input
                                        type="color"
                                        name="custom_color"
                                        id="customThemeColor"
                                        class="settings-theme-color-input"
                                        value="<?= e($customThemeColor) ?>"
                                        aria-label="Choose a custom theme color"
                                        title="Choose a custom color"
                                        <?= !$canManageSettings ? 'disabled' : '' ?>
                                    >
                                </span>
                                <span>Custom</span>
                                <span class="material-symbols-outlined settings-theme-picker-icon" aria-hidden="true">colorize</span>
                            </label>
                        </div>
                        <div class="flex justify-end mt-5">
                            <button class="btn btn-primary" <?= !$canManageSettings ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="primary" data-confirm-title="Apply color theme?" data-confirm-message="This will update the CLINiQ interface theme for all pages using the shared shell." data-confirm-toast="Applying theme...">
                                <span class="material-symbols-outlined text-[18px]">palette</span>
                                Apply Theme
                            </button>
                        </div>
                    </form>
                    <script>
                        (() => {
                            const picker = document.getElementById('customThemeColor');
                            const radio = document.getElementById('customThemeRadio');
                            const swatch = document.getElementById('customThemeSwatch');
                            const option = document.getElementById('customThemeOption');
                            if (!picker || !radio || !swatch || !option) return;

                            const selectCustomTheme = () => {
                                radio.checked = true;
                                radio.dispatchEvent(new Event('change', { bubbles: true }));
                            };

                            picker.addEventListener('pointerdown', selectCustomTheme);
                            picker.addEventListener('input', () => {
                                selectCustomTheme();
                                swatch.style.setProperty('--theme-swatch', picker.value);
                            });
                            option.addEventListener('click', (event) => {
                                if (event.target === picker || picker.disabled) return;
                                event.preventDefault();
                                selectCustomTheme();
                                picker.click();
                            });
                        })();
                    </script>
                </section>
            </div>

            <div id="settings-account" class="settings-tab-panel <?= $currentTab === 'account' ? 'active' : '' ?> space-y-6">
                <section>
                    <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
                        <div>
                            <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Staff Profiles</h2>
                            <p class="text-xs font-bold text-slate-500 mb-0">Create separate logins for doctors and clinic staff. Visit records are automatically attributed to the profile currently signed in.</p>
                        </div>
                        <button type="button" class="btn btn-primary" onclick="showModal('createStaffProfileModal')" <?= !$canManageStaffProfiles ? 'disabled' : '' ?>>
                            <span class="material-symbols-outlined text-[18px]">person_add</span>
                            Create Profile
                        </button>
                    </div>
                    <?php if (!$canManageStaffProfiles): ?>
                        <div class="rounded-xl bg-amber-50 border border-amber-100 text-amber-800 px-4 py-3 text-sm font-bold mb-5">
                            Staff profiles can be managed only by administrators or IT experts.
                        </div>
                    <?php endif; ?>

                    <div class="modal-backdrop" id="createStaffProfileModal" aria-hidden="true">
                        <div class="modal-content bg-white rounded-[2rem] p-6 w-full max-w-3xl shadow-2xl">
                            <div class="flex items-start justify-between gap-4 mb-5">
                                <div class="flex items-center gap-3">
                                    <span class="w-11 h-11 rounded-xl bg-primary-fixed text-primary flex items-center justify-center">
                                        <span class="material-symbols-outlined">person_add</span>
                                    </span>
                                    <div>
                                        <h3 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Create Staff Profile</h3>
                                        <p class="text-xs font-bold text-slate-500 mb-0">Create a separate clinic staff login.</p>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-ghost" onclick="closeModal('createStaffProfileModal')" aria-label="Close create staff profile popup">
                                    <span class="material-symbols-outlined">close</span>
                                </button>
                            </div>

                            <form method="post" class="space-y-5" autocomplete="off" data-password-confirm-form>
                                <input type="hidden" name="action" value="create_staff_profile">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="settings-field">
                                        <label class="clinic-label" for="new_staff_name">Full Name</label>
                                        <input class="settings-input" id="new_staff_name" name="name" placeholder="Dr. Maria Santos" required>
                                    </div>
                                    <div class="settings-field">
                                        <label class="clinic-label" for="new_staff_email">Email Address</label>
                                        <input class="settings-input" id="new_staff_email" name="email" placeholder="santos_maria@plpasig.edu.ph" readonly>
                                    </div>
                                    <div class="settings-field">
                                        <label class="clinic-label" for="new_staff_id_number">Login ID Number</label>
                                        <input class="settings-input" id="new_staff_id_number" name="id_number" placeholder="STAFF-0006" pattern="STAFF-[0-9]{4}" style="text-transform:uppercase;">
                                    </div>
                                    <div class="settings-field">
                                        <label class="clinic-label" for="new_staff_role">Role</label>
                                        <select class="settings-input" id="new_staff_role" name="role">
                                            <?php foreach ($staffRoles as $roleValue => $roleLabel): ?>
                                                <option value="<?= e($roleValue) ?>" <?= $roleValue === 'doctor' ? 'selected' : '' ?>><?= e($roleLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="settings-field">
                                        <label class="clinic-label" for="new_staff_password">Password</label>
                                        <div class="password-field-control">
                                            <input class="settings-input" id="new_staff_password" name="password" type="password" minlength="8" autocomplete="new-password" required>
                                            <button type="button" class="password-visibility-button" data-password-toggle="new_staff_password" aria-label="Show password" aria-pressed="false"><span class="material-symbols-outlined">visibility</span></button>
                                        </div>
                                    </div>
                                    <div class="settings-field">
                                        <label class="clinic-label" for="new_staff_password_confirmation">Confirm Password</label>
                                        <div class="password-field-control">
                                            <input class="settings-input" id="new_staff_password_confirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required>
                                            <button type="button" class="password-visibility-button" data-password-toggle="new_staff_password_confirmation" aria-label="Show confirmation password" aria-pressed="false"><span class="material-symbols-outlined">visibility</span></button>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex justify-end">
                                    <button class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Create staff profile?" data-confirm-message="This staff member will be able to sign in and create records under their own name." data-confirm-toast="Creating staff profile...">
                                        <span class="material-symbols-outlined text-[18px]">person_add</span>
                                        Create Profile
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="settings-profile-list">
                        <?php foreach ($staffProfiles as $staffProfile): ?>
                            <?php $staffId = (int) $staffProfile['id']; ?>
                            <button type="button" class="settings-profile-row" onclick="showModal('staffProfileModal_<?= $staffId ?>')" aria-label="Open <?= e($staffProfile['name']) ?> staff profile">
                                <div class="settings-profile-avatar <?= avatar_color($staffProfile['name']) ?>"><?= initials($staffProfile['name']) ?></div>
                                <div class="settings-profile-row-copy">
                                    <strong><?= e($staffProfile['name']) ?></strong>
                                    <span>Click to view and manage this profile</span>
                                </div>
                                <span class="badge badge-in-progress"><?= e(staff_profile_role_label((string) $staffProfile['role'])) ?></span>
                                <span class="material-symbols-outlined settings-profile-row-arrow" aria-hidden="true">chevron_right</span>
                            </button>

                            <div class="modal-backdrop" id="staffProfileModal_<?= $staffId ?>" aria-hidden="true">
                                <div class="modal-content bg-white rounded-[2rem] p-6 w-full max-w-3xl shadow-2xl">
                                    <div class="flex items-start justify-between gap-4 mb-5">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <div class="settings-profile-avatar <?= avatar_color($staffProfile['name']) ?>"><?= initials($staffProfile['name']) ?></div>
                                            <div class="min-w-0">
                                                <h3 class="font-headline text-xl font-extrabold text-[#17261d] truncate mb-1"><?= e($staffProfile['name']) ?></h3>
                                                <span class="badge badge-in-progress"><?= e(staff_profile_role_label((string) $staffProfile['role'])) ?></span>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-ghost" onclick="closeModal('staffProfileModal_<?= $staffId ?>')" aria-label="Close staff profile popup">
                                            <span class="material-symbols-outlined">close</span>
                                        </button>
                                    </div>

                                    <form method="post" class="settings-profile-form">
                                        <input type="hidden" name="action" value="update_staff_profile">
                                        <input type="hidden" name="user_id" value="<?= $staffId ?>">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div class="settings-field">
                                                <label class="clinic-label" for="staff_name_<?= $staffId ?>">Name</label>
                                                <input class="settings-input staff-name-input" id="staff_name_<?= $staffId ?>" name="name" value="<?= e($staffProfile['name']) ?>" <?= !$canManageStaffProfiles ? 'readonly' : '' ?> required>
                                            </div>
                                            <div class="settings-field">
                                                <label class="clinic-label" for="staff_email_<?= $staffId ?>">Email</label>
                                                <input class="settings-input staff-email-input" id="staff_email_<?= $staffId ?>" name="email" value="<?= e($staffProfile['email']) ?>" readonly>
                                            </div>
                                            <div class="settings-field">
                                                <label class="clinic-label" for="staff_id_number_<?= $staffId ?>">ID Number</label>
                                                <input class="settings-input" id="staff_id_number_<?= $staffId ?>" name="id_number" value="<?= e($staffProfile['id_number']) ?>" pattern="STAFF-[0-9]{4}" style="text-transform:uppercase;" <?= !$canManageStaffProfiles ? 'readonly' : '' ?> required>
                                            </div>
                                            <div class="settings-field">
                                                <label class="clinic-label" for="staff_role_<?= $staffId ?>">Role</label>
                                                <select class="settings-input" id="staff_role_<?= $staffId ?>" name="role" <?= !$canManageStaffProfiles ? 'disabled' : '' ?>>
                                                    <?php foreach ($staffRoles as $roleValue => $roleLabel): ?>
                                                        <option value="<?= e($roleValue) ?>" <?= $staffProfile['role'] === $roleValue ? 'selected' : '' ?>><?= e($roleLabel) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="flex justify-end mt-5">
                                            <button class="btn btn-primary" <?= !$canManageStaffProfiles ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="primary" data-confirm-title="Update staff profile?" data-confirm-message="This updates the staff member name, email, login ID, or role used by CLINiQ." data-confirm-toast="Updating profile...">
                                                <span class="material-symbols-outlined text-[18px]">save</span>
                                                Save Changes
                                            </button>
                                        </div>
                                    </form>

                                    <div class="border-t border-slate-100 mt-6 pt-5">
                                        <form method="post" class="settings-password-reset-form" data-password-confirm-form>
                                            <input type="hidden" name="action" value="reset_staff_password">
                                            <input type="hidden" name="user_id" value="<?= $staffId ?>">
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                <div class="settings-field">
                                                    <label class="clinic-label" for="staff_password_<?= $staffId ?>">New Password</label>
                                                    <div class="password-field-control">
                                                        <input class="settings-input" id="staff_password_<?= $staffId ?>" name="password" type="password" minlength="8" autocomplete="new-password" placeholder="Enter a new password" <?= !$canManageStaffProfiles ? 'readonly' : '' ?> required>
                                                        <button type="button" class="password-visibility-button" data-password-toggle="staff_password_<?= $staffId ?>" aria-label="Show password" aria-pressed="false"><span class="material-symbols-outlined">visibility</span></button>
                                                    </div>
                                                </div>
                                                <div class="settings-field">
                                                    <label class="clinic-label" for="staff_password_confirmation_<?= $staffId ?>">Confirm New Password</label>
                                                    <div class="password-field-control">
                                                        <input class="settings-input" id="staff_password_confirmation_<?= $staffId ?>" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" placeholder="Repeat the new password" <?= !$canManageStaffProfiles ? 'readonly' : '' ?> required>
                                                        <button type="button" class="password-visibility-button" data-password-toggle="staff_password_confirmation_<?= $staffId ?>" aria-label="Show confirmation password" aria-pressed="false"><span class="material-symbols-outlined">visibility</span></button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="flex justify-end mt-4">
                                                <button class="btn btn-ghost" <?= !$canManageStaffProfiles ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="primary" data-confirm-title="Reset staff password?" data-confirm-message="This replaces the selected staff member password." data-confirm-toast="Resetting password...">
                                                    <span class="material-symbols-outlined text-[18px]">lock_reset</span>
                                                    Reset Password
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">My Password</h2>
                    <p class="text-xs font-bold text-slate-500 mb-5">Update the password for your own signed-in CLINiQ profile.</p>
                    <form method="post" class="settings-section space-y-5" autocomplete="off" data-password-confirm-form>
                        <input type="hidden" name="action" value="update_password">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="settings-field">
                                <label class="clinic-label" for="new_password">New Password</label>
                                <div class="password-field-control">
                                    <input class="settings-input" id="new_password" name="password" type="password" minlength="8" autocomplete="new-password" required>
                                    <button type="button" class="password-visibility-button" data-password-toggle="new_password" aria-label="Show password" aria-pressed="false"><span class="material-symbols-outlined">visibility</span></button>
                                </div>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label" for="confirm_password">Confirm New Password</label>
                                <div class="password-field-control">
                                    <input class="settings-input" id="confirm_password" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required>
                                    <button type="button" class="password-visibility-button" data-password-toggle="confirm_password" aria-label="Show confirmation password" aria-pressed="false"><span class="material-symbols-outlined">visibility</span></button>
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Update password?" data-confirm-message="Your staff login password will be replaced." data-confirm-toast="Updating password...">
                                <span class="material-symbols-outlined text-[18px]">lock_reset</span>
                                Update Password
                            </button>
                        </div>
                    </form>
                </section>
            </div>

            <div id="settings-dropdowns" class="settings-tab-panel <?= $currentTab === 'dropdowns' ? 'active' : '' ?> space-y-6">
                <section>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Dropdown Options</h2>
                    <p class="text-xs font-bold text-slate-500 mb-5">Edit the options shown in clinic forms. Deleted options disappear from future forms but old records keep their saved text.</p>
                    <?php if (!$canManageSettings): ?>
                        <div class="rounded-xl bg-amber-50 border border-amber-100 text-amber-800 px-4 py-3 text-sm font-bold mb-5">
                            Dropdown options can be managed only by administrators, doctors, or IT experts.
                        </div>
                    <?php endif; ?>

                    <nav class="settings-dropdown-category-list mb-5" aria-label="Dropdown categories">
                        <?php foreach ($dropdownCategoryLabels as $categoryKey => $categoryLabel): ?>
                            <?php $categoryCount = count($dropdownGroupsByCategory[$categoryKey] ?? []); ?>
                            <?php $defaultCategoryGroup = array_key_first($dropdownGroupsByCategory[$categoryKey] ?? []); ?>
                            <a href="index.php?tab=dropdowns&category=<?= urlencode($categoryKey) ?><?= $defaultCategoryGroup ? '&group=' . urlencode((string) $defaultCategoryGroup) : '' ?>"
                               class="settings-dropdown-category-link <?= $currentDropdownCategory === $categoryKey ? 'active' : '' ?> text-decoration-none"
                               data-dropdown-category-link
                               data-dropdown-category="<?= e($categoryKey) ?>"
                               data-dropdown-default-group="<?= e((string) $defaultCategoryGroup) ?>"
                               data-no-ajax="true">
                                <span><?= e($categoryLabel) ?></span>
                                <small><?= $categoryCount ?></small>
                            </a>
                        <?php endforeach; ?>
                    </nav>

                    <div class="grid grid-cols-1 xl:grid-cols-[0.42fr_0.58fr] gap-5">
                        <nav class="settings-section settings-dropdown-group-list" aria-label="Dropdown groups">
                            <?php foreach ($dropdownCategoryLabels as $categoryKey => $categoryLabel): ?>
                                <?php $categoryGroups = $dropdownGroupsByCategory[$categoryKey] ?? []; ?>
                                <div data-dropdown-category-panel="<?= e($categoryKey) ?>" <?= $currentDropdownCategory === $categoryKey ? '' : 'hidden' ?>>
                                    <div class="settings-dropdown-group-heading">
                                        <strong><?= e($categoryLabel) ?></strong>
                                        <small><?= count($categoryGroups) ?> dropdown groups</small>
                                    </div>
                                    <?php foreach ($categoryGroups as $groupKey => $group): ?>
                                        <a href="index.php?tab=dropdowns&category=<?= urlencode($categoryKey) ?>&group=<?= urlencode($groupKey) ?>"
                                           class="settings-dropdown-group-link <?= $currentDropdownGroup === $groupKey ? 'active' : '' ?> text-decoration-none"
                                           data-dropdown-group-link
                                           data-dropdown-category="<?= e($categoryKey) ?>"
                                           data-dropdown-group="<?= e($groupKey) ?>"
                                           data-no-ajax="true">
                                            <span>
                                                <strong><?= e($group['label']) ?></strong>
                                                <small><?= count($group['options']) ?> options</small>
                                            </span>
                                            <span class="material-symbols-outlined">chevron_right</span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </nav>

                        <div class="space-y-5" data-dropdown-content-panels>
                            <?php foreach ($dropdownGroups as $panelGroupKey => $selectedDropdown): ?>
                                <?php $panelCategory = $dropdownGroupCategories[$panelGroupKey] ?? 'students'; ?>
                                <div data-dropdown-content-panel="<?= e($panelGroupKey) ?>"
                                     data-dropdown-category="<?= e($panelCategory) ?>"
                                     <?= $currentDropdownGroup === $panelGroupKey ? '' : 'hidden' ?>>
                                    <section class="settings-section">
                                        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4 mb-5">
                                            <div>
                                                <h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1"><?= e($selectedDropdown['label']) ?></h3>
                                                <p class="settings-help mb-0"><?= e($selectedDropdown['description']) ?></p>
                                            </div>
                                            <form method="post">
                                                <input type="hidden" name="action" value="reset_dropdown_group">
                                                <input type="hidden" name="group_key" value="<?= e($panelGroupKey) ?>">
                                                <button class="btn btn-ghost" <?= !$canManageSettings ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="danger" data-confirm-title="Reset this dropdown?" data-confirm-message="This restores this dropdown group to its default CLINiQ options." data-confirm-toast="Resetting dropdown...">
                                                    <span class="material-symbols-outlined text-[18px]">restart_alt</span>
                                                    Reset Group
                                                </button>
                                            </form>
                                        </div>

                                        <form method="post" class="settings-dropdown-add-form">
                                            <input type="hidden" name="action" value="add_dropdown_option">
                                            <input type="hidden" name="group_key" value="<?= e($panelGroupKey) ?>">
                                            <input class="settings-input" name="label" placeholder="New option label" maxlength="120" <?= !$canManageSettings ? 'readonly' : '' ?> required>
                                            <button class="btn btn-primary" <?= !$canManageSettings ? 'disabled' : '' ?>>
                                                <span class="material-symbols-outlined text-[18px]">add</span>
                                                Add
                                            </button>
                                        </form>
                                    </section>

                                    <div class="settings-dropdown-option-list">
                                        <?php foreach ($selectedDropdown['options'] as $option): ?>
                                            <article class="settings-section settings-dropdown-option-card">
                                                <form method="post" class="settings-dropdown-option-form">
                                                    <input type="hidden" name="action" value="update_dropdown_option">
                                                    <input type="hidden" name="group_key" value="<?= e($panelGroupKey) ?>">
                                                    <input type="hidden" name="option_id" value="<?= e($option['id']) ?>">
                                                    <label class="clinic-label" for="dropdown_<?= e($panelGroupKey . '_' . $option['id']) ?>">Option Label</label>
                                                    <div class="settings-dropdown-option-row">
                                                        <input class="settings-input" id="dropdown_<?= e($panelGroupKey . '_' . $option['id']) ?>" name="label" value="<?= e($option['label']) ?>" maxlength="120" <?= !$canManageSettings ? 'readonly' : '' ?> required>
                                                        <label class="settings-switch-inline">
                                                            <input type="checkbox" name="active" value="1" <?= !empty($option['active']) ? 'checked' : '' ?> <?= !$canManageSettings ? 'disabled' : '' ?>>
                                                            Active
                                                        </label>
                                                        <button class="btn btn-outline" <?= !$canManageSettings ? 'disabled' : '' ?>>
                                                            <span class="material-symbols-outlined text-[18px]">save</span>
                                                            Save
                                                        </button>
                                                    </div>
                                                </form>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="delete_dropdown_option">
                                                    <input type="hidden" name="group_key" value="<?= e($panelGroupKey) ?>">
                                                    <input type="hidden" name="option_id" value="<?= e($option['id']) ?>">
                                                    <button class="btn btn-danger" <?= !$canManageSettings ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="danger" data-confirm-title="Delete dropdown option?" data-confirm-message="This removes the option from future forms. Existing saved records will not be changed." data-confirm-toast="Deleting option...">
                                                        <span class="material-symbols-outlined text-[18px]">delete</span>
                                                        Delete
                                                    </button>
                                                </form>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            </div>

            <div id="settings-clinical" class="settings-tab-panel <?= $currentTab === 'clinical' ? 'active' : '' ?> space-y-6">
                <section>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Incident Risk Settings</h2>
                    <p class="text-xs font-bold text-slate-500 mb-5">These values control only QR-NFC incident reports and nurse alert risk badges. Walk-in clinic visits are not classified here.</p>
                </section>

                <form method="post" class="space-y-6">
                    <input type="hidden" name="action" value="save_risk">

                    <section class="settings-section">
                        <div class="flex items-center gap-3 mb-5">
                            <span class="w-10 h-10 rounded-xl bg-primary-fixed text-primary flex items-center justify-center material-symbols-outlined">monitoring</span>
                            <div>
                                <h3 class="font-headline text-lg font-extrabold text-[#17261d] m-0">Score Bands</h3>
                                <p class="settings-help m-0">Set the minimum score needed for each incident alert badge.</p>
                            </div>
                        </div>
                        <div class="settings-score-band">
                            <div class="settings-band-card">
                                <span class="badge badge-low mb-3">Low</span>
                                <p class="settings-help mb-0">Score below Moderate minimum.</p>
                            </div>
                            <div class="settings-band-card">
                                <label class="clinic-label" for="moderate_min">Moderate Starts At</label>
                                <input class="settings-input" id="moderate_min" name="moderate_min" type="number" min="0" value="<?= risk_setting_value($settings, 'moderate_min') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                            </div>
                            <div class="settings-band-card">
                                <label class="clinic-label" for="high_min">High Starts At</label>
                                <input class="settings-input" id="high_min" name="high_min" type="number" min="0" value="<?= risk_setting_value($settings, 'high_min') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                            </div>
                            <div class="settings-band-card">
                                <label class="clinic-label" for="critical_min">Critical Starts At</label>
                                <input class="settings-input" id="critical_min" name="critical_min" type="number" min="0" value="<?= risk_setting_value($settings, 'critical_min') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                            </div>
                        </div>
                    </section>

                    <section class="settings-section">
                        <div class="flex items-center gap-3 mb-5">
                            <span class="w-10 h-10 rounded-xl bg-primary-fixed text-primary flex items-center justify-center material-symbols-outlined">emergency</span>
                            <div>
                                <h3 class="font-headline text-lg font-extrabold text-[#17261d] m-0">Incident Answer Points</h3>
                                <p class="settings-help m-0">Adjust how each structured incident report answer contributes to the risk score.</p>
                            </div>
                        </div>
                        <?php
                        $incidentPointGroups = [
                            'Incident Type' => [
                                ['Breathing difficulty', 'incident_type_breathing_points'],
                                ['Fainting or unconscious', 'incident_type_unconscious_points'],
                                ['Allergic reaction', 'incident_type_allergic_points'],
                                ['Bleeding or wound', 'incident_type_bleeding_points'],
                                ['Injury or fall', 'incident_type_injury_points'],
                                ['Fever or illness', 'incident_type_illness_points'],
                            ],
                            'Observed Condition' => [
                                ['Unconscious', 'condition_unconscious_points'],
                                ['Seizure-like movement', 'condition_seizure_points'],
                                ['Severe pain', 'condition_severe_pain_points'],
                                ['Dizzy or weak', 'condition_dizzy_weak_points'],
                            ],
                            'Breathing, Bleeding, Mobility, Pain' => [
                                ['Not breathing normally', 'breathing_not_normal_points'],
                                ['Shortness of breath', 'breathing_shortness_points'],
                                ['Wheezing', 'breathing_wheezing_points'],
                                ['Heavy bleeding', 'bleeding_heavy_points'],
                                ['Minor bleeding', 'bleeding_minor_points'],
                                ['Cannot stand or walk', 'mobility_cannot_points'],
                                ['Needs assistance', 'mobility_assistance_points'],
                                ['Severe pain level', 'pain_severe_points'],
                                ['Moderate pain level', 'pain_moderate_points'],
                            ],
                        ];
                        ?>
                        <div class="space-y-5">
                            <?php foreach ($incidentPointGroups as $groupLabel => $fields): ?>
                                <div>
                                    <h4 class="text-sm font-extrabold text-slate-800 mb-3"><?= e($groupLabel) ?></h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                                        <?php foreach ($fields as [$label, $field]): ?>
                                            <div class="settings-field">
                                                <label class="clinic-label" for="<?= e($field) ?>"><?= e($label) ?></label>
                                                <input class="settings-input" id="<?= e($field) ?>" name="<?= e($field) ?>" type="number" min="0" value="<?= risk_setting_value($settings, $field) ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="settings-section">
                        <div class="flex items-center gap-3 mb-5">
                            <span class="w-10 h-10 rounded-xl bg-primary-fixed text-primary flex items-center justify-center material-symbols-outlined">travel_explore</span>
                            <div>
                                <h3 class="font-headline text-lg font-extrabold text-[#17261d] m-0">Keyword Indicators</h3>
                                <p class="settings-help m-0">Keywords are matched against incident notes and selected answers. Use one keyword per line.</p>
                            </div>
                        </div>
                        <?php
                        $keywordGroups = [
                            ['Critical Keywords', 'critical_keywords', 'critical_keyword_points'],
                            ['Urgent Keywords', 'urgent_keywords', 'urgent_keyword_points'],
                            ['Minor Keywords', 'minor_keywords', 'minor_keyword_points'],
                        ];
                        ?>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                            <?php foreach ($keywordGroups as [$label, $keywordField, $pointsField]): ?>
                                <div class="settings-field">
                                    <label class="clinic-label" for="<?= e($keywordField) ?>"><?= e($label) ?></label>
                                    <textarea class="settings-textarea settings-textarea-compact" id="<?= e($keywordField) ?>" name="<?= e($keywordField) ?>" <?= !$canManageSettings ? 'readonly' : '' ?>><?= e(risk_keywords_text($settings, $keywordField)) ?></textarea>
                                    <label class="clinic-label mt-3" for="<?= e($pointsField) ?>">Points Per Match</label>
                                    <input class="settings-input" id="<?= e($pointsField) ?>" name="<?= e($pointsField) ?>" type="number" min="0" value="<?= risk_setting_value($settings, $pointsField) ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="settings-section">
                        <div class="flex items-center gap-3 mb-5">
                            <span class="w-10 h-10 rounded-xl bg-primary-fixed text-primary flex items-center justify-center material-symbols-outlined">clinical_notes</span>
                            <div>
                                <h3 class="font-headline text-lg font-extrabold text-[#17261d] m-0">Response Guidance</h3>
                                <p class="settings-help m-0">This text appears inside the nurse alert card after the incident is classified.</p>
                            </div>
                        </div>
                        <?php
                        $guidanceFields = [
                            ['Low Guidance', 'guidance_low'],
                            ['Moderate Guidance', 'guidance_moderate'],
                            ['High Guidance', 'guidance_high'],
                            ['Critical Guidance', 'guidance_critical'],
                        ];
                        ?>
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            <?php foreach ($guidanceFields as [$label, $field]): ?>
                                <div class="settings-field">
                                    <label class="clinic-label" for="<?= e($field) ?>"><?= e($label) ?></label>
                                    <textarea class="settings-textarea settings-textarea-compact" id="<?= e($field) ?>" name="<?= e($field) ?>" maxlength="500" <?= !$canManageSettings ? 'readonly' : '' ?>><?= risk_setting_value($settings, $field) ?></textarea>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pt-2">
                        <p class="settings-help mb-0">Saved changes apply to newly submitted incident alerts. Existing alert badges remain as originally recorded.</p>
                        <button class="btn btn-primary justify-center" <?= !$canManageSettings ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="primary" data-confirm-title="Save incident risk settings?" data-confirm-message="This will change how future incident alerts are classified." data-confirm-toast="Saving incident risk settings...">
                            <span class="material-symbols-outlined text-[18px]">save</span>
                            Save Incident Risk Settings
                        </button>
                    </div>
                </form>
            </div>

            <?php if ($canManageApeCycles): ?>
                <div id="settings-ape-cycle" class="settings-tab-panel <?= $currentTab === 'ape-cycle' ? 'active' : '' ?> space-y-6">
                    <section>
                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                            <div>
                                <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Annual Physical Examination Cycle</h2>
                                <p class="text-xs font-bold text-slate-500 mb-0">Prepare one APE cycle per school year for every patient with an active account.</p>
                            </div>
                            <?php
                            $apeCycleStatus = (string) ($apeCurrentCycle['status'] ?? 'Not Started');
                            $apeCycleBadgeClass = match ($apeCycleStatus) {
                                'Active' => 'badge-in-progress',
                                'Closed' => 'badge-completed',
                                'Archived' => 'badge-cancelled',
                                default => 'badge-cancelled',
                            };
                            ?>
                            <span class="badge <?= e($apeCycleBadgeClass) ?>"><?= e($apeCycleStatus) ?></span>
                        </div>
                    </section>

                    <?php if ($apeCurrentCycle): ?>
                        <section class="settings-section space-y-5">
                            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                                <div>
                                    <p class="clinic-label mb-2">Current / Latest Cycle</p>
                                    <h3 class="font-headline text-2xl font-extrabold text-[#17261d] mb-1">School Year <?= e($apeCurrentCycle['academic_year']) ?></h3>
                                    <p class="settings-help mb-0">
                                        <?= e(date('M j, Y', strtotime($apeCurrentCycle['compliance_start']))) ?> to <?= e(date('M j, Y', strtotime($apeCurrentCycle['compliance_end']))) ?>
                                        &bull; Started <?= e(date('M j, Y g:i A', strtotime($apeCurrentCycle['started_at']))) ?>
                                        <?php if (!empty($apeCurrentCycle['started_by_name'])): ?>&bull; by <?= e($apeCurrentCycle['started_by_name']) ?><?php endif; ?>
                                    </p>
                                </div>
                                <?php if ($apeCycleStatus === 'Active'): ?>
                                    <form method="post" data-no-ajax="true">
                                        <input type="hidden" name="action" value="close_ape_cycle">
                                        <input type="hidden" name="ape_cycle_id" value="<?= (int) $apeCurrentCycle['ape_cycle_id'] ?>">
                                        <button class="btn btn-secondary justify-center" data-confirm-submit data-confirm-type="primary" data-confirm-title="Close this APE cycle?" data-confirm-message="No new work should be added after closing. Existing APE records remain available." data-confirm-toast="Closing APE cycle...">
                                            <span class="material-symbols-outlined text-[18px]">event_busy</span>
                                            Close Cycle
                                        </button>
                                    </form>
                                <?php elseif ($apeCycleStatus === 'Closed'): ?>
                                    <form method="post" data-no-ajax="true">
                                        <input type="hidden" name="action" value="archive_ape_cycle">
                                        <input type="hidden" name="ape_cycle_id" value="<?= (int) $apeCurrentCycle['ape_cycle_id'] ?>">
                                        <button class="btn btn-secondary justify-center" data-confirm-submit data-confirm-type="primary" data-confirm-title="Archive this APE cycle?" data-confirm-message="Archived records remain available in patient history and reports." data-confirm-toast="Archiving APE cycle...">
                                            <span class="material-symbols-outlined text-[18px]">archive</span>
                                            Archive Cycle
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
                                <?php foreach ([
                                    'not_started' => 'Not Started',
                                    'in_progress' => 'In Progress',
                                    'completed' => 'Ready for Decision',
                                    'cleared' => 'Cleared',
                                    'follow_up' => 'Follow-up',
                                ] as $progressKey => $progressLabel): ?>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                        <strong class="font-headline text-2xl text-[#17261d] block"><?= number_format((int) $apeCycleProgress[$progressKey]) ?></strong>
                                        <span class="text-xs font-bold text-slate-500"><?= e($progressLabel) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div>
                                <div class="flex items-center justify-between gap-3 mb-2 text-xs font-bold text-slate-600">
                                    <span>Clearance compliance</span>
                                    <span><?= (int) $apeCycleProgress['compliance_percent'] ?>% &bull; <?= number_format((int) $apeCycleProgress['cleared']) ?> of <?= number_format((int) $apeCycleProgress['total']) ?> patient(s)</span>
                                </div>
                                <div class="h-3 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-valuenow="<?= (int) $apeCycleProgress['compliance_percent'] ?>" aria-valuemin="0" aria-valuemax="100">
                                    <div class="h-full rounded-full bg-[var(--cliniq-primary)] transition-all" style="width: <?= (int) $apeCycleProgress['compliance_percent'] ?>%"></div>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>

                    <section class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="settings-section">
                            <p class="clinic-label mb-2">Patients Included</p>
                            <div class="flex items-end gap-2">
                                <strong class="font-headline text-3xl text-[#17261d]" id="apeActivePatientCount"><?= number_format($activeApePatientCount) ?></strong>
                                <span class="text-xs font-bold text-slate-500 pb-1">active patient(s)</span>
                            </div>
                            <p class="settings-help mt-3 mb-0">Starting a cycle creates one APE record for each active patient who does not already have one for that school year.</p>
                        </div>
                        <div class="settings-section">
                            <p class="clinic-label mb-2">Patients Excluded</p>
                            <div class="flex items-end gap-2">
                                <strong class="font-headline text-3xl text-slate-500"><?= number_format($excludedApePatientCount) ?></strong>
                                <span class="text-xs font-bold text-slate-500 pb-1">inactive or suspended</span>
                            </div>
                            <p class="settings-help mt-3 mb-0">These accounts are not included until their account status becomes active.</p>
                        </div>
                    </section>

                    <section class="settings-section space-y-5">
                        <div>
                            <h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">School Year and Compliance Period</h3>
                            <p class="settings-help mb-0">The compliance period tells patients when this school year's APE requirements must be completed.</p>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                            <div class="settings-field">
                                <label class="clinic-label" for="apeSchoolYear">School Year</label>
                                <input class="settings-input" id="apeSchoolYear" value="<?= e($defaultApeSchoolYear) ?>" inputmode="numeric" maxlength="9" placeholder="2026-2027" aria-describedby="apeSchoolYearHelp" <?= $hasActiveApeCycle ? 'disabled' : '' ?>>
                                <p class="settings-help mb-0" id="apeSchoolYearHelp">Use the format YYYY-YYYY.</p>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label" for="apePeriodStart">Compliance Start</label>
                                <input class="settings-input" id="apePeriodStart" type="date" value="<?= e($defaultApeStartDate) ?>" <?= $hasActiveApeCycle ? 'disabled' : '' ?>>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label" for="apePeriodEnd">Compliance End</label>
                                <input class="settings-input" id="apePeriodEnd" type="date" value="<?= e($defaultApeEndDate) ?>" <?= $hasActiveApeCycle ? 'disabled' : '' ?>>
                            </div>
                            <div class="settings-field md:col-span-3">
                                <label class="clinic-label" for="apeExamScheduleDate">Exam Schedule Date <span class="font-normal text-slate-400">(optional)</span></label>
                                <input class="settings-input" id="apeExamScheduleDate" type="date" <?= $hasActiveApeCycle ? 'disabled' : '' ?>>
                                <p class="settings-help mb-0">The single day patients are scheduled for examination. If they miss this date without confirming vitals, their status will show as <strong>Missed</strong>.</p>
                            </div>
                        </div>
                        <p class="hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs font-bold text-red-700" id="apeCycleError" role="alert"></p>
                    </section>

                    <section class="settings-section bg-[var(--cliniq-surface-low)]">
                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
                            <div>
                                <p class="clinic-label mb-2">Cycle Preview</p>
                                <h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1" id="apeCyclePreviewTitle">School Year <?= e($defaultApeSchoolYear) ?></h3>
                                <p class="settings-help mb-0"><span id="apeCyclePreviewCount"><?= number_format($activeApePatientCount) ?></span> active patient(s) &bull; <span id="apeCyclePreviewDates"><?= e(date('M j, Y', strtotime($defaultApeStartDate))) ?> to <?= e(date('M j, Y', strtotime($defaultApeEndDate))) ?></span></p>
                            </div>
                            <button type="button" class="btn btn-primary justify-center shrink-0" id="startApeCycleButton" <?= ($activeApePatientCount < 1 || $hasActiveApeCycle) ? 'disabled' : '' ?>>
                                <span class="material-symbols-outlined text-[18px]">play_circle</span>
                                Start This Year&rsquo;s APE
                            </button>
                        </div>
                        <?php if ($activeApePatientCount < 1): ?>
                            <p class="settings-help mt-3 mb-0">A cycle cannot start because there are no active patient accounts.</p>
                        <?php elseif ($hasActiveApeCycle): ?>
                            <p class="settings-help mt-3 mb-0">Close the active <?= e($apeCurrentCycle['academic_year']) ?> cycle before starting another school year.</p>
                        <?php endif; ?>
                    </section>

                    <?php if ($hasActiveApeCycle): ?>
                    <section class="settings-section space-y-4">
                        <div>
                            <h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Exam Schedule Date</h3>
                            <p class="settings-help mb-0">
                                <?php if (!empty($apeCurrentCycle['exam_schedule_date'])): ?>
                                    Currently set to <strong><?= e(date('F j, Y', strtotime($apeCurrentCycle['exam_schedule_date']))) ?></strong>. Update below to change.
                                <?php else: ?>
                                    No exam date set yet. Set one to let patients know when to present for examination.
                                <?php endif; ?>
                            </p>
                        </div>
                        <form method="post" data-no-ajax="true" class="flex flex-col sm:flex-row gap-3 items-end">
                            <input type="hidden" name="action" value="update_ape_cycle_schedule">
                            <input type="hidden" name="ape_cycle_id" value="<?= (int) $apeCurrentCycle['ape_cycle_id'] ?>">
                            <div class="settings-field mb-0 flex-1">
                                <label class="clinic-label" for="updateExamScheduleDate">Exam Schedule Date</label>
                                <input class="settings-input" id="updateExamScheduleDate" name="exam_schedule_date" type="date" value="<?= e($apeCurrentCycle['exam_schedule_date'] ?? '') ?>" required>
                            </div>
                            <button class="btn btn-primary justify-center shrink-0" data-confirm-submit data-confirm-type="primary" data-confirm-title="Set exam schedule date?" data-confirm-message="Patients who miss this date without confirming vitals will be marked as Missed." data-confirm-toast="Saving exam schedule...">
                                <span class="material-symbols-outlined text-[18px]">event_available</span>
                                Set Schedule
                            </button>
                        </form>
                    </section>
                    <?php endif; ?>

                    <section class="settings-section border-2 border-red-200 bg-red-50 space-y-4">
                        <div>
                            <h3 class="font-headline text-lg font-extrabold text-red-800 mb-1">Start New School Year</h3>
                            <p class="settings-help mb-0 text-red-700">This resets <strong>all active patient accounts</strong> (students, faculty, and personnel) to inactive. On their next login, each person will be asked to confirm they are still enrolled or employed before regaining access.</p>
                        </div>
                        <form method="post" data-no-ajax="true">
                            <input type="hidden" name="action" value="reset_school_year">
                            <button class="btn btn-danger justify-center" data-confirm-submit data-confirm-type="danger" data-confirm-title="Reset all patient accounts?" data-confirm-message="All active student, faculty, and personnel accounts will be set to inactive. Each person must confirm re-enrollment or re-employment on their next login. This cannot be undone." data-confirm-toast="Resetting accounts...">
                                <span class="material-symbols-outlined text-[18px]">restart_alt</span>
                                Start New School Year
                            </button>
                        </form>
                    </section>

                </div>
            <?php endif; ?>
            <div id="settings-email" class="settings-tab-panel <?= $currentTab === 'email' ? 'active' : '' ?> space-y-6">
                <section>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Email Settings</h2>
                    <p class="text-xs font-bold text-slate-500 mb-0">Set the clinic email identity used for patient notifications.</p>
                </section>

                <section class="settings-section space-y-5">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-5">
                        <div class="flex items-start gap-4">
                            <div class="w-14 h-14 rounded-2xl bg-[var(--cliniq-surface-low)] text-[var(--cliniq-primary)] flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-[28px]">alternate_email</span>
                            </div>
                            <div>
                                <p class="clinic-label mb-2">Clinic Email</p>
                                <h3 class="font-headline text-2xl font-extrabold text-[#17261d] mb-1">
                                    <?= e($mailSettings['from_name'] ?: 'CLINiQ Clinic') ?>
                                </h3>
                                <p class="text-sm font-bold text-slate-500 mb-0">
                                    <?= e($mailSettings['from_email'] ?: ($mailSettings['username'] ?: 'No email configured yet')) ?>
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-col sm:flex-row gap-3">
                            <?php if ($canManageSettings): ?>
                                <button type="button" class="btn btn-secondary justify-center" id="openEmailComposerButton" <?= $mailRecipients === [] ? 'disabled' : '' ?> title="<?= $mailRecipients === [] ? 'No valid recipient emails found' : 'Compose a custom email' ?>">
                                    <span class="material-symbols-outlined text-[18px]">edit_square</span>
                                    Compose Email
                                </button>
                                <button type="button" class="btn btn-primary justify-center" id="openEmailConfigButton">
                                    <span class="material-symbols-outlined text-[18px]">settings</span>
                                    Configure Email
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($mailConfigured): ?>
                        <div class="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs font-bold text-emerald-700">
                            <span class="material-symbols-outlined text-[16px]">check_circle</span>
                            Email is configured and ready for system notifications.
                        </div>
                    <?php else: ?>
                        <div class="flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-bold text-amber-700">
                            <span class="material-symbols-outlined text-[16px]">warning</span>
                            Email is not configured yet. Patient notifications will not send until it is configured.
                        </div>
                    <?php endif; ?>
                </section>

                <section class="settings-section space-y-4">
                    <div>
                        <h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Email Notification Formats</h3>
                        <p class="settings-help mb-0">Review every automated patient email. Formats stay locked until you explicitly unlock the editor.</p>
                    </div>
                    <div class="divide-y divide-slate-200 rounded-2xl border border-slate-200 overflow-hidden">
                        <?php foreach ($mailNotificationTemplates as $templateKey => $definition): ?>
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 bg-white">
                                <div class="flex items-start gap-3 min-w-0">
                                    <span class="w-10 h-10 rounded-xl bg-[var(--cliniq-surface-low)] text-[var(--cliniq-primary)] flex items-center justify-center shrink-0">
                                        <span class="material-symbols-outlined text-[21px]"><?= e($definition['icon']) ?></span>
                                    </span>
                                    <div class="min-w-0">
                                        <h4 class="font-headline text-sm font-extrabold text-slate-800 mb-1"><?= e($definition['label']) ?></h4>
                                        <p class="text-xs font-bold text-slate-500 leading-5 mb-0"><?= e($definition['description']) ?></p>
                                    </div>
                                </div>
                                <?php if ($canManageSettings): ?>
                                    <button type="button" class="btn btn-secondary justify-center shrink-0" data-edit-email-template="<?= e($templateKey) ?>">
                                        <span class="material-symbols-outlined text-[18px]">edit_note</span>
                                        Edit Format
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <?php if ($mailConfigured && $canManageSettings): ?>
                <section class="settings-section space-y-4">
                    <div>
                        <h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Send Test Email</h3>
                        <p class="settings-help mb-0">Send a test email to verify your SMTP configuration is working correctly.</p>
                    </div>
                    <form method="post" data-no-ajax="true" class="flex flex-col sm:flex-row gap-3 items-end">
                        <input type="hidden" name="action" value="send_test_email">
                        <div class="settings-field mb-0 flex-1">
                            <label class="clinic-label" for="testEmailAddress">Send Test To</label>
                            <input class="settings-input" id="testEmailAddress" name="test_email" type="email"
                                placeholder="your-email@example.com"
                                value="<?= e($user['email'] ?? '') ?>" required>
                        </div>
                        <button type="submit" class="btn btn-secondary justify-center shrink-0">
                            <span class="material-symbols-outlined text-[18px]">send</span>
                            Send Test Email
                        </button>
                    </form>
                </section>
                <?php endif; ?>
            </div>

            <div id="settings-maintenance" class="settings-tab-panel <?= $currentTab === 'maintenance' ? 'active' : '' ?> space-y-6">
                <section>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Maintenance</h2>
                    <p class="text-xs font-bold text-slate-500 mb-5">Restore incident alert classification values to the default prototype values.</p>
                </section>
                <section class="settings-section flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Reset Incident Risk Rules</h3>
                        <p class="settings-help mb-0">This restores incident alert point values, keyword groups, score bands, and response guidance.</p>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="reset_risk">
                        <button class="btn btn-danger justify-center" <?= !$canManageSettings ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="danger" data-confirm-title="Reset incident risk settings?" data-confirm-message="This will restore the default incident alert risk rules." data-confirm-toast="Resetting incident risk settings...">
                            <span class="material-symbols-outlined text-[18px]">restart_alt</span>
                            Reset Incident Rules
                        </button>
                    </form>
                </section>
            </div>
        </div>
    </section>
</div>

<?php if ($canManageSettings): ?>
    <script type="application/json" id="emailNotificationTemplateData"><?= json_encode($mailNotificationTemplates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>

    <div id="emailTemplateModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="emailTemplateModalTitle">
        <div class="modal-content bg-white rounded-[1.5rem] w-full max-w-5xl max-h-[calc(100vh-2rem)] overflow-y-auto p-7 shadow-2xl border border-outline-variant/10">
            <div class="flex items-start justify-between gap-4 mb-6">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-xl bg-[var(--cliniq-surface-low)] text-[var(--cliniq-primary)] flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-[26px]" id="emailTemplateModalIcon">mail</span>
                    </div>
                    <div>
                        <p class="clinic-label mb-1">Automated Notification</p>
                        <h3 class="font-headline text-2xl font-extrabold text-[#17261d] mb-1" id="emailTemplateModalTitle">Email Format</h3>
                        <p class="text-sm font-bold text-slate-500 leading-6 mb-0" id="emailTemplateModalDescription"></p>
                    </div>
                </div>
                <button type="button" class="btn btn-ghost justify-center px-3" id="closeEmailTemplateButton" aria-label="Close email format editor">
                    <span class="material-symbols-outlined text-[20px]">close</span>
                </button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_minmax(300px,.8fr)] gap-6">
                <form method="post" data-no-ajax="true" id="emailTemplateForm" class="space-y-4">
                    <input type="hidden" name="action" value="save_mail_template">
                    <input type="hidden" name="template_key" id="emailTemplateKey">

                    <div class="flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                        <span class="text-xs font-extrabold text-amber-800" id="emailTemplateLockStatus">Format is read-only.</span>
                        <button type="button" class="btn btn-secondary justify-center shrink-0" id="unlockEmailTemplateButton">
                            <span class="material-symbols-outlined text-[18px]">lock_open</span>
                            Unlock Editing
                        </button>
                    </div>

                    <div class="settings-field">
                        <label class="clinic-label" for="emailTemplateSubject">Subject</label>
                        <input class="settings-input" id="emailTemplateSubject" name="subject" type="text" maxlength="180" required readonly>
                    </div>
                    <div class="settings-field">
                        <label class="clinic-label" for="emailTemplateHeading">Heading</label>
                        <input class="settings-input" id="emailTemplateHeading" name="heading" type="text" maxlength="180" required readonly>
                    </div>
                    <div class="settings-field">
                        <label class="clinic-label" for="emailTemplateMessage">Message</label>
                        <textarea class="settings-input min-h-32 resize-y" id="emailTemplateMessage" name="message" maxlength="4000" required readonly></textarea>
                    </div>
                    <div class="settings-field">
                        <label class="clinic-label" for="emailTemplateButtonLabel">Button Label</label>
                        <input class="settings-input" id="emailTemplateButtonLabel" name="button_label" type="text" maxlength="60" required readonly>
                        <p class="settings-help mb-0" id="emailTemplateActionHint"></p>
                    </div>
                    <div class="settings-field">
                        <label class="clinic-label" for="emailTemplateFooter">Footer</label>
                        <textarea class="settings-input min-h-24 resize-y" id="emailTemplateFooter" name="footer" maxlength="1000" readonly></textarea>
                    </div>

                    <div>
                        <p class="clinic-label mb-2">Available Placeholders</p>
                        <div class="flex flex-wrap gap-2" id="emailTemplatePlaceholders"></div>
                        <p class="settings-help mt-2 mb-0">Required placeholders must remain somewhere in the format. Click a pill to insert it into the last selected field after unlocking.</p>
                    </div>

                    <div class="flex flex-col-reverse sm:flex-row sm:justify-between gap-3 pt-2">
                        <button type="button" class="btn btn-secondary justify-center" id="cancelEmailTemplateButton">Cancel</button>
                        <button type="submit" class="btn btn-primary justify-center" id="saveEmailTemplateButton" disabled>
                            <span class="material-symbols-outlined text-[18px]">save</span>
                            Save Format
                        </button>
                    </div>
                </form>

                <div class="space-y-4">
                    <div>
                        <p class="clinic-label mb-2">Live Preview</p>
                        <div class="rounded-2xl overflow-hidden border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-center gap-3 bg-[var(--cliniq-primary)] px-5 py-4 text-white">
                                <img src="<?= e($systemLogoUrl) ?>" alt="Clinic logo" class="w-11 h-11 rounded-xl bg-white object-contain p-1">
                                <strong><?= e($clinicProfile['system_name'] ?? 'CLINiQ Clinic') ?></strong>
                            </div>
                            <div class="p-5">
                                <p class="text-xs font-extrabold uppercase tracking-wider text-slate-400 mb-2" id="emailTemplatePreviewSubject"></p>
                                <h4 class="font-headline text-xl font-extrabold text-slate-800 mb-3" id="emailTemplatePreviewHeading"></h4>
                                <p class="text-sm font-semibold leading-6 text-slate-600 whitespace-pre-line mb-5" id="emailTemplatePreviewMessage"></p>
                                <span class="btn btn-primary pointer-events-none inline-flex" id="emailTemplatePreviewButton"></span>
                                <p class="text-xs font-bold leading-5 text-slate-400 whitespace-pre-line mt-5 mb-0" id="emailTemplatePreviewFooter"></p>
                            </div>
                        </div>
                    </div>
                    <form method="post" data-no-ajax="true" id="resetEmailTemplateForm">
                        <input type="hidden" name="action" value="reset_mail_template">
                        <input type="hidden" name="template_key" id="resetEmailTemplateKey">
                        <button type="submit" class="btn btn-ghost justify-center w-full text-slate-600" data-confirm-submit data-confirm-type="warning" data-confirm-title="Restore default email format?" data-confirm-message="Your saved wording for this notification will be replaced by the system default." data-confirm-toast="Restoring email format...">
                            <span class="material-symbols-outlined text-[18px]">restart_alt</span>
                            Restore Default
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div id="emailConfigModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="emailConfigModalTitle">
        <div class="modal-content bg-white rounded-[1.5rem] w-full max-w-3xl p-7 shadow-2xl border border-outline-variant/10">
            <div class="flex items-start justify-between gap-4 mb-6">
                <div>
                    <div class="w-12 h-12 rounded-xl bg-[var(--cliniq-surface-low)] text-[var(--cliniq-primary)] flex items-center justify-center mb-5">
                        <span class="material-symbols-outlined text-[26px]">alternate_email</span>
                    </div>
                    <h3 class="font-headline text-2xl font-extrabold text-[#17261d] mb-2" id="emailConfigModalTitle">Configure Email</h3>
                    <p class="text-sm font-bold text-slate-500 leading-6 mb-0">These details are only needed when setting up the clinic sender account.</p>
                </div>
                <button type="button" class="btn btn-ghost justify-center px-3" id="closeEmailConfigButton" aria-label="Close email configuration">
                    <span class="material-symbols-outlined text-[20px]">close</span>
                </button>
            </div>

            <form method="post" data-no-ajax="true" class="space-y-5">
                <input type="hidden" name="action" value="save_mail_settings">

                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5 space-y-5">
                    <h4 class="font-headline text-lg font-extrabold text-[#17261d] mb-0">Clinic Email Identity</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="settings-field">
                            <label class="clinic-label" for="mailFromName">Email Name</label>
                            <input class="settings-input" id="mailFromName" name="from_name" type="text"
                                placeholder="CLINiQ Clinic"
                                value="<?= e($mailSettings['from_name'] ?? 'CLINiQ Clinic') ?>">
                        </div>
                        <div class="settings-field">
                            <label class="clinic-label" for="mailFromEmail">Email Address</label>
                            <input class="settings-input" id="mailFromEmail" name="from_email" type="email"
                                placeholder="cliniq@plpasig.edu.ph"
                                value="<?= e($mailSettings['from_email'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <details class="rounded-2xl border border-slate-200 bg-white p-5" open>
                    <summary class="cursor-pointer font-headline text-lg font-extrabold text-[#17261d]">Connection Details</summary>
                    <div class="mt-5 space-y-5">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                            <div class="settings-field md:col-span-2">
                                <label class="clinic-label" for="mailHost">Host</label>
                                <input class="settings-input" id="mailHost" name="host" type="text"
                                    placeholder="smtp.gmail.com"
                                    value="<?= e($mailSettings['host'] ?? '') ?>">
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label" for="mailPort">Port</label>
                                <input class="settings-input" id="mailPort" name="port" type="number"
                                    min="1" max="65535" placeholder="587"
                                    value="<?= e((string) ($mailSettings['port'] ?? '587')) ?>">
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label" for="mailEncryption">Security</label>
                                <select class="settings-input" id="mailEncryption" name="encryption">
                                    <option value="tls" <?= ($mailSettings['encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS</option>
                                    <option value="ssl" <?= ($mailSettings['encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="settings-field">
                                <label class="clinic-label" for="mailUsername">Login Email</label>
                                <input class="settings-input" id="mailUsername" name="username" type="email"
                                    placeholder="your-email@gmail.com"
                                    value="<?= e($mailSettings['username'] ?? '') ?>">
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label" for="mailPassword">
                                    Password / App Password
                                    <?php if (!empty($mailSettings['password'])): ?>
                                        <span class="font-normal text-emerald-600 ml-1">— saved</span>
                                    <?php endif; ?>
                                </label>
                                <input class="settings-input" id="mailPassword" name="password" type="password"
                                    placeholder="<?= !empty($mailSettings['password']) ? '••••••••••••••••' : 'Enter password or app password' ?>"
                                    autocomplete="new-password">
                                <p class="settings-help mb-0">Leave blank to keep the existing saved password.</p>
                            </div>
                        </div>
                    </div>
                </details>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                    <button type="button" class="btn btn-secondary justify-center" id="cancelEmailConfigButton">Cancel</button>
                    <button type="submit" class="btn btn-primary justify-center">
                        <span class="material-symbols-outlined text-[18px]">save</span>
                        Save Email
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="emailComposerModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="emailComposerModalTitle">
        <div class="modal-content bg-white rounded-[1.5rem] w-full max-w-4xl p-7 shadow-2xl border border-outline-variant/10">
            <div class="flex items-start justify-between gap-4 mb-6">
                <div>
                    <div class="w-12 h-12 rounded-xl bg-[var(--cliniq-surface-low)] text-[var(--cliniq-primary)] flex items-center justify-center mb-5">
                        <span class="material-symbols-outlined text-[26px]">edit_square</span>
                    </div>
                    <h3 class="font-headline text-2xl font-extrabold text-[#17261d] mb-2" id="emailComposerModalTitle">Compose Email</h3>
                    <p class="text-sm font-bold text-slate-500 leading-6 mb-0">Select registered accounts and send each recipient a private copy.</p>
                </div>
                <button type="button" class="btn btn-ghost justify-center px-3" id="closeEmailComposerButton" aria-label="Close email composer">
                    <span class="material-symbols-outlined text-[20px]">close</span>
                </button>
            </div>

            <form method="post" data-no-ajax="true" class="space-y-5" id="customEmailForm">
                <input type="hidden" name="action" value="send_custom_email">

                <?php if (!$mailConfigured): ?>
                    <div class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
                        <span class="material-symbols-outlined text-[18px] mt-0.5">warning</span>
                        <span>You can prepare this message now, but Configure Email must be completed before it can be sent.</span>
                    </div>
                <?php endif; ?>

                <div class="settings-field">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                        <div>
                            <span class="clinic-label">Recipients</span>
                            <p class="settings-help mb-0"><span id="selectedEmailRecipientCount">0</span> selected</p>
                        </div>
                        <button type="button" class="btn btn-secondary justify-center" id="openEmailRecipientPickerButton">
                            <span class="material-symbols-outlined text-[18px]">group_add</span>
                            Select Recipients
                        </button>
                    </div>
                    <div class="min-h-12 rounded-xl border border-slate-200 bg-slate-50 p-3 flex flex-wrap items-center gap-2" id="selectedEmailRecipientPills">
                        <span class="text-sm font-bold text-slate-400" data-empty-recipient-message>No recipients selected.</span>
                    </div>
                    <div id="customEmailRecipientInputs"></div>
                </div>

                <div class="settings-field">
                    <label class="clinic-label" for="customEmailSubject">Subject</label>
                    <input class="settings-input" id="customEmailSubject" name="subject" type="text" maxlength="180" required>
                </div>
                <div class="settings-field">
                    <label class="clinic-label" for="customEmailMessage">Message</label>
                    <textarea class="settings-input min-h-48 resize-y" id="customEmailMessage" name="message" maxlength="10000" placeholder="Write the email message..." required></textarea>
                </div>

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
                    <button type="button" class="btn btn-secondary justify-center" id="cancelEmailComposerButton">Cancel</button>
                    <button type="submit" class="btn btn-primary justify-center" <?= !$mailConfigured ? 'disabled' : '' ?> title="<?= !$mailConfigured ? 'Configure email before sending' : 'Send this email' ?>" data-confirm-submit data-confirm-type="primary" data-confirm-title="Send custom email?" data-confirm-message="Each selected account will receive a separate private copy of this message." data-confirm-toast="Sending email...">
                        <span class="material-symbols-outlined text-[18px]">send</span>
                        Send Email
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="emailRecipientPickerModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="emailRecipientPickerTitle">
        <div class="modal-content bg-white rounded-[1.5rem] w-full max-w-3xl max-h-[calc(100vh-2rem)] overflow-hidden p-6 shadow-2xl border border-outline-variant/10">
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <div class="w-10 h-10 rounded-xl bg-[var(--cliniq-surface-low)] text-[var(--cliniq-primary)] flex items-center justify-center mb-3">
                        <span class="material-symbols-outlined text-[26px]">group_add</span>
                    </div>
                    <h3 class="font-headline text-2xl font-extrabold text-[#17261d] mb-1" id="emailRecipientPickerTitle">Select Recipients</h3>
                    <p class="text-sm font-bold text-slate-500 mb-0">Selections are kept while you search or change pages.</p>
                </div>
                <button type="button" class="btn btn-ghost justify-center px-3" id="closeEmailRecipientPickerButton" aria-label="Close recipient selection">
                    <span class="material-symbols-outlined text-[20px]">close</span>
                </button>
            </div>

            <div class="settings-field mb-3">
                <label class="clinic-label" for="emailRecipientPickerSearch">Search accounts</label>
                <input class="settings-input" id="emailRecipientPickerSearch" type="search" placeholder="Search name, email, or account type">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-2" id="emailRecipientPickerList">
                <?php foreach ($mailRecipients as $recipient): ?>
                    <?php
                    $recipientName = $recipient['name'] ?: 'Unnamed Account';
                    $recipientSearch = strtolower(trim(implode(' ', [$recipientName, $recipient['email'], $recipient['type'], $recipient['status']])));
                    ?>
                    <label class="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-2 hover:bg-slate-50 cursor-pointer" data-email-recipient-option
                        data-id="<?= (int) $recipient['account_id'] ?>"
                        data-name="<?= e($recipientName) ?>"
                        data-email="<?= e($recipient['email']) ?>"
                        data-type="<?= e($recipient['type']) ?>"
                        data-search="<?= e($recipientSearch) ?>">
                        <input type="checkbox" class="w-4 h-4 accent-[var(--cliniq-primary)]" value="<?= (int) $recipient['account_id'] ?>">
                        <span class="min-w-0 flex-1">
                            <strong class="block text-sm font-extrabold text-slate-800 truncate"><?= e($recipientName) ?></strong>
                            <span class="block text-xs font-bold text-slate-500 truncate"><?= e($recipient['email']) ?></span>
                        </span>
                        <span class="badge badge-in-progress shrink-0"><?= e($recipient['type']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 mt-3">
                <p class="settings-help mb-0" id="emailRecipientPickerSummary"></p>
                <div class="flex items-center gap-2">
                    <button type="button" class="btn btn-secondary justify-center px-3" id="emailRecipientPickerPrevious" aria-label="Previous recipient page">
                        <span class="material-symbols-outlined text-[18px]">chevron_left</span>
                    </button>
                    <span class="min-w-20 text-center text-sm font-extrabold text-slate-700" id="emailRecipientPickerPageLabel">1 / 1</span>
                    <button type="button" class="btn btn-secondary justify-center px-3" id="emailRecipientPickerNext" aria-label="Next recipient page">
                        <span class="material-symbols-outlined text-[18px]">chevron_right</span>
                    </button>
                </div>
            </div>

            <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-3 mt-4">
                <button type="button" class="btn btn-ghost justify-center text-slate-600" id="clearEmailRecipients">Clear Selection</button>
                <button type="button" class="btn btn-primary justify-center" id="applyEmailRecipientSelection">
                    Done
                </button>
            </div>
        </div>
    </div>

    <script>
        (() => {
            const dataNode = document.getElementById('emailNotificationTemplateData');
            if (!dataNode) return;
            const templates = JSON.parse(dataNode.textContent || '{}');
            const form = document.getElementById('emailTemplateForm');
            const fields = {
                subject: document.getElementById('emailTemplateSubject'),
                heading: document.getElementById('emailTemplateHeading'),
                message: document.getElementById('emailTemplateMessage'),
                button_label: document.getElementById('emailTemplateButtonLabel'),
                footer: document.getElementById('emailTemplateFooter'),
            };
            const preview = {
                subject: document.getElementById('emailTemplatePreviewSubject'),
                heading: document.getElementById('emailTemplatePreviewHeading'),
                message: document.getElementById('emailTemplatePreviewMessage'),
                button_label: document.getElementById('emailTemplatePreviewButton'),
                footer: document.getElementById('emailTemplatePreviewFooter'),
            };
            const unlockButton = document.getElementById('unlockEmailTemplateButton');
            const saveButton = document.getElementById('saveEmailTemplateButton');
            const lockStatus = document.getElementById('emailTemplateLockStatus');
            const placeholders = document.getElementById('emailTemplatePlaceholders');
            let activeTemplate = null;
            let lastFocusedField = fields.message;
            let unlocked = false;

            const sampleValues = {
                patient_name: 'Alex',
                clinic_name: <?= json_encode((string) ($clinicProfile['system_name'] ?? 'CLINiQ Clinic')) ?>,
                expiry_minutes: '60',
            };
            const interpolatePreview = (value) => String(value || '').replace(/\{\{([a-z_]+)\}\}/g, (token, key) => sampleValues[key] ?? token);
            const renderPreview = () => {
                Object.entries(fields).forEach(([key, field]) => {
                    if (preview[key]) preview[key].textContent = interpolatePreview(field.value);
                });
            };
            const setLocked = (locked) => {
                unlocked = !locked;
                Object.values(fields).forEach((field) => field.readOnly = locked);
                saveButton.disabled = locked;
                unlockButton.hidden = !locked;
                lockStatus.textContent = locked ? 'Format is read-only.' : 'Editing is unlocked. Save to apply changes.';
                lockStatus.closest('div').classList.toggle('border-amber-200', locked);
                lockStatus.closest('div').classList.toggle('bg-amber-50', locked);
                lockStatus.closest('div').classList.toggle('border-emerald-200', !locked);
                lockStatus.closest('div').classList.toggle('bg-emerald-50', !locked);
            };
            const openTemplate = (key) => {
                const definition = templates[key];
                if (!definition) return;
                activeTemplate = definition;
                document.getElementById('emailTemplateKey').value = key;
                document.getElementById('resetEmailTemplateKey').value = key;
                document.getElementById('emailTemplateModalTitle').textContent = definition.label;
                document.getElementById('emailTemplateModalDescription').textContent = definition.description;
                document.getElementById('emailTemplateModalIcon').textContent = definition.icon;
                document.getElementById('emailTemplateActionHint').textContent = definition.action_hint;
                Object.entries(fields).forEach(([field, input]) => input.value = definition.template[field] || '');
                placeholders.replaceChildren(...definition.allowed_placeholders.map((placeholder) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'badge badge-in-progress';
                    button.textContent = placeholder;
                    button.dataset.insertEmailPlaceholder = placeholder;
                    if (definition.required_placeholders.includes(placeholder)) button.title = 'Required placeholder';
                    return button;
                }));
                lastFocusedField = fields.message;
                setLocked(true);
                renderPreview();
                showModal('emailTemplateModal');
            };

            document.querySelectorAll('[data-edit-email-template]').forEach((button) => {
                button.addEventListener('click', () => openTemplate(button.dataset.editEmailTemplate));
            });
            [document.getElementById('closeEmailTemplateButton'), document.getElementById('cancelEmailTemplateButton')].forEach((button) => {
                button?.addEventListener('click', () => {
                    setLocked(true);
                    closeModal('emailTemplateModal');
                });
            });
            unlockButton?.addEventListener('click', () => {
                setLocked(false);
                fields.subject.focus();
            });
            Object.values(fields).forEach((field) => {
                field.addEventListener('focus', () => lastFocusedField = field);
                field.addEventListener('input', renderPreview);
            });
            placeholders?.addEventListener('click', (event) => {
                const button = event.target.closest('[data-insert-email-placeholder]');
                if (!button || !unlocked || !lastFocusedField) return;
                const start = lastFocusedField.selectionStart ?? lastFocusedField.value.length;
                const end = lastFocusedField.selectionEnd ?? start;
                lastFocusedField.setRangeText(button.dataset.insertEmailPlaceholder, start, end, 'end');
                lastFocusedField.focus();
                renderPreview();
            });
            form?.addEventListener('submit', (event) => {
                if (!unlocked || !activeTemplate) {
                    event.preventDefault();
                    return;
                }
                const combined = Object.values(fields).map((field) => field.value).join('\n');
                const missing = activeTemplate.required_placeholders.filter((placeholder) => !combined.includes(placeholder));
                if (missing.length > 0) {
                    event.preventDefault();
                    if (typeof showToast === 'function') showToast(`Keep required placeholder: ${missing.join(', ')}`, 'warning');
                }
            });
        })();

        (() => {
            const openButton = document.getElementById('openEmailConfigButton');
            const closeButton = document.getElementById('closeEmailConfigButton');
            const cancelButton = document.getElementById('cancelEmailConfigButton');
            const openComposerButton = document.getElementById('openEmailComposerButton');
            const closeComposerButton = document.getElementById('closeEmailComposerButton');
            const cancelComposerButton = document.getElementById('cancelEmailComposerButton');
            const composerForm = document.getElementById('customEmailForm');
            const openRecipientPickerButton = document.getElementById('openEmailRecipientPickerButton');
            const closeRecipientPickerButton = document.getElementById('closeEmailRecipientPickerButton');
            const applyRecipientSelectionButton = document.getElementById('applyEmailRecipientSelection');
            const recipientSearch = document.getElementById('emailRecipientPickerSearch');
            const recipientRows = Array.from(document.querySelectorAll('[data-email-recipient-option]'));
            const recipientCount = document.getElementById('selectedEmailRecipientCount');
            const recipientPills = document.getElementById('selectedEmailRecipientPills');
            const recipientInputs = document.getElementById('customEmailRecipientInputs');
            const recipientSummary = document.getElementById('emailRecipientPickerSummary');
            const recipientPageLabel = document.getElementById('emailRecipientPickerPageLabel');
            const recipientPrevious = document.getElementById('emailRecipientPickerPrevious');
            const recipientNext = document.getElementById('emailRecipientPickerNext');
            const selectedRecipientIds = new Set();
            const recipientPageSize = 10;
            let recipientPage = 1;

            const recipientDirectory = new Map(recipientRows.map((row) => [String(row.dataset.id || ''), {
                id: String(row.dataset.id || ''),
                name: String(row.dataset.name || 'Unnamed Account'),
                email: String(row.dataset.email || ''),
                type: String(row.dataset.type || ''),
            }]));

            if (openButton) {
                openButton.addEventListener('click', () => showModal('emailConfigModal'));
            }
            [closeButton, cancelButton].forEach((button) => {
                if (button) {
                    button.addEventListener('click', () => closeModal('emailConfigModal'));
                }
            });
            if (openComposerButton) {
                openComposerButton.addEventListener('click', () => showModal('emailComposerModal'));
            }
            [closeComposerButton, cancelComposerButton].forEach((button) => {
                if (button) button.addEventListener('click', () => closeModal('emailComposerModal'));
            });

            const getFilteredRecipientRows = () => {
                const query = recipientSearch?.value.trim().toLowerCase() || '';
                return recipientRows.filter((row) => query === '' || String(row.dataset.search || '').includes(query));
            };

            const renderRecipientPicker = () => {
                const filteredRows = getFilteredRecipientRows();
                const pageCount = Math.max(1, Math.ceil(filteredRows.length / recipientPageSize));
                recipientPage = Math.min(Math.max(recipientPage, 1), pageCount);
                const pageStart = (recipientPage - 1) * recipientPageSize;
                const visibleRows = new Set(filteredRows.slice(pageStart, pageStart + recipientPageSize));

                recipientRows.forEach((row) => {
                    const isVisible = visibleRows.has(row);
                    row.hidden = !isVisible;
                    row.classList.toggle('hidden', !isVisible);
                    const checkbox = row.querySelector('input[type="checkbox"]');
                    if (checkbox) checkbox.checked = selectedRecipientIds.has(String(row.dataset.id || ''));
                });

                if (recipientPageLabel) recipientPageLabel.textContent = `${recipientPage} / ${pageCount}`;
                if (recipientPrevious) recipientPrevious.disabled = recipientPage <= 1;
                if (recipientNext) recipientNext.disabled = recipientPage >= pageCount;
                if (recipientSummary) {
                    const first = filteredRows.length === 0 ? 0 : pageStart + 1;
                    const last = Math.min(pageStart + recipientPageSize, filteredRows.length);
                    recipientSummary.textContent = filteredRows.length === 0
                        ? 'No accounts found'
                        : `Showing ${first}-${last} of ${filteredRows.length}`;
                }
            };

            const renderRecipientSelection = () => {
                if (recipientCount) recipientCount.textContent = String(selectedRecipientIds.size);

                if (recipientInputs) {
                    recipientInputs.replaceChildren(...Array.from(selectedRecipientIds, (id) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'recipient_ids[]';
                        input.value = id;
                        return input;
                    }));
                }

                if (!recipientPills) return;
                recipientPills.replaceChildren();
                const selectedRecipients = Array.from(selectedRecipientIds)
                    .map((id) => recipientDirectory.get(id))
                    .filter(Boolean);

                if (selectedRecipients.length === 0) {
                    const emptyMessage = document.createElement('span');
                    emptyMessage.className = 'text-sm font-bold text-slate-400';
                    emptyMessage.textContent = 'No recipients selected.';
                    recipientPills.append(emptyMessage);
                    return;
                }

                selectedRecipients.slice(0, 10).forEach((recipient) => {
                    const pill = document.createElement('span');
                    pill.className = 'inline-flex max-w-full items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-extrabold text-emerald-800';
                    pill.title = recipient.email;

                    const label = document.createElement('span');
                    label.className = 'max-w-52 truncate';
                    label.textContent = recipient.name;

                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.className = 'inline-flex rounded-full text-emerald-700 hover:text-red-600';
                    removeButton.setAttribute('aria-label', `Remove ${recipient.name}`);
                    removeButton.dataset.removeEmailRecipient = recipient.id;
                    removeButton.innerHTML = '<span class="material-symbols-outlined text-[16px]">close</span>';

                    pill.append(label, removeButton);
                    recipientPills.append(pill);
                });

                if (selectedRecipients.length > 10) {
                    const remainingPill = document.createElement('span');
                    remainingPill.className = 'inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-extrabold text-slate-600';
                    remainingPill.textContent = `+${selectedRecipients.length - 10} more`;
                    recipientPills.append(remainingPill);
                }
            };

            recipientRows.forEach((row) => {
                row.querySelector('input[type="checkbox"]')?.addEventListener('change', (event) => {
                    const id = String(row.dataset.id || '');
                    if (event.currentTarget.checked) selectedRecipientIds.add(id);
                    else selectedRecipientIds.delete(id);
                    renderRecipientSelection();
                });
            });

            openRecipientPickerButton?.addEventListener('click', () => {
                renderRecipientPicker();
                showModal('emailRecipientPickerModal');
            });
            [closeRecipientPickerButton, applyRecipientSelectionButton].forEach((button) => {
                button?.addEventListener('click', () => closeModal('emailRecipientPickerModal'));
            });
            recipientSearch?.addEventListener('input', () => {
                recipientPage = 1;
                renderRecipientPicker();
            });
            recipientPrevious?.addEventListener('click', (event) => {
                event.preventDefault();
                recipientPage -= 1;
                renderRecipientPicker();
            });
            recipientNext?.addEventListener('click', (event) => {
                event.preventDefault();
                recipientPage += 1;
                renderRecipientPicker();
            });
            document.getElementById('clearEmailRecipients')?.addEventListener('click', () => {
                selectedRecipientIds.clear();
                renderRecipientSelection();
                renderRecipientPicker();
            });
            recipientPills?.addEventListener('click', (event) => {
                const removeButton = event.target.closest('[data-remove-email-recipient]');
                if (!removeButton) return;
                selectedRecipientIds.delete(String(removeButton.dataset.removeEmailRecipient || ''));
                renderRecipientSelection();
                renderRecipientPicker();
            });
            composerForm?.addEventListener('click', (event) => {
                if (!event.target.closest('[type="submit"]') || selectedRecipientIds.size > 0) return;
                event.preventDefault();
                event.stopPropagation();
                if (typeof showToast === 'function') showToast('Select at least one email recipient.', 'warning');
                renderRecipientPicker();
                showModal('emailRecipientPickerModal');
            }, true);
            composerForm?.addEventListener('submit', (event) => {
                if (selectedRecipientIds.size > 0) {
                    closeModal('emailRecipientPickerModal');
                    closeModal('emailComposerModal');
                    return;
                }
                event.preventDefault();
                if (typeof showToast === 'function') showToast('Select at least one email recipient.', 'warning');
                renderRecipientPicker();
                showModal('emailRecipientPickerModal');
            }, true);
            renderRecipientSelection();
            renderRecipientPicker();
        })();
    </script>
<?php endif; ?>

<script>
    (() => {
        if (document.documentElement.dataset.cliniqDropdownSettingsReady === '1') return;
        document.documentElement.dataset.cliniqDropdownSettingsReady = '1';

        const switchDropdownPanel = (category, group) => {
            if (!category) return;

            const categoryPanel = document.querySelector(`[data-dropdown-category-panel="${CSS.escape(category)}"]`);
            const fallbackGroup = categoryPanel?.querySelector('[data-dropdown-group-link]')?.dataset.dropdownGroup || '';
            const targetGroup = group || fallbackGroup;

            document.querySelectorAll('[data-dropdown-category-link]').forEach((link) => {
                link.classList.toggle('active', link.dataset.dropdownCategory === category);
            });

            document.querySelectorAll('[data-dropdown-category-panel]').forEach((panel) => {
                panel.hidden = panel.dataset.dropdownCategoryPanel !== category;
            });

            document.querySelectorAll('[data-dropdown-group-link]').forEach((link) => {
                link.classList.toggle('active', link.dataset.dropdownGroup === targetGroup);
            });

            document.querySelectorAll('[data-dropdown-content-panel]').forEach((panel) => {
                panel.hidden = panel.dataset.dropdownContentPanel !== targetGroup;
            });

            const url = new URL(window.location.href);
            url.searchParams.set('tab', 'dropdowns');
            url.searchParams.set('category', category);
            if (targetGroup) {
                url.searchParams.set('group', targetGroup);
            } else {
                url.searchParams.delete('group');
            }
            window.history.replaceState({}, '', url);
        };

        document.addEventListener('click', (event) => {
            const categoryLink = event.target.closest('[data-dropdown-category-link]');
            if (categoryLink) {
                event.preventDefault();
                switchDropdownPanel(
                    categoryLink.dataset.dropdownCategory || '',
                    categoryLink.dataset.dropdownDefaultGroup || ''
                );
                return;
            }

            const groupLink = event.target.closest('[data-dropdown-group-link]');
            if (groupLink) {
                event.preventDefault();
                switchDropdownPanel(
                    groupLink.dataset.dropdownCategory || '',
                    groupLink.dataset.dropdownGroup || ''
                );
            }
        });
    })();
</script>

<?php if ($canManageApeCycles): ?>
    <div id="apeCycleModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="apeCycleModalTitle">
        <div class="modal-content bg-white rounded-[1.5rem] w-full max-w-lg p-7 shadow-2xl border border-outline-variant/10">
            <div class="w-12 h-12 rounded-xl bg-[var(--cliniq-surface-low)] text-[var(--cliniq-primary)] flex items-center justify-center mb-5">
                <span class="material-symbols-outlined text-[26px]">event_available</span>
            </div>
            <h3 class="font-headline text-2xl font-extrabold text-[#17261d] mb-2" id="apeCycleModalTitle">Start this year&rsquo;s APE?</h3>
            <p class="text-sm font-bold text-slate-500 leading-6 mb-5">Review the cycle information before continuing.</p>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3 text-sm">
                <div class="flex justify-between gap-4"><span class="font-bold text-slate-500">School Year</span><strong id="apeConfirmSchoolYear"></strong></div>
                <div class="flex justify-between gap-4"><span class="font-bold text-slate-500">Compliance Period</span><strong class="text-right" id="apeConfirmDates"></strong></div>
                <div class="flex justify-between gap-4"><span class="font-bold text-slate-500">Exam Date</span><strong id="apeConfirmExamDate">Not set</strong></div>
                <div class="flex justify-between gap-4"><span class="font-bold text-slate-500">Active Patients</span><strong><?= number_format($activeApePatientCount) ?></strong></div>
            </div>
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-bold text-amber-800">
                This creates the cycle and one APE record for every active patient. The action cannot be repeated for the same school year.
            </div>
            <form method="post" data-no-ajax="true" id="apeStartCycleForm">
                <input type="hidden" name="action" value="start_ape_cycle">
                <input type="hidden" name="academic_year" id="apeSubmitSchoolYear">
                <input type="hidden" name="compliance_start" id="apeSubmitPeriodStart">
                <input type="hidden" name="compliance_end" id="apeSubmitPeriodEnd">
                <input type="hidden" name="exam_schedule_date" id="apeSubmitExamDate">
                <div class="grid grid-cols-2 gap-3 mt-6">
                    <button type="button" class="btn btn-secondary justify-center" id="cancelApeCycleButton">Cancel</button>
                    <button type="submit" class="btn btn-primary justify-center" id="confirmApeCycleButton">Start APE Cycle</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (() => {
            const generateEmailInput = (fullName) => {
                const trimmed = fullName.trim();
                if (!trimmed) return "";
                const parts = trimmed.split(/\s+/).filter(Boolean);
                let firstName = "";
                let lastName = "";
                if (parts.length === 1) {
                    firstName = parts[0];
                    lastName = parts[0];
                } else {
                    firstName = parts[0];
                    lastName = parts[parts.length - 1];
                }
                const cleanFirst = firstName.toLowerCase().replace(/\s+/g, "");
                const cleanLast = lastName.toLowerCase().replace(/\s+/g, "");
                return `${cleanLast}_${cleanFirst}@plpasig.edu.ph`;
            };

            const newStaffName = document.getElementById('new_staff_name');
            const newStaffEmail = document.getElementById('new_staff_email');
            if (newStaffName && newStaffEmail) {
                newStaffName.addEventListener('input', () => {
                    newStaffEmail.value = generateEmailInput(newStaffName.value);
                });
            }

            document.querySelectorAll('.staff-name-input').forEach((input) => {
                const form = input.closest('form');
                if (!form) return;
                const emailInput = form.querySelector('.staff-email-input');
                if (!emailInput) return;
                input.addEventListener('input', () => {
                    emailInput.value = generateEmailInput(input.value);
                });
            });

            const schoolYear = document.getElementById('apeSchoolYear');
            const periodStart = document.getElementById('apePeriodStart');
            const periodEnd = document.getElementById('apePeriodEnd');
            const error = document.getElementById('apeCycleError');
            const startButton = document.getElementById('startApeCycleButton');
            const patientCount = <?= (int) $activeApePatientCount ?>;

            if (!schoolYear || !periodStart || !periodEnd || !startButton) return;

            const formatDate = (value) => {
                if (!value) return 'Not set';
                const parts = value.split('-').map(Number);
                return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                    .format(new Date(parts[0], parts[1] - 1, parts[2]));
            };

            const updatePreview = () => {
                document.getElementById('apeCyclePreviewTitle').textContent = `School Year ${schoolYear.value || 'Not set'}`;
                document.getElementById('apeCyclePreviewDates').textContent = `${formatDate(periodStart.value)} to ${formatDate(periodEnd.value)}`;
                error.classList.add('hidden');
            };

            const validate = () => {
                const match = schoolYear.value.trim().match(/^(\d{4})-(\d{4})$/);
                let message = '';
                if (!match || Number(match[2]) !== Number(match[1]) + 1) {
                    message = 'Enter a consecutive school year using the format YYYY-YYYY.';
                } else if (!periodStart.value || !periodEnd.value) {
                    message = 'Select both the compliance start and end dates.';
                } else if (periodStart.value > periodEnd.value) {
                    message = 'The compliance end date must be on or after the start date.';
                } else if (patientCount < 1) {
                    message = 'There are no active patient accounts to include.';
                }
                error.textContent = message;
                error.classList.toggle('hidden', !message);
                return !message;
            };

            [schoolYear, periodStart, periodEnd].forEach((input) => input.addEventListener('input', updatePreview));

            startButton.addEventListener('click', () => {
                if (!validate()) return;
                document.getElementById('apeConfirmSchoolYear').textContent = schoolYear.value.trim();
                document.getElementById('apeConfirmDates').textContent = `${formatDate(periodStart.value)} to ${formatDate(periodEnd.value)}`;
                const examDateEl = document.getElementById('apeExamScheduleDate');
                const examDateConfirmEl = document.getElementById('apeConfirmExamDate');
                const examDateSubmitEl = document.getElementById('apeSubmitExamDate');
                if (examDateEl && examDateConfirmEl && examDateSubmitEl) {
                    examDateConfirmEl.textContent = examDateEl.value ? formatDate(examDateEl.value) : 'Not set';
                    examDateSubmitEl.value = examDateEl.value;
                }
                document.getElementById('apeSubmitSchoolYear').value = schoolYear.value.trim();
                document.getElementById('apeSubmitPeriodStart').value = periodStart.value;
                document.getElementById('apeSubmitPeriodEnd').value = periodEnd.value;
                showModal('apeCycleModal');
            });

            document.getElementById('cancelApeCycleButton').addEventListener('click', () => closeModal('apeCycleModal'));
        })();
    </script>
<?php endif; ?>

<?php render_footer(); ?>
