<?php

declare(strict_types=1);

final class LoginThrottleException extends RuntimeException
{
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION['cliniq_csrf_token']) || !is_string($_SESSION['cliniq_csrf_token'])) {
        $_SESSION['cliniq_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['cliniq_csrf_token'];
}

function csrf_rotate_token(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['cliniq_csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_request_token(): string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($header) && $header !== '') {
        return $header;
    }
    $posted = $_POST['_csrf'] ?? '';
    return is_string($posted) ? $posted : '';
}

function csrf_request_is_valid(?string $provided = null): bool
{
    $provided ??= csrf_request_token();
    return $provided !== '' && hash_equals(csrf_token(), $provided);
}

function csrf_enforce_request(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }
    if (PHP_SAPI === 'cli' && env_value('CLINIQ_ENFORCE_CSRF_IN_CLI', 'false') !== 'true') {
        return;
    }
    if (csrf_request_is_valid()) {
        return;
    }

    http_response_code(419);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    exit('This form has expired or the request could not be verified. Refresh the page and try again.');
}

function auth_request_ip(): string
{
    $cloudflareIp = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cloudflareIp !== '' && filter_var($cloudflareIp, FILTER_VALIDATE_IP)) {
        return $cloudflareIp;
    }
    $remoteIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return filter_var($remoteIp, FILTER_VALIDATE_IP) ? $remoteIp : 'unknown';
}

function auth_throttle_hash(string $value): string
{
    return hash_hmac('sha256', strtolower(trim($value)), (string) env_value('APP_KEY', 'development-only-key'));
}

function auth_throttle_assert_allowed(PDO $db, string $portal, string $identifier): void
{
    $db->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $stmt = $db->prepare('
        SELECT
          SUM(identifier_hash = ?) AS identifier_failures,
          SUM(ip_hash = ?) AS ip_failures
        FROM login_attempts
        WHERE portal = ? AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ');
    $stmt->execute([auth_throttle_hash($identifier), auth_throttle_hash(auth_request_ip()), $portal]);
    $failures = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int) ($failures['identifier_failures'] ?? 0) >= 5 || (int) ($failures['ip_failures'] ?? 0) >= 25) {
        throw new LoginThrottleException('Too many unsuccessful sign-in attempts. Wait 15 minutes before trying again.');
    }
}

function auth_throttle_record_failure(PDO $db, string $portal, string $identifier): void
{
    $stmt = $db->prepare('INSERT INTO login_attempts (portal, identifier_hash, ip_hash) VALUES (?, ?, ?)');
    $stmt->execute([$portal, auth_throttle_hash($identifier), auth_throttle_hash(auth_request_ip())]);
}

function auth_throttle_clear(PDO $db, string $portal, string $identifier): void
{
    $stmt = $db->prepare('DELETE FROM login_attempts WHERE portal = ? AND identifier_hash = ?');
    $stmt->execute([$portal, auth_throttle_hash($identifier)]);
}
