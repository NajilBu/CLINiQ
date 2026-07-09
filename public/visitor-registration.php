<?php

require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/services/RiskClassifier.php';

$errors = [];
$success = null;

$form = [
    'full_name' => '',
    'identifier' => '',
    'category' => '',
    'year_level' => '',
    'department' => '',
    'reason' => '',
    'chief_complaint' => '',
];

function split_visitor_name(string $fullName): array
{
    $parts = preg_split('/\s+/', trim($fullName));
    if (!$parts || count($parts) === 1) {
        return [$fullName, 'Visitor'];
    }

    $lastName = array_pop($parts);
    return [implode(' ', $parts), $lastName];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $value) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    foreach (['full_name', 'identifier', 'category', 'department', 'reason', 'chief_complaint'] as $required) {
        if ($form[$required] === '') {
            $errors[$required] = 'Required';
        }
    }

    if ($form['category'] === 'Student' && $form['year_level'] === '') {
        $errors['year_level'] = 'Required';
    }

    if (!$errors) {
        [$firstName, $lastName] = split_visitor_name($form['full_name']);
        $courseSection = trim(implode(' - ', array_filter([
            $form['category'],
            $form['year_level'],
            $form['department'],
        ])));

        $patientStmt = db()->prepare('SELECT id FROM patients WHERE student_number = ? LIMIT 1');
        $patientStmt->execute([$form['identifier']]);
        $patientId = (int) $patientStmt->fetchColumn();

        if (!$patientId) {
            $token = hash('sha256', 'visitor-' . $form['identifier'] . '-' . microtime(true));
            $insertStmt = db()->prepare(
                'INSERT INTO patients (student_number, first_name, last_name, course_section, emergency_token)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insertStmt->execute([$form['identifier'], $firstName, $lastName, $courseSection, $token]);
            $patientId = (int) db()->lastInsertId();
        }

        $symptoms = trim(
            'Submitted Name: ' . $form['full_name'] . "\n" .
            'Category: ' . $form['category'] . "\n" .
            ($form['year_level'] ? 'Year Level: ' . $form['year_level'] . "\n" : '') .
            'Course/Department: ' . $form['department'] . "\n" .
            'Reason: ' . $form['reason'] . "\n" .
            'Visitor notes: ' . $form['chief_complaint']
        );
        $risk = classify_patient_risk(['symptoms' => $symptoms]);

        $visitStmt = db()->prepare(
            'INSERT INTO clinic_visits (patient_id, visit_datetime, chief_complaint, symptoms, risk_level, risk_score, action_taken, recorded_by)
             VALUES (?, NOW(), ?, ?, ?, ?, ?, NULL)'
        );
        $visitStmt->execute([
            $patientId,
            mb_substr($form['reason'] . ' - ' . $form['chief_complaint'], 0, 255),
            $symptoms,
            $risk['level'],
            $risk['score'],
            'Visitor/patient self-registration. Awaiting clinic assessment.',
        ]);

        $success = [
            'name' => $form['full_name'],
            'identifier' => $form['identifier'],
            'time' => date('h:i A'),
        ];

        $form = array_map(fn () => '', $form);
    }
}

?>
<!doctype html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Visitor / Patient Registration | CLINiQ</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Manrope:wght@700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: '#3F7D52',
                        'primary-fixed': '#e8f6ec',
                        'primary-container': '#23422C',
                        surface: '#f4fbf6',
                        'on-surface': '#17261d',
                        'outline-variant': '#c7dccd'
                    },
                    fontFamily: {
                        headline: ['Manrope', 'sans-serif'],
                        body: ['Inter', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <link href="<?= app_url('assets/css/app.css?v=system-ui-2') ?>" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            background:
                linear-gradient(180deg, rgba(244, 251, 246, 0.96), rgba(248, 252, 249, 1)),
                radial-gradient(circle at 80% 0%, rgba(79, 159, 94, 0.09), transparent 32rem);
        }

        .visit-shell {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .visit-main {
            flex: 1 1 auto;
            display: grid;
            place-items: center;
            padding: 1rem;
        }

        .visit-card {
            width: min(100%, 64rem);
            overflow: hidden;
            border: 1px solid rgba(199, 220, 205, 0.72);
            border-radius: 1rem;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 1px 2px rgba(23, 38, 29, 0.04), 0 24px 60px rgba(23, 38, 29, 0.08);
        }

        .visit-field {
            position: relative;
        }

        .visit-field .material-symbols-outlined {
            position: absolute;
            left: 0.55rem;
            top: 50%;
            transform: translateY(-50%);
            color: #cbd5e1;
            font-size: 1.2rem;
            pointer-events: none;
        }

        .visit-field textarea + .material-symbols-outlined,
        .visit-field .textarea-icon {
            top: 0.9rem;
            transform: none;
        }

        .visit-input {
            width: 100%;
            min-height: 2.7rem;
            padding: 0.65rem 0.5rem 0.65rem 2.25rem;
            border: 0;
            border-bottom: 2px solid #e2e8f0;
            border-radius: 0;
            background: transparent;
            color: #17261d;
            font-size: 0.875rem;
            font-weight: 500;
            outline: none;
            transition: border-color 0.16s ease, box-shadow 0.16s ease;
        }

        .visit-input:focus {
            border-color: #3F7D52;
            box-shadow: 0 8px 18px rgba(79, 159, 94, 0.08);
        }

        .visit-input.input-error {
            border-color: #ef4444;
        }

        .visit-input::placeholder {
            color: #cbd5e1;
            font-weight: 400;
        }

        .visit-label {
            display: block;
            margin: 0 0 0.35rem 0.25rem;
            color: #94a3b8;
            font-size: 0.625rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

    </style>
</head>
<body class="font-body text-on-surface overflow-x-hidden">
<div class="visit-shell">
    <?php render_cliniq_entry_header([
        'homeUrl' => app_url('index.php'),
        'logoUrl' => app_url('assets/img/clinic-logo.png'),
    ]); ?>

    <main class="visit-main">
        <section class="visit-card">
            <div class="px-5 sm:px-10 py-6 border-b border-outline-variant/20 text-center">
                <div class="inline-flex items-center justify-center w-10 h-10 bg-primary-fixed text-primary rounded-xl mb-3 border border-outline-variant/40">
                    <span class="material-symbols-outlined">edit_note</span>
                </div>
                <h1 class="font-headline font-extrabold text-2xl sm:text-3xl text-[#17261d] mb-1">Clinic Visit Log Form</h1>
                <p class="text-xs sm:text-sm font-bold text-slate-500">Please provide your details for clinic assessment.</p>
            </div>

            <?php if ($success): ?>
                <div class="px-5 sm:px-10 py-10 text-center">
                    <div class="inline-flex items-center justify-center w-14 h-14 bg-emerald-50 text-emerald-600 rounded-xl mb-4">
                        <span class="material-symbols-outlined text-[2rem]">check_circle</span>
                    </div>
                    <h2 class="font-headline text-2xl font-extrabold text-[#17261d] mb-2">Visit log recorded</h2>
                    <p class="text-sm text-slate-500 mb-4 max-w-xl mx-auto leading-relaxed">
                        Thank you, <?= e($success['name']) ?>. Your clinic visit was recorded at <?= e($success['time']) ?>.
                        Please wait nearby and listen for your name to be called by the clinic staff.
                    </p>
                    <div class="inline-flex items-center gap-2 rounded-xl bg-slate-50 border border-slate-100 px-4 py-3 text-xs font-black text-slate-500 uppercase tracking-widest">
                        <span class="material-symbols-outlined text-primary text-[16px]">badge</span>
                        <?= e($success['identifier']) ?>
                    </div>
                    <p class="text-xs text-slate-400 mt-5 mb-0">
                        Returning to the clinic visit log form in <span id="redirectCountdown" class="font-semibold text-primary">5</span> seconds.
                    </p>
                    <div class="mt-7 flex flex-col sm:flex-row items-center justify-center gap-3">
                        <a href="<?= app_url('visitor-registration.php') ?>" class="btn btn-primary text-decoration-none">
                            <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                            Back to form now
                        </a>
                        <a href="<?= app_url('index.php') ?>" class="btn btn-ghost text-decoration-none">Return to first page</a>
                    </div>
                </div>
            <?php else: ?>
                <form method="post" class="px-5 sm:px-10 py-6 space-y-5">
                    <?php if ($errors): ?>
                        <div class="rounded-xl bg-red-50 border border-red-100 text-red-700 px-4 py-3 text-sm font-bold">
                            Please complete the required fields before registering your visit.
                        </div>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-5">
                        <div>
                            <label class="visit-label" for="full_name">Full Name</label>
                            <div class="visit-field">
                                <span class="material-symbols-outlined">person_outline</span>
                                <input class="visit-input <?= isset($errors['full_name']) ? 'input-error' : '' ?>" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" placeholder="e.g. Juan dela Cruz" required>
                            </div>
                        </div>

                        <div>
                            <label class="visit-label" for="identifier">Student / Staff ID</label>
                            <div class="visit-field">
                                <span class="material-symbols-outlined">badge</span>
                                <input class="visit-input <?= isset($errors['identifier']) ? 'input-error' : '' ?>" id="identifier" name="identifier" value="<?= e($form['identifier']) ?>" placeholder="e.g. 2026-00123" required>
                            </div>
                        </div>

                        <div>
                            <label class="visit-label" for="category">Category</label>
                            <div class="visit-field">
                                <span class="material-symbols-outlined">group</span>
                                <select class="visit-input <?= isset($errors['category']) ? 'input-error' : '' ?>" id="category" name="category" required>
                                    <option value="">Select Category</option>
                                    <?php foreach (['Student', 'Staff', 'Faculty', 'Guest'] as $category): ?>
                                        <option <?= $form['category'] === $category ? 'selected' : '' ?>><?= e($category) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div id="year-level-wrap" class="<?= $form['category'] === 'Student' ? '' : 'hidden' ?>">
                            <label class="visit-label" for="year_level">Year Level</label>
                            <div class="visit-field">
                                <span class="material-symbols-outlined">grade</span>
                                <select class="visit-input <?= isset($errors['year_level']) ? 'input-error' : '' ?>" id="year_level" name="year_level">
                                    <option value="">Select Year</option>
                                    <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $year): ?>
                                        <option <?= $form['year_level'] === $year ? 'selected' : '' ?>><?= e($year) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="visit-label" for="department">Course / Department</label>
                            <div class="visit-field">
                                <span class="material-symbols-outlined">school</span>
                                <select class="visit-input <?= isset($errors['department']) ? 'input-error' : '' ?>" id="department" name="department" required>
                                    <option value="">Select Dept/Course</option>
                                    <?php foreach (['College of Computer Studies', 'College of Nursing', 'Arts & Sciences', 'Administrative Office', 'Guest / Visitor'] as $department): ?>
                                        <option <?= $form['department'] === $department ? 'selected' : '' ?>><?= e($department) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="visit-label" for="reason">Reason</label>
                            <div class="visit-field">
                                <span class="material-symbols-outlined">medical_information</span>
                                <select class="visit-input <?= isset($errors['reason']) ? 'input-error' : '' ?>" id="reason" name="reason" required>
                                    <option value="">Select Reason</option>
                                    <?php foreach (['APE', 'Health Monitoring', 'Pain Management', 'Medical Consult', 'Dental Consult', 'Wound Care'] as $reason): ?>
                                        <option <?= $form['reason'] === $reason ? 'selected' : '' ?>><?= e($reason) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="visit-label" for="chief_complaint">Chief Complaint</label>
                        <div class="visit-field">
                            <span class="material-symbols-outlined textarea-icon">notes</span>
                            <textarea class="visit-input <?= isset($errors['chief_complaint']) ? 'input-error' : '' ?>" id="chief_complaint" name="chief_complaint" rows="2" placeholder="Briefly describe your symptoms or reason for visiting..." required><?= e($form['chief_complaint']) ?></textarea>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_auto] gap-4 items-end border-t border-slate-100 pt-5">
                        <div>
                            <label class="visit-label">Time Recording</label>
                            <div class="inline-flex items-center gap-2 bg-slate-50 border border-slate-100 rounded-xl px-4 py-3">
                                <span class="material-symbols-outlined text-primary text-[18px]">schedule</span>
                                <span class="text-sm font-bold text-slate-600">Time in is set automatically on submit</span>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <button class="btn btn-primary min-h-[3rem]" type="submit">
                                Register Clinic Visit
                                <span class="material-symbols-outlined text-[18px]">arrow_right_alt</span>
                            </button>
                            <button class="btn btn-ghost min-h-[3rem]" type="reset" aria-label="Reset form">
                                <span class="material-symbols-outlined text-[18px]">refresh</span>
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>

            <div class="px-5 sm:px-10 py-4 bg-slate-50/70 border-t border-outline-variant/20 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex flex-wrap items-center gap-5">
                    <span class="inline-flex items-center gap-2 text-[9px] font-black text-slate-400 uppercase tracking-widest">
                        <span class="material-symbols-outlined text-[14px] text-primary">verified_user</span>
                        Secure Gateway
                    </span>
                    <span class="inline-flex items-center gap-2 text-[9px] font-black text-slate-400 uppercase tracking-widest">
                        <span class="material-symbols-outlined text-[14px] text-primary">timer</span>
                        Auto-Timestamp
                    </span>
                </div>
                <span class="text-[9px] font-black text-slate-300 uppercase tracking-widest">Clinic DTR compatible</span>
            </div>
        </section>
    </main>
</div>

<script>
    const category = document.getElementById('category');
    const yearLevelWrap = document.getElementById('year-level-wrap');
    const yearLevel = document.getElementById('year_level');

    function syncYearLevel() {
        const isStudent = category && category.value === 'Student';
        yearLevelWrap?.classList.toggle('hidden', !isStudent);
        if (yearLevel) {
            yearLevel.required = isStudent;
            if (!isStudent) yearLevel.value = '';
        }
    }

    category?.addEventListener('change', syncYearLevel);
    syncYearLevel();

    <?php if ($success): ?>
    const redirectTarget = '<?= app_url('visitor-registration.php') ?>';
    const countdown = document.getElementById('redirectCountdown');
    let secondsRemaining = 5;

    const redirectTimer = window.setInterval(() => {
        secondsRemaining -= 1;
        if (countdown) {
            countdown.textContent = String(Math.max(secondsRemaining, 0));
        }

        if (secondsRemaining <= 0) {
            window.clearInterval(redirectTimer);
            window.location.href = redirectTarget;
        }
    }, 1000);
    <?php endif; ?>
</script>
</body>
</html>
