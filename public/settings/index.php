<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/RiskSettings.php';
require_once __DIR__ . '/../../app/services/RiskClassifier.php';

require_login();
ensure_system_settings_schema();

$user = current_user() ?? [];
$canManageSettings = in_array($user['role'] ?? '', ['admin', 'nurse', 'it_expert'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageSettings) {
    $action = $_POST['action'] ?? 'save_risk';

    if ($action === 'reset_risk') {
        save_risk_settings(default_risk_settings(), (int) ($user['id'] ?? 0) ?: null);
        flash_message('success', 'Risk classification settings were restored to the default CLINiQ rules.');
    } else {
        save_risk_settings($_POST, (int) ($user['id'] ?? 0) ?: null);
        flash_message('success', 'Risk classification settings saved.');
    }

    header('Location: index.php?tab=clinical');
    exit;
}

$settings = risk_settings();
$currentTab = $_GET['tab'] ?? 'general';
if (!in_array($currentTab, ['general', 'clinical', 'maintenance'], true)) {
    $currentTab = 'general';
}

function risk_setting_value(array $settings, string $key): string
{
    return e((string) ($settings[$key] ?? ''));
}

render_header('System Settings');
?>

<style>
    .settings-shell {
        display: grid;
        grid-template-columns: 17rem minmax(0, 1fr);
        gap: 1.5rem;
        align-items: start;
    }
    .settings-tabs {
        position: sticky;
        top: 1rem;
        display: grid;
        gap: 0.6rem;
    }
    .settings-tab-link {
        display: flex;
        align-items: center;
        gap: 0.9rem;
        width: 100%;
        border: 1px solid transparent;
        border-radius: 0.85rem;
        padding: 0.95rem 1rem;
        background: transparent;
        color: #64748b;
        font-size: 0.82rem;
        font-weight: 900;
        text-align: left;
        transition: background-color 0.16s ease, color 0.16s ease, border-color 0.16s ease;
    }
    .settings-tab-link:hover {
        background: #edf8f0;
        color: #23422C;
    }
    .settings-tab-link.active {
        background: #3F7D52;
        border-color: #3F7D52;
        color: #ffffff;
        box-shadow: 0 14px 32px rgba(63, 125, 82, 0.18);
    }
    .settings-tab-link .material-symbols-outlined {
        font-size: 1.25rem;
    }
    .settings-tab-panel {
        display: none;
    }
    .settings-tab-panel.active {
        display: block;
    }
    .settings-panel {
        border: 1px solid rgba(199, 220, 205, 0.72);
        border-radius: 1rem;
        background: #ffffff;
        box-shadow: 0 14px 30px rgba(15, 23, 42, 0.04);
        overflow: hidden;
    }
    .settings-panel-body {
        padding: clamp(1.25rem, 3vw, 2rem);
    }
    .settings-section {
        border: 1px solid rgba(199, 220, 205, 0.72);
        border-radius: 0.9rem;
        background: #fbfdfb;
        padding: 1.25rem;
    }
    .settings-field {
        display: grid;
        gap: 0.42rem;
    }
    .settings-input,
    .settings-textarea {
        width: 100%;
        border: 1px solid rgba(148, 163, 184, 0.24);
        border-radius: 0.7rem;
        background: #ffffff;
        color: #17261d;
        font-size: 0.83rem;
        font-weight: 800;
        outline: none;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .settings-input {
        min-height: 3.15rem;
        padding: 0.7rem 0.9rem;
    }
    .settings-textarea {
        min-height: 9rem;
        padding: 0.9rem;
        line-height: 1.45;
        resize: vertical;
    }
    .settings-input:focus,
    .settings-textarea:focus {
        border-color: rgba(63, 125, 82, 0.55);
        box-shadow: 0 0 0 3px rgba(63, 125, 82, 0.12);
    }
    .settings-help {
        color: #64748b;
        font-size: 0.75rem;
        font-weight: 700;
        line-height: 1.45;
    }
    .settings-switch {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        border: 1px solid rgba(199, 220, 205, 0.72);
        border-radius: 0.85rem;
        background: #ffffff;
        padding: 1rem;
    }
    .settings-switch input {
        width: 1.25rem;
        height: 1.25rem;
        border-radius: 0.35rem;
        color: #3F7D52;
    }
    .settings-score-band {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.85rem;
    }
    .settings-band-card {
        border: 1px solid rgba(148, 163, 184, 0.22);
        border-radius: 0.8rem;
        background: #ffffff;
        padding: 1rem;
    }
    @media (max-width: 980px) {
        .settings-shell {
            grid-template-columns: 1fr;
        }
        .settings-tabs {
            position: static;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        .settings-tab-link {
            justify-content: center;
        }
        .settings-tab-link span:last-child {
            display: none;
        }
        .settings-score-band {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 640px) {
        .settings-score-band {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4 mb-6">
    <div>
        <p class="text-[11px] font-black text-primary uppercase tracking-widest mb-2">Clinic Configuration</p>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#17261d]">System Settings</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Manage clinic environment variables and risk classification rules.</p>
    </div>
    <span class="badge badge-in-progress">Authorized Settings</span>
</div>

<?php if (!$canManageSettings): ?>
    <div class="rounded-2xl bg-red-50 border border-red-100 text-red-700 px-5 py-4 font-bold">
        Settings can be changed only by administrators, nurses, or IT experts.
    </div>
<?php endif; ?>

<div class="settings-shell">
    <aside class="settings-tabs">
        <button type="button" class="settings-tab-link <?= $currentTab === 'general' ? 'active' : '' ?>" data-settings-tab="general">
            <span class="material-symbols-outlined">corporate_fare</span>
            <span>Clinic Profile</span>
        </button>
        <button type="button" class="settings-tab-link <?= $currentTab === 'clinical' ? 'active' : '' ?>" data-settings-tab="clinical">
            <span class="material-symbols-outlined">monitor_heart</span>
            <span>Risk Settings</span>
        </button>
        <button type="button" class="settings-tab-link <?= $currentTab === 'maintenance' ? 'active' : '' ?>" data-settings-tab="maintenance">
            <span class="material-symbols-outlined">database</span>
            <span>Maintenance</span>
        </button>
    </aside>

    <section class="settings-panel">
        <div class="settings-panel-body">
            <div id="settings-general" class="settings-tab-panel <?= $currentTab === 'general' ? 'active' : '' ?> space-y-6">
                <section>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Clinic Identity</h2>
                    <p class="text-xs font-bold text-slate-500 mb-5">These profile fields are prepared for future branding and printed report configuration.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="settings-field">
                            <label class="clinic-label">System Name</label>
                            <input class="settings-input" value="CLINiQ" readonly>
                        </div>
                        <div class="settings-field">
                            <label class="clinic-label">Department</label>
                            <input class="settings-input" value="University Health Services" readonly>
                        </div>
                        <div class="settings-field md:col-span-2">
                            <label class="clinic-label">System Purpose</label>
                            <textarea class="settings-textarea" readonly>School clinic information management system for patient records, visits, APE workflow, emergency alerts, appointments, inventory, referrals, and reports.</textarea>
                        </div>
                    </div>
                </section>
            </div>

            <div id="settings-clinical" class="settings-tab-panel <?= $currentTab === 'clinical' ? 'active' : '' ?> space-y-6">
                <section>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Risk Classification Rules</h2>
                    <p class="text-xs font-bold text-slate-500 mb-5">These values control how CLINiQ scores visits and when High or Critical cases are escalated to nurse alerts.</p>
                </section>

                <form method="post" class="space-y-6">
                    <input type="hidden" name="action" value="save_risk">

                    <section class="settings-section">
                        <div class="flex items-center gap-3 mb-5">
                            <span class="w-10 h-10 rounded-xl bg-primary-fixed text-primary flex items-center justify-center material-symbols-outlined">thermostat</span>
                            <div>
                                <h3 class="font-headline text-lg font-extrabold text-[#17261d] m-0">Temperature</h3>
                                <p class="settings-help m-0">Adjust fever and high-fever thresholds.</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div class="settings-field">
                                <label class="clinic-label">Fever C</label>
                                <input class="settings-input" name="fever_temp" type="number" step="0.1" value="<?= risk_setting_value($settings, 'fever_temp') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label">Fever Points</label>
                                <input class="settings-input" name="fever_points" type="number" min="0" value="<?= risk_setting_value($settings, 'fever_points') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label">High Fever C</label>
                                <input class="settings-input" name="high_fever_temp" type="number" step="0.1" value="<?= risk_setting_value($settings, 'high_fever_temp') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label">High Fever Points</label>
                                <input class="settings-input" name="high_fever_points" type="number" min="0" value="<?= risk_setting_value($settings, 'high_fever_points') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                            </div>
                        </div>
                    </section>

                    <section class="settings-section">
                        <div class="flex items-center gap-3 mb-5">
                            <span class="w-10 h-10 rounded-xl bg-primary-fixed text-primary flex items-center justify-center material-symbols-outlined">ecg_heart</span>
                            <div>
                                <h3 class="font-headline text-lg font-extrabold text-[#17261d] m-0">Pulse and Blood Pressure</h3>
                                <p class="settings-help m-0">Define abnormal pulse, elevated BP, low BP, and critical BP scores.</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <div class="settings-field">
                                <label class="clinic-label">Pulse Below</label>
                                <input class="settings-input" name="pulse_low" type="number" min="0" value="<?= risk_setting_value($settings, 'pulse_low') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label">Pulse Above</label>
                                <input class="settings-input" name="pulse_high" type="number" min="0" value="<?= risk_setting_value($settings, 'pulse_high') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label">Pulse Points</label>
                                <input class="settings-input" name="pulse_points" type="number" min="0" value="<?= risk_setting_value($settings, 'pulse_points') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="settings-field">
                                <label class="clinic-label">Elevated BP / Points</label>
                                <div class="grid grid-cols-[1fr_1fr_0.8fr] gap-2">
                                    <input class="settings-input" name="bp_high_systolic" type="number" value="<?= risk_setting_value($settings, 'bp_high_systolic') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                                    <input class="settings-input" name="bp_high_diastolic" type="number" value="<?= risk_setting_value($settings, 'bp_high_diastolic') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                                    <input class="settings-input" name="bp_high_points" type="number" value="<?= risk_setting_value($settings, 'bp_high_points') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                                </div>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label">Low BP / Points</label>
                                <div class="grid grid-cols-[1fr_1fr_0.8fr] gap-2">
                                    <input class="settings-input" name="bp_low_systolic" type="number" value="<?= risk_setting_value($settings, 'bp_low_systolic') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                                    <input class="settings-input" name="bp_low_diastolic" type="number" value="<?= risk_setting_value($settings, 'bp_low_diastolic') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                                    <input class="settings-input" name="bp_low_points" type="number" value="<?= risk_setting_value($settings, 'bp_low_points') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                                </div>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label">Critical BP / Points</label>
                                <div class="grid grid-cols-[1fr_1fr_0.8fr] gap-2">
                                    <input class="settings-input" name="bp_critical_systolic" type="number" value="<?= risk_setting_value($settings, 'bp_critical_systolic') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                                    <input class="settings-input" name="bp_critical_diastolic" type="number" value="<?= risk_setting_value($settings, 'bp_critical_diastolic') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                                    <input class="settings-input" name="bp_critical_points" type="number" value="<?= risk_setting_value($settings, 'bp_critical_points') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="settings-section">
                        <div class="flex items-center gap-3 mb-5">
                            <span class="w-10 h-10 rounded-xl bg-primary-fixed text-primary flex items-center justify-center material-symbols-outlined">emergency</span>
                            <div>
                                <h3 class="font-headline text-lg font-extrabold text-[#17261d] m-0">Critical Symptoms and Score Bands</h3>
                                <p class="settings-help m-0">Keywords are matched against submitted symptoms. Use one keyword per line.</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 lg:grid-cols-[1fr_0.35fr] gap-4 mb-5">
                            <div class="settings-field">
                                <label class="clinic-label">Critical Symptom Keywords</label>
                                <textarea class="settings-textarea" name="critical_keywords" <?= !$canManageSettings ? 'readonly' : '' ?>><?= e(risk_keywords_text($settings)) ?></textarea>
                            </div>
                            <div class="settings-field">
                                <label class="clinic-label">Points Per Keyword</label>
                                <input class="settings-input" name="critical_symptom_points" type="number" min="0" value="<?= risk_setting_value($settings, 'critical_symptom_points') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                            </div>
                        </div>
                        <div class="settings-score-band">
                            <div class="settings-band-card">
                                <span class="badge badge-low mb-3">Low</span>
                                <p class="settings-help mb-0">Score below Moderate minimum.</p>
                            </div>
                            <div class="settings-band-card">
                                <span class="badge badge-moderate mb-3">Moderate Starts At</span>
                                <input class="settings-input" name="moderate_min" type="number" min="0" value="<?= risk_setting_value($settings, 'moderate_min') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                            </div>
                            <div class="settings-band-card">
                                <span class="badge badge-high mb-3">High Starts At</span>
                                <input class="settings-input" name="high_min" type="number" min="0" value="<?= risk_setting_value($settings, 'high_min') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                            </div>
                            <div class="settings-band-card">
                                <span class="badge badge-critical mb-3">Critical Starts At</span>
                                <input class="settings-input" name="critical_min" type="number" min="0" value="<?= risk_setting_value($settings, 'critical_min') ?>" <?= !$canManageSettings ? 'readonly' : '' ?>>
                            </div>
                        </div>
                    </section>

                    <section class="settings-switch">
                        <div>
                            <strong class="block text-sm text-slate-800">Create nurse alert for High/Critical visits</strong>
                            <span class="settings-help">When enabled, major-risk visits automatically appear in the nurse alert queue.</span>
                        </div>
                        <input type="checkbox" name="auto_alert_major_risk" value="1" <?= !empty($settings['auto_alert_major_risk']) ? 'checked' : '' ?> <?= !$canManageSettings ? 'disabled' : '' ?>>
                    </section>

                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pt-2">
                        <p class="settings-help mb-0">Saved changes apply to newly classified or updated visits. Existing visit grades remain historically stored until re-assessed.</p>
                        <button class="btn btn-primary justify-center" <?= !$canManageSettings ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="primary" data-confirm-title="Save risk settings?" data-confirm-message="This will change how CLINiQ classifies future visit risks." data-confirm-toast="Saving risk settings...">
                            <span class="material-symbols-outlined text-[18px]">save</span>
                            Save Risk Settings
                        </button>
                    </div>
                </form>
            </div>

            <div id="settings-maintenance" class="settings-tab-panel <?= $currentTab === 'maintenance' ? 'active' : '' ?> space-y-6">
                <section>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Maintenance</h2>
                    <p class="text-xs font-bold text-slate-500 mb-5">Restore classification variables to the default prototype values.</p>
                </section>
                <section class="settings-section flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Reset Risk Rules</h3>
                        <p class="settings-help mb-0">This restores fever, pulse, blood pressure, keyword, score-band, and alert-escalation settings.</p>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="reset_risk">
                        <button class="btn btn-danger justify-center" <?= !$canManageSettings ? 'disabled' : '' ?> data-confirm-submit data-confirm-type="danger" data-confirm-title="Reset risk settings?" data-confirm-message="This will restore the default CLINiQ risk classification rules." data-confirm-toast="Resetting risk settings...">
                            <span class="material-symbols-outlined text-[18px]">restart_alt</span>
                            Reset Defaults
                        </button>
                    </form>
                </section>
            </div>
        </div>
    </section>
</div>

<script>
document.querySelectorAll('[data-settings-tab]').forEach((button) => {
    button.addEventListener('click', () => {
        const tab = button.dataset.settingsTab;
        document.querySelectorAll('[data-settings-tab]').forEach((item) => item.classList.toggle('active', item === button));
        document.querySelectorAll('.settings-tab-panel').forEach((panel) => {
            panel.classList.toggle('active', panel.id === 'settings-' + tab);
        });
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url);
    });
});
</script>

<?php render_footer(); ?>
