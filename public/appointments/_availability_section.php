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
$availabilityToday = new DateTimeImmutable('today');
$availabilityMinimumDate = $availabilityToday->format('N') === '7'
    ? $availabilityToday->modify('+1 day')
    : $availabilityToday;
$availabilityCurrentWeek = $availabilityToday->modify('monday this week')->format('Y-m-d');
$availabilityWeekEnd = $availabilityWeek->modify('+5 days');
$availabilityRequestedDate = '';
if (isset($_GET['block_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['block_date'])) {
    $requestedDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $_GET['block_date']);
    if ($requestedDate && $requestedDate->format('Y-m-d') === (string) $_GET['block_date'] && $requestedDate >= $availabilityMinimumDate) {
        $availabilityRequestedDate = $requestedDate->format('Y-m-d');
    }
}
if ($availabilityRequestedDate !== '') {
    $availabilityDefaultDate = $availabilityRequestedDate;
} elseif ($availabilityMinimumDate >= $availabilityWeek && $availabilityMinimumDate <= $availabilityWeekEnd) {
    $availabilityDefaultDate = $availabilityMinimumDate->format('Y-m-d');
} elseif ($availabilityWeekEnd < $availabilityMinimumDate) {
    $availabilityDefaultDate = $availabilityMinimumDate->format('Y-m-d');
} else {
    $availabilityDefaultDate = $availabilityWeek->format('Y-m-d');
}
$availabilityWeekLabel = $availabilityWeek->format('Y-m-d') === $availabilityCurrentWeek
    ? 'This Week'
    : $availabilityWeek->format('M j') . '–' . $availabilityWeekEnd->format('M j');
$availabilityStartMinutes = 8 * 60;
$availabilityEndMinutes = 17 * 60;
$availabilityDurationMinutes = $availabilityEndMinutes - $availabilityStartMinutes;
$availabilityUrlForWeek = static function (string $week) use ($filterStatus, $dateFrom, $dateTo): string {
    $query = ['status' => $filterStatus, 'week' => $week];
    if ($dateFrom !== '') {
        $query['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $query['date_to'] = $dateTo;
    }
    return 'index.php?' . http_build_query($query) . '#clinic-availability';
};
?>

<section class="clinic-card overflow-hidden" id="clinic-availability">
    <div class="p-5 sm:p-6 border-b border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Scheduling</p>
            <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Clinic Availability</h2>
            <p class="text-xs font-bold text-slate-500 mb-0">Monday to Saturday, 8:00 AM–5:00 PM. Click an open hour to prepare a block.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?= e($availabilityUrlForWeek($availabilityPrevWeek)) ?>" class="btn btn-sm btn-ghost text-decoration-none" title="Previous week" data-no-ajax="true" data-availability-week-nav>
                <span class="material-symbols-outlined">chevron_left</span>
            </a>
            <a href="<?= e($availabilityUrlForWeek($availabilityCurrentWeek)) ?>" class="btn btn-sm btn-outline text-decoration-none" data-no-ajax="true" data-availability-week-nav><?= e($availabilityWeekLabel) ?></a>
            <a href="<?= e($availabilityUrlForWeek($availabilityNextWeek)) ?>" class="btn btn-sm btn-ghost text-decoration-none" title="Next week" data-no-ajax="true" data-availability-week-nav>
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
                        $isToday = $date === $availabilityToday->format('Y-m-d');
                        $isPast = $day < $availabilityMinimumDate;
                        ?>
                        <div class="appointment-week-header <?= $isToday ? 'is-today' : '' ?> <?= $isPast ? 'is-past' : '' ?>">
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
                        $isToday = $date === $availabilityToday->format('Y-m-d');
                        $isPast = $day < $availabilityMinimumDate;
                        ?>
                        <div class="appointment-week-day-column <?= $isToday ? 'is-today' : '' ?> <?= $isPast ? 'is-past' : '' ?>" data-week-date="<?= e($date) ?>" data-is-past="<?= $isPast ? 'true' : 'false' ?>" role="button" tabindex="<?= $isPast ? '-1' : '0' ?>" aria-disabled="<?= $isPast ? 'true' : 'false' ?>" aria-label="<?= $isPast ? 'Past date unavailable on ' : 'Choose an available hour on ' ?><?= e($day->format('l, F j')) ?>">
                            <?php for ($hour = 8; $hour < 17; $hour++):
                                $top = ((($hour * 60) - $availabilityStartMinutes) / $availabilityDurationMinutes) * 100;
                                $height = (60 / $availabilityDurationMinutes) * 100;
                                ?>
                                <button type="button" class="appointment-week-hour-cell"
                                    style="top: <?= number_format($top, 4, '.', '') ?>%; height: <?= number_format($height, 4, '.', '') ?>%;"
                                    data-hour-slot data-start-hour="<?= $hour ?>" data-hour-date="<?= e($date) ?>"
                                    <?= $isPast ? 'disabled' : '' ?>
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
                    <div id="availabilitySelectedDates">
                        <input type="hidden" name="block_dates[]" value="<?= e($availabilityDefaultDate) ?>">
                    </div>
                    <div id="availabilitySelectedSlots"></div>
                    <div class="grid gap-2">
                        <span class="text-[11px] font-black uppercase tracking-widest text-slate-400">Date(s)</span>
                        <input type="hidden" name="block_date" id="blockDateInput" value="<?= e($availabilityDefaultDate) ?>" required>
                        <button type="button" class="appointment-date-picker-trigger" id="openAvailabilityDateModal">
                            <span class="material-symbols-outlined">calendar_month</span>
                            <span>Select Dates</span>
                        </button>
                        <div class="appointment-selected-dates" id="availabilitySelectedDateSummary" aria-live="polite"></div>
                    </div>
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
                    <div class="appointment-selected-time-slots" id="availabilitySelectedSlotSummary" aria-live="polite"></div>
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
                                        <input type="hidden" name="id" value="<?= (int) $block['availability_block_id'] ?>">
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

<div class="appointment-date-modal" id="availabilityDateModal" hidden>
    <div class="appointment-date-modal-backdrop" data-close-availability-date-modal></div>
    <section class="appointment-date-modal-card" role="dialog" aria-modal="true" aria-labelledby="availabilityDateModalTitle">
        <div class="appointment-date-modal-header">
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Unavailable dates</p>
                <h3 class="font-headline text-xl font-extrabold text-[#17261d] mb-0" id="availabilityDateModalTitle">Select Multiple Dates</h3>
            </div>
            <button type="button" class="appointment-date-modal-close" data-close-availability-date-modal aria-label="Close date selector">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <div class="appointment-date-modal-toolbar">
            <button type="button" class="btn btn-sm btn-ghost" data-availability-month-prev>
                <span class="material-symbols-outlined">chevron_left</span>
            </button>
            <strong id="availabilityDateModalMonth"></strong>
            <button type="button" class="btn btn-sm btn-ghost" data-availability-month-next>
                <span class="material-symbols-outlined">chevron_right</span>
            </button>
        </div>

        <div class="appointment-date-modal-weekdays" aria-hidden="true">
            <span>Sun</span>
            <span>Mon</span>
            <span>Tue</span>
            <span>Wed</span>
            <span>Thu</span>
            <span>Fri</span>
            <span>Sat</span>
        </div>
        <div class="appointment-date-modal-grid" id="availabilityDateModalGrid"></div>

        <div class="appointment-date-modal-selected">
            <span class="text-[11px] font-black uppercase tracking-widest text-slate-400">Selected</span>
            <div id="availabilityDateModalSelected"></div>
        </div>

        <div class="appointment-date-modal-actions">
            <button type="button" class="btn btn-ghost" data-availability-date-clear>Clear</button>
            <button type="button" class="btn btn-primary" data-availability-date-apply>
                <span class="material-symbols-outlined">check</span>
                Apply Dates
            </button>
        </div>
    </section>
</div>

<script>
(() => {
    if (window.cliniqAvailabilityListenersReady) {
        return;
    }
    window.cliniqAvailabilityListenersReady = true;

    const liveElements = () => ({
        form: document.getElementById('availabilityForm'),
        date: document.getElementById('blockDateInput'),
        selectedDates: document.getElementById('availabilitySelectedDates'),
        selectedSlots: document.getElementById('availabilitySelectedSlots'),
        summary: document.getElementById('availabilitySelectedDateSummary'),
        slotSummary: document.getElementById('availabilitySelectedSlotSummary'),
        modal: document.getElementById('availabilityDateModal'),
        modalMonth: document.getElementById('availabilityDateModalMonth'),
        modalGrid: document.getElementById('availabilityDateModalGrid'),
        modalSelected: document.getElementById('availabilityDateModalSelected'),
        allDay: document.getElementById('allDayToggle'),
        partial: document.getElementById('partialTimeFields'),
    });

    const selectedCalendarDates = new Set();
    const selectedTimeSlots = new Set();
    if (document.getElementById('blockDateInput')?.value) {
        selectedCalendarDates.add(document.getElementById('blockDateInput').value);
    }

    const minimumAvailabilityDate = '<?= e($availabilityMinimumDate->format('Y-m-d')) ?>';

    const normalizedDate = (dateString) => {
        const parts = String(dateString || '').split('-').map(Number);
        if (parts.length !== 3 || parts.some(Number.isNaN)) {
            return '';
        }
        const date = new Date(parts[0], parts[1] - 1, parts[2]);
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const dayOfMonth = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${dayOfMonth}`;
    };

    let modalVisibleMonth = (() => {
        const base = normalizedDate(document.getElementById('blockDateInput')?.value || minimumAvailabilityDate) || minimumAvailabilityDate;
        const [year, month] = base.split('-').map(Number);
        return new Date(year, month - 1, 1);
    })();

    const isPastDate = (dateString) => {
        const date = normalizedDate(dateString);
        return date !== '' && date < minimumAvailabilityDate;
    };

    const isSunday = (dateString) => {
        const date = normalizedDate(dateString);
        if (!date) return false;
        const [year, month, day] = date.split('-').map(Number);
        return new Date(year, month - 1, day).getDay() === 0;
    };

    const formatDisplayDate = (dateString) => {
        const date = normalizedDate(dateString);
        if (!date) return '';
        const [year, month, day] = date.split('-').map(Number);
        return new Date(year, month - 1, day).toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    };

    const sortedSelectedDates = () => Array.from(selectedCalendarDates).sort();
    const sortedSelectedSlots = () => Array.from(selectedTimeSlots).sort();

    const slotKey = (date, start, end) => `${date}|${start}|${end}`;
    const parseSlotKey = (key) => {
        const [date = '', start = '', end = ''] = String(key || '').split('|');
        return { date, start, end };
    };
    const hasSlotsForDate = (date) => sortedSelectedSlots().some((slot) => parseSlotKey(slot).date === date);

    const timeToMinutes = (value) => {
        const [hour, minute] = String(value || '').split(':').map(Number);
        if (Number.isNaN(hour) || Number.isNaN(minute)) {
            return null;
        }
        return (hour * 60) + minute;
    };

    const syncSelectedDateSummary = () => {
        const elements = liveElements();
        const dates = sortedSelectedDates();
        if (elements.date) {
            elements.date.value = dates[0] || minimumAvailabilityDate;
        }
        if (!elements.summary) {
            return;
        }
        elements.summary.replaceChildren();
        if (!elements.allDay?.checked) {
            elements.summary.hidden = true;
            return;
        }
        elements.summary.hidden = false;
        if (dates.length === 0) {
            const empty = document.createElement('span');
            empty.className = 'appointment-selected-date-empty';
            empty.textContent = 'No date selected';
            elements.summary.appendChild(empty);
            return;
        }
        dates.forEach((date) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'appointment-selected-date-chip';
            chip.dataset.selectedDateChip = date;
            chip.setAttribute('aria-label', `Remove ${formatDisplayDate(date)}`);
            chip.textContent = formatDisplayDate(date);
            elements.summary.appendChild(chip);
        });
    };

    const syncSelectedSlotSummary = () => {
        const elements = liveElements();
        const slots = sortedSelectedSlots();

        if (elements.selectedSlots) {
            elements.selectedSlots.replaceChildren();
            slots.forEach((slot) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'block_slots[]';
                input.value = slot;
                elements.selectedSlots.appendChild(input);
            });
        }

        if (!elements.slotSummary) {
            return;
        }

        elements.slotSummary.replaceChildren();
        if (elements.allDay?.checked) {
            elements.slotSummary.hidden = true;
            return;
        }

        elements.slotSummary.hidden = false;
        if (slots.length === 0) {
            const empty = document.createElement('span');
            empty.className = 'appointment-selected-date-empty';
            empty.textContent = 'No specific time selected. Start and End will apply to selected date(s).';
            elements.slotSummary.appendChild(empty);
            return;
        }

        slots.forEach((slot) => {
            const { date, start, end } = parseSlotKey(slot);
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'appointment-selected-date-chip appointment-selected-slot-chip';
            chip.dataset.selectedSlotChip = slot;
            chip.setAttribute('aria-label', `Remove ${formatDisplayDate(date)} ${start} to ${end}`);
            chip.textContent = `${formatDisplayDate(date)} · ${start}–${end}`;
            elements.slotSummary.appendChild(chip);
        });
    };

    const renderDateModal = () => {
        const elements = liveElements();
        if (!elements.modalMonth || !elements.modalGrid || !elements.modalSelected) {
            return;
        }

        elements.modalMonth.textContent = modalVisibleMonth.toLocaleDateString(undefined, {
            month: 'long',
            year: 'numeric',
        });
        elements.modalGrid.replaceChildren();

        const year = modalVisibleMonth.getFullYear();
        const month = modalVisibleMonth.getMonth();
        const firstDay = new Date(year, month, 1);
        const startOffset = firstDay.getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const cells = Math.ceil((startOffset + daysInMonth) / 7) * 7;

        for (let index = 0; index < cells; index += 1) {
            const dayNumber = index - startOffset + 1;
            const cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'appointment-date-modal-day';

            if (dayNumber < 1 || dayNumber > daysInMonth) {
                cell.classList.add('is-empty');
                cell.disabled = true;
                elements.modalGrid.appendChild(cell);
                continue;
            }

            const date = new Date(year, month, dayNumber);
            const dateString = normalizedDate(`${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(dayNumber).padStart(2, '0')}`);
            const disabled = isPastDate(dateString) || isSunday(dateString);
            cell.textContent = String(dayNumber);
            cell.dataset.modalDate = dateString;
            cell.classList.toggle('is-selected', selectedCalendarDates.has(dateString));
            cell.classList.toggle('is-today', dateString === minimumAvailabilityDate);
            cell.classList.toggle('is-disabled', disabled);
            cell.disabled = disabled;
            elements.modalGrid.appendChild(cell);
        }

        elements.modalSelected.replaceChildren();
        sortedSelectedDates().forEach((date) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'appointment-selected-date-chip';
            chip.dataset.selectedDateChip = date;
            chip.setAttribute('aria-label', `Remove ${formatDisplayDate(date)}`);
            chip.textContent = formatDisplayDate(date);
            elements.modalSelected.appendChild(chip);
        });
        if (!elements.modalSelected.children.length) {
            const empty = document.createElement('span');
            empty.className = 'appointment-selected-date-empty';
            empty.textContent = 'No date selected';
            elements.modalSelected.appendChild(empty);
        }
    };

    const openDateModal = () => {
        const elements = liveElements();
        if (!elements.modal) {
            return;
        }
        const firstSelected = sortedSelectedDates()[0] || minimumAvailabilityDate;
        const [year, month] = firstSelected.split('-').map(Number);
        modalVisibleMonth = new Date(year, month - 1, 1);
        renderDateModal();
        elements.modal.hidden = false;
        document.body.classList.add('modal-open');
    };

    const closeDateModal = () => {
        const elements = liveElements();
        if (elements.modal) {
            elements.modal.hidden = true;
        }
        document.body.classList.remove('modal-open');
    };

    const syncCalendarSelection = () => {
        const elements = liveElements();
        if (elements.selectedDates) {
            elements.selectedDates.replaceChildren();
            selectedCalendarDates.forEach((date) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'block_dates[]';
                input.value = date;
                elements.selectedDates.appendChild(input);
            });
        }

        const isWholeDay = Boolean(elements.allDay?.checked);
        const startMinutes = timeToMinutes(elements.form?.querySelector('[name="start_time"]')?.value);
        const endMinutes = timeToMinutes(elements.form?.querySelector('[name="end_time"]')?.value);

        document.querySelectorAll('[data-week-date]').forEach((day) => {
            day.classList.toggle('is-selected', isWholeDay && selectedCalendarDates.has(day.dataset.weekDate || ''));
        });

        document.querySelectorAll('[data-hour-slot]').forEach((cell) => {
            const date = cell.dataset.hourDate || '';
            const hourStart = Number(cell.dataset.startHour) * 60;
            const hourEnd = hourStart + 60;
            const exactSlot = slotKey(date, `${String(cell.dataset.startHour).padStart(2, '0')}:00`, `${String(Number(cell.dataset.startHour) + 1).padStart(2, '0')}:00`);
            const selected = !isWholeDay
                && (
                    selectedTimeSlots.has(exactSlot)
                    || (
                        selectedTimeSlots.size === 0
                        && selectedCalendarDates.has(date)
                        && startMinutes !== null
                        && endMinutes !== null
                        && hourStart < endMinutes
                        && hourEnd > startMinutes
                    )
                );
            cell.classList.toggle('is-selected', selected);
        });
        syncSelectedDateSummary();
        syncSelectedSlotSummary();
        renderDateModal();
    };

    const selectAvailabilityDate = (date, replaceSelection = true) => {
        const elements = liveElements();
        if (!elements.date || !date) return;
        if (isPastDate(date)) {
            return;
        }

        elements.date.value = date;
        if (replaceSelection) {
            selectedCalendarDates.clear();
        }
        if (selectedCalendarDates.has(date) && !replaceSelection) {
            selectedCalendarDates.delete(date);
        } else {
            selectedCalendarDates.add(date);
        }
        syncCalendarSelection();
    };

    const syncPartialFields = () => {
        const elements = liveElements();
        if (elements.partial && elements.allDay) {
            elements.partial.style.display = elements.allDay.checked ? 'none' : 'grid';
        }
        syncCalendarSelection();
    };

    const applyWeekHour = (dayColumn, startHour, addToSelection = false) => {
        const elements = liveElements();
        if (!elements.form || !elements.date || !elements.allDay) {
            return;
        }
        if (dayColumn.dataset.isPast === 'true') {
            return;
        }
        selectedCalendarDates.add(dayColumn.dataset.weekDate);
        elements.allDay.checked = false;
        syncPartialFields();
        const startValue = String(startHour).padStart(2, '0') + ':00';
        const endValue = String(startHour + 1).padStart(2, '0') + ':00';
        elements.form.querySelector('[name="start_time"]').value = startValue;
        elements.form.querySelector('[name="end_time"]').value = endValue;
        const key = slotKey(dayColumn.dataset.weekDate, startValue, endValue);
        if (selectedTimeSlots.has(key)) {
            selectedTimeSlots.delete(key);
            if (!hasSlotsForDate(dayColumn.dataset.weekDate)) {
                selectedCalendarDates.delete(dayColumn.dataset.weekDate);
            }
        } else {
            selectedTimeSlots.add(key);
        }
        elements.date.focus({ preventScroll: true });
        syncCalendarSelection();
    };

    document.addEventListener('change', (event) => {
        if (event.target.id === 'allDayToggle') {
            syncPartialFields();
        }
        if (event.target.id === 'blockDateInput') {
            if (isPastDate(event.target.value)) {
                event.target.value = minimumAvailabilityDate;
            }
            selectAvailabilityDate(event.target.value);
        }
        if (event.target.matches('#availabilityForm [name="start_time"], #availabilityForm [name="end_time"]')) {
            syncCalendarSelection();
        }
    });

    document.addEventListener('click', (event) => {
        if (event.target.closest('#openAvailabilityDateModal')) {
            openDateModal();
            return;
        }

        if (event.target.closest('[data-close-availability-date-modal]')) {
            closeDateModal();
            return;
        }

        if (event.target.closest('[data-availability-month-prev]')) {
            modalVisibleMonth = new Date(modalVisibleMonth.getFullYear(), modalVisibleMonth.getMonth() - 1, 1);
            renderDateModal();
            return;
        }

        if (event.target.closest('[data-availability-month-next]')) {
            modalVisibleMonth = new Date(modalVisibleMonth.getFullYear(), modalVisibleMonth.getMonth() + 1, 1);
            renderDateModal();
            return;
        }

        if (event.target.closest('[data-availability-date-clear]')) {
            selectedCalendarDates.clear();
            selectedTimeSlots.clear();
            syncCalendarSelection();
            return;
        }

        if (event.target.closest('[data-availability-date-apply]')) {
            closeDateModal();
            return;
        }

        const selectedDateChip = event.target.closest('[data-selected-date-chip]');
        if (selectedDateChip) {
            const date = selectedDateChip.dataset.selectedDateChip || '';
            if (date) {
                selectedCalendarDates.delete(date);
                sortedSelectedSlots().forEach((slot) => {
                    if (parseSlotKey(slot).date === date) {
                        selectedTimeSlots.delete(slot);
                    }
                });
                syncCalendarSelection();
            }
            return;
        }

        const selectedSlotChip = event.target.closest('[data-selected-slot-chip]');
        if (selectedSlotChip) {
            const slot = selectedSlotChip.dataset.selectedSlotChip || '';
            if (slot) {
                selectedTimeSlots.delete(slot);
                syncCalendarSelection();
            }
            return;
        }

        const modalDay = event.target.closest('[data-modal-date]');
        if (modalDay) {
            const date = modalDay.dataset.modalDate || '';
            if (modalDay.disabled || !date || isPastDate(date) || isSunday(date)) {
                return;
            }
            if (selectedCalendarDates.has(date)) {
                selectedCalendarDates.delete(date);
            } else {
                selectedCalendarDates.add(date);
            }
            syncCalendarSelection();
            return;
        }

        const weekNav = event.target.closest('[data-availability-week-nav]');
        if (weekNav) {
            event.preventDefault();
            window.location.assign(weekNav.href);
            return;
        }

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
            selectAvailabilityDate(block.dataset.date);
            const isAllDay = !block.dataset.start || !block.dataset.end;
            elements.allDay.checked = isAllDay;
            syncPartialFields();
            if (!isAllDay) {
                elements.form.querySelector('[name="start_time"]').value = block.dataset.start.slice(0, 5);
                elements.form.querySelector('[name="end_time"]').value = block.dataset.end.slice(0, 5);
            }
            return;
        }

        const day = event.target.closest('[data-week-date]');
        if (!day) {
            return;
        }
        if (day.dataset.isPast === 'true') {
            return;
        }
        const hourCell = event.target.closest('[data-hour-slot]');
        if (hourCell) {
            applyWeekHour(day, Number(hourCell.dataset.startHour), true);
            return;
        }
        const rect = day.getBoundingClientRect();
        const relativeY = Math.max(0, Math.min(rect.height - 1, event.clientY - rect.top));
        applyWeekHour(day, 8 + Math.min(8, Math.floor((relativeY / rect.height) * 9)), true);
    });

    document.addEventListener('keydown', (event) => {
        const day = event.target.closest('[data-week-date]');
        if (!day || (event.key !== 'Enter' && event.key !== ' ')) {
            return;
        }
        event.preventDefault();
        applyWeekHour(day, 8, true);
    });

    syncPartialFields();
    syncCalendarSelection();
})();
</script>
