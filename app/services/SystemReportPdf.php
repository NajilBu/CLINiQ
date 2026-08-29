<?php

require_once __DIR__ . '/SystemReport.php';
require_once __DIR__ . '/SystemReportRenderer.php';
require_once __DIR__ . '/SystemSettings.php';

final class SimpleReportPdf
{
    private const PAGE_W = 595.28;
    private const PAGE_H = 841.89;
    private array $pages = [];
    private string $content = '';
    private float $y = 0.0;

    public function addPage(): void
    {
        if ($this->content !== '') {
            $this->pages[] = $this->content;
        }
        $this->content = '';
        $this->y = 780;
    }

    public function setY(float $y): void
    {
        $this->y = $y;
    }

    public function getY(): float
    {
        return $this->y;
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb, bool $fill = true): void
    {
        [$r, $g, $b] = $this->rgb($rgb);
        $op = $fill ? 'f' : 'S';
        $this->content .= "{$r} {$g} {$b} " . ($fill ? 'rg' : 'RG') . "\n";
        $this->content .= $this->num($x) . ' ' . $this->num($y) . ' ' . $this->num($w) . ' ' . $this->num($h) . " re {$op}\n";
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb = [148, 163, 184], float $width = 0.7): void
    {
        [$r, $g, $b] = $this->rgb($rgb);
        $this->content .= "{$r} {$g} {$b} RG\n";
        $this->content .= $this->num($width) . " w\n";
        $this->content .= $this->num($x1) . ' ' . $this->num($y1) . ' m ' . $this->num($x2) . ' ' . $this->num($y2) . " l S\n";
    }

    public function text(float $x, float $y, string $text, int $size = 10, array $rgb = [23, 38, 29], string $font = 'F1'): void
    {
        [$r, $g, $b] = $this->rgb($rgb);
        $this->content .= "BT /{$font} {$size} Tf {$r} {$g} {$b} rg " . $this->num($x) . ' ' . $this->num($y) . ' Td (' . $this->escape($text) . ") Tj ET\n";
    }

    public function textBlock(float $x, float $y, float $width, string $text, int $size = 10, array $rgb = [23, 38, 29], float $lineHeight = 13, string $font = 'F1'): float
    {
        $lines = $this->wrap($text, max(12, (int) floor($width / max(4.2, $size * 0.52))));
        foreach ($lines as $line) {
            $this->text($x, $y, $line, $size, $rgb, $font);
            $y -= $lineHeight;
        }
        return $y;
    }

    public function render(): string
    {
        if ($this->content !== '') {
            $this->pages[] = $this->content;
            $this->content = '';
        }

        $objects = [];
        $catalogId = 1;
        $pagesId = 2;
        $fontRegularId = 3;
        $fontBoldId = 4;
        $nextId = 5;
        $pageIds = [];

        foreach ($this->pages as $stream) {
            $contentId = $nextId++;
            $pageId = $nextId++;
            $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
            $objects[$pageId] = "<< /Type /Page /Parent {$pagesId} 0 R /MediaBox [0 0 " . self::PAGE_W . ' ' . self::PAGE_H . "] /Resources << /Font << /F1 {$fontRegularId} 0 R /F2 {$fontBoldId} 0 R >> >> /Contents {$contentId} 0 R >>";
            $pageIds[] = $pageId;
        }

        $objects[$catalogId] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";
        $objects[$pagesId] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn(int $id): string => "{$id} 0 R", $pageIds)) . '] /Count ' . count($pageIds) . ' >>';
        $objects[$fontRegularId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[$fontBoldId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root {$catalogId} 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $pdf;
    }

    private function wrap(string $text, int $maxChars): array
    {
        $text = trim($this->plain($text));
        if ($text === '') {
            return [''];
        }
        return explode("\n", wordwrap($text, $maxChars, "\n", true));
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $this->plain($text));
    }

    private function plain(string $text): string
    {
        $text = strtr($text, [
            '·' => '-',
            '–' => '-',
            '—' => '-',
            '’' => "'",
            '‘' => "'",
            '“' => '"',
            '”' => '"',
        ]);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xA0-\xFF]/', '', $text) ?? $text;
    }

    private function rgb(array $rgb): array
    {
        return array_map(static fn(float|int $v): string => rtrim(rtrim(sprintf('%.4F', max(0, min(255, (float) $v)) / 255), '0'), '.'), $rgb);
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');
    }
}

function render_system_report_pdf(array $report, array $options = []): string
{
    $pdf = new SimpleReportPdf();
    $clinicProfile = clinic_profile_settings();
    $systemName = trim((string) ($clinicProfile['system_name'] ?? 'CLINiQ')) ?: 'CLINiQ';
    $institutionName = trim((string) ($clinicProfile['institution_name'] ?? 'Pamantasan ng Lungsod ng Pasig')) ?: 'Pamantasan ng Lungsod ng Pasig';
    $departmentName = trim((string) ($clinicProfile['department'] ?? 'University Health Services')) ?: 'University Health Services';
    $preparedBy = is_array($options['prepared_by'] ?? null) ? $options['prepared_by'] : [];
    $preparedByName = trim((string) ($preparedBy['name'] ?? '')) ?: 'CLINiQ Staff';
    $preparedByRole = trim((string) ($preparedBy['role'] ?? '')) ?: 'Authorized Staff';
    $preparedByRole = ucwords(str_replace('_', ' ', $preparedByRole));
    $remarks = is_array($options['remarks'] ?? null) ? $options['remarks'] : [];
    $coverage = implode(', ', array_map(static fn(array $section): string => (string) ($section['title'] ?? ''), $report['sections']));

    $pdf->addPage();
    $pdf->rect(92, 385, 412, 276, [31, 107, 67]);
    $pdf->rect(122, 612, 32, 32, [255, 255, 255]);
    $pdf->text(134, 622, '+', 18, [31, 107, 67], 'F2');
    $pdf->text(168, 628, $systemName, 17, [255, 255, 255], 'F2');
    $pdf->text(168, 615, mb_strtoupper($departmentName), 8, [217, 241, 226], 'F2');
    $pdf->text(122, 570, (string) $report['title'], 24, [255, 255, 255], 'F2');
    $pdf->textBlock(122, 545, 350, 'Consolidated operational analytics from the CLINiQ patient, clinical, appointment, inventory, APE, referral, and incident modules.', 10, [224, 242, 231], 13, 'F2');
    $pdf->rect(122, 484, 105, 36, [62, 132, 86]);
    $pdf->rect(238, 484, 120, 36, [62, 132, 86]);
    $pdf->rect(369, 484, 105, 36, [62, 132, 86]);
    $pdf->text(132, 506, 'REPORTING PERIOD', 6, [201, 232, 213], 'F2');
    $pdf->text(132, 493, date('M j, Y', strtotime($report['date_from'])) . ' - ' . date('M j, Y', strtotime($report['date_to'])), 8, [255, 255, 255], 'F2');
    $pdf->text(248, 506, 'INCLUDED MODULES', 6, [201, 232, 213], 'F2');
    $pdf->text(248, 493, count($report['sections']) . ' module(s)', 8, [255, 255, 255], 'F2');
    $pdf->text(379, 506, 'GENERATED', 6, [201, 232, 213], 'F2');
    $pdf->text(379, 493, date('M j, Y g:i A', strtotime($report['generated_at'])), 8, [255, 255, 255], 'F2');

    $pdf->rect(92, 315, 198, 36, [255, 255, 255]);
    $pdf->rect(305, 315, 198, 36, [255, 255, 255]);
    $pdf->text(102, 337, 'PREPARED FOR', 7, [100, 116, 139], 'F2');
    $pdf->textBlock(102, 326, 174, $institutionName . ' - ' . $departmentName, 8, [23, 38, 29], 10, 'F2');
    $pdf->text(315, 337, 'PREPARED BY', 7, [100, 116, 139], 'F2');
    $pdf->textBlock(315, 326, 174, "{$preparedByName} - {$preparedByRole}", 8, [23, 38, 29], 10, 'F2');
    $pdf->rect(92, 270, 198, 36, [255, 255, 255]);
    $pdf->rect(305, 270, 198, 36, [255, 255, 255]);
    $pdf->text(102, 292, 'REPORT TYPE', 7, [100, 116, 139], 'F2');
    $pdf->text(102, 280, 'System Analytics Report', 8, [23, 38, 29], 'F2');
    $pdf->text(315, 292, 'SCHOOL OFFICE', 7, [100, 116, 139], 'F2');
    $pdf->text(315, 280, $departmentName, 8, [23, 38, 29], 'F2');
    $pdf->rect(92, 205, 412, 50, [255, 255, 255]);
    $pdf->text(102, 238, 'COVERAGE', 7, [100, 116, 139], 'F2');
    $pdf->textBlock(102, 225, 386, $coverage, 8, [23, 38, 29], 10, 'F2');
    $pdf->textBlock(110, 145, 376, 'This report contains clinic operational data and should be handled only by authorized ' . $departmentName . ' personnel.', 8, [71, 85, 105], 10);

    $sectionNumber = 0;
    foreach ($report['sections'] as $sectionKey => $section) {
        $sectionNumber++;
        $pdf->addPage();
        $pdf->text(42, 780, (string) $sectionNumber, 12, [47, 133, 83], 'F2');
        $pdf->text(68, 780, (string) $section['title'], 20, [23, 38, 29], 'F2');
        $y = $pdf->textBlock(68, 760, 470, (string) $section['description'], 9, [100, 116, 139], 12);
        $y -= 12;

        $metrics = $section['metrics'] ?? [];
        $x = 42;
        foreach ($metrics as $index => $metric) {
            if ($index > 0 && $index % 4 === 0) {
                $x = 42;
                $y -= 62;
            }
            $pdf->rect($x, $y - 46, 120, 50, [251, 253, 251]);
            $pdf->text($x + 9, $y - 10, (string) $metric['label'], 7, [100, 116, 139], 'F2');
            $pdf->text($x + 9, $y - 32, system_report_format_number((float) $metric['value'], (int) ($metric['decimals'] ?? 0)), 18, [32, 95, 61], 'F2');
            $x += 128;
        }
        $y -= 78;

        foreach (($section['charts'] ?? []) as $chart) {
            if ($y < 160) {
                $pdf->addPage();
                $y = 780;
            }
            $pdf->text(42, $y, (string) $chart['title'], 12, [51, 65, 85], 'F2');
            $y -= 18;
            $rows = array_slice($chart['rows'] ?? [], 0, 8);
            $max = max(1.0, ...array_map(static fn(array $row): float => (float) ($row['value'] ?? 0), $rows ?: [['value' => 0]]));
            if (!$rows) {
                $pdf->text(54, $y, (string) ($chart['empty'] ?? 'No data available for this period.'), 9, [148, 163, 184]);
                $y -= 22;
                continue;
            }
            foreach ($rows as $row) {
                $value = (float) ($row['value'] ?? 0);
                $barW = ($value / $max) * 260;
                $pdf->textBlock(54, $y, 125, (string) ($row['label'] ?? 'Not specified'), 8, [71, 85, 105], 9);
                $pdf->rect(190, $y - 6, 260, 7, [237, 243, 239]);
                $pdf->rect(190, $y - 6, max(2, $barW), 7, [47, 133, 83]);
                $pdf->text(462, $y - 4, system_report_format_number($value), 8, [32, 95, 61], 'F2');
                $y -= 15;
            }
            $y -= 14;
        }

        if (array_key_exists($sectionKey, $remarks)) {
            if ($y < 110) {
                $pdf->addPage();
                $y = 780;
            }
            $pdf->text(42, $y, 'Remarks', 9, [71, 85, 105], 'F2');
            $pdf->rect(42, $y - 58, 510, 46, [255, 255, 255]);
            $pdf->textBlock(52, $y - 26, 490, (string) $remarks[$sectionKey], 8, [51, 65, 85], 10);
        }
    }

    return $pdf->render();
}
