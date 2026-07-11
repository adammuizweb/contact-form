<?php
// /plugins/contact-form/plugin.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT') && !defined('PLUGIN_SYSTEM_LOADED')) {
    return;
}

// Submit endpoint for the public form. Kept off the `contact` prefix so the
// `contact` slug stays free for a CMS Page. The form (rendered via the
// [contact_form] shortcode) posts to /cf-submit/.
if (function_exists('register_frontend_route')) {
    register_frontend_route('cf-submit', PLUGIN_PATH . '/contact-form/public/submit.php');
}

// Settings keys
const CF_FORM_FIELDS_KEY = 'contact_form_fields';
const CF_SECRET_KEY = 'contact_form_secret';
const CF_RECAPTCHA_ENABLED_KEY = 'contact_form_recaptcha_enabled';
const CF_RECAPTCHA_SITEKEY_KEY = 'contact_form_recaptcha_sitekey';
const CF_RECAPTCHA_SECRET_KEY = 'contact_form_recaptcha_secret';
const CF_ACCESS_CONFIG_KEY = 'contact_form_access_config';

function cf_get_secret(PDO $pdo): string {
    $secret = settings_get($pdo, CF_SECRET_KEY, '');
    if (is_string($secret) && strlen($secret) >= 32) {
        return $secret;
    }
    $secret = bin2hex(random_bytes(32));
    settings_set($pdo, CF_SECRET_KEY, $secret, 1);
    return $secret;
}

function cf_ensure_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `contact_submissions` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `contact` varchar(255) DEFAULT NULL,
            `message` text DEFAULT NULL,
            `data_json` longtext DEFAULT NULL,
            `ip` varchar(45) DEFAULT NULL,
            `is_read` tinyint(1) NOT NULL DEFAULT 0,
            `is_spam` tinyint(1) NOT NULL DEFAULT 0,
            `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `created_at` (`created_at`),
            KEY `is_read` (`is_read`),
            KEY `is_spam` (`is_spam`),
            KEY `is_deleted` (`is_deleted`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // idempotent migrations for existing tables
    try {
        $pdo->exec("ALTER TABLE `contact_submissions` ADD COLUMN IF NOT EXISTS `is_spam` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_read`");
        $pdo->exec("ALTER TABLE `contact_submissions` ADD COLUMN IF NOT EXISTS `is_deleted` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_spam`");
    } catch (Throwable $e) {
        // ignore if syntax unsupported or already exists
    }
}

function cf_default_fields(): array {
    return [
        ['id' => 'name', 'key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'hidden' => false, 'order' => 0],
        ['id' => 'contact', 'key' => 'contact', 'label' => 'Contact (Email / Phone)', 'type' => 'text', 'required' => true, 'hidden' => false, 'order' => 1],
        ['id' => 'message', 'key' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true, 'hidden' => false, 'order' => 2],
    ];
}

function cf_get_fields(PDO $pdo): array {
    $raw = settings_get($pdo, CF_FORM_FIELDS_KEY, '');
    if (!is_string($raw) || $raw === '') {
        return cf_default_fields();
    }
    $fields = json_decode($raw, true);
    if (!is_array($fields) || empty($fields)) {
        return cf_default_fields();
    }
    // normalize
    $out = [];
    foreach ($fields as $f) {
        if (empty($f['key']) || empty($f['id'])) continue;
        $out[] = [
            'id' => trim((string)$f['id']),
            'key' => trim((string)$f['key']),
            'label' => trim((string)($f['label'] ?? '')),
            'type' => in_array($f['type'] ?? 'text', ['text', 'email', 'tel', 'textarea'], true) ? ($f['type'] ?? 'text') : 'text',
            'required' => !empty($f['required']),
            'hidden' => !empty($f['hidden']),
            'order' => (int)($f['order'] ?? 0),
        ];
    }
    usort($out, function ($a, $b) { return $a['order'] <=> $b['order']; });
    return $out;
}

function cf_save_fields(PDO $pdo, array $fields): bool {
    if (!function_exists('settings_set')) return false;
    $fields = array_values(array_filter($fields, function ($f) {
        return !empty($f['id']) && !empty($f['key']);
    }));
    usort($fields, function ($a, $b) { return ($a['order'] ?? 0) <=> ($b['order'] ?? 0); });
    return settings_set($pdo, CF_FORM_FIELDS_KEY, json_encode($fields, JSON_UNESCAPED_UNICODE), 1);
}

function cf_recaptcha_enabled(PDO $pdo): bool {
    return (function_exists('settings_get') ? settings_get($pdo, CF_RECAPTCHA_ENABLED_KEY, '0') : '0') === '1';
}

function cf_recaptcha_keys(PDO $pdo): array {
    return [
        'sitekey' => (string)(function_exists('settings_get') ? settings_get($pdo, CF_RECAPTCHA_SITEKEY_KEY, '') : ''),
        'secret'  => (string)(function_exists('settings_get') ? settings_get($pdo, CF_RECAPTCHA_SECRET_KEY, '') : ''),
    ];
}

function cf_recaptcha_verify(string $secret, string $token, string $remoteIp = ''): array {
    $token = trim($token);
    if ($token === '') return ['ok' => false, 'error' => 'Missing recaptcha token'];

    $post = http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $remoteIp]);
    $resp = null;
    if (function_exists('curl_init')) {
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $resp = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $post,
                'timeout' => 10,
            ]
        ]);
        $resp = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $ctx);
    }

    if (!is_string($resp) || $resp === '') return ['ok' => false, 'error' => 'recaptcha verify failed'];
    $j = json_decode($resp, true);
    if (!is_array($j)) return ['ok' => false, 'error' => 'Invalid recaptcha response'];
    if (empty($j['success'])) return ['ok' => false, 'error' => 'recaptcha failed', 'raw' => $j];
    return ['ok' => true, 'raw' => $j];
}

function cf_access_config(PDO $pdo): array {
    $default = json_encode(['roles' => ['admin'], 'users' => []], JSON_UNESCAPED_UNICODE);
    $raw = function_exists('settings_get') ? settings_get($pdo, CF_ACCESS_CONFIG_KEY, $default) : $default;
    if (!is_string($raw) || $raw === '') $raw = $default;
    $cfg = json_decode($raw, true);
    if (!is_array($cfg)) $cfg = [];
    $cfg['roles'] = isset($cfg['roles']) && is_array($cfg['roles']) ? array_values(array_filter(array_map('strval', $cfg['roles']))) : ['admin'];
    $cfg['users'] = isset($cfg['users']) && is_array($cfg['users']) ? array_values(array_filter(array_map('intval', $cfg['users']))) : [];
    return $cfg;
}

function cf_save_access_config(PDO $pdo, array $cfg): bool {
    if (!function_exists('settings_set')) return false;
    $cfg['roles'] = array_values(array_filter(array_map('strval', $cfg['roles'] ?? [])));
    $cfg['users'] = array_values(array_filter(array_map('intval', $cfg['users'] ?? [])));
    return settings_set($pdo, CF_ACCESS_CONFIG_KEY, json_encode($cfg, JSON_UNESCAPED_UNICODE), 1);
}

function cf_current_user_can_access(PDO $pdo): bool {
    if (!function_exists('current_user_role') || !function_exists('current_user_id')) return true;
    $userRole = current_user_role($pdo);
    $userId = current_user_id();
    $cfg = cf_access_config($pdo);
    if ($userRole === 'admin') return true;
    if (in_array($userRole, $cfg['roles'], true)) return true;
    if (in_array((int)$userId, $cfg['users'], true)) return true;
    return false;
}

add_action('admin_init', function (): void {
    $pluginDir = defined('PLUGIN_PATH') ? PLUGIN_PATH . '/contact-form' : __DIR__;
    $vendorAutoload = $pluginDir . '/vendor/autoload.php';
    $composerJson = $pluginDir . '/composer.json';
    if (!is_file($vendorAutoload) && is_file($composerJson) && function_exists('shell_exec')) {
        $lockFile = $pluginDir . '/.composer_installed';
        if (!is_file($lockFile)) {
            $cmd = sprintf('cd %s && composer install --no-dev --prefer-dist --no-interaction --no-cache 2>&1', escapeshellarg($pluginDir));
            $out = shell_exec($cmd);
            file_put_contents($lockFile, (string)($out ?: 'done'));
        }
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!($pdo instanceof PDO)) return;
    cf_get_secret($pdo);
    cf_ensure_schema($pdo);
});

add_action('plugin_uninstall', function (string $name): void {
    if ($name !== 'contact-form') return;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!($pdo instanceof PDO)) return;
    $pdo->exec('DROP TABLE IF EXISTS `contact_submissions`');
    settings_set($pdo, CF_SECRET_KEY, '', 1);
    settings_set($pdo, CF_FORM_FIELDS_KEY, '', 1);
    settings_set($pdo, CF_RECAPTCHA_ENABLED_KEY, '0', 1);
    settings_set($pdo, CF_RECAPTCHA_SITEKEY_KEY, '', 1);
    settings_set($pdo, CF_RECAPTCHA_SECRET_KEY, '', 1);
    settings_set($pdo, CF_ACCESS_CONFIG_KEY, '', 1);
});

// Public form renderer (shared by the /contact/ page and the [contact_form] shortcode)
require_once __DIR__ . '/public/form.php';

// Shortcode: [contact_form] → render the public contact form inside any post/page content.
add_filter('post_content', function (string $html, array $post = []): string {
    if (strpos($html, '[contact_form]') === false) {
        return $html;
    }
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!($pdo instanceof PDO) || !function_exists('cf_render_form')) {
        return str_replace('[contact_form]', '', $html);
    }
    return str_replace('[contact_form]', cf_render_form($pdo), $html);
});
