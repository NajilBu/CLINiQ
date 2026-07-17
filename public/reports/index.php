<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/VisitWorkflow.php';
require_login();

// ── Date range filter ───────────────────────────────────────
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');

$stats = [
    'visits_today'   => db()->query('SELECT COUNT(*) AS total FROM clinic_visits WHERE DATE(visit_datetime) = CURDATE()')->fetch()['total'] ?? 0,
    'visits_range'   => 0,
    'alerts_pending' => db()->query("SELECT COUNT(*) AS total FROM nurse_alerts WHERE status = 'Pending'")->fetch()['total'] ?? 0,
    'low_stock'      => db()->query('SELECT COUNT(*) AS total FROM inventory_items WHERE quantity <= reorder_level')->fetch()['total'] ?? 0,
    'total_patients'  => db()->query('SELECT COUNT(*) AS total FROM patients')->fetch()['total'] ?? 0,
];

// Visits in date range
$rangeStmt = db()->prepare('SELECT COUNT(*) AS total FROM clinic_visits WHERE DATE(visit_datetime) BETWEEN ? AND ?');
$rangeStmt->execute([$dateFrom, $dateTo]);
$stats['visits_range'] = (int)$rangeStmt->fetch()['total'];

// Common complaints in range
$complaints = db()->prepare("
    SELECT chief_complaint, COUNT(*) AS total
    FROM clinic_visits
    WHERE DATE(visit_datetime) BETWEEN ? AND ?
    GROUP BY chief_complaint
    ORDER BY total DESC, chief_complaint ASC
    LIMIT 10
");
$complaints->execute([$dateFrom, $dateTo]);
$complaints = $complaints->fetchAll();
$maxComplaint = $complaints ? max(array_column($complaints, 'total')) : 1;

// Visit status distribution
$statusDist = db()->prepare("
    SELECT COALESCE(status, 'Unaddressed') AS status, COUNT(*) AS total
    FROM clinic_visits
    WHERE DATE(visit_datetime) BETWEEN ? AND ?
    GROUP BY COALESCE(status, 'Unaddressed')
    ORDER BY FIELD(COALESCE(status, 'Unaddressed'), 'Unaddressed', 'Active', 'Completed', 'Cancelled')
");
$statusDist->execute([$dateFrom, $dateTo]);
$statusDist = $statusDist->fetchAll();

// Monthly trend (last 6 months)
$monthlyTrend = db()->query("
    SELECT DATE_FORMAT(visit_datetime, '%Y-%m') AS month_key,
           DATE_FORMAT(visit_datetime, '%b %Y') AS month_label,
           COUNT(*) AS total
    FROM clinic_visits
    WHERE visit_datetime >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key ASC
")->fetchAll();
$maxMonthly = $monthlyTrend ? max(array_column($monthlyTrend, 'total')) : 1;

render_header('Reports');
?>

<!-- ═══ Title ═══ -->
<?php render_clinic_command_header(
    'Reports',
    'Reports & Analytics',
    'Clinic summaries, visit trends, alerts, and inventory warnings.',
    '<a class="btn btn-primary text-decoration-none" href="export.php?from=' . e($dateFrom) . '&to=' . e($dateTo) . '"><span class="material-symbols-outlined text-[20px]">download</span>Export CSV</a>'
); ?>

<!-- ═══ Date Range Filter ═══ -->
<form method="get" class="clinic-card p-4 flex flex-col sm:flex-row items-end gap-4">
    <div class="flex-1">
        <label class="clinic-label">From</label>
        <input class="clinic-input" type="date" name="from" value="<?= e($dateFrom) ?>">
    </div>
    <div class="flex-1">
        <label class="clinic-label">To</label>
        <input class="clinic-input" type="date" name="to" value="<?= e($dateTo) ?>">
    </div>
    <button class="btn btn-outline" type="submit">
        <span class="material-symbols-outlined text-[18px]">filter_list</span> Apply
    </button>
</form>

<!-- ═══ Stats Cards ═══ -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
    <div class="bg-white p-6 rounded-[2rem] border border-outline-variant/20 shadow-sm">
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Visits in Range</p>
        <p class="text-3xl font-headline font-extrabold text-slate-800 mt-2"><?= (int) $stats['visits_range'] ?></p>
        <p class="text-xs font-bold text-slate-400 mt-1"><?= e(date('M d', strtotime($dateFrom))) ?> – <?= e(date('M d, Y', strtotime($dateTo))) ?></p>
    </div>
    <div class="bg-white p-6 rounded-[2rem] border border-outline-variant/20 shadow-sm">
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Visits Today</p>
        <p class="text-3xl font-headline font-extrabold text-slate-800 mt-2"><?= (int) $stats['visits_today'] ?></p>
    </div>
    <div class="bg-white p-6 rounded-[2rem] border border-outline-variant/20 shadow-sm">
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Pending Alerts</p>
        <p class="text-3xl font-headline font-extrabold text-slate-800 mt-2"><?= (int) $stats['alerts_pending'] ?></p>
    </div>
    <div class="bg-white p-6 rounded-[2rem] border border-outline-variant/20 shadow-sm">
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Patients</p>
        <p class="text-3xl font-headline font-extrabold text-slate-800 mt-2"><?= (int) $stats['total_patients'] ?></p>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <!-- ═══ Common Complaints ═══ -->
    <section class="bg-white rounded-[2rem] border border-outline-variant/20 shadow-sm overflow-hidden">
        <div class="p-6 border-b border-slate-100">
            <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Common Complaints</h2>
            <p class="text-xs font-bold text-slate-500 mb-0">Most frequent visit reasons in selected period.</p>
        </div>
        <div class="p-6 space-y-3">
            <?php foreach ($complaints as $complaint):
                $pct = round(((int)$complaint['total'] / $maxComplaint) * 100);
            ?>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm font-bold text-slate-700"><?= e($complaint['chief_complaint']) ?></span>
                        <strong class="text-sm text-primary"><?= (int) $complaint['total'] ?></strong>
                    </div>
                    <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full bg-primary/60 rounded-full transition-all" style="width: <?= $pct ?>%"></div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$complaints): ?>
                <p class="text-sm font-bold text-slate-500 mb-0">No data for this period.</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- Visit Status Breakdown -->
    <section class="bg-white rounded-[2rem] border border-outline-variant/20 shadow-sm overflow-hidden">
        <div class="p-6 border-b border-slate-100">
            <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Visit Status Breakdown</h2>
            <p class="text-xs font-bold text-slate-500 mb-0">Breakdown of visit workflow states in selected period.</p>
        </div>
        <div class="p-6 space-y-4">
            <?php
            $statusColors = [
                'Unaddressed' => ['bg' => 'bg-amber-100', 'fill' => 'bg-amber-400', 'text' => 'text-amber-700'],
                'Active'      => ['bg' => 'bg-blue-100', 'fill' => 'bg-blue-400', 'text' => 'text-blue-700'],
                'Completed'   => ['bg' => 'bg-emerald-100', 'fill' => 'bg-emerald-400', 'text' => 'text-emerald-700'],
                'Cancelled'   => ['bg' => 'bg-slate-100', 'fill' => 'bg-slate-400', 'text' => 'text-slate-700'],
            ];
            $totalStatusVisits = array_sum(array_column($statusDist, 'total')) ?: 1;
            foreach ($statusDist as $statusRow):
                $status = $statusRow['status'] ?: 'Unaddressed';
                $colors = $statusColors[$status] ?? $statusColors['Unaddressed'];
                $pct = round(((int)$statusRow['total'] / $totalStatusVisits) * 100);
            ?>
                <div class="flex items-center gap-4">
                    <span class="badge <?= visit_status_badge_class($status) ?> w-28 justify-center"><?= e($status) ?></span>
                    <div class="flex-1 h-3 <?= $colors['bg'] ?> rounded-full overflow-hidden">
                        <div class="h-full <?= $colors['fill'] ?> rounded-full transition-all" style="width: <?= $pct ?>%"></div>
                    </div>
                    <span class="text-sm font-extrabold <?= $colors['text'] ?> w-16 text-right"><?= (int)$statusRow['total'] ?> (<?= $pct ?>%)</span>
                </div>
            <?php endforeach; ?>
            <?php if (!$statusDist): ?>
                <p class="text-sm font-bold text-slate-500 mb-0">No data for this period.</p>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- ═══ Monthly Trend ═══ -->
<section class="bg-white rounded-[2rem] border border-outline-variant/20 shadow-sm overflow-hidden">
    <div class="p-6 border-b border-slate-100">
        <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Monthly Visit Trend</h2>
        <p class="text-xs font-bold text-slate-500 mb-0">Visits per month over the last 6 months.</p>
    </div>
    <div class="p-6">
        <?php if ($monthlyTrend): ?>
        <div class="flex items-end gap-4 h-48">
            <?php foreach ($monthlyTrend as $month):
                $heightPct = round(((int)$month['total'] / $maxMonthly) * 100);
            ?>
                <div class="flex-1 flex flex-col items-center gap-2">
                    <span class="text-xs font-extrabold text-primary"><?= (int)$month['total'] ?></span>
                    <div class="w-full bg-primary/15 rounded-t-lg relative" style="height: <?= max($heightPct, 5) ?>%;">
                        <div class="absolute inset-0 bg-primary/60 rounded-t-lg"></div>
                    </div>
                    <span class="text-[10px] font-bold text-slate-400"><?= e($month['month_label']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <p class="text-sm font-bold text-slate-500 mb-0">No monthly data available.</p>
        <?php endif; ?>
    </div>
</section>

<?php render_footer(); ?>
