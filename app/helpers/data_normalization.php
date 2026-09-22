<?php

declare(strict_types=1);

function cliniq_normalize_whitespace(?string $value, bool $preserveParagraphs = false): string
{
    $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
    $value = preg_replace('/[\t\x{00A0}\x{2000}-\x{200B}]+/u', ' ', $value) ?? '';
    $lines = array_map(static fn (string $line): string => preg_replace('/ {2,}/u', ' ', trim($line)) ?? '', explode("\n", $value));
    $value = $preserveParagraphs
        ? preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? ''
        : implode(' ', array_filter($lines, static fn (string $line): bool => $line !== ''));
    return trim($value);
}

function cliniq_normalize_free_text(?string $value): string
{
    return cliniq_normalize_whitespace($value, true);
}

function cliniq_normalize_person_name(?string $value): string
{
    $value = cliniq_normalize_whitespace($value);
    if ($value === '' || (mb_strtolower($value, 'UTF-8') !== $value && mb_strtoupper($value, 'UTF-8') !== $value)) {
        return $value;
    }
    $particles = ['de', 'del', 'la', 'van', 'von'];
    $suffixes = ['jr', 'jr.', 'sr', 'sr.', 'i', 'ii', 'iii', 'iv', 'v'];
    $words = preg_split('/\s+/u', $value) ?: [];
    foreach ($words as $index => $word) {
        $lower = mb_strtolower($word, 'UTF-8');
        if ($index > 0 && in_array($lower, $particles, true)) { $words[$index] = $lower; continue; }
        if (in_array($lower, $suffixes, true)) { $words[$index] = strtoupper(rtrim($lower, '.')) . (str_ends_with($lower, '.') ? '.' : ''); continue; }
        $words[$index] = preg_replace_callback("/[\\p{L}]+(?:['-][\\p{L}]+)*/u", static fn (array $m): string => mb_convert_case(mb_strtolower($m[0], 'UTF-8'), MB_CASE_TITLE, 'UTF-8'), $word) ?? $word;
    }
    return implode(' ', $words);
}

function cliniq_normalize_email(?string $value): string { return mb_strtolower(cliniq_normalize_whitespace($value), 'UTF-8'); }
function cliniq_normalize_phone(?string $value): ?string { return function_exists('cliniq_format_ph_mobile') ? cliniq_format_ph_mobile((string) $value) : null; }
function cliniq_normalize_blood_type(?string $value): string { return strtoupper(cliniq_normalize_whitespace($value)); }
