<?php

require_once __DIR__ . '/SystemReportRenderer.php';

function system_report_chrome_path(): ?string
{
    $paths = [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    ];
    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function system_report_remove_temp_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            system_report_remove_temp_directory($path);
        }
    }
    @rmdir($directory);
}

function system_report_run_browser(string $command, string $pdfPath, int $timeoutSeconds = 30): array
{
    $logPath = $pdfPath . '.log';
    $pipes = [];
    $process = proc_open($command, [1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']], $pipes);
    if (!is_resource($process)) {
        return [1, 'Unable to start the PDF browser process.'];
    }
    $startedAt = microtime(true);
    $timedOut = false;
    $lastSize = 0;
    $stableChecks = 0;
    $pdfReady = false;
    do {
        $status = proc_get_status($process);
        clearstatcache(true, $pdfPath);
        $size = is_file($pdfPath) ? (int) filesize($pdfPath) : 0;
        if ($size >= 500 && $size === $lastSize) {
            $stableChecks++;
        } else {
            $stableChecks = 0;
        }
        $lastSize = $size;
        if ($stableChecks >= 3) {
            $pdfReady = true;
            if ($status['running']) {
                proc_terminate($process);
            }
            break;
        }
        if (!$status['running']) {
            break;
        }
        if (microtime(true) - $startedAt >= $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(100000);
    } while (true);
    $exitCode = proc_close($process);
    $output = is_file($logPath) ? (string) file_get_contents($logPath) : '';
    @unlink($logPath);
    return [$timedOut ? 124 : ($pdfReady ? 0 : $exitCode), trim($output)];
}

function generate_system_report_pdf(array $report, array $renderOptions = []): string
{
    $browser = system_report_chrome_path();
    if ($browser === null || !function_exists('proc_open')) {
        throw new RuntimeException('PDF export requires Google Chrome or Microsoft Edge on the clinic computer.');
    }

    $base = tempnam(sys_get_temp_dir(), 'cliniq_report_');
    if ($base === false) {
        throw new RuntimeException('Unable to create the temporary report file.');
    }
    @unlink($base);
    $htmlPath = $base . '.html';
    $pdfPath = $base . '.pdf';
    $profilePath = $base . '_profile';
    @mkdir($profilePath, 0700, true);
    $html = render_system_report_document($report, true, $renderOptions);
    if (file_put_contents($htmlPath, $html) === false) {
        throw new RuntimeException('Unable to prepare the report for PDF export.');
    }

    $fileUrl = 'file:///' . str_replace(['\\', ' '], ['/', '%20'], $htmlPath);
    $command = escapeshellarg($browser)
        . ' --headless=new --disable-gpu --no-pdf-header-footer --run-all-compositor-stages-before-draw'
        . ' --disable-background-networking --disable-extensions --disable-sync --no-first-run'
        . ' --user-data-dir=' . escapeshellarg($profilePath)
        . ' --print-to-pdf=' . escapeshellarg($pdfPath)
        . ' ' . escapeshellarg($fileUrl);
    [$exitCode, $output] = system_report_run_browser($command, $pdfPath);
    @unlink($htmlPath);
    system_report_remove_temp_directory($profilePath);

    for ($attempt = 0; $attempt < 50; $attempt++) {
        clearstatcache(true, $pdfPath);
        if (is_file($pdfPath) && filesize($pdfPath) >= 500) {
            break;
        }
        usleep(100000);
    }

    if (!is_file($pdfPath) || filesize($pdfPath) < 500) {
        @unlink($pdfPath);
        $reason = $exitCode === 124 ? 'The PDF renderer exceeded the 30-second limit.' : $output;
        throw new RuntimeException('The PDF could not be generated. ' . trim($reason));
    }

    return $pdfPath;
}
