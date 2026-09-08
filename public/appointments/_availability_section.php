<?php

$availabilityWeek = appointment_week_from_request($_GET['week'] ?? null);
$availabilityBlocksByDate = appointment_blocks_for_week($availabilityWeek);
[$availabilityRangeStart, $availabilityRangeEnd] = appointment_week_bounds($availabilityWeek);
$availabilityApeBatchesByDate = appointment_ape_batches_for_range($availabilityRangeStart, $availabilityRangeEnd);
$availabilityWeekDays = [];
for ($offset = 0; $offset < 5; $offset++) {
    $availabilityWeekDays[] = $availabilityWeek->modify('+' . $offset . ' days');
}

$usingAvailabilityPlaceholders = empty($availabilityBlocksByDate) && empty($availabilityApeBatchesByDate);
$calendarBlocksByDate = $availabilityBlocksByDate;
// Combine adjoining unavailable periods for display; retain the original saved records.
foreach ($calendarBlocksByDate as $date => $dayBlocks) {
    usort($dayBlocks, static fn(array $a, array $b): int => strcmp($a['start_time'] ?? '08:00:00', $b['start_time'] ?? '08:00:00'));
    $merged = [];
    foreach ($dayBlocks as $block) {
        $last = count($merged) - 1;
        $start = $block['start_time'] ?: '08:00:00';
        $end = $block['end_time'] ?: '17:00:00';
        if ($last >= 0 && $start <= ($merged[$last]['end_time'] ?: '17:00:00')) {
            if (empty($merged[$last]['start_time']) || empty($block['start_time'])) {
                $merged[$last]['start_time'] = null;
                $merged[$last]['end_time'] = null;
            } else {
                $merged[$last]['end_time'] = max($merged[$last]['end_time'], $end);
            }
            $merged[$last]['reason'] = implode(' / ', array_unique(array_filter([$merged[$last]['reason'] ?? '', $block['reason'] ?? ''])));
        } else {
            $merged[] = $block;
        }
    }
    $calendarBlocksByDate[$date] = $merged;
}
$availabilityDisplayBlocks = [];
foreach ($availabilityBlocksByDate as $date => $dayBlocks) {
    usort($dayBlocks, static fn(array $a, array $b): int => strcmp($a['start_time'] ?? '00:00:00', $b['start_time'] ?? '00:00:00'));
    foreach ($dayBlocks as $block) {
        $start = $block['start_time'] ?: '08:00:00';
        $end = $block['end_time'] ?: '17:00:00';
        $last = count($availabilityDisplayBlocks) - 1;
        $sameDate = $last >= 0 && $availabilityDisplayBlocks[$last]['date'] === $date;
        if ($sameDate && $start <= ($availabilityDisplayBlocks[$last]['end_time'] ?: '17:00:00')) {
            $availabilityDisplayBlocks[$last]['end_time'] = empty($availabilityDisplayBlocks[$last]['start_time']) || empty($block['start_time']) ? null : max($availabilityDisplayBlocks[$last]['end_time'], $end);
            $availabilityDisplayBlocks[$last]['start_time'] = empty($availabilityDisplayBlocks[$last]['start_time']) || empty($block['start_time']) ? null : $availabilityDisplayBlocks[$last]['start_time'];
            $availabilityDisplayBlocks[$last]['reason'] = implode(' / ', array_unique(array_filter([$availabilityDisplayBlocks[$last]['reason'], $block['reason'] ?? ''])));
            $availabilityDisplayBlocks[$last]['ids'][] = (int) $block['availability_block_id'];
        } else {
            $availabilityDisplayBlocks[] = ['date' => $date, 'start_time' => $block['start_time'], 'end_time' => $block['end_time'], 'reason' => $block['reason'] ?? '', 'ids' => [(int) $block['availability_block_id']]];
        }
    }
}
foreach ($availabilityApeBatchesByDate as $date => $batches) {
    foreach ($batches as $batch) {
        $assignedCount = (int) ($batch['assigned_count'] ?? 0);
        $calendarBlocksByDate[$date][] = [
            'start_time' => $batch['start_time'],
            'end_time' => $batch['end_time'],
            'reason' => $batch['batch_name'] . ' • ' . $batch['patient_category'] . ' • ' . $assignedCount . ' patient' . ($assignedCount === 1 ? '' : 's'),
            '_ape_batch' => true,
        ];
    }
}
if ($usingAvailabilityPlaceholders) {
    $calendarBlocksByDate[$availabilityWeekDays[0]->format('Y-m-d')][] = [
        'id' => 0, 'start_time' => '09:00:00', 'end_time' => '10:00:00',
        'reason' => 'Staff Meeting', '_placeholder' => true,
    ];
    $calendarBlocksByDate[$availabilityWeekDays[2]->format('Y-m-d')][] = [
        'id' => 0, 'start_time' => '13:00:00', 'end_time' => '15:00:00',
        'reason' => 'Clinic Maintenance', '_placeholder' => true,
    ];
    $calendarBlocksByDate[$availabilityWeekDays[4]->format('Y-m-d')][] = [
        'id' => 0, 'start_time' => null, 'end_time' => null,
        'reason' => 'Campus Event', '_placeholder' => true,
    ];
}

$availabilityPrevWeek = $availabilityWeek->modify('-1 week')->format('Y-m-d');
$availabilityNextWeek = $availabilityWeek->modify('+1 week')->format('Y-m-d');
$availabilityToday = new DateTimeImmutable('today');
$availabilityMinimumDate = (int) $availabilityToday->format('N') >= 6
    ? $availabilityToday->modify('next monday')
    : $availabilityToday;
$availabilityCurrentWeek = $availabilityToday->modify('monday this week')->format('Y-m-d');
$availabilityWeekEnd = $availabilityWeek->modify('+4 days');
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
            <p class="text-xs font-bold text-slate-500 mb-0">Monday to Friday, 8:00 AM–5:00 PM. Click or hold and drag across open hours to select a range.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?= e($availabilityUrlForWeek($availabilityPrevWeek)) ?>" class="btn btn-sm btn-ghost text-decoration-none" title="Previous week" data-no-ajax="true" data-availability-week-nav>
                <span class="material-symbols-outlined">chevron_left</span>
            </a>
            <button type="button" class="btn btn-sm btn-outline" id="openAvailabilityWeekPicker" aria-haspopup="dialog" aria-controls="availabilityWeekPicker"><?= e($availabilityWeekLabel) ?></button>
            <a href="<?= e($availabilityUrlForWeek($availabilityNextWeek)) ?>" class="btn btn-sm btn-ghost text-decoration-none" title="Next week" data-no-ajax="true" data-availability-week-nav>
                <span class="material-symbols-outlined">chevron_right</span>
            </a>
        </div>
    </div>

    <div class="appointment-availability-layout">
        <div class="appointment-availability-calendar-panel">
            <div class="appointment-week-range">
                <strong><?= e($availabilityWeek->format('M j')) ?>–<?= e($availabilityWeekEnd->format('M j, Y')) ?></strong>
                <span>Unavailable periods appear in red; APE examinations appear in green.</span>
            </div>

            <?php if ($usingAvailabilityPlaceholders): ?>
                <div class="appointment-week-sample-note" role="note">
                    <span class="material-symbols-outlined" aria-hidden="true">info</span>
                    <span>This week has no saved availability blocks. Faded blocks are UI-only examples and do not affect booking.</span>
                </div>
            <?php endif; ?>

            <div class="appointment-week-scroll">
                <div class="appointment-week-calendar" style="grid-template-columns: 4.75rem repeat(5, minmax(8.5rem, 1fr)); min-width: 50rem;">
                    <div class="appointment-week-header appointment-week-time-heading">Time</div>
                    <?php foreach ($availabilityWeekDays as $day):
                        $date = $day->format('Y-m-d');
                        $isToday = $date === $availabilityToday->format('Y-m-d');
                        $isPast = $day < $availabilityMinimumDate;
                        ?>
                        <button type="button" class="appointment-week-header <?= $isToday ? 'is-today' : '' ?> <?= $isPast ? 'is-past' : '' ?>" data-week-day-toggle="<?= e($date) ?>" aria-pressed="false" aria-label="Select whole day <?= e($day->format('l, F j')) ?>" <?= $isPast ? 'disabled' : '' ?> style="cursor:<?= $isPast ? 'not-allowed' : 'pointer' ?>;">
                            <span><?= e($day->format('D')) ?></span>
                            <strong><?= e($day->format('M j')) ?></strong>
                        </button>
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
                                    style="user-select:none; touch-action:none; top: <?= number_format($top, 4, '.', '') ?>%; height: <?= number_format($height, 4, '.', '') ?>%;"
                                    data-hour-slot data-start-hour="<?= $hour ?>" data-hour-date="<?= e($date) ?>"
                                    <?= $isPast ? 'disabled' : '' ?>
                                    aria-label="<?= e($day->format('l, M j') . ', ' . date('g:i A', mktime($hour, 0)) . ' to ' . date('g:i A', mktime($hour + 1, 0))) ?>"></button>
                            <?php endfor; ?>

                            <?php foreach ($blocks as $block):
                                $isPlaceholder = !empty($block['_placeholder']);
                                $isApeBatch = !empty($block['_ape_batch']);
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
                                <div class="appointment-week-block <?= $isWholeDay ? 'is-all-day' : '' ?> <?= $isPlaceholder ? 'is-placeholder' : '' ?> <?= $isApeBatch ? 'is-ape' : '' ?>"
                                    style="top: calc(<?= number_format($top, 4, '.', '') ?>% + 2px); height: calc(<?= number_format($height, 4, '.', '') ?>% - 4px);"
                                    role="button" tabindex="0" aria-haspopup="dialog"
                                    data-reason="<?= e($block['reason'] ?? '') ?>" data-time-label="<?= e($isWholeDay ? 'Whole day unavailable' : appointment_format_block_time($block)) ?>"
                                    data-availability-block data-date="<?= e($date) ?>" data-start="<?= e($block['start_time'] ?? '') ?>" data-end="<?= e($block['end_time'] ?? '') ?>"
                                    <?= $isPlaceholder ? 'data-placeholder-block="true"' : '' ?>
                                    <?= $isApeBatch ? 'data-ape-block="true"' : '' ?>
                                    title="<?= e(appointment_format_block_time($block) . ($block['reason'] ? ' — ' . $block['reason'] : '')) ?>">
                                    <strong><?= $isWholeDay ? 'Unavailable' : e(appointment_format_block_time($block)) ?><?php if ($isPlaceholder): ?><span class="appointment-week-sample-badge">Sample</span><?php endif; ?><?php if ($isApeBatch): ?><span class="appointment-week-sample-badge">APE</span><?php endif; ?></strong>
                                    <span><?= $isApeBatch ? 'APE: ' : '' ?><?= e($block['reason'] ?: ($isWholeDay ? 'Whole day blocked' : 'Unavailable')) ?></span>
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
                    <?php if (empty($availabilityDisplayBlocks)): ?>
                        <div class="text-sm font-bold text-slate-500 p-4 rounded-xl bg-slate-50">No unavailable times for this week.</div>
                    <?php else: ?>
                        <?php foreach ($availabilityDisplayBlocks as $block): ?>
                                <div class="appointment-block-row" data-edit-availability-block
                                    data-ids="<?= e(implode(',', array_map('intval', $block['ids']))) ?>"
                                    data-date="<?= e($block['date']) ?>"
                                    data-start="<?= e($block['start_time'] ? substr($block['start_time'], 0, 5) : '') ?>"
                                    data-end="<?= e($block['end_time'] ? substr($block['end_time'], 0, 5) : '') ?>"
                                    data-reason="<?= e($block['reason']) ?>">
                                    <div>
                                        <strong><?= e(date('M d, Y', strtotime($block['date']))) ?></strong>
                                        <span><?= e(appointment_format_block_time($block)) ?><?= $block['reason'] ? ' — ' . e($block['reason']) : '' ?></span>
                                    </div>
                                </div>
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

<div id="availabilityEditModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="availabilityEditModalTitle" style="display:none">
    <div class="modal-content bg-white rounded-2xl p-6 w-full max-w-md shadow-2xl">
        <div class="flex items-center justify-between gap-4 mb-5">
            <h3 id="availabilityEditModalTitle" class="font-headline text-xl font-extrabold">Edit unavailable time</h3>
            <button type="button" class="btn btn-ghost" data-close-availability-edit aria-label="Close"><span class="material-symbols-outlined">close</span></button>
        </div>
        <form method="POST" action="availability.php" data-no-ajax="true" class="grid gap-4">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="week" value="<?= e($availabilityWeek->format('Y-m-d')) ?>">
            <div id="availabilityEditIds"></div>
            <input type="hidden" name="block_date" id="availabilityEditDate">
            <input type="hidden" name="original_start" id="availabilityEditOriginalStart">
            <input type="hidden" name="original_end" id="availabilityEditOriginalEnd">
            <div class="grid grid-cols-2 gap-3" id="availabilityEditTimes">
                <label class="grid gap-1"><span class="text-[11px] font-black uppercase tracking-widest text-slate-400">Start</span><input class="form-input" type="time" name="start_time" id="availabilityEditStart" required></label>
                <label class="grid gap-1"><span class="text-[11px] font-black uppercase tracking-widest text-slate-400">End</span><input class="form-input" type="time" name="end_time" id="availabilityEditEnd" required></label>
            </div>
            <label class="grid gap-1"><span class="text-[11px] font-black uppercase tracking-widest text-slate-400">Reason</span><textarea class="form-textarea" name="reason" id="availabilityEditReason" rows="3"></textarea></label>
            <div class="flex justify-between gap-3">
                <button type="submit" name="action" value="delete_group" formnovalidate class="btn btn-ghost text-red-700" data-confirm-submit data-confirm-type="danger" data-confirm-title="Delete entire unavailable time?" data-confirm-message="This will remove the complete unavailable period and make it available again." data-confirm-toast="Deleting unavailable time...">Delete entire unavailable time</button>
                <button type="submit" class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save unavailable block?" data-confirm-toast="Saving availability...">Save changes</button>
            </div>
        </form>
    </div>
</div>

<div id="availabilityBlockDetails" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="availabilityBlockDetailsTitle" style="display:none">
    <div class="modal-content bg-white rounded-2xl p-6 w-full max-w-md shadow-2xl">
        <div class="flex items-center justify-between gap-4 mb-5">
            <h3 id="availabilityBlockDetailsTitle" class="font-headline text-xl font-extrabold">Unavailable time details</h3>
            <button type="button" class="btn btn-ghost" onclick="closeModal('availabilityBlockDetails')" aria-label="Close details"><span class="material-symbols-outlined">close</span></button>
        </div>
        <dl class="grid gap-4">
            <div><dt class="text-xs font-bold text-slate-400">DATE</dt><dd id="availabilityBlockDetailsDate" class="font-bold"></dd></div>
            <div><dt class="text-xs font-bold text-slate-400">TIME</dt><dd id="availabilityBlockDetailsTime" class="font-bold"></dd></div>
            <div><dt class="text-xs font-bold text-slate-400">REASON</dt><dd id="availabilityBlockDetailsReason" style="white-space:pre-wrap;overflow-wrap:anywhere"></dd></div>
        </dl>
    </div>
</div>
<div id="availabilityWeekPicker" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="availabilityWeekPickerTitle" data-selected-week="<?= e($availabilityWeek->format('Y-m-d')) ?>" data-week-url="<?= e($availabilityUrlForWeek($availabilityWeek->format('Y-m-d'))) ?>">
    <div class="modal-content bg-white rounded-2xl p-6 w-full max-w-lg shadow-2xl" style="max-height:85vh;display:flex;flex-direction:column;">
        <div class="flex items-center justify-between gap-4 mb-4">
            <h3 id="availabilityWeekPickerTitle" class="font-headline text-xl font-extrabold">Select Week</h3>
            <button type="button" class="btn btn-ghost" onclick="closeModal('availabilityWeekPicker')" aria-label="Close week picker"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="flex items-center justify-between gap-3 mb-4">
            <button type="button" class="btn btn-outline" data-week-picker-month="-1" aria-label="Previous month"><span class="material-symbols-outlined">chevron_left</span></button>
            <strong id="availabilityWeekPickerYear" aria-live="polite"></strong>
            <button type="button" class="btn btn-outline" data-week-picker-month="1" aria-label="Next month"><span class="material-symbols-outlined">chevron_right</span></button>
        </div>
        <div id="availabilityWeekPickerList" style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px;overflow-y:auto;min-height:0;" aria-label="Week selection calendar"></div>
        <a href="<?= e($availabilityUrlForWeek($availabilityCurrentWeek)) ?>" class="btn btn-outline mt-4" style="flex-shrink:0;" data-availability-week-nav>This Week</a>
    </div>
</div>

<script>
(() => {
    if (window.cliniqAvailabilityListenersReady) {
        return;
    }
    window.cliniqAvailabilityListenersReady = true;

    let weekPickerMonth = null;
    const mondayForDate = date => {
        const monday = new Date(date);
        monday.setUTCDate(monday.getUTCDate() - (monday.getUTCDay() + 6) % 7);
        return monday;
    };
    const weekCalendarDays = month => {
        const first = new Date(Date.UTC(month.getUTCFullYear(), month.getUTCMonth(), 1));
        const cursor = new Date(first);
        cursor.setUTCDate(cursor.getUTCDate() - cursor.getUTCDay());
        return Array.from({ length: 42 }, () => {
            const date = new Date(cursor);
            cursor.setUTCDate(cursor.getUTCDate() + 1);
            return { date, week: mondayForDate(date).toISOString().slice(0, 10), inMonth: date.getUTCMonth() === first.getUTCMonth() };
        });
    };
    const renderWeekPicker = () => {
        const modal = document.getElementById('availabilityWeekPicker');
        const list = document.getElementById('availabilityWeekPickerList');
        document.getElementById('availabilityWeekPickerYear').textContent = weekPickerMonth.toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' });
        list.replaceChildren();
        ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(day => {
            const heading = document.createElement('span');
            heading.className = 'text-xs font-bold text-slate-500 text-center py-2';
            heading.textContent = day;
            heading.style.cssText = 'display:flex;align-items:center;justify-content:center;text-align:center;';
            if (day === 'Sat' || day === 'Sun') { heading.style.opacity = '0.4'; heading.style.filter = 'blur(0.4px)'; }
            list.appendChild(heading);
        });
        weekCalendarDays(weekPickerMonth).forEach(({ date, week, inMonth }) => {
            const weekend = date.getUTCDay() === 0 || date.getUTCDay() === 6;
            const link = document.createElement(weekend ? 'span' : 'a');
            const url = new URL(modal.dataset.weekUrl, window.location.href);
            url.searchParams.set('week', week);
            if (!weekend) { link.href = url.href; link.dataset.availabilityWeekNav = ''; link.dataset.noAjax = 'true'; }
            link.className = 'btn btn-ghost text-decoration-none';
            link.style.cssText = 'padding:0;min-width:0;min-height:42px;display:flex;align-items:center;justify-content:center;text-align:center;';
            link.textContent = date.getUTCDate();
            const label = date.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
            link.setAttribute('aria-label', `Select week containing ${label}`);
            if (!inMonth) link.style.color = '#94a3b8';
            if (weekend) { link.style.opacity = '0.35'; link.style.filter = 'blur(0.5px)'; link.style.cursor = 'not-allowed'; link.setAttribute('aria-disabled', 'true'); link.setAttribute('aria-label', label + ', weekend unavailable'); }
            if (week === modal.dataset.selectedWeek && (date.getUTCDay() + 6) % 7 < 5) {
                link.setAttribute('aria-current', 'date');
                link.style.background = '#e7f4eb';
                link.style.color = '#245c36';
                link.style.boxShadow = 'inset 0 -2px #43855b';
            }
            list.appendChild(link);
        });
    };

    let weekLoadController = null;
    const loadAvailabilityWeek = async href => {
        weekLoadController?.abort();
        const controller = new AbortController();
        weekLoadController = controller;
        const panel = document.getElementById('clinic-availability');
        const modal = document.getElementById('availabilityWeekPicker');
        panel.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(href, { credentials: 'same-origin', signal: controller.signal });
            if (!response.ok) throw new Error('Unable to load week');
            const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
            const replacement = parsed.getElementById('clinic-availability');
            const newPicker = parsed.getElementById('availabilityWeekPicker');
            if (!replacement || !newPicker) throw new Error('The week could not be loaded. Your session may have expired.');
            if (controller.signal.aborted) return;
            panel.replaceWith(replacement);
            modal.dataset.selectedWeek = newPicker.dataset.selectedWeek;
            modal.dataset.weekUrl = newPicker.dataset.weekUrl;
            selectedCalendarDates.clear();
            selectedTimeSlots.clear();
            const refreshedElements = liveElements();
            if (refreshedElements.allDay) refreshedElements.allDay.checked = false;
            syncPartialFields();
            history.replaceState(history.state, '', href);
            renderWeekPickerIfOpen();
            closeModal('availabilityWeekPicker');
            document.getElementById('openAvailabilityWeekPicker')?.focus({ preventScroll: true });
        } catch (error) {
            if (error.name !== 'AbortError') showToast(error.message || 'Unable to load this week. Please try again.', 'error');
        } finally {
            if (weekLoadController === controller) document.getElementById('clinic-availability')?.removeAttribute('aria-busy');
        }
    };
    const renderWeekPickerIfOpen = () => {
        if (weekPickerMonth) renderWeekPicker();
    };

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

    let modalDateDrag = null;
    let suppressDatePointerClick = false;
    const previewModalDateDrag = (endDate) => {
        const grid = liveElements().modalGrid;
        if (!grid || !modalDateDrag) return;
        const [first, last] = [modalDateDrag.start, endDate].sort();
        selectedCalendarDates.clear();
        modalDateDrag.original.forEach(date => selectedCalendarDates.add(date));
        grid.querySelectorAll('[data-modal-date]').forEach(day => {
            const date = day.dataset.modalDate;
            if (!day.disabled && !isPastDate(date) && !isWeekend(date) && date >= first && date <= last) {
                if (modalDateDrag.remove) selectedCalendarDates.delete(date);
                else selectedCalendarDates.add(date);
            }
            day.classList.toggle('is-selected', selectedCalendarDates.has(date));
            day.setAttribute('aria-pressed', String(selectedCalendarDates.has(date)));
        });
    };
    document.addEventListener('pointerdown', event => {
        const day = event.target.closest('[data-modal-date]');
        if (!day || day.disabled || event.button !== 0 || !event.isPrimary) return;
        const grid = liveElements().modalGrid;
        if (!grid?.contains(day)) return;
        suppressDatePointerClick = false;
        modalDateDrag = { pointer: event.pointerId, start: day.dataset.modalDate,
            remove: selectedCalendarDates.has(day.dataset.modalDate), original: new Set(selectedCalendarDates), grid };
        event.preventDefault();
        grid.setPointerCapture(event.pointerId);
        previewModalDateDrag(day.dataset.modalDate);
    });
    document.addEventListener('pointermove', event => {
        if (!modalDateDrag || event.pointerId !== modalDateDrag.pointer) return;
        const day = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-modal-date]');
        if (day && !day.disabled && modalDateDrag.grid.contains(day)) previewModalDateDrag(day.dataset.modalDate);
    });
    const finishModalDateDrag = event => {
        if (!modalDateDrag || event.pointerId !== modalDateDrag.pointer) return;
        const drag = modalDateDrag;
        modalDateDrag = null;
        if (event.type === 'pointercancel') {
            selectedCalendarDates.clear();
            drag.original.forEach(date => selectedCalendarDates.add(date));
        }
        suppressDatePointerClick = true;
        if (drag.grid.hasPointerCapture(drag.pointer)) drag.grid.releasePointerCapture(drag.pointer);
        syncCalendarSelection();
        window.setTimeout(() => { suppressDatePointerClick = false; }, 0);
    };
    document.addEventListener('pointerup', finishModalDateDrag);
    document.addEventListener('pointercancel', finishModalDateDrag);
    document.addEventListener('lostpointercapture', finishModalDateDrag);
    document.addEventListener('click', event => {
        if (!suppressDatePointerClick || event.detail === 0) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        suppressDatePointerClick = false;
    }, true);

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

    const isWeekend = (dateString) => {
        const date = normalizedDate(dateString);
        if (!date) return false;
        const [year, month, day] = date.split('-').map(Number);
        const weekday = new Date(year, month - 1, day).getDay();
        return weekday === 0 || weekday === 6;
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

    const groupConsecutiveDates = (dates) => {
        const groups = [];
        [...dates].sort().forEach(date => {
            const group = groups[groups.length - 1];
            const previous = group?.[group.length - 1];
            const dayNumber = value => { const [y, m, d] = value.split('-').map(Number); return Date.UTC(y, m - 1, d) / 86400000; };
            if (previous && dayNumber(date) === dayNumber(previous) + 1) group.push(date);
            else groups.push([date]);
        });
        return groups;
    };
    const appendDateRangeChips = (container, dates) => {
        groupConsecutiveDates(dates).forEach(group => {
            const first = group[0], last = group[group.length - 1];
            const label = first === last ? formatDisplayDate(first) : `${formatDisplayDate(first)} – ${formatDisplayDate(last)}`;
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'appointment-selected-date-chip';
            chip.dataset.selectedDateChip = group.join(',');
            chip.setAttribute('aria-label', `Remove ${label}`);
            chip.textContent = label;
            container.appendChild(chip);
        });
    };
    const groupAdjacentSlots = (slots) => {
        const groups = [];
        [...slots].sort().forEach(slot => {
            const { date, start, end } = parseSlotKey(slot);
            const group = groups[groups.length - 1];
            if (group && group.date === date && group.end === start) {
                group.end = end;
                group.slots.push(slot);
            } else groups.push({ date, start, end, slots: [slot] });
        });
        return groups;
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
        appendDateRangeChips(elements.summary, dates);
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

        groupAdjacentSlots(slots).forEach(({ date, start, end, slots: groupedSlots }) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'appointment-selected-date-chip appointment-selected-slot-chip';
            chip.dataset.selectedSlotChip = groupedSlots.join(',');
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
            cell.style.touchAction = 'none';
            cell.style.userSelect = 'none';

            if (dayNumber < 1 || dayNumber > daysInMonth) {
                cell.classList.add('is-empty');
                cell.disabled = true;
                elements.modalGrid.appendChild(cell);
                continue;
            }

            const date = new Date(year, month, dayNumber);
            const dateString = normalizedDate(`${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(dayNumber).padStart(2, '0')}`);
            const dayColumn = document.querySelector(`[data-week-date="${dateString}"]`);
            const alreadyUnavailable = Boolean(dayColumn?.querySelector('[data-availability-block]:not([data-placeholder-block="true"])'));
            const disabled = isPastDate(dateString) || isWeekend(dateString) || alreadyUnavailable;
            cell.textContent = String(dayNumber);
            cell.dataset.modalDate = dateString;
            cell.classList.toggle('is-selected', selectedCalendarDates.has(dateString));
            cell.classList.toggle('is-today', dateString === minimumAvailabilityDate);
            cell.classList.toggle('is-disabled', disabled);
            cell.disabled = disabled;
            elements.modalGrid.appendChild(cell);
        }

        elements.modalSelected.replaceChildren();
        appendDateRangeChips(elements.modalSelected, sortedSelectedDates());
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
        selectedCalendarDates.forEach((date) => {
            const dayColumn = document.querySelector(`[data-week-date="${date}"]`);
            if (dayColumn?.querySelector('[data-availability-block]:not([data-placeholder-block="true"])')) {
                selectedCalendarDates.delete(date);
                selectedTimeSlots.forEach((slot) => {
                    if (parseSlotKey(slot).date === date) selectedTimeSlots.delete(slot);
                });
            }
        });
        if (!selectedCalendarDates.size && elements.allDay?.checked) elements.allDay.checked = false;
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
        document.querySelectorAll('[data-week-day-toggle]').forEach(header => {
            const selected = isWholeDay && selectedCalendarDates.has(header.dataset.weekDayToggle);
            header.setAttribute('aria-pressed', String(selected));
            header.style.boxShadow = selected ? 'inset 0 -3px #43855b' : '';
        });

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
        if (elements.allDay) elements.allDay.checked = selectedCalendarDates.size > 0;
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
        const targetCell = dayColumn.querySelector(`[data-start-hour="${startHour}"]`);
        if (!availableDragCell(targetCell)) {
            return;
        }
        selectedCalendarDates.add(dayColumn.dataset.weekDate);
        elements.allDay.checked = false;
        syncPartialFields();
        const startValue = String(startHour).padStart(2, '0') + ':00';
        const endValue = String(startHour + 1).padStart(2, '0') + ':00';
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

    let hourDrag = null;
    let suppressHourClick = false;
    const hourCellKey = cell => slotKey(cell.dataset.hourDate,
        `${String(cell.dataset.startHour).padStart(2, '0')}:00`,
        `${String(Number(cell.dataset.startHour) + 1).padStart(2, '0')}:00`);
    const availableDragCell = cell => {
        if (!cell || cell.disabled) return false;
        const hour = Number(cell.dataset.startHour) * 60;
        return !Array.from(cell.closest('[data-week-date]').querySelectorAll('[data-availability-block]')).some(block => {
            if (block.dataset.placeholderBlock === 'true') return false;
            const start = block.dataset.start ? timeToMinutes(block.dataset.start.slice(0, 5)) : 480;
            const end = block.dataset.end ? timeToMinutes(block.dataset.end.slice(0, 5)) : 1020;
            return hour < end && hour + 60 > start;
        });
    };
    const paintHourRange = cell => {
        if (!hourDrag || !cell) return;
        const minDate = [hourDrag.date, cell.dataset.hourDate].sort()[0];
        const maxDate = [hourDrag.date, cell.dataset.hourDate].sort()[1];
        const minHour = Math.min(hourDrag.hour, Number(cell.dataset.startHour));
        const maxHour = Math.max(hourDrag.hour, Number(cell.dataset.startHour));
        selectedTimeSlots.clear();
        hourDrag.slots.forEach(key => selectedTimeSlots.add(key));
        selectedCalendarDates.clear();
        hourDrag.dates.forEach(date => selectedCalendarDates.add(date));
        document.querySelectorAll('[data-hour-slot]').forEach(candidate => {
            const date = candidate.dataset.hourDate, hour = Number(candidate.dataset.startHour);
            if (date < minDate || date > maxDate || hour < minHour || hour > maxHour || !availableDragCell(candidate)) return;
            const key = hourCellKey(candidate);
            if (hourDrag.remove) {
                selectedTimeSlots.delete(key);
                if (!hasSlotsForDate(date)) selectedCalendarDates.delete(date);
            } else {
                selectedTimeSlots.add(key);
                selectedCalendarDates.add(date);
            }
        });
        syncCalendarSelection();
    };
    document.addEventListener('pointerdown', event => {
        const cell = event.target.closest('[data-hour-slot]');
        if (event.button !== 0 || !event.isPrimary || !availableDragCell(cell)) return;
        event.preventDefault();
        const elements = liveElements();
        hourDrag = { pointer: event.pointerId, date: cell.dataset.hourDate, hour: Number(cell.dataset.startHour),
            remove: selectedTimeSlots.has(hourCellKey(cell)), slots: new Set(selectedTimeSlots), dates: new Set(selectedCalendarDates) };
        elements.allDay.checked = false;
        syncPartialFields();
        suppressHourClick = true;
        cell.setPointerCapture(event.pointerId);
        paintHourRange(cell);
    });
    document.addEventListener('pointermove', event => {
        if (!hourDrag || event.pointerId !== hourDrag.pointer) return;
        const target = document.elementFromPoint(event.clientX, event.clientY);
        const day = target?.closest('[data-week-date]');
        if (!day) return;
        const rect = day.getBoundingClientRect();
        const hour = 8 + Math.max(0, Math.min(8, Math.floor((event.clientY - rect.top) / rect.height * 9)));
        paintHourRange(day.querySelector(`[data-start-hour="${hour}"]`));
    });
    ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(type => document.addEventListener(type, event => {
        if (!hourDrag || event.pointerId !== hourDrag.pointer) return;
        hourDrag = null;
        setTimeout(() => { suppressHourClick = false; }, 0);
    }));
    document.addEventListener('click', event => {
        if (suppressHourClick && event.target.closest('[data-week-date]')) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    const validateUnavailableReason = (event, form, submitter) => {
        if (!form?.matches('#availabilityForm, #availabilityEditModal form')) return;
        const action = submitter?.name === 'action'
            ? submitter.value : form.querySelector('input[name="action"]')?.value;
        if (action === 'delete_group' || action === 'delete') return;
        const reason = form.querySelector('textarea[name="reason"]');
        if (!reason || reason.value.trim()) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        confirmAction('Reason required',
            'Enter a reason for the unavailable time before saving. Your selected dates and times have been kept.',
            () => reason.focus({ preventScroll: true }), 'primary');
        const warning = document.getElementById('confirmActionModal');
        warning.setAttribute('role', 'alertdialog');
        warning.querySelector('.btn-ghost')?.remove();
        warning.querySelector('.material-symbols-outlined').textContent = 'warning';
        const okay = warning.querySelector('#confirmActionBtn');
        okay.textContent = 'OK';
        okay.focus();
    };
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-confirm-submit]');
        if (button) validateUnavailableReason(event, button.form, button);
    }, true);
    document.addEventListener('submit', event => {
        validateUnavailableReason(event, event.target, event.submitter);
    }, true);
    document.addEventListener('change', (event) => {
        if (event.target.id === 'allDayToggle') {
            if (!event.target.checked) {
                selectedCalendarDates.clear();
                selectedTimeSlots.clear();
            }
            syncPartialFields();
        }
        if (event.target.id === 'blockDateInput') {
            if (isPastDate(event.target.value)) {
                event.target.value = minimumAvailabilityDate;
            }
            selectAvailabilityDate(event.target.value);
        }
    });

    document.addEventListener('click', (event) => {
        if (event.target.closest('#openAvailabilityWeekPicker')) {
            const modal = document.getElementById('availabilityWeekPicker');
            const selected = new Date(modal.dataset.selectedWeek + 'T00:00:00Z');
            selected.setUTCDate(selected.getUTCDate() + 3);
            weekPickerMonth = new Date(Date.UTC(selected.getUTCFullYear(), selected.getUTCMonth(), 1));
            renderWeekPicker();
            showModal('availabilityWeekPicker');
            const current = modal.querySelector('[aria-current="date"]');
            if (current) { current.scrollIntoView({ block: 'center' }); current.focus({ preventScroll: true }); }
            return;
        }
        const yearButton = event.target.closest('[data-week-picker-month]');
        if (yearButton) {
            weekPickerMonth = new Date(Date.UTC(weekPickerMonth.getUTCFullYear(), weekPickerMonth.getUTCMonth() + Number(yearButton.dataset.weekPickerMonth), 1));
            renderWeekPicker();
            return;
        }
        const dateHeader = event.target.closest('[data-week-day-toggle]');
        if (dateHeader) {
            const date = dateHeader.dataset.weekDayToggle;
            const elements = liveElements();
            if (dateHeader.disabled || isPastDate(date) || !elements.allDay) return;
            const dayColumn = document.querySelector(`[data-week-date="${date}"]`);
            if (dayColumn?.querySelector('[data-availability-block]:not([data-placeholder-block="true"])')) return;
            if (!elements.allDay.checked) {
                selectedCalendarDates.clear();
                selectedTimeSlots.clear();
            }
            elements.allDay.checked = true;
            if (selectedCalendarDates.has(date)) selectedCalendarDates.delete(date);
            else selectedCalendarDates.add(date);
            elements.allDay.checked = selectedCalendarDates.size > 0;
            sortedSelectedSlots().forEach(slot => {
                if (parseSlotKey(slot).date === date) selectedTimeSlots.delete(slot);
            });
            syncPartialFields();
            return;
        }
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
            const dates = (selectedDateChip.dataset.selectedDateChip || '').split(',').filter(Boolean);
            if (dates.length) {
                dates.forEach(date => {
                selectedCalendarDates.delete(date);
                sortedSelectedSlots().forEach((slot) => {
                    if (parseSlotKey(slot).date === date) {
                        selectedTimeSlots.delete(slot);
                    }
                });
                });
                syncCalendarSelection();
            }
            return;
        }

        const selectedSlotChip = event.target.closest('[data-selected-slot-chip]');
        if (selectedSlotChip) {
            const slot = selectedSlotChip.dataset.selectedSlotChip || '';
            if (slot) {
                slot.split(',').forEach(key => selectedTimeSlots.delete(key));
                syncCalendarSelection();
            }
            return;
        }

        const modalDay = event.target.closest('[data-modal-date]');
        if (modalDay) {
            const date = modalDay.dataset.modalDate || '';
            if (modalDay.disabled || !date || isPastDate(date) || isWeekend(date)) {
                return;
            }
            if (selectedCalendarDates.has(date)) {
                selectedCalendarDates.delete(date);
            } else {
                selectedCalendarDates.add(date);
            }
            const elements = liveElements();
            if (elements.allDay) elements.allDay.checked = selectedCalendarDates.size > 0;
            syncCalendarSelection();
            return;
        }

        const weekNav = event.target.closest('[data-availability-week-nav]');
        if (weekNav) {
            event.preventDefault();
            loadAvailabilityWeek(weekNav.href);
            return;
        }

        const block = event.target.closest('[data-availability-block]');
        if (block) {
            event.stopPropagation();
            if (block.dataset.placeholderBlock === 'true' || block.dataset.apeBlock === 'true') {
                return;
            }
            document.getElementById('availabilityBlockDetailsDate').textContent = formatDisplayDate(block.dataset.date);
            document.getElementById('availabilityBlockDetailsTime').textContent = block.dataset.timeLabel;
            document.getElementById('availabilityBlockDetailsReason').textContent = block.dataset.reason || 'No reason recorded';
            showModal('availabilityBlockDetails');
            document.querySelector('#availabilityBlockDetails button').focus();
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
        const block = event.target.closest('[data-availability-block]');
        if (block && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            block.click();
            return;
        }

        const day = event.target.closest('[data-week-date]');
        if (!day || (event.key !== 'Enter' && event.key !== ' ')) {
            return;
        }
        event.preventDefault();
        applyWeekHour(day, 8, true);
    });

    document.addEventListener('click', (event) => {
        const edit = event.target.closest('[data-edit-availability-block]');
        const modal = document.getElementById('availabilityEditModal');
        if (edit && modal) {
            const ids = (edit.dataset.ids || '').split(',').filter(Boolean);
            document.getElementById('availabilityEditIds').innerHTML = ids.map(id => `<input type="hidden" name="ids[]" value="${id}">`).join('');
            document.getElementById('availabilityEditDate').value = edit.dataset.date || '';
            const originalStart = edit.dataset.start || '08:00';
            const originalEnd = edit.dataset.end || '17:00';
            const startSelect = document.getElementById('availabilityEditStart');
            const endSelect = document.getElementById('availabilityEditEnd');
            startSelect.value = originalStart;
            endSelect.value = originalEnd;
            document.getElementById('availabilityEditReason').value = edit.dataset.reason || '';
            document.getElementById('availabilityEditOriginalStart').value = edit.dataset.start || '';
            document.getElementById('availabilityEditOriginalEnd').value = edit.dataset.end || '';
            startSelect.min = originalStart;
            startSelect.max = originalEnd;
            endSelect.min = originalStart;
            endSelect.max = originalEnd;
            if (typeof showModal === 'function') showModal('availabilityEditModal');
        }
        if (event.target.closest('[data-close-availability-edit]')) {
            if (typeof closeModal === 'function') closeModal('availabilityEditModal');
        }
    });
    syncPartialFields();
    syncCalendarSelection();
})();
</script>
