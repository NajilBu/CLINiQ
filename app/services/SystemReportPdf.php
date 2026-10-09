<?php

require_once __DIR__ . '/SystemReport.php';
require_once __DIR__ . '/SystemReportRenderer.php';

final class SystemReportPdfDocument
{
    private const WIDTH = 595.28;
    private const HEIGHT = 841.89;
    private array $pages = [];
    private string $page = '';
    private float $y = 0.0;

    public function startPage(string $title): void
    {
        if ($this->page !== '') $this->pages[] = $this->page;
        $this->page = '';
        $this->y = 744;
        $this->line(42, 770, 553, 770, [47, 133, 83]);
        $this->text(42, 782, $title, 14, [31, 107, 67], true);
    }

    public function write(string $text, int $size = 9, bool $bold = false): void
    {
        foreach ($this->wrap($text, $size) as $line) {
            if ($this->y < 52) $this->startPage('CLINiQ Clinic Transaction Summary');
            $this->text(48, $this->y, $line, $size, [51, 65, 85], $bold);
            $this->y -= max(11, $size + 3);
        }
    }

    public function gap(float $height = 8): void { $this->y -= $height; }

    public function table(string $title, array $rows): void
    {
        $height = 38 + count($rows) * 17;
        if ($this->y - $height < 52) $this->startPage('CLINiQ Clinic Transaction Summary');
        $this->text(48, $this->y, $title, 10, [31, 107, 67], true);
        $this->y -= 18;
        $this->rect(48, $this->y - 16, 470, 16, [47, 133, 83]);
        $this->text(54, $this->y - 11, 'Measure', 8, [255, 255, 255], true);
        $this->text(486, $this->y - 11, 'Value', 8, [255, 255, 255], true);
        $this->y -= 20;
        foreach ($rows as $index => $row) {
            if ($index % 2 === 0) $this->rect(48, $this->y - 15, 470, 15, [237, 246, 237]);
            $this->text(54, $this->y - 10, (string) ($row['label'] ?? 'Not specified'), 8, [51, 65, 85], false);
            $this->text(496, $this->y - 10, system_report_format_number((float) ($row['value'] ?? 0), (int) ($row['decimals'] ?? 0)), 8, [31, 107, 67], true);
            $this->y -= 17;
        }
        $this->gap(10);
    }

    public function render(): string
    {
        if ($this->page !== '') $this->pages[] = $this->page;
        $objects = [3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', 4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>'];
        $kids = []; $id = 5;
        $total = count($this->pages);
        foreach ($this->pages as $number => $stream) {
            $content = $id++; $page = $id++;
            $stream .= $this->footer($number + 1, $total);
            $objects[$content] = '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}endstream";
            $objects[$page] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::WIDTH . ' ' . self::HEIGHT . "] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$content} 0 R >>";
            $kids[] = "{$page} 0 R";
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n"; $offsets = [0];
        foreach ($objects as $objectId => $object) { $offsets[$objectId] = strlen($pdf); $pdf .= "{$objectId} 0 obj\n{$object}\nendobj\n"; }
        $xref = strlen($pdf); $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private function text(float $x, float $y, string $text, int $size, array $rgb, bool $bold): void
    {
        [$r, $g, $b] = array_map(static fn(int $value): string => rtrim(rtrim(sprintf('%.3F', $value / 255), '0'), '.'), $rgb);
        $safe = preg_replace('/[^\x20-\x7E]/', '', strtr($text, ['–' => '-', '—' => '-', '·' => '-', '’' => "'"])) ?? '';
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $safe);
        $this->page .= "BT /" . ($bold ? 'F2' : 'F1') . " {$size} Tf {$r} {$g} {$b} rg {$x} {$y} Td ({$safe}) Tj ET\n";
    }

    private function line(float $x1, float $y1, float $x2, float $y2, array $rgb): void
    {
        [$r, $g, $b] = array_map(static fn(int $value): string => rtrim(rtrim(sprintf('%.3F', $value / 255), '0'), '.'), $rgb);
        $this->page .= "{$r} {$g} {$b} RG .6 w {$x1} {$y1} m {$x2} {$y2} l S\n";
    }

    private function rect(float $x, float $y, float $width, float $height, array $rgb): void
    {
        [$r, $g, $b] = array_map(static fn(int $value): string => rtrim(rtrim(sprintf('%.3F', $value / 255), '0'), '.'), $rgb);
        $this->page .= "{$r} {$g} {$b} rg {$x} {$y} {$width} {$height} re f\n";
    }

    private function footer(int $page, int $total): string
    {
        return "0.812 0.871 0.831 RG .5 w 42 34 m 553 34 l S\nBT /F1 7 Tf 0.392 0.455 0.447 rg 42 20 Td (CLINiQ Clinic Transaction Summary) Tj ET\nBT /F1 7 Tf 0.392 0.455 0.447 rg 505 20 Td (Page {$page} of {$total}) Tj ET\n";
    }

    private function wrap(string $text, int $size): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return $text === '' ? [''] : explode("\n", wordwrap($text, max(36, (int) floor(100 / max(1, $size / 5))), "\n", true));
    }
}

function render_system_report_pdf(array $report, array $options = []): string
{
    $pdf = new SystemReportPdfDocument();
    $pdf->startPage('CLINiQ Clinic Transaction Summary');
    $pdf->write('Reporting period: ' . date('M j, Y', strtotime($report['date_from'])) . ' to ' . date('M j, Y', strtotime($report['date_to'])) . '.', 10);
    $pdf->write('Prepared by: ' . (string) (($options['prepared_by']['name'] ?? '') ?: 'Authorized CLINiQ staff') . '.', 9);
    $pdf->gap(12);
    $pdf->table('Transaction Summary', (array) ($report['transaction_summary'] ?? []));
    $pdf->table('Attention Summary', (array) ($report['attention_summary'] ?? []));
    foreach ($report['sections'] as $key => $section) {
        $pdf->startPage((string) $section['title']);
        $pdf->write((string) ($section['description'] ?? ''), 9);
        foreach ($section['metrics'] ?? [] as $metric) $pdf->write((string) $metric['label'] . ': ' . system_report_format_number((float) $metric['value'], (int) ($metric['decimals'] ?? 0)), 10, true);
        foreach (array_merge($section['charts'] ?? [], $section['tables'] ?? []) as $chart) {
            $pdf->gap(); $pdf->write((string) $chart['title'], 10, true);
            $rows = (array) ($chart['rows'] ?? []);
            if ($rows === []) { $pdf->write((string) ($chart['empty'] ?? $chart['insight'] ?? 'No data available for this period.')); continue; }
            foreach ($rows as $row) $pdf->write('- ' . (string) ($row['label'] ?? 'Not specified') . ': ' . system_report_format_number((float) ($row['value'] ?? 0), (int) ($chart['decimals'] ?? 0)));
        }
        $remark = $options['remarks'][$key] ?? '';
        if (is_string($remark) && trim($remark) !== '') { $pdf->gap(); $pdf->write('Remarks', 10, true); $pdf->write($remark); }
    }
    return $pdf->render();
}
