<?php

require_once __DIR__ . '/../app/helpers/auth.php';

logout_user();
header('Location: ' . app_url('index.php'));
exit;
