<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AppointmentWorkflow.php';
require_login();
ensure_appointment_schema();

$user = current_user();
$month = appointment_month_from_request($_GET['month'] ?? null);
$monthParam = $month->format('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = db()->prepare('DELETE FROM appointment_availability_blocks WHERE id = ?');
            $stmt->execute([$id]);
            flash_message('success', 'Availability block removed.');
        }
    } else {
        $date = trim($_POST['block_date'] ?? '');
        $allDay = isset($_POST['all_day']);
        $start = trim($_POST['start_time'] ?? '');
        $end = trim($_POST['end_time'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            flash_message('error', 'Choose a valid date to block.');
        } elseif (!$allDay && ($start === '' || $end === '' || $start >= $end)) {
            flash_message('error', 'Choose a valid start and end time for a partial-day block.');
        } else {
            $stmt = db()->prepare("
                INSERT INTO appointment_availability_blocks (block_date, start_time, end_time, reason, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $date,
                $allDay ? null : $start,
                $allDay ? null : $end,
                $reason !== '' ? $reason : null,
                (int) ($user['id'] ?? 0) ?: null,
            ]);
            flash_message('success', $allDay ? 'Full-day unavailable block added.' : 'Hourly unavailable block added.');
            $monthParam = substr($date, 0, 7);
        }
    }

    header('Location: availability.php?month=' . urlencode($monthParam));
    exit;
}

$blocksByDate = appointment_blocks_for_month($month);
$firstDay = $month->modify('first day of this month');
$daysInMonth = (int) $month->format('t');
$leadingBlanks = (int) $firstDay->format('w');
$prevMonth = $month->modify('-1 month')->format('Y-m');
$nextMonth = $month->modify('+1 month')->format('Y-m');

set_page_back_link(app_url('appointments/index.php'), 'Appointments');
render_header('Appointment Availability');

render_clinic_command_header(
    'Scheduling',
    'Clinic Availability',
    'Block dates or hours when the clinic cannot entertain student appointments.',
    '<a href="' . e(app_url('appointments/index.php')) . '" class="btn btn-outline text-decoration-none"><span class="material-symbols-outlined">event_note</span>Appointment Queue</a>'
);
?>

<div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_360px] gap-5">
    <section class="clinic-card overflow-hidden">
        <div class="p-5 sm:p-6 border-b border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1"><?= e($month->format('F Y')) ?></h2>
                <p class="text-xs font-bold text-slate-500 mb-0">Green dates are open, red dates are unavailable, amber dates have partial blocked hours.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="?month=<?= e($prevMonth) ?>" class="btn btn-sm btn-ghost text-decoration-none" title="Previous month">
                    <span class="material-symbols-outlined">chevron_left</span>
                </a>
                <a href="?month=<?= e(date('Y-m')) ?>" class="btn btn-sm btn-outline text-decoration-none">Today</a>
                <a href="?month=<?= e($nextMonth) ?>" class="btn btn-sm btn-ghost text-decoration-none" title="Next month">
                    <span class="material-symbols-outlined">chevron_right</span>
                </a>
            </div>
        </div>

        <div class="appointment-calendar p-5 sm:p-6">
            <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day): ?>
                <div class="appointment-calendar-weekday"><?= e($day) ?></div>
            <?php endforeach; ?>

            <?php for ($i = 0; $i < $leadingBlanks; $i++): ?>
                <div class="appointment-calendar-day is-empty"></div>
            <?php endfor; ?>

            <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                <?php
                $date = $month->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                $blocks = $blocksByDate[$date] ?? [];
                $fullDay = appointment_is_full_day_blocked($blocks);
                $state = $fullDay ? 'is-unavailable' : (!empty($blocks) ? 'is-partial' : 'is-open');
                ?>
                <button type="button" class="appointment-calendar-day <?= e($state) ?>" data-date="<?= e($date) ?>">
                    <span class="appointment-calendar-number"><?= (int) $day ?></span>
                    <span class="appointment-calendar-status">
                        <?= $fullDay ? 'Unavailable' : (!empty($blocks) ? count($blocks) . ' hour block(s)' : 'Available') ?>
                    </span>
                    <?php if (!empty($blocks)): ?>
                        <span class="appointment-calendar-note"><?= e(appointment_format_block_time($blocks[0])) ?></span>
                    <?php endif; ?>
                </button>
            <?php endfor; ?>
        </div>
    </section>

    <aside class="grid gap-5 content-start">
        <section class="clinic-card p-5 sm:p-6">
            <h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Add Unavailable Time</h2>
            <p class="text-xs font-bold text-slate-500 mb-4">Choose a full day or a specific time range.</p>

            <form method="POST" class="grid gap-4" id="availabilityForm">
                <input type="hidden" name="action" value="add">
                <label class="grid gap-1">
                    <span class="text-[11px] font-black uppercase tracking-widest text-slate-400">Date</span>
                    <input type="date" name="block_date" id="blockDateInput" class="form-input" value="<?= e($month->format('Y-m-d')) ?>" required>
                </label>
                <label class="flex items-center gap-3 text-sm font-bold text-slate-700">
                    <input type="checkbox" name="all_day" id="allDayToggle" checked>
                    Whole day unavailable
                </label>
                <div class="grid grid-cols-2 gap-3" id="partialTimeFields">
                    <label class="grid gap-1">
                        <span class="text-[11px] font-black uppercase tracking-widest text-slate-400">Start</span>
                        <input type="time" name="start_time" class="form-input" value="08:00">
                    </label>
                    <label class="grid gap-1">
                        <span class="text-[11px] font-black uppercase tracking-widest text-slate-400">End</span>
                        <input type="time" name="end_time" class="form-input" value="12:00">
                    </label>
                </div>
                <label class="grid gap-1">
                    <span class="text-[11px] font-black uppercase tracking-widest text-slate-400">Reason</span>
                    <textarea name="reason" class="form-textarea" rows="3" placeholder="Staff meeting, campus event, maintenance..."></textarea>
                </label>
                <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Add unavailable block?" data-confirm-message="Students will not be able to request appointments for this blocked date or time." data-confirm-toast="Saving availability...">
                    <span class="material-symbols-outlined">block</span>
                    Save Unavailable Time
                </button>
            </form>
        </section>

        <section class="clinic-card overflow-hidden">
            <div class="p-5 border-b border-slate-100">
                <h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">This Month's Blocks</h2>
                <p class="text-xs font-bold text-slate-500 mb-0"><?= array_sum(array_map('count', $blocksByDate)) ?> unavailable block(s)</p>
            </div>
            <div class="p-4 grid gap-3 max-h-[420px] overflow-y-auto">
                <?php if (empty($blocksByDate)): ?>
                    <div class="text-sm font-bold text-slate-500 p-4 rounded-xl bg-slate-50">No unavailable dates for this month.</div>
                <?php else: ?>
                    <?php foreach ($blocksByDate as $date => $blocks): ?>
                        <?php foreach ($blocks as $block): ?>
                            <div class="appointment-block-row">
                                <div>
                                    <strong><?= e(date('M d, Y', strtotime($date))) ?></strong>
                                    <span><?= e(appointment_format_block_time($block)) ?><?= $block['reason'] ? ' - ' . e($block['reason']) : '' ?></span>
                                </div>
                                <form method="POST">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $block['id'] ?>">
                                    <button class="btn btn-sm btn-ghost btn-cancel-icon" title="Remove block" data-confirm-submit data-confirm-type="danger" data-confirm-title="Remove this unavailable block?" data-confirm-message="This will make the date or time available for student appointment requests again." data-confirm-toast="Removing block...">
                                        <span class="material-symbols-outlined">cancel</span>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </aside>
</div>

<script>
    const allDayToggle = document.getElementById('allDayToggle');
    const partialTimeFields = document.getElementById('partialTimeFields');
    const dateInput = document.getElementById('blockDateInput');

    function syncPartialFields() {
        partialTimeFields.style.display = allDayToggle.checked ? 'none' : 'grid';
    }

    allDayToggle.addEventListener('change', syncPartialFields);
    syncPartialFields();

    document.querySelectorAll('[data-date]').forEach((day) => {
        day.addEventListener('click', () => {
            dateInput.value = day.dataset.date;
            dateInput.focus();
        });
    });
</script>

<?php render_footer(); ?>
