<?php
// /plugins/contact-form/plugin.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT') && !defined('PLUGIN_SYSTEM_LOADED')) {
    return;
}

// Register frontend public route.
if (function_exists('register_frontend_route')) {
    register_frontend_route('contact', PLUGIN_PATH . '/contact-form/public/index.php');
}

// Settings keys
const CF_FORM_FIELDS_KEY = 'contact_form_fields';
const CF_SECRET_KEY = 'contact_form_secret';

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
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `created_at` (`created_at`),
            KEY `is_read` (`is_read`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
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

add_action('admin_init', function (): void {
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
});
