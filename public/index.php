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

$ctx = cf_public_ctx($pdo);
$csrfEsc = htmlspecialchars($ctx['csrf'], ENT_QUOTES, 'UTF-8');
$csrfJs = json_encode($ctx['csrf'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

$fields = cf_get_fields($pdo);
$visibleFields = array_values(array_filter($fields, function ($f) { return empty($f['hidden']); }));

$recaptcha = cf_recaptcha_keys($pdo);
$recaptchaEnabled = cf_recaptcha_enabled($pdo);
$siteKeyEsc = htmlspecialchars($recaptcha['sitekey'], ENT_QUOTES, 'UTF-8');

function cf_render_field(array $f): string {
    $label = htmlspecialchars($f['label'] ?: $f['key'], ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars($f['key'], ENT_QUOTES, 'UTF-8');
    $required = !empty($f['required']) ? ' required' : '';
    $mark = !empty($f['required']) ? ' *' : '';
    if ($f['type'] === 'textarea') {
        return '<label class="cf-field" data-field="' . $name . '">
            <span class="cf-label">' . $label . $mark . '</span>
            <textarea class="cf-input" name="' . $name . '" rows="5"' . $required . '></textarea>
        </label>';
    }
    $type = htmlspecialchars($f['type'], ENT_QUOTES, 'UTF-8');
    return '<label class="cf-field" data-field="' . $name . '">
        <span class="cf-label">' . $label . $mark . '</span>
        <input class="cf-input" type="' . $type . '" name="' . $name . '"' . $required . '>
    </label>';
}

$fieldsHtml = implode("\n", array_map(function ($f) { return cf_render_field($f); }, $visibleFields));

$page_title = 'Contact';
$context_for_layout = 'main.contact';
$enable_sidebar = false;
$layout_full_width = false;

$recaptchaDiv = '';
$recaptchaScript = '';
if ($recaptchaEnabled && $siteKeyEsc !== '') {
    $recaptchaDiv = '<div class="cf-captcha"><div class="g-recaptcha" data-sitekey="' . $siteKeyEsc . '" data-callback="cfRecaptchaOk" data-expired-callback="cfRecaptchaExpired"></div></div>';
    $recaptchaScript = '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
}

$content_html = <<< 'HTML'
<section class="cf-section">
  <div class="cf-container">
    <div class="cf-head">
      <span class="cf-kicker">Get in Touch</span>
      <h1 class="cf-title">Contact Us</h1>
      <p class="cf-lead">Send us a message and we will get back to you as soon as possible.</p>
    </div>

    <form class="cf-form" id="cfForm" method="post" action="/contact/submit" novalidate>
      <input type="hidden" name="csrf_token" value="__CSRF_ESC__">
      __FIELDS__
      __RECAPTCHA_DIV__

      <button class="cf-submit" type="submit">Send Message</button>
      <div class="cf-hint" id="cfHint"></div>
    </form>
  </div>
</section>

__RECAPTCHA_SCRIPT__
<style>
.cf-section {
  --cf-primary: 43 122 74;
  --cf-primary-deep: 28 86 51;
  --cf-navy: 19 40 66;
  --cf-text: 27 41 32;
  --cf-muted: 87 112 94;
  --cf-border: 176 201 164;
  --cf-surface: 247 250 243;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 120px 16px 90px;
  background: linear-gradient(180deg, rgb(var(--cf-surface)), #fff 55%);
}
.cf-container { width: 100%; max-width: 600px; margin: 0 auto; }
.cf-head { text-align: center; margin-bottom: 28px; }
.cf-kicker {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 7px 13px;
  border-radius: 999px;
  font-family: 'Space Mono', monospace;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: .08em;
  text-transform: uppercase;
  background: rgb(var(--cf-border) / .25);
  color: rgb(var(--cf-primary-deep));
}
.cf-title { margin: 14px 0 10px; font-family: 'Space Grotesk', system-ui, sans-serif; font-size: clamp(28px, 4vw, 42px); font-weight: 700; color: rgb(var(--cf-navy)); }
.cf-lead { margin: 0 auto; max-width: 440px; color: rgb(var(--cf-muted)); line-height: 1.65; }
.cf-form { display: grid; gap: 16px; }
.cf-field { display: grid; gap: 7px; }
.cf-label { font-family: 'Space Grotesk', system-ui, sans-serif; font-size: 12.5px; font-weight: 700; color: rgb(var(--cf-text)); }
.cf-input {
  width: 100%;
  border-radius: 16px;
  border: 1px solid rgb(var(--cf-border));
  padding: 12px 14px;
  outline: none;
  color: rgb(var(--cf-text));
  background: #fff;
  font-family: 'Lora', Georgia, serif;
  font-size: 15px;
  transition: box-shadow .2s ease, border-color .2s ease;
}
.cf-input:focus { border-color: rgb(var(--cf-primary)); box-shadow: 0 0 0 4px rgb(var(--cf-primary) / .12); }
.cf-input:hover { border-color: rgb(var(--cf-primary) / .6); }
.cf-captcha { display: flex; justify-content: center; }
.cf-submit {
  width: 100%;
  border: 0;
  border-radius: 999px;
  padding: 15px 18px;
  font-family: 'Space Grotesk', system-ui, sans-serif;
  font-size: 16px;
  font-weight: 700;
  cursor: pointer;
  color: #fff;
  background: linear-gradient(135deg, rgb(var(--cf-primary)), rgb(var(--cf-primary-deep)));
  box-shadow: 0 14px 34px rgb(var(--cf-primary) / .28);
  transition: transform .08s ease, filter .2s ease;
}
.cf-submit:hover { filter: brightness(1.06); }
.cf-submit:disabled { opacity: .65; cursor: not-allowed; }
.cf-hint { font-size: 13px; color: rgb(var(--cf-muted)); text-align: center; min-height: 1.2em; }
.cf-hint--error { color: #c2410c; }
.cf-hint--ok { color: rgb(var(--cf-primary-deep)); }
@media (max-width: 720px) { .cf-section { padding: 100px 16px 64px; } }
</style>

<script>
(function(){
  const CSRF = __CSRF_JS__;
  const form = document.getElementById('cfForm');
  const hint = document.getElementById('cfHint');
  let recaptchaValid = false;
  window.cfRecaptchaOk = function() { recaptchaValid = true; };
  window.cfRecaptchaExpired = function() { recaptchaValid = false; };
  if (!form) return;

  form.addEventListener('submit', async function(e){
    e.preventDefault();
    hint.textContent = 'Sending...';
    hint.className = 'cf-hint';
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;

    const recaptchaPresent = !!form.querySelector('.g-recaptcha');
    if (recaptchaPresent && !recaptchaValid) {
      hint.textContent = 'Please complete the reCAPTCHA.';
      hint.className = 'cf-hint cf-hint--error';
      btn.disabled = false;
      return;
    }

    try {
      const fd = new FormData(form);
      const r = await fetch('/contact/submit', { method: 'POST', body: fd });
      const text = await r.text();
      if (r.ok) {
        hint.textContent = 'Message sent successfully.';
        hint.className = 'cf-hint cf-hint--ok';
        form.reset();
        recaptchaValid = false;
        if (typeof grecaptcha !== 'undefined') grecaptcha.reset();
      } else {
        hint.textContent = text || 'Failed to send.';
        hint.className = 'cf-hint cf-hint--error';
        if (recaptchaPresent && typeof grecaptcha !== 'undefined') grecaptcha.reset();
      }
    } catch (err) {
      hint.textContent = 'Network error. Please try again.';
      hint.className = 'cf-hint cf-hint--error';
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>
HTML;

$content_html = str_replace(['__CSRF_ESC__', '__FIELDS__', '__CSRF_JS__', '__RECAPTCHA_DIV__', '__RECAPTCHA_SCRIPT__'], [$csrfEsc, $fieldsHtml, $csrfJs, $recaptchaDiv, $recaptchaScript], $content_html);

require_once $publicRoot . '/app/layout.php';
