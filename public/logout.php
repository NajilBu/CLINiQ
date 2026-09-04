<?php

require_once __DIR__ . '/../app/helpers/auth.php';

logout_user();
$isElectronRuntime = str_contains(strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 'cliniqelectron/');
header('Location: ' . app_url($isElectronRuntime ? 'login.php' : 'index.php'));
exit;
