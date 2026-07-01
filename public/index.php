<?php

require_once __DIR__ . '/../app/helpers/auth.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

header('Location: login.php');
exit;
