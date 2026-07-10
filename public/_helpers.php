<?php
// /plugins/contact-form/public/_helpers.php
declare(strict_types=1);

function cf_public_ctx(PDO $pdo): array {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (is_string($ip) && strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    $secret = cf_get_secret($pdo);
    $token = hash_hmac('sha256', session_id() . 'cf', $secret);
    return [
        'csrf' => $token,
        'ip' => (string)$ip,
    ];
}

function cf_csrf_check(string $token, string $sent): bool {
    return hash_equals($token, trim($sent));
}
