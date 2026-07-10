<?php
// /plugins/contact-form/public/submit.php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$publicRoot = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
require_once $publicRoot . '/app/bootstrap_core.php';

if (!($pdo instanceof PDO)) { http_response_code(500); exit('DB unavailable'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    exit('Not found');
}

$ctx = cf_public_ctx($pdo);
$csrf = trim((string)($_POST['csrf_token'] ?? ''));
if (!cf_csrf_check($ctx['csrf'], $csrf)) {
    http_response_code(400);
    exit('Invalid CSRF');
}

$recaptchaEnabled = cf_recaptcha_enabled($pdo);
if ($recaptchaEnabled) {
    $keys = cf_recaptcha_keys($pdo);
    $token = trim((string)($_POST['g-recaptcha-response'] ?? ''));
    $rc = cf_recaptcha_verify($keys['secret'], $token, $ctx['ip']);
    if (!$rc['ok']) {
        http_response_code(400);
        exit('reCAPTCHA failed');
    }
}

// Basic rate limit: max 5 submissions per IP per hour
$rateOk = cf_rate_limit_check($pdo, $ctx['ip'], 'submit', 3600, 5);
if (!$rateOk) {
    http_response_code(429);
    exit('Too many submissions. Please try again later.');
}

$fields = cf_get_fields($pdo);
$visibleFields = array_values(array_filter($fields, function ($f) { return empty($f['hidden']); }));

$data = [];
$errors = [];

foreach ($visibleFields as $f) {
    $key = $f['key'];
    $value = trim((string)($_POST[$key] ?? ''));
    if ($f['required'] && $value === '') {
        $errors[] = ($f['label'] ?: $key) . ' is required';
    }
    if ($f['type'] === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        $errors[] = ($f['label'] ?: $key) . ' is invalid';
    }
    $data[$key] = $value;
}

if ($errors) {
    http_response_code(400);
    echo 'Validation: ' . implode(' | ', $errors);
    exit;
}

$name = trim((string)($data['name'] ?? ''));
$contact = trim((string)($data['contact'] ?? ''));
$message = trim((string)($data['message'] ?? ''));

$stmt = $pdo->prepare("
    INSERT INTO `contact_submissions` (`name`, `contact`, `message`, `data_json`, `ip`, `created_at`)
    VALUES (:name, :contact, :message, :data_json, :ip, NOW())
");
$stmt->execute([
    ':name' => $name,
    ':contact' => $contact,
    ':message' => $message,
    ':data_json' => json_encode($data, JSON_UNESCAPED_UNICODE),
    ':ip' => $ctx['ip'] ?: null,
]);

echo 'OK';
