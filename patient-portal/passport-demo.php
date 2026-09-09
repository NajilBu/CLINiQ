<?php

declare(strict_types=1);

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(404);
    exit;
}

header('Location: ../public/emergency.php?token=' . urlencode($token));
exit;
