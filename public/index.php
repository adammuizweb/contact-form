<?php
// /plugins/contact-form/public/index.php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$publicRoot = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
require_once $publicRoot . '/app/bootstrap_core.php';

if (!($pdo instanceof PDO)) { http_response_code(500); exit('DB unavailable'); }

// Dispatch sub-routes under /contact/
$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$pathTrimmed = trim($rawPath, '/');
$basePrefix = 'contact';
$subPath = '';
if ($pathTrimmed !== $basePrefix && strpos($pathTrimmed, $basePrefix . '/') === 0) {
    $subPath = substr($pathTrimmed, strlen($basePrefix) + 1);
}
$routeMap = [
    'submit.php' => __DIR__ . '/submit.php',
    'submit'     => __DIR__ . '/submit.php',
];
if ($subPath !== '' && isset($routeMap[$subPath])) {
    require $routeMap[$subPath];
    exit;
}

require_once __DIR__ . '/form.php';

$page_title = 'Contact';
$context_for_layout = 'main.contact';
$enable_sidebar = false;
$layout_full_width = false;

$content_html = cf_render_form($pdo);

require_once $publicRoot . '/app/layout.php';
