<?php

require_once __DIR__ . '/../../app/helpers/auth.php';
require_report_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce_request();
    $_POST['inline'] = '1';
} else {
    $_GET['inline'] = '1';
}

require __DIR__ . '/download.php';
