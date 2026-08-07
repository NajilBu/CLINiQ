<?php

$availabilityWeek = appointment_week_from_request($_GET['week'] ?? null);
$availabilityBlocksByDate = appointment_blocks_for_week($availabilityWeek);
$availabilityWeekDays = [];
for ($offset = 0; $offset < 6; $offset++) {
    $availabilityWeekDays[] = $availabilityWeek->modify('+' . $offset . ' days');
}

$usingAvailabilityPlaceholders = empty($availabilityBlocksByDate);
$calendarBlocksByDate = $availabilityBlocksByDate;
if ($usingAvailabilityPlaceholders) {
    $calendarBlocksByDate[$availabilityWeekDays[0]->format('Y-m-d')][] = [
        'id' => 0, 'start_time' => '09:00:00', 'end_time' => '10:00:00',
        'reason' => 'Staff Meeting', '_placeholder' => true,
    ];
    $calendarBlocksByDate[$availabilityWeekDays[2]->format('Y-m-d')][] = [
        'id' => 0, 'start_time' => '13:00:00', 'end_time' => '15:00:00',
        'reason' => 'Clinic Maintenance', '_placeholder' => true,
    ];
    $calendarBlocksByDate[$availabilityWeekDays[5]->format('Y-m-d')][] = [
        'id' => 0, 'start_time' => null, 'end_time' => null,
        'reason' => 'Campus Event', '_placeholder' => true,
    ];
}

$availabilityPrevWeek = $availabilityWeek->modify('-1 week')->format('Y-m-d');
$availabilityNextWeek = $availabilityWeek->modify('+1 week')->format('Y-m-d');
$availabilityCurrentWeek = (new DateTimeImmutable('today'))->modify('monday this week')->format('Y-m-d');
$availabilityWeekEnd = $availabilityWeek->modify('+5 days');
$availabilityDefaultDate = date('N') !== '7'
    && date('Y-m-d') >= $availabilityWeek->format('Y-m-d')
    && date('Y-m-d') <= $availabilityWeekEnd->format('Y-m-d')
        ? date('Y-m-d')
        : $availabilityWeek->format('Y-m-d');
$availabilityStartMinutes = 8 * 60;
$availabilityEndMinutes = 17 * 60;
$availabilityDurationMinutes = $availabilityEndMinutes - $availabilityStartMinutes;
?>

<section class="clinic-card overflow-hidden" id="clinic-availability">
    <div class="p-5 sm:p-6 border-b border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Scheduling</p>
            <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Clinic Availability</h2>
            <p class="text-xs font-bold text-slate-500 mb-0">Monday to Saturday, 8:00 AM–5:00 PM. Click an open hour to prepare a block.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="?status=<?= e(urlencode($filterStatus)) ?>&week=<?= e($availabilityPrevWeek) ?>#clinic-availability" class="btn btn-sm btn-ghost text-decoration-none" title="Previous week" data-no-ajax="true">
                <span class="material-symbols-outlined">chevron_left</span>
            </a>
            <a href="?status=<?= e(urlencode($filterStatus)) ?>&week=<?= e($availabilityCurrentWeek) ?>#clinic-availability" class="btn btn-sm btn-outline text-decoration-none" data-no-ajax="true">This Week</a>
            <a href="?status=<?= e(urlencode($filterStatus)) ?>&week=<?= e($availabilityNextWeek) ?>#clinic-availability" class="btn btn-sm btn-ghost text-decoration-none" title="Next week" data-no-ajax="true">
                <span class="material-symbols-outlined">chevron_right</span>
            </a>
        </div>
    </div>

    <div class="appointment-availability-layout">
        <div class="appointment-availability-calendar-panel">
            <div class="appointment-week-range">
                <strong><?= e($availabilityWeek->format('M j')) ?>–<?= e($availabilityWeekEnd->format('M j, Y')) ?></strong>
                <span>Unavailable periods appear in red.</span>
            </div>

            <?php if ($usingAvailabilityPlaceholders): ?>
                <div class="appointment-week-sample-note" role="note">
                    <span class="material-symbols-outlined" aria-hidden="true">info</span>
                    <span>This week has no saved availability blocks. Faded blocks are UI-only examples and do not affect booking.</span>
                </div>
            <?php endif; ?>

            <div class="appointment-week-scroll">
                <div class="appointment-week-calendar">
                    <div class="appointment-week-header appointment-week-time-heading">Time</div>
                    <?php foreach ($availabilityWeekDays as $day):
                        $date = $day->format('Y-m-d');
                        $isToday = $date === date('Y-m-d');
                        ?>
                        <div class="appointment-week-header <?= $isToday ? 'is-today' : '' ?>">
                            <span><?= e($day->format('D')) ?></span>
                            <strong><?= e($day->format('M j')) ?></strong>
                        </div>
                    <?php endforeach; ?>

                    <div class="appointment-week-time-column" aria-hidden="true">
                        <?php for ($hour = 8; $hour <= 17; $hour++):
                            $top = ((($hour * 60) - $availabilityStartMinutes) / $availabilityDurationMinutes) * 100;
                            ?>
                            <time style="top: <?= number_format($top, 4, '.', '') ?>%;"><?= e(date('g A', mktime($hour, 0))) ?></time>
                        <?php endfor; ?>
                    </div>

                    <?php foreach ($availabilityWeekDays as $day):
                        $date = $day->format('Y-m-d');
                        $blocks = $calendarBlocksByDate[$date] ?? [];
                        $isToday = $date === date('Y-m-d');
                        ?>
                        <div class="appointment-week-day-column <?= $isToday ? 'is-today' : '' ?>" data-week-date="<?= e($date) ?>" role="button" tabindex="0" aria-label="Choose an available hour on <?= e($day->format('l, F j')) ?>">
                            <?php for ($hour = 8; $hour < 17; $hour++):
                                $top = ((($hour * 60) - $availabilityStartMinutes) / $availabilityDurationMinutes) * 100;
                                $height = (60 / $availabilityDurationMinutes) * 100;
                                ?>
                                <button type="button" class="appointment-week-hour-cell"
                                    style="top: <?= number_format($top, 4, '.', '') ?>%; height: <?= number_format($height, 4, '.', '') ?>%;"
                                    data-hour-slot data-start-hour="<?= $hour ?>"
                                    aria-label="<?= e($day->format('l, M j') . ', ' . date('g:i A', mktime($hour, 0)) . ' to ' . date('g:i A', mktime($hour + 1, 0))) ?>"></button>
                            <?php endfor; ?>

                            <?php foreach ($blocks as $block):
                                $isPlaceholder = !empty($block['_placeholder']);
                                $isWholeDay = empty($block['start_time']) || empty($block['end_time']);
                                $blockStart = $isWholeDay ? $availabilityStartMinutes : (((int) substr($block['start_time'], 0, 2) * 60) + (int) substr($block['start_time'], 3, 2));
                                $blockEnd = $isWholeDay ? $availabilityEndMinutes : (((int) substr($block['end_time'], 0, 2) * 60) + (int) substr($block['end_time'], 3, 2));
                                if ($blockEnd <= $availabilityStartMinutes || $blockStart >= $availabilityEndMinutes) {
                                    continue;
                                }
                                $visibleStart = max($blockStart, $availabilityStartMinutes);
                                $visibleEnd = min($blockEnd, $availabilityEndMinutes);
                                $top = (($visibleStart - $availabilityStartMinutes) / $availabilityDurationMinutes) * 100;
                                $height = (($visibleEnd - $visibleStart) / $availabilityDurationMinutes) * 100;
                                ?>
                                <div class="appointment-week-block <?= $isWholeDay ? 'is-all-day' : '' ?> <?= $isPlaceholder ? 'is-placeholder' : '' ?>"
                                    style="top: calc(<?= number_format($top, 4, '.', '') ?>% + 2px); height: calc(<?= number_format($height, 4, '.', '') ?>% - 4px);"
                                    data-availability-block data-date="<?= e($date) ?>" data-start="<?= e($block['start_time'] ?? '') ?>" data-end="<?= e($block['end_time'] ?? '') ?>"
                                    <?= $isPlaceholder ? 'data-placeholder-block="true"' : '' ?>
                                    title="<?= e(appointment_format_block_time($block) . ($block['reason'] ? ' — ' . $block['reason'] : '')) ?>">
                                    <strong><?= $isWholeDay ? 'Unavailable' : e(appointment_format_block_time($block)) ?><?php if ($isPlaceholder): ?><span class="appointment-week-sample-badge">Sample</span><?php endif; ?></strong>
                                    <span><?= e($block['reason'] ?: ($isWholeDay ? 'Whole day blocked' : 'Unavailable')) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <aside class="appointment-availability-sidebar">
            <section class="appointment-availability-form-card">
                <h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Add Unavailable Time</h3>
                <p class="text-xs font-bold text-slate-500 mb-4">Choose a whole day or a specific time range.</p>

                <form method="POST" action="availability.php" class="grid gap-4" id="availabilityForm" data-no-ajax="true">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="week" value="<?= e($availabilityWeek->format('Y-m-d')) ?>">
                    <label class="grid gap-1">
                        <span class="text-[11px] font-black uppercase tracking-widest text-slate-400">Date</span>
                        <input type="date" name="block_date" id="blockDateInput" class="form-input" value="<?= e($availabilityDefaultDate) ?>" min="<?= e($availabilityWeek->format('Y-m-d')) ?>" max="<?= e($availabilityWeekEnd->format('Y-m-d')) ?>" required>
                    </label>
                    <label class="flex items-center gap-3 text-sm font-bold text-slate-700">
                        <input type="checkbox" name="all_day" id="allDayToggle" checked>
                        Whole day unavailable
                    </label>
                    <div class="grid grid-cols-2 gap-3" id="partialTimeFields" style="display: none;">
                        <label class="grid gap-1">
                            <span class="text-[11px] font-black uppercase tracking-widest text-slate-400">Start</span>
                            <input type="time" name="start_time" class="form-input" value="08:00" min="08:00" max="16:00">
                        </label>
                        <label class="grid gap-1">
                            <span class="text-[11px] font-black uppercase tracking-widest text-slate-400">End</span>
                            <input type="time" name="end_time" class="form-input" value="12:00" min="09:00" max="17:00">
                        </label>
                    </div>
                    <label class="grid gap-1">
                        <span class="text-[11px] font-black uppercase tracking-widest text-slate-400">Reason</span>
                        <textarea name="reason" class="form-textarea" rows="3" placeholder="Staff meeting, campus event, maintenance..."></textarea>
                    </label>
                    <button class="btn btn-primary w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Add unavailable block?" data-confirm-message="Patients will not be able to request appointments for this blocked date or time." data-confirm-toast="Saving availability...">
                        <span class="material-symbols-outlined">block</span>
                        Save Unavailable Time
                    </button>
                </form>
            </section>

            <section class="appointment-availability-block-list">
                <div class="p-5 border-b border-slate-100">
                    <h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">This Week's Blocks</h3>
                    <p class="text-xs font-bold text-slate-500 mb-0"><?= array_sum(array_map('count', $availabilityBlocksByDate)) ?> unavailable block(s)</p>
                </div>
                <div class="p-4 grid gap-3 max-h-[420px] overflow-y-auto">
                    <?php if (empty($availabilityBlocksByDate)): ?>
                        <div class="text-sm font-bold text-slate-500 p-4 rounded-xl bg-slate-50">No unavailable times for this week.</div>
                    <?php else: ?>
                        <?php foreach ($availabilityBlocksByDate as $date => $blocks): ?>
                            <?php foreach ($blocks as $block): ?>
                                <div class="appointment-block-row">
                                    <div>
                                        <strong><?= e(date('M d, Y', strtotime($date))) ?></strong>
                                        <span><?= e(appointment_format_block_time($block)) ?><?= $block['reason'] ? ' — ' . e($block['reason']) : '' ?></span>
                                    </div>
                                    <form method="POST" action="availability.php" data-no-ajax="true">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $block['id'] ?>">
                                        <input type="hidden" name="week" value="<?= e($availabilityWeek->format('Y-m-d')) ?>">
                                        <button class="btn btn-sm btn-ghost btn-cancel-icon" title="Remove block" data-confirm-submit data-confirm-type="danger" data-confirm-title="Remove this unavailable block?" data-confirm-message="This will make the date or time available for appointment requests again." data-confirm-toast="Removing block...">
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
</section>

<script>
(() => {
    if (window.cliniqAvailabilityListenersReady) {
        return;
    }
    window.cliniqAvailabilityListenersReady = true;

    const liveElements = () => ({
        form: document.getElementById('availabilityForm'),
        date: document.getElementById('blockDateInput'),
        allDay: document.getElementById('allDayToggle'),
        partial: document.getElementById('partialTimeFields'),
    });

    const syncPartialFields = () => {
        const elements = liveElements();
        if (elements.partial && elements.allDay) {
            elements.partial.style.display = elements.allDay.checked ? 'none' : 'grid';
        }
    };

    const applyWeekHour = (dayColumn, startHour) => {
        const elements = liveElements();
        if (!elements.form || !elements.date || !elements.allDay) {
            return;
        }
        elements.date.value = dayColumn.dataset.weekDate;
        elements.allDay.checked = false;
        syncPartialFields();
        elements.form.querySelector('[name="start_time"]').value = String(startHour).padStart(2, '0') + ':00';
        elements.form.querySelector('[name="end_time"]').value = String(startHour + 1).padStart(2, '0') + ':00';
        elements.date.focus({ preventScroll: true });
        elements.form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    document.addEventListener('change', (event) => {
        if (event.target.id === 'allDayToggle') {
            syncPartialFields();
        }
    });

    document.addEventListener('click', (event) => {
        const block = event.target.closest('[data-availability-block]');
        if (block) {
            event.stopPropagation();
            if (block.dataset.placeholderBlock === 'true') {
                return;
            }
            const elements = liveElements();
            if (!elements.form || !elements.date || !elements.allDay) {
                return;
            }
            elements.date.value = block.dataset.date;
            const isAllDay = !block.dataset.start || !block.dataset.end;
            elements.allDay.checked = isAllDay;
            syncPartialFields();
            if (!isAllDay) {
                elements.form.querySelector('[name="start_time"]').value = block.dataset.start.slice(0, 5);
                elements.form.querySelector('[name="end_time"]').value = block.dataset.end.slice(0, 5);
            }
            elements.form.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        const day = event.target.closest('[data-week-date]');
        if (!day) {
            return;
        }
        const hourCell = event.target.closest('[data-hour-slot]');
        if (hourCell) {
            applyWeekHour(day, Number(hourCell.dataset.startHour));
            return;
        }
        const rect = day.getBoundingClientRect();
        const relativeY = Math.max(0, Math.min(rect.height - 1, event.clientY - rect.top));
        applyWeekHour(day, 8 + Math.min(8, Math.floor((relativeY / rect.height) * 9)));
    });

    document.addEventListener('keydown', (event) => {
        const day = event.target.closest('[data-week-date]');
        if (!day || (event.key !== 'Enter' && event.key !== ' ')) {
            return;
        }
        event.preventDefault();
        applyWeekHour(day, 8);
    });

    syncPartialFields();
})();
</script>
