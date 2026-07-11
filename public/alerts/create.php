<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AlertWorkflow.php';
ensure_alert_workflow_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $photoPath = isset($_FILES['photo']) ? save_alert_photo_upload($_FILES['photo']) : null;
    $stmt = db()->prepare(
        'INSERT INTO nurse_alerts (reporter_name, reporter_role, location, concern, details, photo_path) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        trim($_POST['reporter_name'] ?? ''),
        trim($_POST['reporter_role'] ?? ''),
        trim($_POST['location'] ?? ''),
        trim($_POST['concern'] ?? ''),
        trim($_POST['details'] ?? ''),
        $photoPath,
    ]);

    flash_message('success', 'Alert submitted! The clinic has been notified.');
    header('Location: index.php');
    exit;
}

set_page_back_link('index.php', 'Back');
render_header('Submit Alert');
?>
<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]">Submit Emergency Alert</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Send an urgent concern to the clinic dashboard.</p>
    </div>
</div>

<form class="clinic-card p-6 md:p-8" method="post" enctype="multipart/form-data">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div>
            <label class="clinic-label">Reporter Name</label>
            <input class="clinic-input" name="reporter_name" required placeholder="Your full name">
        </div>
        <div>
            <label class="clinic-label">Reporter Role</label>
            <input class="clinic-input" name="reporter_role" placeholder="Student, Teacher, Staff">
        </div>
        <div>
            <label class="clinic-label">Location</label>
            <input class="clinic-input" name="location" required placeholder="Building, room, or area">
        </div>
        <div>
            <label class="clinic-label">Concern</label>
            <input class="clinic-input" name="concern" required placeholder="Brief description of the emergency">
        </div>
        <div class="md:col-span-2">
            <label class="clinic-label">Details</label>
            <textarea class="clinic-textarea" name="details" rows="4" placeholder="Additional details about the situation..."></textarea>
        </div>
        <div class="md:col-span-2">
            <label class="clinic-label">Photo Evidence (Optional)</label>
            <input class="clinic-input" name="photo" type="file" accept="image/png,image/jpeg,image/webp">
            <p class="text-xs font-bold text-slate-400 mt-2 mb-0">JPG, PNG, or WebP only. Maximum 5MB.</p>
        </div>
    </div>
    <div class="mt-6">
        <button class="btn btn-danger" data-confirm-submit data-confirm-type="danger" data-confirm-title="Submit this alert?" data-confirm-message="This will send the emergency alert to the nurse alert queue." data-confirm-toast="Submitting alert...">
            <span class="material-symbols-outlined text-[18px]">emergency_home</span> Send Alert
        </button>
    </div>
</form>
<?php render_footer(); ?>
