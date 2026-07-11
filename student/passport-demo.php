<?php
require_once __DIR__ . '/../app/config/database.php';

function pp_e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function pp_split_lines(?string $value, array $fallback): array
{
    $text = trim((string) $value);
    if ($text === '') {
        return $fallback;
    }

    $parts = preg_split('/[\r\n,;]+/', $text) ?: [];
    $items = array_values(array_filter(array_map('trim', $parts)));

    return $items ?: $fallback;
}

function pp_upload_incident_photo(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return null;
    }

    $mime = mime_content_type($tmpName) ?: '';
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($extensions[$mime])) {
        return null;
    }

    $uploadDir = __DIR__ . '/../uploads/incidents';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $filename = 'incident-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    $target = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($tmpName, $target)) {
        return null;
    }

    return 'uploads/incidents/' . $filename;
}

$token = trim($_GET['token'] ?? '');
$patient = null;

if ($token !== '') {
    $stmt = db()->prepare('SELECT * FROM patients WHERE emergency_token = ? AND token_enabled = 1 LIMIT 1');
    $stmt->execute([$token]);
    $patient = $stmt->fetch();
}

if (!$patient) {
    http_response_code(404);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Emergency Passport Not Found | CLINiQ</title>
      <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;800&display=swap" rel="stylesheet">
      <style>
        body { margin:0; min-height:100vh; display:grid; place-items:center; font-family:Inter,system-ui,sans-serif; background:#f4fbf6; color:#17261d; }
        section { width:min(92vw,34rem); padding:2rem; background:#fff; border:1px solid #d8e9dd; border-radius:1rem; box-shadow:0 14px 34px rgba(23,38,29,.08); }
        h1 { margin:0 0 .5rem; font-size:1.6rem; }
        p { margin:0; color:#64756a; line-height:1.55; }
      </style>
    </head>
    <body>
      <section>
        <h1>Emergency Passport Not Found</h1>
        <p>This QR/NFC passport link is missing, disabled, or no longer valid. Please contact the clinic for assistance.</p>
      </section>
    </body>
    </html>
    <?php
    exit;
}

$fullName = trim(implode(' ', array_filter([
    $patient['first_name'] ?? '',
    $patient['middle_name'] ?? '',
    $patient['last_name'] ?? '',
])));
$student = [
    'name' => $fullName !== '' ? $fullName : 'Student',
    'student_id' => (string) ($patient['student_number'] ?? 'Not recorded'),
    'course' => (string) ($patient['course_section'] ?? 'Not recorded'),
    'blood_type' => (string) ($patient['blood_type'] ?: 'Unknown'),
    'status' => 'No Active Incident',
    'last_updated' => !empty($patient['updated_at']) ? date('F j, Y', strtotime($patient['updated_at'])) : date('F j, Y'),
];

$allergies = pp_split_lines($patient['allergies'] ?? null, ['No allergies recorded']);
$conditions = pp_split_lines($patient['existing_conditions'] ?? null, ['No existing conditions recorded']);
$instructions = pp_split_lines($patient['emergency_instructions'] ?? null, [
    'Provide immediate first aid as needed.',
    'Notify the school clinic immediately.',
    'Contact guardian if the student needs transfer or observation.',
]);

$guardian = [
    'name' => (string) ($patient['guardian_name'] ?: 'Guardian not recorded'),
    'phone' => (string) ($patient['guardian_contact'] ?: 'No phone recorded'),
    'relationship' => 'Guardian',
];

$reportSubmitted = false;
$reportError = '';
$photoPath = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reporterName = trim($_POST['reporter_name'] ?? 'Emergency Tag Scanner') ?: 'Emergency Tag Scanner';
    $reporterContact = trim($_POST['reporter_contact'] ?? '');
    $location = trim($_POST['location'] ?? 'Location not specified') ?: 'Location not specified';
    $notes = trim($_POST['incident_notes'] ?? '');
    $photoCount = 0;

    if (isset($_FILES['incident_photos']) && is_array($_FILES['incident_photos']['name'] ?? null)) {
        $fileCount = count($_FILES['incident_photos']['name']);
        for ($index = 0; $index < $fileCount; $index++) {
            if (($_FILES['incident_photos']['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $photoCount++;
            if ($photoPath === null) {
                $photoPath = pp_upload_incident_photo([
                    'name' => $_FILES['incident_photos']['name'][$index] ?? '',
                    'type' => $_FILES['incident_photos']['type'][$index] ?? '',
                    'tmp_name' => $_FILES['incident_photos']['tmp_name'][$index] ?? '',
                    'error' => $_FILES['incident_photos']['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $_FILES['incident_photos']['size'][$index] ?? 0,
                ]);
            }
        }
    }

    try {
        $stmt = db()->prepare("
            INSERT INTO incident_reports (
                patient_id, emergency_token, reporter_name, reporter_contact, location, notes, ip_address, user_agent, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'New')
        ");
        $stmt->execute([
            (int) $patient['id'],
            $token,
            $reporterName,
            $reporterContact !== '' ? $reporterContact : null,
            $location,
            $notes !== '' ? $notes : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);

        $details = trim("Emergency passport incident report submitted.\nReporter: {$reporterName}\nContact: {$reporterContact}\nNotes: {$notes}\nPhotos attached: {$photoCount}");
        $stmt = db()->prepare("
            INSERT INTO nurse_alerts (
                patient_id, reporter_name, reporter_role, location, concern, details, photo_path, status
            ) VALUES (?, ?, 'Emergency Passport Scanner', ?, 'Emergency passport incident report', ?, ?, 'Pending')
        ");
        $stmt->execute([
            (int) $patient['id'],
            $reporterName,
            $location,
            $details,
            $photoPath,
        ]);

        $reportSubmitted = true;
        $student['status'] = 'Clinic Notified';
    } catch (Throwable $exception) {
        $reportError = 'The clinic could not receive the report. Please call the clinic or guardian immediately.';
    }
}

$guidance = [
    [
        'icon' => 'pulmonology',
        'title' => 'Asthma Attack',
        'class' => 'asthma',
        'steps' => ['Help patient sit upright', 'Assist with inhaler', 'Monitor breathing'],
    ],
    [
        'icon' => 'warning',
        'title' => 'Allergic Reaction',
        'class' => 'allergy',
        'steps' => ['Avoid allergen exposure', 'Monitor airway carefully', 'Seek medical assistance immediately'],
    ],
    [
        'icon' => 'airline_seat_flat',
        'title' => 'Unconscious Patient',
        'class' => 'unconscious',
        'steps' => ['Place in recovery position', 'Monitor breathing', 'Contact guardian immediately'],
    ],
];

$telHref = preg_replace('/[^0-9+]/', '', $guardian['phone']);
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#23422C">
  <title><?= pp_e($student['name']) ?> - Emergency Passport | CLINiQ</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">

  <style>
    *,
    *::before,
    *::after {
      box-sizing: border-box;
    }

    :root {
      --bg: #f4fbf6;
      --ink: #17261d;
      --muted: #64756a;
      --card: #ffffff;
      --primary: #3F7D52;
      --primary-dark: #23422C;
      --primary-soft: #e8f6ec;
      --border: #d8e9dd;
      --border-soft: #e5f0e8;
      --surface: #edf8f0;
      --danger: #dc2626;
      --danger-dark: #991b1b;
      --danger-soft: #fef2f2;
      --danger-border: #fecaca;
      --warning: #b7791f;
      --warning-soft: #fffbeb;
      --warning-border: #fde68a;
      --success: #15803d;
      --success-soft: #ecfdf3;
      --success-border: #bbf7d0;
      --blue: #00478d;
      --blue-soft: #eff6ff;
      --shadow: 0 1px 2px rgba(23, 38, 29, 0.04), 0 12px 30px rgba(23, 38, 29, 0.06);
      --radius: 0.85rem;
    }

    html {
      min-height: 100%;
      scrollbar-color: #cbd8ce #f4fbf6;
      scrollbar-width: thin;
    }

    body {
      min-height: 100vh;
      margin: 0;
      background: var(--bg);
      color: var(--ink);
      font-family: Inter, system-ui, sans-serif;
      letter-spacing: 0;
    }

    button,
    textarea,
    input {
      font: inherit;
    }

    .material-symbols-outlined {
      font-variation-settings: 'FILL' 0, 'wght' 500, 'GRAD' 0, 'opsz' 24;
      line-height: 1;
    }

    .pp-brand {
      position: sticky;
      top: 0;
      z-index: 20;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      min-height: 4rem;
      padding: 0.75rem clamp(1rem, 4vw, 2rem);
      background: rgba(255, 255, 255, 0.92);
      border-bottom: 1px solid var(--border-soft);
      backdrop-filter: blur(16px);
    }

    .pp-brand-lockup {
      display: inline-flex;
      align-items: center;
      gap: 0.75rem;
      min-width: 0;
    }

    .pp-logo {
      display: grid;
      place-items: center;
      width: 2.45rem;
      height: 2.45rem;
      padding: 0.13rem;
      overflow: hidden;
      background: #fff;
      border: 1px solid rgba(199, 220, 205, 0.82);
      border-radius: 0.7rem;
      box-shadow: 0 10px 24px rgba(23, 38, 29, 0.08);
      flex: 0 0 auto;
    }

    .pp-logo img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }

    .pp-brand-title {
      display: block;
      font-size: 1rem;
      font-weight: 700;
      line-height: 1.05;
    }

    .pp-brand-subtitle {
      display: block;
      margin-top: 0.18rem;
      color: var(--muted);
      font-size: 0.72rem;
      font-weight: 500;
      line-height: 1.1;
    }

    .pp-use-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      min-height: 2rem;
      padding: 0.38rem 0.75rem;
      color: var(--danger-dark);
      background: var(--danger-soft);
      border: 1px solid var(--danger-border);
      border-radius: 999px;
      font-size: 0.68rem;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      white-space: nowrap;
    }

    .pp-shell {
      width: min(100% - 2rem, 74rem);
      margin: 0 auto;
      padding: 1.25rem 0 2rem;
    }

    .pp-alert {
      display: grid;
      grid-template-columns: auto 1fr auto;
      align-items: center;
      gap: 0.8rem;
      padding: 0.85rem 1rem;
      margin-bottom: 1rem;
      color: var(--danger-dark);
      background: #fff7f7;
      border: 1px solid #ffb7b7;
      border-left: 4px solid var(--danger);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
    }

    .pp-alert strong {
      display: block;
      font-size: 0.78rem;
      font-weight: 900;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .pp-alert-copy {
      display: block;
      margin-top: 0.15rem;
      color: #8f3e42;
      font-size: 0.78rem;
      line-height: 1.35;
    }

    .pp-pulse {
      position: relative;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 2.25rem;
      height: 2.25rem;
      color: #fff;
      background: var(--danger);
      border-radius: 0.75rem;
      box-shadow: 0 8px 16px rgba(220, 38, 38, 0.18);
    }

    .pp-pulse::after {
      content: "";
      position: absolute;
      inset: -4px;
      border: 1px solid rgba(220, 38, 38, 0.24);
      border-radius: 0.95rem;
      animation: pulse 1.6s ease infinite;
    }

    @keyframes pulse {
      0% { transform: scale(0.9); opacity: 0.8; }
      70% { transform: scale(1.15); opacity: 0; }
      100% { transform: scale(1.15); opacity: 0; }
    }

    .pp-grid {
      display: grid;
      grid-template-columns: minmax(0, 1.35fr) minmax(20rem, 0.85fr);
      gap: 1rem;
      align-items: start;
    }

    .pp-main,
    .pp-side {
      display: grid;
      gap: 1rem;
      min-width: 0;
    }

    .pp-card {
      overflow: hidden;
      background: var(--card);
      border: 1px solid var(--border-soft);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
    }

    .pp-card-pad {
      padding: 1rem;
    }

    .pp-hero {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr) auto;
      gap: 1rem;
      align-items: center;
      padding: 1.15rem;
      background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
      color: #fff;
    }

    .pp-avatar {
      display: grid;
      place-items: center;
      width: clamp(4rem, 7vw, 5rem);
      height: clamp(4rem, 7vw, 5rem);
      color: rgba(255, 255, 255, 0.76);
      background: rgba(255, 255, 255, 0.14);
      border: 3px solid rgba(255, 255, 255, 0.32);
      border-radius: 50%;
      flex: 0 0 auto;
    }

    .pp-avatar .material-symbols-outlined {
      font-size: 2.35rem;
    }

    .pp-kicker-row {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0.5rem;
      margin-bottom: 0.45rem;
    }

    .pp-pill,
    .pp-status {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      min-height: 1.5rem;
      padding: 0.22rem 0.6rem;
      border-radius: 999px;
      font-size: 0.64rem;
      font-weight: 900;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      white-space: nowrap;
    }

    .pp-pill {
      color: #dff4e4;
      background: rgba(255, 255, 255, 0.14);
      border: 1px solid rgba(255, 255, 255, 0.28);
    }

    .pp-status {
      color: #166534;
      background: #ecfdf3;
      border: 1px solid #bbf7d0;
    }

    .pp-name {
      margin: 0;
      color: #fff;
      font-size: clamp(1.35rem, 3vw, 2rem);
      font-weight: 800;
      line-height: 1.15;
      overflow-wrap: anywhere;
    }

    .pp-meta {
      margin-top: 0.35rem;
      color: rgba(255, 255, 255, 0.76);
      font-size: 0.86rem;
      font-weight: 500;
    }

    .pp-blood {
      min-width: 5.2rem;
      padding: 0.75rem 0.9rem;
      color: var(--danger-dark);
      background: #fff;
      border-radius: 0.8rem;
      text-align: center;
      box-shadow: 0 10px 22px rgba(23, 38, 29, 0.1);
    }

    .pp-blood span {
      display: block;
      font-size: 0.6rem;
      font-weight: 900;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .pp-blood strong {
      display: block;
      margin-top: 0.12rem;
      font-size: 1.8rem;
      font-weight: 900;
      line-height: 1;
    }

    .pp-info-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 0.75rem;
      padding: 1rem;
    }

    .pp-info-panel {
      min-width: 0;
      padding: 0.9rem;
      background: #fff;
      border: 1px solid var(--border-soft);
      border-radius: 0.75rem;
    }

    .pp-priority-card {
      position: relative;
      display: flex;
      flex-direction: column;
      gap: 0.72rem;
      min-height: 9.45rem;
      padding: 1rem;
      border-color: #cfe6d6;
      background: linear-gradient(180deg, #ffffff 0%, #fbfffc 100%);
    }

    .pp-priority-card::before {
      content: "";
      position: absolute;
      inset: 0 auto 0 0;
      width: 4px;
      background: var(--primary);
      border-radius: 0.75rem 0 0 0.75rem;
    }

    .pp-priority-card.allergy-focus {
      border-color: var(--danger-border);
      background: linear-gradient(180deg, #fff 0%, #fff7f7 100%);
    }

    .pp-priority-card.allergy-focus::before {
      background: var(--danger);
    }

    .pp-priority-card.condition-focus {
      border-color: #fed7aa;
      background: linear-gradient(180deg, #fff 0%, #fffaf3 100%);
    }

    .pp-priority-card.condition-focus::before {
      background: #f59e0b;
    }

    .pp-priority-card.medication-focus {
      border-color: #bbf7d0;
      background: linear-gradient(180deg, #fff 0%, #f4fff8 100%);
    }

    .pp-priority-card.medication-focus::before {
      background: var(--primary);
    }

    .pp-label {
      display: flex;
      align-items: center;
      gap: 0.45rem;
      margin-bottom: 0.55rem;
      color: var(--muted);
      font-size: 0.67rem;
      font-weight: 900;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .pp-priority-card .pp-label {
      margin-bottom: 0;
      color: var(--ink);
      font-size: 0.74rem;
    }

    .pp-label .material-symbols-outlined {
      font-size: 1rem;
    }

    .pp-priority-card .pp-label .material-symbols-outlined {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 1.75rem;
      height: 1.75rem;
      color: #fff !important;
      background: var(--primary);
      border-radius: 0.55rem;
      font-size: 1.05rem;
    }

    .pp-priority-card.allergy-focus .pp-label .material-symbols-outlined {
      background: var(--danger);
    }

    .pp-priority-card.condition-focus .pp-label .material-symbols-outlined {
      background: #f59e0b;
    }

    .pp-tags {
      display: flex;
      flex-wrap: wrap;
      gap: 0.4rem;
    }

    .pp-tag {
      display: inline-flex;
      align-items: center;
      min-height: 1.65rem;
      padding: 0.28rem 0.65rem;
      color: var(--danger-dark);
      background: var(--danger-soft);
      border: 1px solid var(--danger-border);
      border-radius: 999px;
      font-size: 0.78rem;
      font-weight: 700;
    }

    .pp-priority-card .pp-tag {
      min-height: 1.85rem;
      padding: 0.32rem 0.75rem;
      font-size: 0.86rem;
      font-weight: 800;
    }

    .pp-list {
      display: grid;
      gap: 0.38rem;
      margin: 0;
      padding: 0;
      list-style: none;
      color: var(--ink);
      font-size: 0.86rem;
      font-weight: 600;
      line-height: 1.45;
    }

    .pp-priority-card .pp-list {
      gap: 0.55rem;
      font-size: 0.95rem;
      font-weight: 800;
      line-height: 1.45;
    }

    .pp-priority-card .pp-list li {
      overflow-wrap: anywhere;
    }

    .pp-instructions {
      grid-column: 1 / -1;
      display: grid;
      grid-template-columns: auto 1fr;
      gap: 0.75rem;
      padding: 0.95rem;
      color: var(--warning);
      background: var(--warning-soft);
      border: 1px solid var(--warning-border);
      border-left: 4px solid #f59e0b;
      border-radius: 0.75rem;
    }

    .pp-instructions .material-symbols-outlined {
      color: #f59e0b;
      font-size: 1.35rem;
    }

    .pp-instructions ul {
      margin: 0;
      padding-left: 1.1rem;
      font-size: 0.86rem;
      font-weight: 600;
      line-height: 1.55;
    }

    .pp-contact-card {
      display: grid;
      gap: 0.9rem;
    }

    .pp-section-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 1rem;
      padding: 1rem;
      border-bottom: 1px solid var(--border-soft);
    }

    .pp-section-title {
      margin: 0;
      font-size: 1rem;
      font-weight: 800;
    }

    .pp-section-copy {
      margin: 0.22rem 0 0;
      color: var(--muted);
      font-size: 0.76rem;
      line-height: 1.4;
    }

    .pp-contact-row {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr);
      gap: 0.8rem;
      align-items: center;
    }

    .pp-contact-icon {
      display: grid;
      place-items: center;
      width: 2.65rem;
      height: 2.65rem;
      color: var(--primary);
      background: var(--primary-soft);
      border-radius: 0.75rem;
    }

    .pp-contact-name {
      font-size: 0.95rem;
      font-weight: 800;
    }

    .pp-contact-meta {
      margin-top: 0.2rem;
      color: var(--muted);
      font-size: 0.78rem;
      font-weight: 600;
    }

    .pp-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.65rem;
    }

    .pp-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.45rem;
      min-height: 2.75rem;
      padding: 0.7rem 0.85rem;
      border: 1px solid transparent;
      border-radius: 0.7rem;
      cursor: pointer;
      font-size: 0.78rem;
      font-weight: 800;
      line-height: 1.15;
      text-align: center;
      text-decoration: none;
      transition: background 0.15s ease, border-color 0.15s ease, transform 0.15s ease;
    }

    .pp-button:hover {
      transform: translateY(-1px);
    }

    .pp-button-primary {
      color: #fff;
      background: var(--primary);
      box-shadow: 0 8px 18px rgba(63, 125, 82, 0.16);
    }

    .pp-button-primary:hover {
      background: var(--primary-dark);
    }

    .pp-button-danger {
      color: #fff;
      background: var(--danger);
      box-shadow: 0 10px 20px rgba(220, 38, 38, 0.18);
    }

    .pp-button-danger:hover {
      background: #b91c1c;
    }

    .pp-button-warning {
      color: #fff;
      background: var(--warning);
    }

    .pp-button-success {
      color: #fff;
      background: var(--success);
      cursor: default;
    }

    .pp-button:disabled {
      transform: none;
      opacity: 0.94;
    }

    .pp-sticky-notify {
      position: fixed;
      left: 0;
      right: 0;
      bottom: 0;
      z-index: 40;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 0.7rem max(1rem, env(safe-area-inset-left)) calc(0.7rem + env(safe-area-inset-bottom)) max(1rem, env(safe-area-inset-right));
      background: rgba(255, 255, 255, 0.92);
      border-top: 1px solid var(--border-soft);
      box-shadow: 0 -16px 36px rgba(23, 38, 29, 0.12);
      backdrop-filter: blur(16px);
    }

    .pp-sticky-notify .pp-button {
      width: 100%;
      max-width: 34rem;
      min-height: 3.15rem;
      border-radius: 0.8rem;
      font-size: 0.9rem;
    }

    .pp-guidance-grid {
      display: grid;
      gap: 0.65rem;
      padding: 1rem;
    }

    .pp-guide {
      padding: 0.85rem;
      border: 1px solid var(--border-soft);
      border-radius: 0.75rem;
    }

    .pp-guide.asthma {
      background: #eff6ff;
      border-color: #bfdbfe;
    }

    .pp-guide.allergy {
      background: #fff7ed;
      border-color: #fed7aa;
    }

    .pp-guide.unconscious {
      background: #f5f3ff;
      border-color: #ddd6fe;
    }

    .pp-guide-head {
      display: flex;
      align-items: center;
      gap: 0.55rem;
      margin-bottom: 0.45rem;
      font-weight: 800;
    }

    .pp-guide-head .material-symbols-outlined {
      color: var(--primary);
      font-size: 1.2rem;
    }

    .pp-guide ul {
      margin: 0;
      padding-left: 1.2rem;
      color: var(--ink);
      font-size: 0.78rem;
      font-weight: 600;
      line-height: 1.55;
    }

    .pp-incident-drawer {
      max-height: 0;
      overflow: hidden;
      transition: max-height 0.32s ease;
    }

    .pp-incident-drawer.open {
      max-height: 42rem;
    }

    .pp-form {
      display: grid;
      gap: 0.9rem;
      padding: 0 1rem 1rem;
    }

    .pp-form-label {
      display: block;
      margin-bottom: 0.45rem;
      color: var(--muted);
      font-size: 0.68rem;
      font-weight: 900;
      letter-spacing: 0.07em;
      text-transform: uppercase;
    }

    .pp-dropzone {
      display: grid;
      place-items: center;
      gap: 0.35rem;
      min-height: 6rem;
      padding: 1rem;
      text-align: center;
      color: var(--muted);
      background: #f8fafc;
      border: 2px dashed #cbd5e1;
      border-radius: 0.75rem;
      cursor: pointer;
    }

    .pp-dropzone strong {
      color: var(--ink);
      font-size: 0.82rem;
    }

    .pp-dropzone span:not(.material-symbols-outlined) {
      font-size: 0.72rem;
      font-weight: 600;
    }

    .pp-textarea {
      width: 100%;
      min-height: 7rem;
      padding: 0.75rem 0.85rem;
      color: var(--ink);
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 0.72rem;
      font-size: 0.82rem;
      line-height: 1.45;
      resize: vertical;
      outline: none;
    }

    .pp-textarea:focus {
      border-color: rgba(63, 125, 82, 0.55);
      box-shadow: 0 0 0 3px rgba(63, 125, 82, 0.13);
    }

    .pp-status-card {
      display: none;
    }

    .pp-status-card.visible {
      display: block;
    }

    .pp-timeline {
      display: grid;
      gap: 0;
      padding: 0.2rem 1rem 1rem;
    }

    .pp-timeline-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: 0.7rem 0;
      border-bottom: 1px dashed var(--border);
      font-size: 0.8rem;
    }

    .pp-timeline-row:last-child {
      border-bottom: 0;
    }

    .pp-timeline-row span {
      color: var(--muted);
      font-weight: 700;
    }

    .pp-timeline-row strong {
      text-align: right;
      font-weight: 800;
    }

    .pp-updated {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.85rem 1rem;
      color: var(--muted);
      background: #fff;
      border: 1px solid var(--border-soft);
      border-radius: 0.75rem;
      box-shadow: var(--shadow);
      font-size: 0.74rem;
      font-weight: 600;
      line-height: 1.4;
    }

    .pp-footer {
      width: min(100% - 2rem, 74rem);
      margin: 0 auto;
      padding: 0 0 1.5rem;
      color: var(--muted);
      font-size: 0.72rem;
      line-height: 1.6;
      text-align: center;
    }

    .pp-footer strong {
      color: var(--ink);
    }

    @media (max-width: 960px) {
      .pp-grid {
        grid-template-columns: 1fr;
      }

      .pp-info-grid {
        grid-template-columns: 1fr 1fr;
      }
    }

    @media (max-width: 640px) {
      body {
        padding-bottom: 5rem;
      }

      .pp-brand {
        position: static;
        align-items: flex-start;
        flex-direction: column;
      }

      .pp-brand > .pp-use-badge {
        display: none;
      }

      .pp-shell {
        width: min(100% - 1rem, 74rem);
        padding-top: 0.7rem;
      }

      .pp-alert {
        grid-template-columns: auto 1fr;
        gap: 0.65rem;
        padding: 0.7rem 0.85rem;
        margin-bottom: 0.85rem;
        border-left-width: 3px;
      }

      .pp-alert .pp-pulse {
        width: 1.95rem;
        height: 1.95rem;
        border-radius: 0.65rem;
      }

      .pp-alert .pp-pulse .material-symbols-outlined {
        font-size: 1rem;
      }

      .pp-alert strong {
        font-size: 0.7rem;
        letter-spacing: 0.06em;
      }

      .pp-alert-copy {
        font-size: 0.72rem;
        line-height: 1.25;
      }

      .pp-alert .pp-use-badge {
        display: none;
      }

      .pp-alert-copy {
        display: none;
      }

      .pp-hero {
        grid-template-columns: auto minmax(0, 1fr);
      }

      .pp-blood {
        grid-column: 1 / -1;
        width: 100%;
      }

      .pp-info-grid,
      .pp-actions {
        grid-template-columns: 1fr;
      }

      .pp-info-grid {
        gap: 0.8rem;
        padding: 0.8rem;
      }

      .pp-priority-card {
        min-height: auto;
        padding: 1rem 1rem 1rem 1.1rem;
      }

      .pp-priority-card .pp-label {
        font-size: 0.78rem;
      }

      .pp-priority-card .pp-list {
        font-size: 1rem;
      }

      .pp-priority-card .pp-tag {
        min-height: 2rem;
        font-size: 0.92rem;
      }

      .pp-contact-card .pp-actions #notifyClinicBtn {
        display: none;
      }

      .pp-sticky-notify {
        display: flex;
      }

      .pp-instructions {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>

<body>
  <header class="pp-brand">
    <div class="pp-brand-lockup">
      <span class="pp-logo">
        <img src="../public/assets/img/clinic-logo.png" alt="PLP Health Services Department logo">
      </span>
      <span>
        <span class="pp-brand-title">CLINiQ</span>
        <span class="pp-brand-subtitle">PLP School Clinic Management System</span>
      </span>
    </div>
    <span class="pp-use-badge">
      <span class="material-symbols-outlined" aria-hidden="true">emergency</span>
      Emergency use only
    </span>
  </header>

  <main class="pp-shell" role="main">
    <section class="pp-alert" aria-label="Emergency access notice">
      <span class="pp-pulse" aria-hidden="true">
        <span class="material-symbols-outlined">health_and_safety</span>
      </span>
      <div>
        <strong>Authorised responders only</strong>
        <span class="pp-alert-copy">This page contains limited health information for immediate first aid and guardian contact.</span>
      </div>
      <span class="pp-use-badge">
        <span class="material-symbols-outlined" aria-hidden="true">verified_user</span>
        Limited profile
      </span>
    </section>

    <div class="pp-grid">
      <div class="pp-main">
        <section class="pp-card" aria-label="Patient identity and emergency information">
          <div class="pp-hero">
            <div class="pp-avatar" aria-hidden="true">
              <span class="material-symbols-outlined">person</span>
            </div>

            <div>
              <div class="pp-kicker-row">
                <span class="pp-pill">
                  <span class="material-symbols-outlined" aria-hidden="true" style="font-size:0.85rem;">id_card</span>
                  Emergency Passport
                </span>
                <span class="pp-status" id="passportStatus">
                  <span class="material-symbols-outlined" aria-hidden="true" style="font-size:0.9rem;">check_circle</span>
                  <?= pp_e($student['status']) ?>
                </span>
              </div>
              <h1 class="pp-name"><?= pp_e($student['name']) ?></h1>
              <div class="pp-meta"><?= pp_e($student['student_id']) ?> &middot; <?= pp_e($student['course']) ?></div>
            </div>

            <div class="pp-blood" role="img" aria-label="Blood type <?= pp_e($student['blood_type']) ?>">
              <span>Blood</span>
              <strong><?= pp_e($student['blood_type']) ?></strong>
            </div>
          </div>

          <div class="pp-info-grid">
            <div class="pp-info-panel pp-priority-card allergy-focus">
              <div class="pp-label">
                <span class="material-symbols-outlined" aria-hidden="true" style="color:var(--danger);">priority_high</span>
                Allergies
              </div>
              <div class="pp-tags" role="list" aria-label="Known allergies">
                <?php foreach ($allergies as $allergy): ?>
                  <span class="pp-tag" role="listitem"><?= pp_e($allergy) ?></span>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="pp-info-panel pp-priority-card condition-focus">
              <div class="pp-label">
                <span class="material-symbols-outlined" aria-hidden="true" style="color:#f59e0b;">medical_information</span>
                Conditions
              </div>
              <ul class="pp-list">
                <?php foreach ($conditions as $condition): ?>
                  <li><?= pp_e($condition) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>

            <div class="pp-info-panel pp-priority-card medication-focus">
              <div class="pp-label">
                <span class="material-symbols-outlined" aria-hidden="true" style="color:var(--primary);">medication</span>
                Medication
              </div>
              <ul class="pp-list">
                <li>Salbutamol inhaler as needed</li>
                <li>Check bag first</li>
              </ul>
            </div>

            <div class="pp-instructions" role="note">
              <span class="material-symbols-outlined" aria-hidden="true">report</span>
              <div>
                <div class="pp-label" style="margin-bottom:0.4rem;color:var(--warning);">Emergency Instructions</div>
                <ul>
                  <?php foreach ($instructions as $instruction): ?>
                    <li><?= pp_e($instruction) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>
          </div>
        </section>

        <?php if ($reportError !== ''): ?>
          <section class="pp-card pp-status-card visible" aria-label="Incident report error">
            <div class="pp-section-head">
              <div>
                <h2 class="pp-section-title">Report Not Sent</h2>
                <p class="pp-section-copy"><?= pp_e($reportError) ?></p>
              </div>
              <span class="pp-use-badge">
                <span class="material-symbols-outlined" aria-hidden="true">warning</span>
                Try again
              </span>
            </div>
          </section>
        <?php endif; ?>

        <section class="pp-card pp-status-card <?= $reportSubmitted ? 'visible' : '' ?>" id="incidentStatusCard" aria-label="Incident status">
          <div class="pp-section-head">
            <div>
              <h2 class="pp-section-title">Incident Status</h2>
              <p class="pp-section-copy">The clinic has received the responder report.</p>
            </div>
            <span class="pp-use-badge">
              <span class="material-symbols-outlined" aria-hidden="true">notifications_active</span>
              Clinic notified
            </span>
          </div>
          <div class="pp-timeline">
            <div class="pp-timeline-row">
              <span>Time Submitted</span>
              <strong id="submittedTime"><?= $reportSubmitted ? pp_e(date('h:i A')) : '-' ?></strong>
            </div>
            <div class="pp-timeline-row">
              <span>Reporter</span>
              <strong><?= pp_e($_POST['reporter_name'] ?? 'Emergency Tag Scanner') ?></strong>
            </div>
            <div class="pp-timeline-row">
              <span>Photos Uploaded</span>
              <strong id="photoCount"><?= $reportSubmitted ? pp_e((string) $photoCount . ' attached') : '0 attached' ?></strong>
            </div>
            <div class="pp-timeline-row">
              <span>Assigned Nurse</span>
              <strong>Pending Assignment</strong>
            </div>
          </div>
        </section>
      </div>

      <aside class="pp-side" aria-label="Emergency actions and guidance">
        <section class="pp-card pp-contact-card">
          <div class="pp-section-head">
            <div>
              <h2 class="pp-section-title">Emergency Contact</h2>
              <p class="pp-section-copy">Call the guardian while first aid is being performed.</p>
            </div>
            <span class="material-symbols-outlined" style="color:var(--primary);" aria-hidden="true">contacts</span>
          </div>

          <div class="pp-card-pad">
            <div class="pp-contact-row">
              <div class="pp-contact-icon" aria-hidden="true">
                <span class="material-symbols-outlined">call</span>
              </div>
              <div>
                <div class="pp-contact-name"><?= pp_e($guardian['name']) ?></div>
                <div class="pp-contact-meta"><?= pp_e($guardian['phone']) ?> &middot; <?= pp_e($guardian['relationship']) ?></div>
              </div>
            </div>

            <div class="pp-actions" style="margin-top:1rem;">
              <a class="pp-button pp-button-primary" href="tel:<?= pp_e($telHref) ?>" aria-label="Call <?= pp_e($guardian['name']) ?>">
                <span class="material-symbols-outlined" aria-hidden="true">call</span>
                Call Now
              </a>
              <button class="pp-button <?= $reportSubmitted ? 'pp-button-success' : 'pp-button-danger' ?>" type="button" id="notifyClinicBtn" aria-expanded="false" aria-controls="incidentDrawer" <?= $reportSubmitted ? 'disabled' : '' ?>>
                <span class="material-symbols-outlined" aria-hidden="true"><?= $reportSubmitted ? 'check_circle' : 'notifications_active' ?></span>
                <?= $reportSubmitted ? 'Report Submitted' : 'Notify Clinic' ?>
              </button>
            </div>
          </div>

          <form class="pp-incident-drawer" id="incidentDrawer" role="region" aria-label="Incident response form" method="POST" action="" enctype="multipart/form-data">
            <div class="pp-form">
              <div>
                <label class="pp-form-label" for="reporterName">Reporter Name</label>
                <input class="pp-textarea" id="reporterName" name="reporter_name" type="text" placeholder="Your name or role" style="min-height:2.75rem;resize:none;">
              </div>

              <div>
                <label class="pp-form-label" for="reporterContact">Reporter Contact</label>
                <input class="pp-textarea" id="reporterContact" name="reporter_contact" type="text" placeholder="Phone number or office contact" style="min-height:2.75rem;resize:none;">
              </div>

              <div>
                <label class="pp-form-label" for="incidentLocation">Location</label>
                <input class="pp-textarea" id="incidentLocation" name="location" type="text" placeholder="Where is the student now?" required style="min-height:2.75rem;resize:none;">
              </div>

              <div>
                <label class="pp-form-label">Upload Incident Photos</label>
                <label class="pp-dropzone">
                  <input id="incidentPhotos" name="incident_photos[]" type="file" accept="image/*" multiple hidden>
                  <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.6rem;">upload_file</span>
                  <strong>Click to upload photos</strong>
                  <span>Supported: JPG, PNG, WEBP</span>
                </label>
              </div>

              <div>
                <label class="pp-form-label" for="incidentDesc">Incident Description</label>
                <textarea class="pp-textarea" id="incidentDesc" name="incident_notes" required placeholder="Describe what happened, the student condition, actions already taken, and other observations."></textarea>
              </div>

              <button class="pp-button pp-button-danger" id="submitReportBtn" type="submit" style="width:100%;">
                <span class="material-symbols-outlined" aria-hidden="true">send</span>
                Submit Emergency Report
              </button>
            </div>
          </form>
        </section>

        <section class="pp-card">
          <div class="pp-section-head">
            <div>
              <h2 class="pp-section-title">What To Do</h2>
              <p class="pp-section-copy">Quick guidance for the conditions listed on this passport.</p>
            </div>
            <span class="material-symbols-outlined" style="color:var(--primary);" aria-hidden="true">checklist</span>
          </div>

          <div class="pp-guidance-grid">
            <?php foreach ($guidance as $item): ?>
              <article class="pp-guide <?= pp_e($item['class']) ?>">
                <div class="pp-guide-head">
                  <span class="material-symbols-outlined" aria-hidden="true"><?= pp_e($item['icon']) ?></span>
                  <?= pp_e($item['title']) ?>
                </div>
                <ul>
                  <?php foreach ($item['steps'] as $step): ?>
                    <li><?= pp_e($step) ?></li>
                  <?php endforeach; ?>
                </ul>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <div class="pp-updated">
          <span class="material-symbols-outlined" aria-hidden="true">schedule</span>
          Profile last updated: <strong><?= pp_e($student['last_updated']) ?></strong> &middot; Data provided by PLP School Clinic
        </div>
      </aside>
    </div>
  </main>

  <footer class="pp-footer">
    This page is generated by <strong>CLINiQ - PLP School Clinic</strong>.<br>
    Unauthorised access or misuse of this information is prohibited.
  </footer>

  <div class="pp-sticky-notify" aria-label="Sticky emergency action">
    <button class="pp-button <?= $reportSubmitted ? 'pp-button-success' : 'pp-button-danger' ?>" type="button" id="stickyNotifyBtn" aria-expanded="false" aria-controls="incidentDrawer" <?= $reportSubmitted ? 'disabled' : '' ?>>
      <span class="material-symbols-outlined" aria-hidden="true"><?= $reportSubmitted ? 'check_circle' : 'notifications_active' ?></span>
      <?= $reportSubmitted ? 'Report Submitted' : 'Notify Clinic' ?>
    </button>
  </div>

  <script>
    const notifyBtn = document.getElementById('notifyClinicBtn');
    const stickyNotifyBtn = document.getElementById('stickyNotifyBtn');
    const drawer = document.getElementById('incidentDrawer');
    const statusCard = document.getElementById('incidentStatusCard');

    function setNotifyState(nextOpen) {
      if (notifyBtn.disabled || stickyNotifyBtn.disabled) {
        return;
      }

      drawer.classList.toggle('open', nextOpen);
      notifyBtn.setAttribute('aria-expanded', String(nextOpen));
      stickyNotifyBtn.setAttribute('aria-expanded', String(nextOpen));

      [notifyBtn, stickyNotifyBtn].forEach((button) => {
        button.classList.toggle('pp-button-warning', nextOpen);
        button.classList.toggle('pp-button-danger', !nextOpen);
        button.innerHTML = nextOpen
        ? '<span class="material-symbols-outlined" aria-hidden="true">close</span>Cancel Report'
        : '<span class="material-symbols-outlined" aria-hidden="true">notifications_active</span>Notify Clinic';
      });

      if (nextOpen) {
        document.querySelector('.pp-contact-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }

    function toggleNotifyDrawer() {
      setNotifyState(!drawer.classList.contains('open'));
    }

    notifyBtn.addEventListener('click', toggleNotifyDrawer);
    stickyNotifyBtn.addEventListener('click', toggleNotifyDrawer);
  </script>
</body>

</html>
