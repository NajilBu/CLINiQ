<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/SystemSettings.php';
require_once __DIR__ . '/../../app/services/RiskSettings.php';

require_login();
ensure_system_settings_schema();
ensure_dropdown_options_schema();

$user = current_user() ?? [];
$canManageSettings = in_array($user['role'] ?? '', ['admin', 'nurse', 'it_expert'], true);
$canManageStaffProfiles = in_array($user['role'] ?? '', ['admin', 'it_expert'], true);
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
        save_clinic_profile_settings($_POST, $updatedBy);
        flash_message('success', 'Clinic profile settings saved.');
        header('Location: index.php?tab=general');
        exit;
    }

    if ($action === 'save_theme') {
        if (!$canManageSettings) {
            flash_message('error', 'You do not have permission to update the system theme.');
            header('Location: index.php?tab=general');
            exit;
        }
        save_cliniq_theme_settings((string) ($_POST['theme'] ?? default_cliniq_theme_key()), $updatedBy);
        flash_message('success', 'System color theme updated.');
        header('Location: index.php?tab=general');
        exit;
    }

    if (in_array($action, ['create_staff_profile', 'update_staff_profile', 'reset_staff_password'], true)) {
        if (!$canManageStaffProfiles) {
            flash_message('error', 'Only administrators and IT experts can manage staff profiles.');
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
                    $_SESSION['user']['email'] = mb_strtolower(trim((string) ($_POST['email'] ?? $user['email'])));
                    $_SESSION['user']['role'] = normalize_staff_profile_role((string) ($_POST['role'] ?? $user['role']));
                }
                flash_message('success', 'Staff profile updated.');
            } else {
                reset_staff_profile_password($_POST);
                flash_message('success', 'Staff password reset. Share the new password only with that staff member.');
            }
        } catch (Throwable $e) {
            $message = str_contains(strtolower($e->getMessage()), 'duplicate') ? 'That email address is already assigned to another staff profile.' : $e->getMessage();
            flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $message);
        }

        header('Location: index.php?tab=account');
        exit;
    }

    if ($action === 'update_password') {
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $user['id']]);
        $passwordHash = (string) ($stmt->fetchColumn() ?: '');
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (!password_verify($currentPassword, $passwordHash)) {
            flash_message('error', 'Current password is incorrect.');
        } elseif (strlen($newPassword) < 8) {
            flash_message('error', 'New password must be at least 8 characters.');
        } elseif ($newPassword !== $confirmPassword) {
            flash_message('error', 'New password confirmation does not match.');
        } else {
            $update = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
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

    flash_message('warning', 'Unknown settings action.');
    header('Location: index.php?tab=general');
    exit;
}

$clinicProfile = clinic_profile_settings();
$themePresets = cliniq_theme_presets();
$activeTheme = cliniq_theme_settings()['theme'];
$staffProfiles = staff_profiles();
$staffRoles = staff_profile_roles();
$settings = risk_settings();
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
if (!in_array($currentTab, ['general', 'account', 'dropdowns', 'clinical', 'maintenance'], true)) {
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
        Clinic configuration can be changed only by administrators, nurses, or IT experts. You can still update your own password.
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
        <a href="index.php?tab=dropdowns" class="settings-tab-link <?= $currentTab === 'dropdowns' ? 'active' : '' ?> text-decoration-none" data-settings-tab="dropdowns" data-no-ajax="true">
            <span class="material-symbols-outlined">list_alt</span>
            <span>Dropdowns</span>
        </a>
        <a href="index.php?tab=clinical" class="settings-tab-link <?= $currentTab === 'clinical' ? 'active' : '' ?> text-decoration-none" data-settings-tab="clinical" data-no-ajax="true">
            <span class="material-symbols-outlined">emergency</span>
            <span>Incident Risk</span>
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
                    <form method="post" class="settings-section space-y-5">
                        <input type="hidden" name="action" value="save_profile">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="settings-field">
                                <label class="clinic-label" for="system_name">System Name</label>
                                <input class="settings-input" id="system_name" name="system_name" value="<?= profile_setting_value($clinicProfile, 'system_name') ?>" <?= !$canManageSettings ? 'readonly' : '' ?> required>
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

                <section>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">System Color Theme</h2>
                    <p class="text-xs font-bold text-slate-500 mb-5">Choose the primary color used by navigation, buttons, focus rings, and active settings controls.</p>
                    <form method="post" class="settings-section">
                        <input type="hidden" name="action" value="save_theme">
                        <div class="settings-theme-grid">
                            <?php foreach ($themePresets as $themeKey => $theme): ?>
                                <label class="settings-theme-option <?= $activeTheme === $themeKey ? 'active' : '' ?>">
                                    <input type="radio" name="theme" value="<?= e($themeKey) ?>" <?= $activeTheme === $themeKey ? 'checked' : '' ?> <?= !$canManageSettings ? 'disabled' : '' ?>>
                                    <span class="settings-theme-swatch" style="--theme-swatch: <?= e($theme['primary']) ?>">
                                        <?php if ($activeTheme === $themeKey): ?>
                                            <span class="material-symbols-outlined">check</span>
                                        <?php endif; ?>
                                    </span>
                                    <span><?= e($theme['label']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="flex justify-end mt-5">
                            <button class="btn btn-primary" <?= !$canManageSettings ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="primary" data-confirm-title="Apply color theme?" data-confirm-message="This will update the CLINiQ interface theme for all pages using the shared shell." data-confirm-toast="Applying theme...">
                                <span class="material-symbols-outlined text-[18px]">palette</span>
                                Apply Theme
                            </button>
                        </div>
                    </form>
                </section>
            </div>

            <div id="settings-account" class="settings-tab-panel <?= $currentTab === 'account' ? 'active' : '' ?> space-y-6">
                <section>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Staff Profiles</h2>
                    <p class="text-xs font-bold text-slate-500 mb-5">Create separate logins for doctors and clinic staff. Visit records are automatically attributed to the profile currently signed in.</p>
                    <?php if (!$canManageStaffProfiles): ?>
                        <div class="rounded-xl bg-amber-50 border border-amber-100 text-amber-800 px-4 py-3 text-sm font-bold mb-5">
                            Staff profiles can be managed only by administrators or IT experts.
                        </div>
                    <?php endif; ?>

                    <form method="post" class="settings-section space-y-5 mb-6" autocomplete="off">
                        <input type="hidden" name="action" value="create_staff_profile">
                        <div class="grid grid-cols-1 lg:grid-cols-[1fr_1fr_0.75fr_0.85fr] gap-4">
                            <div class="settings-field">
                                <label class="clinic-label" for="new_staff_name">Full Name</label>
                                <input class="settings-input" id="new_staff_name" name="name" placeholder="Dr. Maria Santos" <?= !$canManageStaffProfiles ? 'readonly' : '' ?> required>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label" for="new_staff_email">Login Email</label>
                                <input class="settings-input" id="new_staff_email" name="email" type="email" placeholder="doctor@cliniq.local" <?= !$canManageStaffProfiles ? 'readonly' : '' ?> required>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label" for="new_staff_role">Role</label>
                                <select class="settings-input" id="new_staff_role" name="role" <?= !$canManageStaffProfiles ? 'disabled' : '' ?>>
                                    <?php foreach ($staffRoles as $roleValue => $roleLabel): ?>
                                        <option value="<?= e($roleValue) ?>" <?= $roleValue === 'doctor' ? 'selected' : '' ?>><?= e($roleLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label" for="new_staff_password">Temporary Password</label>
                                <input class="settings-input" id="new_staff_password" name="password" type="password" minlength="8" autocomplete="new-password" <?= !$canManageStaffProfiles ? 'readonly' : '' ?> required>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button class="btn btn-primary" <?= !$canManageStaffProfiles ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="primary" data-confirm-title="Create staff profile?" data-confirm-message="This staff member will be able to sign in and create records under their own name." data-confirm-toast="Creating staff profile...">
                                <span class="material-symbols-outlined text-[18px]">person_add</span>
                                Create Profile
                            </button>
                        </div>
                    </form>

                    <div class="settings-profile-list">
                        <?php foreach ($staffProfiles as $staffProfile): ?>
                            <?php $staffId = (int) $staffProfile['id']; ?>
                            <article class="settings-profile-card">
                                <div class="settings-profile-avatar <?= avatar_color($staffProfile['name']) ?>"><?= initials($staffProfile['name']) ?></div>
                                <div class="settings-profile-main">
                                    <form method="post" class="settings-profile-form">
                                        <input type="hidden" name="action" value="update_staff_profile">
                                        <input type="hidden" name="user_id" value="<?= $staffId ?>">
                                        <div class="grid grid-cols-1 lg:grid-cols-[1fr_1fr_0.7fr_auto] gap-3 items-end">
                                            <div class="settings-field">
                                                <label class="clinic-label" for="staff_name_<?= $staffId ?>">Name</label>
                                                <input class="settings-input" id="staff_name_<?= $staffId ?>" name="name" value="<?= e($staffProfile['name']) ?>" <?= !$canManageStaffProfiles ? 'readonly' : '' ?> required>
                                            </div>
                                            <div class="settings-field">
                                                <label class="clinic-label" for="staff_email_<?= $staffId ?>">Email</label>
                                                <input class="settings-input" id="staff_email_<?= $staffId ?>" name="email" type="email" value="<?= e($staffProfile['email']) ?>" <?= !$canManageStaffProfiles ? 'readonly' : '' ?> required>
                                            </div>
                                            <div class="settings-field">
                                                <label class="clinic-label" for="staff_role_<?= $staffId ?>">Role</label>
                                                <select class="settings-input" id="staff_role_<?= $staffId ?>" name="role" <?= !$canManageStaffProfiles ? 'disabled' : '' ?>>
                                                    <?php foreach ($staffRoles as $roleValue => $roleLabel): ?>
                                                        <option value="<?= e($roleValue) ?>" <?= $staffProfile['role'] === $roleValue ? 'selected' : '' ?>><?= e($roleLabel) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <button class="btn btn-outline" <?= !$canManageStaffProfiles ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="primary" data-confirm-title="Update staff profile?" data-confirm-message="This updates the staff member name, email, or role used by CLINiQ." data-confirm-toast="Updating profile...">
                                                <span class="material-symbols-outlined text-[18px]">save</span>
                                                Save
                                            </button>
                                        </div>
                                    </form>
                                    <form method="post" class="settings-password-reset-form mt-3">
                                        <input type="hidden" name="action" value="reset_staff_password">
                                        <input type="hidden" name="user_id" value="<?= $staffId ?>">
                                        <label class="clinic-label" for="staff_password_<?= $staffId ?>">Reset Password</label>
                                        <div class="settings-password-reset-row">
                                            <input class="settings-input" id="staff_password_<?= $staffId ?>" name="password" type="password" minlength="8" autocomplete="new-password" placeholder="New password" <?= !$canManageStaffProfiles ? 'readonly' : '' ?> required>
                                            <button class="btn btn-ghost" <?= !$canManageStaffProfiles ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="primary" data-confirm-title="Reset staff password?" data-confirm-message="This replaces the selected staff member password." data-confirm-toast="Resetting password...">
                                                <span class="material-symbols-outlined text-[18px]">lock_reset</span>
                                                Reset
                                            </button>
                                        </div>
                                    </form>
                                </div>
                                <span class="badge badge-in-progress"><?= e(staff_profile_role_label((string) $staffProfile['role'])) ?></span>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">My Password</h2>
                    <p class="text-xs font-bold text-slate-500 mb-5">Update the password for your own signed-in CLINiQ profile.</p>
                    <form method="post" class="settings-section space-y-5" autocomplete="off">
                        <input type="hidden" name="action" value="update_password">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="settings-field md:col-span-2">
                                <label class="clinic-label" for="current_password">Current Password</label>
                                <input class="settings-input" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label" for="new_password">New Password</label>
                                <input class="settings-input" id="new_password" name="new_password" type="password" minlength="8" autocomplete="new-password" required>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label" for="confirm_password">Confirm New Password</label>
                                <input class="settings-input" id="confirm_password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Update password?" data-confirm-message="Your current staff login password will be replaced." data-confirm-toast="Updating password...">
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
                            Dropdown options can be managed only by administrators, nurses, or IT experts.
                        </div>
                    <?php endif; ?>

                    <nav class="settings-dropdown-category-list mb-5" aria-label="Dropdown categories">
                        <?php foreach ($dropdownCategoryLabels as $categoryKey => $categoryLabel): ?>
                            <?php $categoryCount = count($dropdownGroupsByCategory[$categoryKey] ?? []); ?>
                            <a href="index.php?tab=dropdowns&category=<?= urlencode($categoryKey) ?>" class="settings-dropdown-category-link <?= $currentDropdownCategory === $categoryKey ? 'active' : '' ?> text-decoration-none" data-no-ajax="true">
                                <span><?= e($categoryLabel) ?></span>
                                <small><?= $categoryCount ?></small>
                            </a>
                        <?php endforeach; ?>
                    </nav>

                    <div class="grid grid-cols-1 xl:grid-cols-[0.42fr_0.58fr] gap-5">
                        <nav class="settings-section settings-dropdown-group-list" aria-label="Dropdown groups">
                            <div class="settings-dropdown-group-heading">
                                <strong><?= e($dropdownCategoryLabels[$currentDropdownCategory] ?? 'Dropdowns') ?></strong>
                                <small><?= count($dropdownGroupsByCategory[$currentDropdownCategory] ?? []) ?> dropdown groups</small>
                            </div>
                            <?php foreach (($dropdownGroupsByCategory[$currentDropdownCategory] ?? []) as $groupKey => $group): ?>
                                <a href="index.php?tab=dropdowns&category=<?= urlencode($currentDropdownCategory) ?>&group=<?= urlencode($groupKey) ?>" class="settings-dropdown-group-link <?= $currentDropdownGroup === $groupKey ? 'active' : '' ?> text-decoration-none" data-no-ajax="true">
                                    <span>
                                        <strong><?= e($group['label']) ?></strong>
                                        <small><?= count($group['options']) ?> options</small>
                                    </span>
                                    <span class="material-symbols-outlined">chevron_right</span>
                                </a>
                            <?php endforeach; ?>
                        </nav>

                        <div class="space-y-5">
                            <?php $selectedDropdown = $dropdownGroups[$currentDropdownGroup]; ?>
                            <section class="settings-section">
                                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4 mb-5">
                                    <div>
                                        <h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1"><?= e($selectedDropdown['label']) ?></h3>
                                        <p class="settings-help mb-0"><?= e($selectedDropdown['description']) ?></p>
                                    </div>
                                    <form method="post">
                                        <input type="hidden" name="action" value="reset_dropdown_group">
                                        <input type="hidden" name="group_key" value="<?= e($currentDropdownGroup) ?>">
                                        <button class="btn btn-ghost" <?= !$canManageSettings ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="danger" data-confirm-title="Reset this dropdown?" data-confirm-message="This restores this dropdown group to its default CLINiQ options." data-confirm-toast="Resetting dropdown...">
                                            <span class="material-symbols-outlined text-[18px]">restart_alt</span>
                                            Reset Group
                                        </button>
                                    </form>
                                </div>

                                <form method="post" class="settings-dropdown-add-form">
                                    <input type="hidden" name="action" value="add_dropdown_option">
                                    <input type="hidden" name="group_key" value="<?= e($currentDropdownGroup) ?>">
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
                                            <input type="hidden" name="group_key" value="<?= e($currentDropdownGroup) ?>">
                                            <input type="hidden" name="option_id" value="<?= e($option['id']) ?>">
                                            <label class="clinic-label" for="dropdown_<?= e($currentDropdownGroup . '_' . $option['id']) ?>">Option Label</label>
                                            <div class="settings-dropdown-option-row">
                                                <input class="settings-input" id="dropdown_<?= e($currentDropdownGroup . '_' . $option['id']) ?>" name="label" value="<?= e($option['label']) ?>" maxlength="120" <?= !$canManageSettings ? 'readonly' : '' ?> required>
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
                                            <input type="hidden" name="group_key" value="<?= e($currentDropdownGroup) ?>">
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

<?php render_footer(); ?>
