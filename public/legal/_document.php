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
    $html = '<h1>' . e((string) ($document['title'] ?? 'Legal document')) . '</h1>';
    foreach ((array) ($document['details'] ?? []) as $label => $value) {
        if (trim((string) $value) !== '') {
            $html .= '<p><strong>' . e(ucwords(str_replace('_', ' ', (string) $label))) . ':</strong> ' . e((string) $value) . '</p>';
        }
    }
    return $html . (string) ($document['body_html'] ?? '');
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
        body { margin: 0; padding: 2rem 1rem 4rem; background: linear-gradient(180deg, color-mix(in srgb, <?= e($theme['surface']) ?> 92%, #fff), #f7faf8); }
        main { max-width: 850px; margin: 0 auto; background: #fff; border: 1px solid <?= e($theme['outline_variant']) ?>; border-radius: 20px; padding: clamp(1.5rem, 4vw, 3.25rem); box-shadow: 0 20px 50px rgba(23, 38, 29, .08); }
        a { color: <?= e($theme['primary']) ?>; font-weight: 700; }
        .legal-back { display: inline-block; margin-bottom: 1.5rem; text-decoration: none; }
        .legal-document { color: #30443a; overflow-wrap: anywhere; font: 1rem/1.75 Inter, Arial, sans-serif; }
        .legal-document h1, .legal-document h2, .legal-document h3 { color: #17261d; font-weight: 800; line-height: 1.25; margin: 2rem 0 .7rem; }
        .legal-document h1 { font-size: clamp(1.7rem, 4vw, 2.35rem); margin-top: 0; }
        .legal-document h2 { font-size: 1.35rem; border-top: 1px solid #e4ece7; padding-top: 1.35rem; }
        .legal-document h3 { font-size: 1.1rem; }
        .legal-document p { margin: 0 0 1.05rem; }
        .legal-document ul, .legal-document ol { margin: 0 0 1.1rem 1.35rem; padding: 0; }
        .legal-document li { margin: .35rem 0; padding-left: .25rem; }
        .legal-document strong { color: #17261d; }
    </style>
</head>
<body>
<main>
    <a class="legal-back" href="../../patient-portal/patient-login.php">&larr; Back to CLINiQ Patient Portal</a>
    <article class="legal-document"><?= render_structured_legal_document($documentData) ?></article>
</main>
</body>
</html>
