<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/SystemSettings.php';

$document = (string) ($legalDocument ?? 'privacy');
$allowedDocuments = ['terms', 'privacy'];
if (!in_array($document, $allowedDocuments, true)) {
    http_response_code(404);
    exit('Legal document not found.');
}

$legalDocuments = cliniq_legal_documents();
$documentData = $legalDocuments[$document] ?? [];
$pageTitle = $document === 'terms' ? 'Terms of Use' : 'Privacy Notice';
$clinicProfile = clinic_profile_settings();
$clinicLogoUrl = app_url(clinic_profile_logo_path($clinicProfile));
$theme = active_cliniq_theme();

function render_legal_inline(string $text): string
{
    $text = e($text);
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^\)]+)\)/', static function (array $match): string {
        $label = $match[1];
        $target = trim(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'));
        if ($target === 'privacy-notice.md') {
            $target = 'privacy-notice.php';
        } elseif ($target === 'terms-of-use.md') {
            $target = 'terms-of-use.php';
        }
        if (!preg_match('/^(?:https?:\/\/|[A-Za-z0-9._\/-]+\.php(?:\?[A-Za-z0-9=&_.-]+)?)$/', $target)) {
            return $label;
        }
        return '<a href="' . e($target) . '">' . $label . '</a>';
    }, $text) ?? $text;
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text) ?? $text;
    $text = preg_replace('/(?<!_)_([^_]+)_(?!_)/', '<em>$1</em>', $text) ?? $text;
    return $text;
}

function render_legal_document(string $markdown): string
{
    $lines = preg_split('/\R/', str_replace(["\r\n", "\r"], "\n", $markdown)) ?: [];
    $html = '';
    $paragraph = [];
    $listType = null;

    $flushParagraph = static function () use (&$html, &$paragraph): void {
        if ($paragraph === []) {
            return;
        }
        $html .= '<p>' . render_legal_inline(implode(' ', array_map('trim', $paragraph))) . '</p>';
        $paragraph = [];
    };
    $closeList = static function () use (&$html, &$listType): void {
        if ($listType !== null) {
            $html .= '</' . $listType . '>';
            $listType = null;
        }
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $flushParagraph();
            $closeList();
            continue;
        }
        if (preg_match('/^(#{1,3})\s+(.+)$/', $trimmed, $match)) {
            $flushParagraph();
            $closeList();
            $level = strlen($match[1]);
            $html .= '<h' . $level . '>' . render_legal_inline($match[2]) . '</h' . $level . '>';
            continue;
        }
        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $match) || preg_match('/^\d+[.)]\s+(.+)$/', $trimmed, $match)) {
            $nextType = preg_match('/^\d+[.)]/', $trimmed) ? 'ol' : 'ul';
            $flushParagraph();
            if ($listType !== $nextType) {
                $closeList();
                $listType = $nextType;
                $html .= '<' . $listType . '>';
            }
            $html .= '<li>' . render_legal_inline($match[1]) . '</li>';
            continue;
        }
        $paragraph[] = $trimmed;
    }
    $flushParagraph();
    $closeList();
    return $html;
}

function render_structured_legal_document(array $document): string
{
    $labels = [
        'effective_date' => 'Effective date',
        'system_owner' => 'System owner',
        'controller' => 'Controller',
        'clinic_address' => 'Clinic address',
        'contact_office' => 'Contact office',
        'contact_email' => 'Contact email',
        'contact_phone' => 'Contact phone',
        'privacy_contact' => 'Privacy contact',
        'privacy_email' => 'Privacy email',
        'privacy_phone' => 'Privacy phone',
    ];
    $meta = '';
    foreach ((array) ($document['details'] ?? []) as $label => $value) {
        if (trim((string) $value) !== '') {
            $meta .= '<div class="legal-meta-item"><dt>' . e($labels[$label] ?? ucwords(str_replace('_', ' ', (string) $label))) . '</dt><dd>' . e((string) $value) . '</dd></div>';
        }
    }
    return '<header class="legal-document-header"><p class="legal-eyebrow">Secure patient access</p><h1>' . e((string) ($document['title'] ?? 'Legal document')) . '</h1><p class="legal-intro">Please read this document carefully. It explains how CLINiQ is used and how the clinic handles information.</p></header>'
        . ($meta !== '' ? '<dl class="legal-meta">' . $meta . '</dl>' : '')
        . '<div class="legal-document-body">' . (string) ($document['body_html'] ?? '') . '</div>';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e($clinicProfile['system_name']) ?></title>
    <link href="../assets/vendor/fonts/inter-manrope.css?v=offline-1" rel="stylesheet">
    <style>
        :root { color-scheme: light; font-family: Inter, Arial, sans-serif; background: <?= e($theme['surface']) ?>; color: #17261d; }
        * { box-sizing: border-box; }
        html, body { max-width: 100%; overflow-x: hidden; }
        body { margin: 0; padding: clamp(1rem, 3vw, 2.5rem); background: linear-gradient(135deg, color-mix(in srgb, <?= e($theme['primary']) ?> 7%, #fff), color-mix(in srgb, <?= e($theme['surface']) ?> 88%, #fff)); }
        main { width: 100%; max-width: 1184px; min-height: calc(100svh - 5rem); margin: 0 auto; background: #fff; border: 1px solid <?= e($theme['outline_variant']) ?>; border-radius: 1.25rem; box-shadow: 0 20px 50px rgba(22, 40, 28, .08); overflow: hidden; }
        a { color: <?= e($theme['primary']) ?>; font-weight: 700; }
        .legal-topbar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1.25rem clamp(1.25rem, 3vw, 2.5rem); border-bottom: 1px solid <?= e($theme['outline_variant']) ?>; }
        .legal-brand { display: flex; align-items: center; gap: .85rem; min-width: 0; }
        .legal-brand-mark { display: grid; width: 3rem; height: 3rem; flex: 0 0 3rem; place-items: center; padding: .15rem; border: 1px solid <?= e($theme['outline_variant']) ?>; border-radius: .5rem; background: #fff; }
        .legal-brand-mark img { width: 100%; height: 100%; object-fit: contain; }
        .legal-brand-copy { min-width: 0; }
        .legal-brand-copy p { margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .legal-brand-department { color: #7b9185; font-size: .68rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        .legal-brand-name { color: #17261d; font-family: Manrope, Inter, sans-serif; font-size: .95rem; font-weight: 800; }
        .legal-back { display: inline-flex; align-items: center; gap: .45rem; padding: .7rem .95rem; border: 1px solid <?= e($theme['outline_variant']) ?>; border-radius: 12px; background: #fff; box-shadow: 0 6px 18px rgba(23, 38, 29, .08); text-decoration: none; white-space: nowrap; }
        .legal-content { padding: clamp(1.5rem, 5vw, 4.5rem) clamp(1.25rem, 7vw, 6rem); }
        .legal-document { color: #30443a; overflow-wrap: anywhere; font: 1rem/1.75 Inter, Arial, sans-serif; }
        .legal-document-header { max-width: 760px; }
        .legal-eyebrow { margin: 0 0 .6rem; color: <?= e($theme['primary']) ?>; font-size: .72rem; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
        .legal-document h1 { margin: 0; color: #17261d; font-size: clamp(2rem, 5vw, 3.15rem); font-weight: 800; letter-spacing: -.035em; line-height: 1.08; }
        .legal-intro { margin: 1rem 0 0; color: #64766d; font-size: 1.05rem; line-height: 1.65; }
        .legal-meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .8rem; margin: 2rem 0 2.75rem; padding: 1rem; border: 1px solid #e2ebe5; border-radius: 16px; background: #f7faf8; }
        .legal-meta-item { min-width: 0; padding: .65rem .75rem; }
        .legal-meta-item dt { margin-bottom: .25rem; color: #7b9185; font-size: .68rem; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; }
        .legal-meta-item dd { margin: 0; color: #17261d; font-size: .95rem; line-height: 1.5; overflow-wrap: anywhere; }
        .legal-document-body { width: 100%; max-width: 760px; overflow-wrap: anywhere; word-break: normal; }
        .legal-document-body table { display: block; width: 100%; overflow-x: auto; border-collapse: collapse; margin: 1.35rem 0 1.6rem; border: 1px solid #dce9e1; border-radius: .8rem; background: #fff; }
        .legal-document-body th, .legal-document-body td { min-width: 12rem; padding: .8rem .9rem; border-bottom: 1px solid #e5eee8; text-align: left; vertical-align: top; }
        .legal-document-body th { color: #246b49; background: #f1f7f3; font-size: .78rem; letter-spacing: .08em; text-transform: uppercase; }
        .legal-document-body tr:last-child td { border-bottom: 0; }
        .legal-document h2, .legal-document h3 { color: #17261d; font-weight: 800; line-height: 1.25; margin: 2.3rem 0 .7rem; }
        .legal-document h2 { font-size: 1.4rem; border-top: 1px solid #e4ece7; padding-top: 1.35rem; }
        .legal-document h3 { font-size: 1.1rem; }
        .legal-document p { margin: 0 0 1.05rem; }
        .legal-document ul, .legal-document ol { margin: 0 0 1.1rem 1.35rem; padding: 0; }
        .legal-document li { margin: .35rem 0; padding-left: .25rem; }
        .legal-document strong { color: #17261d; }
        @media (max-width: 640px) {
            body { padding: 0; }
            main { min-height: 100svh; border-width: 0; border-radius: 0; }
            .legal-topbar { align-items: flex-start; padding: 1rem 1.25rem; }
            .legal-brand-mark { width: 2.65rem; height: 2.65rem; flex-basis: 2.65rem; }
            .legal-back { padding: .6rem .75rem; font-size: .82rem; }
            .legal-content { padding: 2.25rem 1.25rem 3rem; }
            .legal-meta { grid-template-columns: 1fr; margin: 1.5rem 0 2rem; padding: .65rem; gap: .35rem; }
            .legal-meta-item { padding: .6rem .65rem; }
            .legal-document { font-size: .98rem; line-height: 1.7; }
            .legal-document h1 { font-size: 2rem; }
            .legal-document h2 { font-size: 1.25rem; margin-top: 2rem; }
        }
        @media print { body { padding: 0; background: #fff; } .legal-topbar, .legal-back { display: none; } main { width: auto; border: 0; box-shadow: none; } .legal-content { padding: 0; } }
    </style>
</head>
<body>
<main>
    <header class="legal-topbar">
        <div class="legal-brand">
            <a class="legal-brand-mark" href="../../patient-portal/patient-welcome.php" aria-label="<?= e($clinicProfile['system_name']) ?> home"><img src="<?= e($clinicLogoUrl) ?>" alt="<?= e($clinicProfile['department']) ?> logo"></a>
            <div class="legal-brand-copy">
                <p class="legal-brand-department"><?= e($clinicProfile['department']) ?></p>
                <p class="legal-brand-name"><?= e($clinicProfile['system_name']) ?></p>
            </div>
        </div>
        <a class="legal-back" href="../../patient-portal/patient-welcome.php">&larr; Back to portal</a>
    </header>
    <div class="legal-content">
        <article class="legal-document"><?= render_structured_legal_document($documentData) ?></article>
    </div>
</main>
</body>
</html>
