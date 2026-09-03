<?php
// Read-only: test the actual scheduling options, markup and server normalizer.
$source = file_get_contents(__DIR__ . '/../public/ape/scheduling.php');
$start = strpos($source, '$apeBatchTimeOptions = [];');
$end = strpos($source, "set_page_back_link('index.php'", $start);
eval(substr($source, $start, $end - $start));
function check_time(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
check_time(count($apeBatchTimeOptions) === 541, 'Preserve every minute from 8 AM through 5 PM.');
check_time($apeBatchTimeOptions['08:00'] === '8:00 AM', 'Opening time.');
check_time($apeBatchTimeOptions['12:00'] === '12:00 PM', 'Noon is PM.');
check_time($apeBatchTimeOptions['13:30'] === '1:30 PM', 'Afternoon uses 12-hour labels.');
check_time($apeBatchTimeOptions['17:00'] === '5:00 PM', 'Closing time.');
foreach ($apeBatchTimeOptions as $value => $label) {
    check_time($value >= '08:00' && $value <= '17:00', 'No outside-hours options.');
    check_time((bool) preg_match('/^(?:[1-9]|1[0-2]):[0-5][0-9] (AM|PM)$/', $label), 'All labels use AM/PM.');
}
function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
$dom = new DOMDocument();
libxml_use_internal_errors(true);
foreach (['apeBatchStart' => 'start_time', 'apeBatchEnd' => 'end_time'] as $id => $name) {
    $start = strpos($source, '<select class="clinic-select" id="' . $id . '"');
    $end = strpos($source, '</select>', $start) + strlen('</select>');
    ob_start(); eval('?>' . substr($source, $start, $end - $start)); $html = ob_get_clean();
    $dom->loadHTML($html); libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    check_time($xpath->query('//select[@name="' . $name . '"][@required]/option')->length === 542, 'Required select includes placeholder and minute options.');
}
$service = file_get_contents(__DIR__ . '/../app/services/ApeCycleService.php');
$start = strpos($service, 'function normalize_ape_batch_time(');
$end = strpos($service, 'function ape_schedule_batches(', $start);
eval(substr($service, $start, $end - $start));
foreach (array_keys($apeBatchTimeOptions) as $value) {
    check_time(normalize_ape_batch_time($value, 'time') === $value . ':00', 'Every displayed option is accepted by server.');
}
foreach (['07:59', '17:01', '02:00', '23:59', '', '8:00 AM', '12:60'] as $value) {
    $rejected = false;
    try { normalize_ape_batch_time($value, 'time'); } catch (InvalidArgumentException $e) { $rejected = true; }
    check_time($rejected, 'Invalid/outside-hours submitted value rejected: ' . $value);
}
echo "PASS: AM/PM option labels, full minute precision, rendered required dropdowns, and server hour validation. No database writes.\n";
