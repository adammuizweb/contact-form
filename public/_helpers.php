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

function cf_rate_limit_check(PDO $pdo, string $ip, string $action, int $windowSeconds, int $max): bool {
    if ($ip === '') return true;
    $bucket = intdiv(time(), max(1, $windowSeconds));
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `contact_rate_limits` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `ip` varchar(45) NOT NULL,
                `action` varchar(40) NOT NULL,
                `bucket` int(10) unsigned NOT NULL,
                `count` int(10) unsigned NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                UNIQUE KEY `ip_action_bucket` (`ip`,`action`,`bucket`),
                KEY `action` (`action`),
                KEY `bucket` (`bucket`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $st = $pdo->prepare("
            INSERT INTO `contact_rate_limits` (`ip`, `action`, `bucket`, `count`)
            VALUES (:ip, :action, :bucket, 1)
            ON DUPLICATE KEY UPDATE count = count + 1
        ");
        $st->execute([':ip' => $ip, ':action' => $action, ':bucket' => $bucket]);

        $q = $pdo->prepare("SELECT count FROM `contact_rate_limits` WHERE ip = ? AND action = ? AND bucket = ? LIMIT 1");
        $q->execute([$ip, $action, $bucket]);
        $cnt = (int)($q->fetchColumn() ?: 0);
        return $cnt <= $max;
    } catch (Throwable $e) {
        return true;
    }
}
