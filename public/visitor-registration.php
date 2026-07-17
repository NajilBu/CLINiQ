<?php

require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/services/VisitWorkflow.php';
require_once __DIR__ . '/../app/services/InventoryWorkflow.php';
ensure_visit_workflow_schema();
ensure_inventory_workflow_schema();

const VISITOR_REASON_BORROW_EQUIPMENT = 'Borrow Equipment';

$errors = [];
$success = null;
$reasonOptions = array_values(array_unique(array_merge(visit_purposes(), [VISITOR_REASON_BORROW_EQUIPMENT])));
$equipmentItems = db()->query("
    SELECT id, item_name, quantity, unit
    FROM inventory_items
    WHERE archived_at IS NULL
      AND LOWER(COALESCE(category, '')) LIKE '%equipment%'
    ORDER BY item_name
")->fetchAll();

$form = [
    'full_name' => '',
    'identifier' => '',
    'category' => '',
    'year_level' => '',
    'department' => '',
    'reason' => '',
    'chief_complaint' => '',
    'borrow_item_id' => '',
    'borrowed_quantity' => '1',
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

function infer_year_level(string $courseSection): string
{
    if (preg_match('/(?:^|\s)([1-4])(?:st|nd|rd|th)?(?:\s*year|\-\d+|\s|$)/i', $courseSection, $match)) {
        return match ($match[1]) {
            '1' => '1st Year',
            '2' => '2nd Year',
            '3' => '3rd Year',
            '4' => '4th Year',
            default => '',
        };
    }

    return '';
}

if (isset($_GET['lookup_identifier'])) {
    $identifier = trim((string) $_GET['lookup_identifier']);
    header('Content-Type: application/json');

    if (!is_valid_student_id($identifier)) {
        echo json_encode(['found' => false]);
        exit;
    }

    $stmt = db()->prepare('SELECT first_name, last_name, course_section FROM patients WHERE student_number = ? LIMIT 1');
    $stmt->execute([$identifier]);
    $patient = $stmt->fetch();

    if (!$patient) {
        echo json_encode(['found' => false]);
        exit;
    }

    echo json_encode([
        'found' => true,
        'name' => trim($patient['first_name'] . ' ' . $patient['last_name']),
        'course' => $patient['course_section'] ?: '',
        'yearLevel' => infer_year_level($patient['course_section'] ?? ''),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $value) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $isBorrowingEquipment = $form['reason'] === VISITOR_REASON_BORROW_EQUIPMENT;
    $requiredFields = ['full_name', 'identifier', 'category', 'department', 'reason'];
    if (!$isBorrowingEquipment) {
        $requiredFields[] = 'chief_complaint';
    }

    foreach ($requiredFields as $required) {
        if ($form[$required] === '') {
            $errors[$required] = 'Required';
        }
    }

    $matchedPatient = null;
    if ($form['identifier'] !== '') {
        $matchedPatientStmt = db()->prepare('SELECT id, first_name, last_name, course_section FROM patients WHERE student_number = ? LIMIT 1');
        $matchedPatientStmt->execute([$form['identifier']]);
        $matchedPatient = $matchedPatientStmt->fetch() ?: null;
        if ($matchedPatient) {
            $form['full_name'] = trim($matchedPatient['first_name'] . ' ' . $matchedPatient['last_name']);
            $form['category'] = 'Student';
            $form['department'] = $matchedPatient['course_section'] ?: $form['department'];
            if ($form['year_level'] === '') {
                $form['year_level'] = infer_year_level($matchedPatient['course_section'] ?? '');
            }
            unset($errors['full_name'], $errors['category'], $errors['department'], $errors['year_level']);
        }
    }

    if ($form['category'] === 'Student' && $form['year_level'] === '') {
        $errors['year_level'] = 'Required';
    }

    if ($form['category'] === 'Student' && !is_valid_student_id($form['identifier'])) {
        $errors['identifier'] = 'Use the format ' . STUDENT_ID_FORMAT_LABEL;
    }

    if ($isBorrowingEquipment) {
        $borrowItemId = (int) $form['borrow_item_id'];
        $borrowedQuantity = max(1, (int) $form['borrowed_quantity']);
        if ($borrowItemId <= 0) {
            $errors['borrow_item_id'] = 'Required';
        }
        if ($borrowedQuantity <= 0) {
            $errors['borrowed_quantity'] = 'Required';
        }
    }

    if (!$errors) {
        [$firstName, $lastName] = split_visitor_name($form['full_name']);
        $courseSection = trim(implode(' - ', array_filter([
            $form['category'],
            $form['year_level'],
            $form['department'],
        ])));

        if ($isBorrowingEquipment) {
            $db = db();
            $borrowedQuantity = max(1, (int) $form['borrowed_quantity']);

            try {
                $db->beginTransaction();

                $itemStmt = $db->prepare("
                    SELECT id, item_name, quantity, unit
                    FROM inventory_items
                    WHERE id = ?
                      AND archived_at IS NULL
                      AND LOWER(COALESCE(category, '')) LIKE '%equipment%'
                    FOR UPDATE
                ");
                $itemStmt->execute([(int) $form['borrow_item_id']]);
                $item = $itemStmt->fetch();

                if (!$item) {
                    throw new RuntimeException('Selected equipment is no longer available.');
                }

                if ((int) $item['quantity'] < $borrowedQuantity) {
                    throw new RuntimeException('Not enough available equipment to lend. Available: ' . (int) $item['quantity'] . ' ' . ($item['unit'] ?: 'unit') . '.');
                }

                $db->prepare('UPDATE inventory_items SET quantity = quantity - ? WHERE id = ?')
                    ->execute([$borrowedQuantity, (int) $item['id']]);
                $user = current_user();
                $db->prepare('
                    INSERT INTO inventory_loans (
                        item_id, borrower_name, borrower_identifier, borrowed_quantity, borrowed_by
                    ) VALUES (?, ?, ?, ?, ?)
                ')->execute([
                    (int) $item['id'],
                    $form['full_name'],
                    $form['identifier'] !== '' ? $form['identifier'] : null,
                    $borrowedQuantity,
                    (int) ($user['id'] ?? 0) ?: null,
                ]);

                $db->commit();
                $success = [
                    'type' => 'borrow',
                    'name' => $form['full_name'],
                    'identifier' => $form['identifier'],
                    'time' => date('h:i A'),
                    'item' => $item['item_name'],
                    'quantity' => $borrowedQuantity,
                    'unit' => $item['unit'] ?: 'unit',
                ];

                $form = array_merge(array_map(fn () => '', $form), ['borrowed_quantity' => '1']);
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $errors['borrow_item_id'] = $exception->getMessage();
            }
        }
    }

    if (!$errors && !$success) {
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
        $visitStmt = db()->prepare(
            'INSERT INTO clinic_visits (patient_id, visit_datetime, chief_complaint, symptoms, status, visit_purpose, visit_source, action_taken, recorded_by)
             VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, NULL)'
        );
        $visitStmt->execute([
            $patientId,
            mb_substr($form['reason'] . ' - ' . $form['chief_complaint'], 0, 255),
            $symptoms,
            'Unaddressed',
            normalize_visit_purpose($form['reason']),
            'Self Logbook',
            'Visitor/patient self-registration. Awaiting clinic assessment.',
        ]);

        $success = [
            'type' => 'visit',
            'name' => $form['full_name'],
            'identifier' => $form['identifier'],
            'time' => date('h:i A'),
        ];

        $form = array_merge(array_map(fn () => '', $form), ['borrowed_quantity' => '1']);
    }
}

?>
<!doctype html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Visitor / Patient Registration | CLINiQ</title>
    <link href="<?= app_url('assets/vendor/fonts/inter-manrope.css?v=offline-1') ?>" rel="stylesheet">
    <link href="<?= app_url('assets/vendor/fonts/material-symbols.css?v=offline-1') ?>" rel="stylesheet">
    <script src="<?= app_url('assets/vendor/tailwind/tailwind-cdn.js?v=offline-1') ?>"></script>
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
    <link href="<?= app_url('assets/css/app.css?v=cancel-icon-1') ?>" rel="stylesheet">
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
            align-items: center;
            justify-items: center;
            padding: clamp(4.75rem, 8vh, 6.25rem) 1rem 1rem;
        }

        .visit-main.is-borrowing {
            align-items: start;
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
            min-height: 2.45rem;
            padding: 0.55rem 0.5rem 0.55rem 2.2rem;
            border: 0;
            border-bottom: 2px solid #e2e8f0;
            border-radius: 0;
            background: transparent;
            color: #17261d;
            font-size: 0.8125rem;
            font-weight: 500;
            line-height: 1.35;
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
            font-size: 0.8125rem;
            font-weight: 400;
            line-height: 1.35;
        }

        .visit-label {
            display: block;
            margin: 0 0 0.28rem 0.25rem;
            color: #94a3b8;
            font-size: 0.625rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .visit-card-header {
            padding: clamp(1.15rem, 3vh, 1.9rem) 2rem clamp(1rem, 2.4vh, 1.45rem);
        }

        .visit-card-icon {
            width: 2.65rem;
            height: 2.65rem;
            margin-bottom: 0.65rem;
        }

        .visit-card-title {
            font-size: clamp(1.65rem, 2vw, 2.15rem);
            line-height: 1.08;
        }

        .visit-card-form {
            padding: clamp(1rem, 2.3vh, 1.45rem) clamp(1.25rem, 3vw, 2.25rem);
        }

        .visit-form-grid {
            row-gap: clamp(0.85rem, 1.8vh, 1.2rem);
        }

        .borrow-equipment-panel {
            padding: 0.85rem 1rem;
        }

        .visit-notes {
            min-height: 3.15rem;
            max-height: 4.25rem;
        }

        .visit-actions-row {
            padding-top: clamp(0.85rem, 1.8vh, 1.15rem);
        }

        .visit-time-pill {
            min-height: 2.65rem;
            padding: 0.65rem 0.9rem;
        }

        .visit-card-footer {
            padding: 0.75rem clamp(1.25rem, 3vw, 2.25rem);
        }

        .visit-lookup-status {
            min-height: 1rem;
            margin: 0.35rem 0 0 0.25rem;
            color: #64748b;
            font-size: 0.68rem;
            font-weight: 800;
        }

        .visit-lookup-status.is-found {
            color: #15803d;
        }

        .visit-lookup-status.is-missing {
            color: #b91c1c;
        }

        @media (max-width: 768px) {
            .visit-main {
                align-items: start;
                padding: 1rem;
            }
        }

        @media (min-width: 769px) and (max-height: 930px) {
            .visit-main {
                padding-top: 4.25rem;
                padding-bottom: 0.6rem;
            }

            .visit-card {
                width: min(100%, 64rem);
            }

            .visit-card-header {
                padding-top: 0.95rem;
                padding-bottom: 0.9rem;
            }

            .visit-card-icon {
                width: 2.2rem;
                height: 2.2rem;
                margin-bottom: 0.45rem;
            }

            .visit-card-title {
                font-size: 1.8rem;
            }

            .visit-card-subtitle {
                font-size: 0.78rem;
                margin-top: 0.15rem;
            }

            .visit-card-form {
                padding-top: 0.95rem;
                padding-bottom: 0.9rem;
            }

            .visit-input {
                min-height: 2.15rem;
                padding-top: 0.42rem;
                padding-bottom: 0.42rem;
            }

            .visit-label {
                margin-bottom: 0.2rem;
                font-size: 0.58rem;
            }

            .visit-lookup-status {
                min-height: 0.85rem;
                margin-top: 0.22rem;
                font-size: 0.66rem;
            }

            .borrow-equipment-panel {
                padding: 0.65rem 0.8rem;
            }

            .visit-notes {
                min-height: 2.55rem;
                max-height: 3rem;
            }

            .visit-time-pill {
                min-height: 2.35rem;
                padding: 0.5rem 0.75rem;
            }

            .visit-card-footer {
                padding-top: 0.55rem;
                padding-bottom: 0.55rem;
            }
        }

    </style>
</head>
<body class="font-body text-on-surface overflow-x-hidden">
<div class="visit-shell">
    <?php render_cliniq_entry_header([
        'homeUrl' => app_url('index.php'),
        'logoUrl' => app_url('assets/img/clinic-logo.png'),
    ]); ?>

    <main class="visit-main <?= $form['reason'] === VISITOR_REASON_BORROW_EQUIPMENT ? 'is-borrowing' : '' ?>">
        <section class="visit-card">
            <div class="visit-card-header border-b border-outline-variant/20 text-center">
                <div class="visit-card-icon inline-flex items-center justify-center bg-primary-fixed text-primary rounded-xl border border-outline-variant/40">
                    <span class="material-symbols-outlined">edit_note</span>
                </div>
                <h1 class="visit-card-title font-headline font-extrabold text-[#17261d] mb-1">Clinic Visit Log Form</h1>
                <p class="visit-card-subtitle text-xs sm:text-sm font-bold text-slate-500">Please provide your details for clinic assessment.</p>
            </div>

            <?php if ($success): ?>
                <div class="px-5 sm:px-10 py-10 text-center">
                    <div class="inline-flex items-center justify-center w-14 h-14 bg-emerald-50 text-emerald-600 rounded-xl mb-4">
                        <span class="material-symbols-outlined text-[2rem]">check_circle</span>
                    </div>
                    <h2 class="font-headline text-2xl font-extrabold text-[#17261d] mb-2"><?= ($success['type'] ?? 'visit') === 'borrow' ? 'Equipment loan recorded' : 'Visit log recorded' ?></h2>
                    <p class="text-sm text-slate-500 mb-4 max-w-xl mx-auto leading-relaxed">
                        <?php if (($success['type'] ?? 'visit') === 'borrow'): ?>
                            Thank you, <?= e($success['name']) ?>. <?= e($success['quantity'] . ' ' . $success['unit'] . ' of ' . $success['item']) ?> was recorded as borrowed at <?= e($success['time']) ?>.
                            Please return the item to the clinic when finished.
                        <?php else: ?>
                            Thank you, <?= e($success['name']) ?>. Your clinic visit was recorded at <?= e($success['time']) ?>.
                            Please wait nearby and listen for your name to be called by the clinic staff.
                        <?php endif; ?>
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
                <form method="post" class="visit-card-form space-y-4">
                    <?php if ($errors): ?>
                        <div class="rounded-xl bg-red-50 border border-red-100 text-red-700 px-4 py-3 text-sm font-bold">
                            Please complete the required fields before registering your visit.
                        </div>
                    <?php endif; ?>

                    <div class="visit-form-grid grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-5">
                        <div>
                            <label class="visit-label" for="identifier">Student / Staff ID</label>
                            <div class="visit-field">
                                <span class="material-symbols-outlined">badge</span>
                                <input class="visit-input <?= isset($errors['identifier']) ? 'input-error' : '' ?>" id="identifier" name="identifier" value="<?= e($form['identifier']) ?>" placeholder="00-00000" data-student-id-format data-student-category-source="category" autocomplete="off" required>
                            </div>
                            <div id="visitorLookupStatus" class="visit-lookup-status">Type your ID to load existing student details.</div>
                        </div>

                        <div>
                            <label class="visit-label" for="full_name">Full Name</label>
                            <div class="visit-field">
                                <span class="material-symbols-outlined">person_outline</span>
                                <input class="visit-input <?= isset($errors['full_name']) ? 'input-error' : '' ?>" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" placeholder="e.g. Juan dela Cruz" required>
                            </div>
                        </div>

                        <div>
                            <label class="visit-label" for="category">Category</label>
                            <div class="visit-field">
                                <span class="material-symbols-outlined">group</span>
                                <select class="visit-input <?= isset($errors['category']) ? 'input-error' : '' ?>" id="category" name="category" required>
                                    <option value="">Select Category</option>
                                    <?php foreach (dropdown_options('person_category') as $category): ?>
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
                                    <?php foreach (dropdown_options('year_level') as $year): ?>
                                        <option <?= $form['year_level'] === $year ? 'selected' : '' ?>><?= e($year) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="visit-label" for="department">Course / Department</label>
                            <div class="visit-field">
                                <span class="material-symbols-outlined">school</span>
                                <input class="visit-input <?= isset($errors['department']) ? 'input-error' : '' ?>" id="department" name="department" value="<?= e($form['department']) ?>" list="departmentOptions" placeholder="Course, section, or department" required>
                                <datalist id="departmentOptions">
                                    <?php foreach (dropdown_options('department') as $department): ?>
                                        <option value="<?= e($department) ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                        </div>

                        <div>
                            <label class="visit-label" for="reason">Reason</label>
                            <div class="visit-field">
                                <span class="material-symbols-outlined">medical_information</span>
                                <select class="visit-input <?= isset($errors['reason']) ? 'input-error' : '' ?>" id="reason" name="reason" required>
                                    <option value="">Select Reason</option>
                                    <?php foreach ($reasonOptions as $reason): ?>
                                        <option <?= $form['reason'] === $reason ? 'selected' : '' ?>><?= e($reason) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="borrow-equipment-wrap" class="<?= $form['reason'] === VISITOR_REASON_BORROW_EQUIPMENT ? '' : 'hidden' ?>">
                        <div class="borrow-equipment-panel grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_10rem] gap-x-6 gap-y-5 rounded-xl border border-emerald-100 bg-emerald-50/30">
                            <div>
                                <label class="visit-label" for="borrow_item_id">Equipment to Borrow</label>
                                <div class="visit-field bg-white">
                                    <span class="material-symbols-outlined">medical_services</span>
                                    <select class="visit-input <?= isset($errors['borrow_item_id']) ? 'input-error' : '' ?>" id="borrow_item_id" name="borrow_item_id">
                                        <option value="">Select Equipment</option>
                                        <?php foreach ($equipmentItems as $item): ?>
                                            <option
                                                value="<?= (int) $item['id'] ?>"
                                                data-available="<?= (int) $item['quantity'] ?>"
                                                data-unit="<?= e($item['unit'] ?: 'unit') ?>"
                                                <?= (int) $form['borrow_item_id'] === (int) $item['id'] ? 'selected' : '' ?>
                                                <?= (int) $item['quantity'] <= 0 ? 'disabled' : '' ?>
                                            >
                                                <?= e($item['item_name']) ?> - <?= (int) $item['quantity'] ?> <?= e($item['unit'] ?: 'unit') ?> available
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div id="borrowEquipmentStatus" class="visit-lookup-status mt-2">Select the item being borrowed.</div>
                            </div>
                            <div>
                                <label class="visit-label" for="borrowed_quantity">Qty</label>
                                <div class="visit-field bg-white">
                                    <input class="visit-input <?= isset($errors['borrowed_quantity']) ? 'input-error' : '' ?>" id="borrowed_quantity" name="borrowed_quantity" type="number" min="1" value="<?= e($form['borrowed_quantity']) ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="visit-label" for="chief_complaint" id="chiefComplaintLabel">Chief Complaint</label>
                        <div class="visit-field">
                            <span class="material-symbols-outlined textarea-icon">notes</span>
                            <textarea class="visit-input visit-notes <?= isset($errors['chief_complaint']) ? 'input-error' : '' ?>" id="chief_complaint" name="chief_complaint" rows="2" placeholder="Briefly describe your symptoms or reason for visiting..." required><?= e($form['chief_complaint']) ?></textarea>
                        </div>
                    </div>

                    <div class="visit-actions-row grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_auto] gap-4 items-end border-t border-slate-100 pt-5">
                        <div>
                            <label class="visit-label">Time Recording</label>
                            <div class="visit-time-pill inline-flex items-center gap-2 bg-slate-50 border border-slate-100 rounded-xl px-4 py-3">
                                <span class="material-symbols-outlined text-primary text-[18px]">schedule</span>
                                <span class="text-sm font-bold text-slate-600">Time in is set automatically on submit</span>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <button class="btn btn-primary min-h-[3rem]" type="submit" id="visitorSubmitButton" data-confirm-submit data-confirm-type="primary" data-confirm-title="Register this clinic visit?" data-confirm-message="This will submit the logbook entry and notify the nurse station." data-confirm-toast="Registering visit...">
                                <span id="visitorSubmitLabel">Register Clinic Visit</span>
                                <span class="material-symbols-outlined text-[18px]">arrow_right_alt</span>
                            </button>
                            <button class="btn btn-ghost min-h-[3rem]" type="reset" aria-label="Reset form">
                                <span class="material-symbols-outlined text-[18px]">refresh</span>
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>

            <div class="visit-card-footer bg-slate-50/70 border-t border-outline-variant/20 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
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
    const identifier = document.getElementById('identifier');
    const fullName = document.getElementById('full_name');
    const category = document.getElementById('category');
    const reason = document.getElementById('reason');
    const visitMain = document.querySelector('.visit-main');
    const yearLevelWrap = document.getElementById('year-level-wrap');
    const yearLevel = document.getElementById('year_level');
    const department = document.getElementById('department');
    const chiefComplaint = document.getElementById('chief_complaint');
    const chiefComplaintLabel = document.getElementById('chiefComplaintLabel');
    const borrowEquipmentWrap = document.getElementById('borrow-equipment-wrap');
    const borrowItem = document.getElementById('borrow_item_id');
    const borrowedQuantity = document.getElementById('borrowed_quantity');
    const borrowEquipmentStatus = document.getElementById('borrowEquipmentStatus');
    const visitorSubmitButton = document.getElementById('visitorSubmitButton');
    const visitorSubmitLabel = document.getElementById('visitorSubmitLabel');
    const lookupStatus = document.getElementById('visitorLookupStatus');
    let visitorLookupSequence = 0;

    function shouldFormatAsStudentId(value) {
        return (category && category.value === 'Student') || /^[\d-]*$/.test(String(value || ''));
    }

    function normalizeVisitorId(value) {
        const digits = String(value || '').replace(/\D/g, '').slice(0, 7);
        if (digits.length <= 2) {
            return digits;
        }

        return `${digits.slice(0, 2)}-${digits.slice(2)}`;
    }

    function setVisitorLookupStatus(message, state = '') {
        if (!lookupStatus) return;

        lookupStatus.textContent = message;
        lookupStatus.classList.toggle('is-found', state === 'found');
        lookupStatus.classList.toggle('is-missing', state === 'missing');
    }

    function syncYearLevel() {
        const isStudent = category && category.value === 'Student';
        yearLevelWrap?.classList.toggle('hidden', !isStudent);
        if (yearLevel) {
            yearLevel.required = isStudent;
            if (!isStudent) yearLevel.value = '';
        }
    }

    function syncBorrowEquipmentStatus() {
        if (!borrowItem || !borrowEquipmentStatus || !borrowedQuantity) return;

        const selected = borrowItem.selectedOptions?.[0];
        const available = Number(selected?.dataset?.available || 0);
        const unit = selected?.dataset?.unit || 'unit';

        if (!borrowItem.value) {
            borrowEquipmentStatus.textContent = 'Select the item being borrowed.';
            borrowedQuantity.removeAttribute('max');
            return;
        }

        borrowedQuantity.max = String(Math.max(available, 1));
        if (Number(borrowedQuantity.value || 1) > available) {
            borrowedQuantity.value = String(Math.max(available, 1));
        }

        borrowEquipmentStatus.textContent = `${available} ${unit} available.`;
    }

    function syncBorrowFlow() {
        const isBorrowing = reason?.value === '<?= e(VISITOR_REASON_BORROW_EQUIPMENT) ?>';

        visitMain?.classList.toggle('is-borrowing', isBorrowing);
        borrowEquipmentWrap?.classList.toggle('hidden', !isBorrowing);
        if (borrowItem) borrowItem.required = isBorrowing;
        if (borrowedQuantity) borrowedQuantity.required = isBorrowing;

        if (chiefComplaint) {
            chiefComplaint.required = !isBorrowing;
            chiefComplaint.placeholder = isBorrowing
                ? 'Optional notes, return reminder, or equipment purpose...'
                : 'Briefly describe your symptoms or reason for visiting...';
        }
        if (chiefComplaintLabel) {
            chiefComplaintLabel.textContent = isBorrowing ? 'Notes' : 'Chief Complaint';
        }
        if (visitorSubmitLabel) {
            visitorSubmitLabel.textContent = isBorrowing ? 'Record Equipment Loan' : 'Register Clinic Visit';
        }
        if (visitorSubmitButton) {
            visitorSubmitButton.dataset.confirmTitle = isBorrowing ? 'Record this equipment loan?' : 'Register this clinic visit?';
            visitorSubmitButton.dataset.confirmMessage = isBorrowing
                ? 'This will record the borrowed equipment and reduce its available quantity until returned.'
                : 'This will submit the logbook entry and notify the nurse station.';
            visitorSubmitButton.dataset.confirmToast = isBorrowing ? 'Recording equipment loan...' : 'Registering visit...';
        }

        syncBorrowEquipmentStatus();
    }

    async function syncVisitorPatientLookup() {
        if (!identifier) return;

        const rawValue = identifier.value;
        const formatted = shouldFormatAsStudentId(rawValue) ? normalizeVisitorId(rawValue) : rawValue.trim();
        if (identifier.value !== formatted) {
            identifier.value = formatted;
        }

        if (formatted === '') {
            identifier.dataset.autofilled = '';
            setVisitorLookupStatus('Type your ID to load existing student details.');
            return;
        }

        if (!/^\d{2}-\d{5}$/.test(formatted)) {
            setVisitorLookupStatus('Continue typing your ID.');
            return;
        }

        const sequence = ++visitorLookupSequence;
        setVisitorLookupStatus('Checking student ID...');

        try {
            const response = await fetch(`visitor-registration.php?lookup_identifier=${encodeURIComponent(formatted)}`, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            const patient = await response.json();
            if (sequence !== visitorLookupSequence || identifier.value !== formatted) {
                return;
            }

            if (!patient.found) {
                if (identifier.dataset.autofilled === '1') {
                    fullName.value = '';
                    department.value = '';
                    yearLevel.value = '';
                    identifier.dataset.autofilled = '';
                    syncYearLevel();
                }
                setVisitorLookupStatus('No existing student record found. Continue filling the form manually.', 'missing');
                return;
            }

            identifier.dataset.autofilled = '1';
            fullName.value = patient.name || '';
            category.value = 'Student';
            department.value = patient.course || '';
            yearLevel.value = patient.yearLevel || '';
            syncYearLevel();
            setVisitorLookupStatus('Existing student details loaded.', 'found');
        } catch (error) {
            if (sequence === visitorLookupSequence) {
                setVisitorLookupStatus('Unable to check the ID right now. Continue filling the form manually.', 'missing');
            }
        }
    }

    category?.addEventListener('change', () => {
        syncYearLevel();
        syncVisitorPatientLookup();
    });
    reason?.addEventListener('change', syncBorrowFlow);
    borrowItem?.addEventListener('change', syncBorrowEquipmentStatus);
    borrowedQuantity?.addEventListener('input', syncBorrowEquipmentStatus);
    identifier?.addEventListener('input', syncVisitorPatientLookup);
    identifier?.addEventListener('change', syncVisitorPatientLookup);
    syncYearLevel();
    syncBorrowFlow();
    syncVisitorPatientLookup();

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
<script src="<?= app_url('assets/js/app.js?v=student-id-format-2') ?>"></script>
</body>
</html>
