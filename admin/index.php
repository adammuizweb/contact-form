<?php
// /plugins/contact-form/admin/index.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) exit;

$pdo = $GLOBALS['pdo'] ?? null;
if (!($pdo instanceof PDO)) {
    echo '<p>Database not available.</p>';
    return;
}

if (function_exists('cf_ensure_schema')) {
    cf_ensure_schema($pdo);
}

if (!cf_current_user_can_access($pdo)) {
    echo '<div class="cf-admin" style="padding:2rem;text-align:center"><p>You do not have access to this page.</p><p style="color:var(--adam-muted);font-size:0.9em">Contact an administrator to grant access.</p></div>';
    return;
}

$csrf = function_exists('csrf_token') ? csrf_token() : '';
$saveMessage = '';

// ---- reCAPTCHA Save ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_recaptcha'])) {
    if (!function_exists('csrf_check') || !csrf_check($_POST['csrf_token'] ?? '')) {
        $saveMessage = 'Invalid CSRF.';
    } else {
        $recEnabled = !empty($_POST['recaptcha_enabled']) ? '1' : '0';
        $recSitekey = trim((string)($_POST['recaptcha_sitekey'] ?? ''));
        $recSecret  = trim((string)($_POST['recaptcha_secret'] ?? ''));
        if (function_exists('settings_set')) {
            settings_set($pdo, CF_RECAPTCHA_ENABLED_KEY, $recEnabled, 1);
            settings_set($pdo, CF_RECAPTCHA_SITEKEY_KEY, $recSitekey, 1);
            settings_set($pdo, CF_RECAPTCHA_SECRET_KEY, $recSecret, 1);
            $recaptchaSavedFlag = true;
        }
    }
}

// ---- Access Save ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_access'])) {
    if (!function_exists('csrf_check') || !csrf_check($_POST['csrf_token'] ?? '')) {
        $saveMessage = 'Invalid CSRF.';
    } else {
        $roles = is_array($_POST['access_roles'] ?? []) ? array_values(array_filter(array_map('strval', $_POST['access_roles']))) : [];
        $users = is_array($_POST['access_users'] ?? []) ? array_values(array_filter(array_map('intval', $_POST['access_users']))) : [];
        cf_save_access_config($pdo, ['roles' => $roles, 'users' => $users]);
        $accessSavedFlag = true;
    }
}

// ---- Form Builder Save ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_form_builder'])) {
    if (!function_exists('csrf_check') || !csrf_check($_POST['csrf_token'] ?? '')) {
        $saveMessage = 'Invalid CSRF.';
    } else {
        $fields = json_decode($_POST['form_fields_json'] ?? '[]', true);
        if (!is_array($fields)) $fields = [];
        $normalized = [];
        $order = 0;
        foreach ($fields as $f) {
            if (empty($f['key'])) continue;
            $id = !empty($f['id']) ? trim((string)$f['id']) : preg_replace('/[^a-z0-9_]/', '_', strtolower(trim((string)$f['key'])));
            $key = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim((string)$f['key'])));
            if ($key === '') continue;
            $normalized[] = [
                'id' => $id,
                'key' => $key,
                'label' => trim((string)($f['label'] ?? '')),
                'type' => in_array($f['type'] ?? 'text', ['text', 'email', 'tel', 'textarea'], true) ? ($f['type'] ?? 'text') : 'text',
                'required' => !empty($f['required']),
                'hidden' => !empty($f['hidden']),
                'order' => $order++,
            ];
        }
        if (cf_save_fields($pdo, $normalized)) {
            $saveMessage = 'Form builder saved.';
        } else {
            $saveMessage = 'Failed to save form builder.';
        }
    }
}

// ---- Single mark read / delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read']) && isset($_POST['id'])) {
    if (!function_exists('csrf_check') || !csrf_check($_POST['csrf_token'] ?? '')) {
        $saveMessage = 'Invalid CSRF.';
    } else {
        $stmt = $pdo->prepare('UPDATE `contact_submissions` SET `is_read` = 1 WHERE `id` = ?');
        $stmt->execute([(int)$_POST['id']]);
        $saveMessage = 'Marked as read.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete']) && isset($_POST['id'])) {
    if (!function_exists('csrf_check') || !csrf_check($_POST['csrf_token'] ?? '')) {
        $saveMessage = 'Invalid CSRF.';
    } else {
        $stmt = $pdo->prepare('DELETE FROM `contact_submissions` WHERE `id` = ?');
        $stmt->execute([(int)$_POST['id']]);
        $saveMessage = 'Deleted.';
    }
}

// ---- Bulk action ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && !empty($_POST['selected_ids'])) {
    if (!function_exists('csrf_check') || !csrf_check($_POST['csrf_token'] ?? '')) {
        $saveMessage = 'Invalid CSRF.';
    } else {
        $ids = array_values(array_filter(array_map('intval', (array)$_POST['selected_ids'])));
        if (!empty($ids)) {
            $action = $_POST['bulk_action'];
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            if ($action === 'mark_read') {
                $st = $pdo->prepare("UPDATE `contact_submissions` SET `is_read` = 1 WHERE `id` IN ($placeholders)");
                $st->execute($ids);
                $saveMessage = 'Marked ' . count($ids) . ' submission(s) as read.';
            } elseif ($action === 'delete') {
                $st = $pdo->prepare("DELETE FROM `contact_submissions` WHERE `id` IN ($placeholders)");
                $st->execute($ids);
                $saveMessage = 'Deleted ' . count($ids) . ' submission(s).';
            }
        }
    }
}

// PRG: JS redirect after POST
if (!empty($accessSavedFlag)) {
    echo '<script>location.replace("?page=admin/tools/contact-form&access_saved=1");</script>';
    return;
}
if (!empty($recaptchaSavedFlag)) {
    echo '<script>location.replace("?page=admin/tools/contact-form&recaptcha_saved=1");</script>';
    return;
}

if (isset($_GET['access_saved'])) $saveMessage = 'Access settings saved.';
if (isset($_GET['recaptcha_saved'])) $saveMessage = 'reCAPTCHA settings saved.';

$recaptchaEnabledValue = cf_recaptcha_enabled($pdo);
$recaptchaSitekeyValue = (string)(function_exists('settings_get') ? settings_get($pdo, CF_RECAPTCHA_SITEKEY_KEY, '') : '');
$recaptchaSecretValue  = (string)(function_exists('settings_get') ? settings_get($pdo, CF_RECAPTCHA_SECRET_KEY, '') : '');
$accessConfig = cf_access_config($pdo);

// Fetch users for access select
$allUsers = [];
try {
    $uStmt = $pdo->query("
        SELECT id, name, email, role
        FROM users
        WHERE is_deleted = 0 AND is_locked = 0 AND role != 'admin'
        ORDER BY name, email
    ");
    $allUsers = $uStmt ? $uStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $allUsers = [];
}

// ---- Filter / Search / Pagination ----
$statusFilter = $_GET['status'] ?? 'all';
$allowedFilters = ['all', 'read', 'unread'];
if (!in_array($statusFilter, $allowedFilters, true)) $statusFilter = 'all';
$search = trim((string)($_GET['q'] ?? ''));

$where = "WHERE 1=1";
$countWhere = "WHERE 1=1";
$params = [];
if ($statusFilter === 'read') {
    $where .= " AND `is_read` = 1";
    $countWhere .= " AND `is_read` = 1";
} elseif ($statusFilter === 'unread') {
    $where .= " AND `is_read` = 0";
    $countWhere .= " AND `is_read` = 0";
}
if ($search !== '') {
    $where .= " AND (`name` LIKE :q OR `contact` LIKE :q OR `message` LIKE :q OR `data_json` LIKE :q)";
    $countWhere .= " AND (`name` LIKE :q OR `contact` LIKE :q OR `message` LIKE :q OR `data_json` LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}

$perPage = 20;
$page = max(1, (int)($_GET['p'] ?? 1));

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM `contact_submissions` $countWhere");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT * FROM `contact_submissions`
    $where
    ORDER BY `created_at` DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$fields = cf_get_fields($pdo);
$fieldsJson = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$submissionsJson = json_encode($submissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$filterLabels = ['all' => 'All', 'read' => 'Read', 'unread' => 'Unread'];

function cf_filter_url(array $overrides): string {
    $base = '?page=admin/tools/contact-form';
    $get = array_merge($_GET, $overrides);
    foreach (['page'] as $k) unset($get[$k]);
    $qs = http_build_query($get, '', '&amp;', PHP_QUERY_RFC3986);
    return $base . ($qs ? '&amp;' . $qs : '');
}

function cf_s(string $text): string {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}
?>
<div class="cf-admin">
  <div class="cf-admin__head">
    <h1 class="cf-admin__title">Contact Submissions</h1>
    <div class="cf-admin__actions">
      <button type="button" class="adam-button adam-button--secondary" onclick="openCfModal('cf-modal-builder')">Form Builder</button>
      <button type="button" class="adam-button adam-button--secondary" onclick="openCfModal('cf-modal-recaptcha')">reCAPTCHA</button>
      <button type="button" class="adam-button adam-button--secondary" onclick="openCfModal('cf-modal-access')">Access</button>
    </div>
  </div>

  <?php if ($saveMessage !== ''): ?>
    <div class="cf-alert cf-alert--success"><?= cf_s($saveMessage) ?></div>
  <?php endif; ?>

  <div class="cf-toolbar">
    <div class="cf-tabs">
      <?php foreach ($allowedFilters as $sf): ?>
        <a href="<?= cf_filter_url(['status' => $sf, 'p' => 1]) ?>"
           class="cf-tab<?= $statusFilter === $sf ? ' cf-tab--active' : '' ?>">
          <?= cf_s($filterLabels[$sf] ?? ucfirst($sf)) ?>
        </a>
      <?php endforeach; ?>
    </div>
    <form class="cf-search" method="get" action="">
      <input type="hidden" name="page" value="admin/tools/contact-form">
      <input type="hidden" name="status" value="<?= cf_s($statusFilter) ?>">
      <input type="search" name="q" class="inpud" value="<?= cf_s($search) ?>" placeholder="Search name, contact, message...">
      <button type="submit" class="adam-button">Search</button>
      <?php if ($search !== ''): ?>
        <a class="adam-cancle" href="<?= cf_filter_url(['q' => '', 'p' => 1]) ?>">Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <form method="post" action="<?= cf_filter_url([]) ?>" id="cf-bulk-form">
    <input type="hidden" name="csrf_token" value="<?= cf_s($csrf) ?>">
    <div class="cf-bulkbar">
      <label class="cf-check">
        <input type="checkbox" id="cf-select-all" title="Select all on this page">
        <span>Select all</span>
      </label>
      <select name="bulk_action" class="inpud">
        <option value="">-- Bulk action --</option>
        <option value="mark_read">Mark as read</option>
        <option value="delete">Delete selected</option>
      </select>
      <button type="submit" class="adam-button" id="cf-bulk-apply">Apply</button>
      <span class="cf-bulkbar__count"><span id="cf-selected-count">0</span> selected</span>
    </div>

  <?php if (empty($submissions)): ?>
    <p class="cf-empty">No submissions found.</p>
  <?php else: ?>
    <div class="cf-table-wrap">
      <table class="cf-table">
        <thead>
          <tr>
            <th class="cf-table__check"></th>
            <th>Status</th>
            <th>Name</th>
            <th>Contact</th>
            <th>Message</th>
            <th>Date</th>
            <th class="cf-table__actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($submissions as $s): ?>
            <tr class="<?= (int)$s['is_read'] ? 'cf-row--read' : 'cf-row--unread' ?>">
              <td class="cf-table__check">
                <input type="checkbox" name="selected_ids[]" value="<?= (int)$s['id'] ?>" class="cf-row-check">
              </td>
              <td>
                <span class="cf-badge cf-badge--<?= (int)$s['is_read'] ? 'read' : 'unread' ?>">
                  <?= (int)$s['is_read'] ? 'Read' : 'New' ?>
                </span>
              </td>
              <td><?= cf_s($s['name']) ?></td>
              <td><?= cf_s($s['contact']) ?></td>
              <td><?= cf_s(mb_strimwidth((string)$s['message'], 0, 80, '...')) ?></td>
              <td><?= cf_s($s['created_at']) ?></td>
              <td class="cf-table__actions">
                <button type="button" class="cf-btn cf-btn--sm" onclick="openCfDetail(<?= (int)$s['id'] ?>)">View</button>
                <?php if (!(int)$s['is_read']): ?>
                  <button type="button" class="cf-btn cf-btn--sm cf-btn--success" onclick="cfMarkRead(<?= (int)$s['id'] ?>)">Mark Read</button>
                <?php endif; ?>
                <button type="button" class="cf-btn cf-btn--sm cf-btn--danger" onclick="cfDelete(<?= (int)$s['id'] ?>)">Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="cf-pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <a href="<?= cf_filter_url(['p' => $i]) ?>"
             class="cf-page<?= $i === $page ? ' cf-page--active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
  </form>

  <!-- Hidden forms for single actions via JS -->
  <form method="post" action="<?= cf_filter_url([]) ?>" id="cf-single-form" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= cf_s($csrf) ?>">
    <input type="hidden" name="id" id="cf-single-id" value="">
    <input type="hidden" name="mark_read" id="cf-single-mark-read" value="">
    <input type="hidden" name="delete" id="cf-single-delete" value="">
  </form>
</div>

<!-- Modal: Form Builder -->
<div id="cf-modal-builder" class="cf-modal" onclick="closeCfModalOnBackdrop(event)">
  <div class="cf-modal__box" onclick="event.stopPropagation()">
    <div class="cf-modal__head">
      <h3>Form Builder</h3>
      <button type="button" class="cf-modal__close" onclick="closeCfModal('cf-modal-builder')" aria-label="Close"></button>
    </div>
    <form method="post" action="<?= cf_filter_url([]) ?>">
      <input type="hidden" name="csrf_token" value="<?= cf_s($csrf) ?>">
      <input type="hidden" name="save_form_builder" value="1">
      <input type="hidden" name="form_fields_json" id="cf-fields-json" value="">

      <div class="cf-modal__body">
        <div class="cf-fields" id="cf-fields-list">
          <!-- fields rendered by JS -->
        </div>
        <button type="button" class="cf-btn cf-btn--secondary" onclick="cfAddField()">+ Add Field</button>
      </div>

      <div class="cf-modal__foot">
        <button type="button" class="adam-cancle" onclick="closeCfModal('cf-modal-builder')">Cancel</button>
        <button type="submit" class="adam-button" onclick="return cfPrepareSave()">Save Form</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Detail -->
<div id="cf-modal-detail" class="cf-modal" onclick="closeCfModalOnBackdrop(event)">
  <div class="cf-modal__box cf-modal__box--lg" onclick="event.stopPropagation()">
    <div class="cf-modal__head">
      <h3>Submission Detail</h3>
      <button type="button" class="cf-modal__close" onclick="closeCfModal('cf-modal-detail')" aria-label="Close"></button>
    </div>
    <div class="cf-modal__body" id="cf-detail-body">
      <!-- detail by JS -->
    </div>
  </div>
</div>

<!-- Modal: reCAPTCHA -->
<div id="cf-modal-recaptcha" class="cf-modal" onclick="closeCfModalOnBackdrop(event)">
  <div class="cf-modal__box" onclick="event.stopPropagation()">
    <div class="cf-modal__head">
      <h3>reCAPTCHA Configuration</h3>
      <button type="button" class="cf-modal__close" onclick="closeCfModal('cf-modal-recaptcha')" aria-label="Close"></button>
    </div>
    <form method="post" action="?page=admin/tools/contact-form" id="cf-recaptcha-form">
      <input type="hidden" name="csrf_token" value="<?= cf_s($csrf) ?>">
      <input type="hidden" name="save_recaptcha" value="1">
      <div class="cf-modal__body">
        <div class="form-group form-group--toggle" style="margin-bottom:1rem">
          <label class="adam-switch" for="recaptcha_enabled">
            <input type="checkbox" name="recaptcha_enabled" id="recaptcha_enabled" value="1" <?= $recaptchaEnabledValue ? 'checked' : '' ?>
            <span class="adam-slider"></span>
          </label>
          <div class="toggle-labels">
            <span class="toggle-title">Enable reCAPTCHA on public form</span>
            <span class="toggle-desc">When on, visitors must pass Google reCAPTCHA before submitting.</span>
          </div>
        </div>

        <label class="cf-field">
          <span class="cf-field__label">Site Key</span>
          <input type="text" name="recaptcha_sitekey" value="<?= cf_s($recaptchaSitekeyValue) ?>" class="inpud" placeholder="6Ld...">
        </label>

        <label class="cf-field">
          <span class="cf-field__label">Secret Key</span>
          <input type="password" name="recaptcha_secret" value="<?= cf_s($recaptchaSecretValue) ?>" class="inpud" placeholder="6Ld...">
        </label>
      </div>
      <div class="cf-modal__foot">
        <button type="button" class="adam-cancle" onclick="closeCfModal('cf-modal-recaptcha')">Cancel</button>
        <button type="button" class="adam-button" onclick="cfSaveRecaptcha()">Save Settings</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Access -->
<div id="cf-modal-access" class="cf-modal" onclick="closeCfModalOnBackdrop(event)">
  <div class="cf-modal__box" onclick="event.stopPropagation()">
    <div class="cf-modal__head">
      <h3>Access Control</h3>
      <button type="button" class="cf-modal__close" onclick="closeCfModal('cf-modal-access')" aria-label="Close"></button>
    </div>
    <form method="post" action="?page=admin/tools/contact-form" id="cf-access-form">
      <input type="hidden" name="csrf_token" value="<?= cf_s($csrf) ?>">
      <input type="hidden" name="save_access" value="1">
      <div class="cf-modal__body">
        <div class="cf-field">
          <span class="cf-field__label">Role-based Override</span>
          <div class="cf-checklist">
            <label class="cf-check">
              <input type="checkbox" name="access_roles[]" value="editor" <?= in_array('editor', $accessConfig['roles'], true) ? 'checked' : '' ?>
              <span>All Editors</span>
            </label>
            <label class="cf-check">
              <input type="checkbox" name="access_roles[]" value="author" <?= in_array('author', $accessConfig['roles'], true) ? 'checked' : '' ?>
              <span>All Authors</span>
            </label>
          </div>
          <span class="cf-field__hint">Checking a role overrides user-specific list below — all users with that role gain access. Admin bypasses all rules.</span>
        </div>

        <div class="cf-field">
          <span class="cf-field__label">User-specific Access</span>
          <div class="cf-dual-list">
            <div class="cf-dual-list__col">
              <div class="cf-dual-list__label">No Access</div>
              <select class="inpud cf-dual-list__select" multiple size="9" id="access-left">
                <?php foreach ($allUsers as $u): ?>
                  <?php if (!in_array((int)$u['id'], $accessConfig['users'], true)): ?>
                  <option value="<?= (int)$u['id'] ?>"><?= cf_s(($u['name'] ?? '') !== '' ? $u['name'] : $u['email']) ?> (<?= cf_s($u['email']) ?>, <?= cf_s(ucfirst($u['role'] ?? '')) ?>)</option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="cf-dual-list__btns">
              <button type="button" class="adam-button" onclick="cfMoveRight()" title="Move selected to Has Access">&gt;</button>
              <button type="button" class="adam-cancle" onclick="cfMoveLeft()" title="Move selected to No Access">&lt;</button>
            </div>
            <div class="cf-dual-list__col">
              <div class="cf-dual-list__label">Has Access</div>
              <select name="access_users[]" class="inpud cf-dual-list__select" multiple size="9" id="access-right">
              <?php foreach ($allUsers as $u): ?>
                <?php if (in_array((int)$u['id'], $accessConfig['users'], true)): ?>
                  <option value="<?= (int)$u['id'] ?>"><?= cf_s(($u['name'] ?? '') !== '' ? $u['name'] : $u['email']) ?> (<?= cf_s($u['email']) ?>, <?= cf_s(ucfirst($u['role'] ?? '')) ?>)</option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <span class="cf-field__hint">Select users and use &gt; / &lt; buttons to grant or revoke individual access.</span>
        </div>
      </div>
      <div class="cf-modal__foot">
        <button type="button" class="adam-cancle" onclick="closeCfModal('cf-modal-access')">Cancel</button>
        <button type="button" class="adam-button" onclick="cfSaveAccess()">Save Access</button>
      </div>
    </form>
  </div>
</div>

<style>
.cf-admin { color: var(--adam-text); }
.cf-admin__head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.2rem; flex-wrap: wrap; }
.cf-admin__title { font-size: 1.35rem; font-weight: 700; margin: 0; }
.cf-admin__actions { display: flex; gap: .5rem; flex-wrap: wrap; }
.cf-alert { padding: .7rem .9rem; border-radius: 8px; margin-bottom: 1rem; font-size: .9rem; background: rgba(30, 143, 74, .12); color: var(--adam-success); border: 1px solid rgba(30, 143, 74, .25); }
.cf-empty { color: var(--adam-muted); padding: 2rem; text-align: center; }
.cf-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
.cf-tabs { display: flex; gap: .25rem; flex-wrap: wrap; }
.cf-tab { display: inline-flex; padding: .45rem .85rem; border-radius: 8px; font-size: .85rem; font-weight: 600; color: var(--adam-text); text-decoration: none; border: 1px solid transparent; }
.cf-tab:hover { background: var(--adam-hover); }
.cf-tab--active { background: var(--adam-card); border-color: var(--adam-border); }
.cf-search { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.cf-search input { min-width: 220px; }
.cf-bulkbar { display: flex; align-items: center; gap: .75rem; margin-bottom: .75rem; padding: .6rem .75rem; background: var(--adam-card); border: 1px solid var(--adam-border); border-radius: 10px; flex-wrap: wrap; }
.cf-bulkbar__count { margin-left: auto; font-size: .85rem; color: var(--adam-muted); }
.cf-check { display: inline-flex; align-items: center; gap: .4rem; font-size: .85rem; cursor: pointer; }
.cf-checklist { display: flex; gap: 1rem; flex-wrap: wrap; margin: .4rem 0; }
.cf-dual-list { display: grid; grid-template-columns: 1fr auto 1fr; gap: .5rem; align-items: center; margin: .4rem 0; }
.cf-dual-list__col { min-width: 0; }
.cf-dual-list__label { font-size: .75rem; color: var(--adam-muted); margin-bottom: .25rem; }
.cf-dual-list__select { width: 100%; min-height: 180px; }
.cf-dual-list__btns { display: flex; flex-direction: column; gap: .4rem; }
.cf-table-wrap { overflow-x: auto; }
.cf-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
.cf-table th, .cf-table td { padding: .65rem .75rem; border-bottom: 1px solid var(--adam-border); text-align: left; }
.cf-table th { font-weight: 600; color: var(--adam-muted); font-size: .8rem; text-transform: uppercase; letter-spacing: .03em; }
.cf-table__check { width: 40px; text-align: center; }
.cf-table__actions { width: 1%; white-space: nowrap; }
.cf-row--unread { background: rgba(59, 130, 246, .04); }
.cf-row--read { background: transparent; }
.cf-badge { display: inline-flex; padding: .2rem .55rem; border-radius: 999px; font-size: .75rem; font-weight: 600; }
.cf-badge--unread { background: rgba(59, 130, 246, .12); color: #2563eb; }
.cf-badge--read { background: rgba(107, 114, 128, .12); color: #6b7280; }
.cf-btn { border: 1px solid var(--adam-border); border-radius: 6px; padding: .35rem .65rem; background: #fff; color: var(--adam-text); font-size: .8rem; cursor: pointer; transition: background .15s; }
.cf-btn:hover { background: var(--adam-hover); }
.cf-btn--sm { padding: .25rem .5rem; font-size: .75rem; }
.cf-btn--secondary { background: var(--adam-card); }
.cf-btn--success { border-color: rgba(30, 143, 74, .3); color: #15803d; }
.cf-btn--danger { border-color: rgba(220, 38, 38, .2); color: #b91c1c; }
.cf-btn--success:hover { background: rgba(30, 143, 74, .08); }
.cf-btn--danger:hover { background: rgba(220, 38, 38, .08); }
.cf-pagination { display: flex; gap: .35rem; justify-content: center; margin-top: 1rem; flex-wrap: wrap; }
.cf-page { display: inline-flex; align-items: center; justify-content: center; min-width: 32px; height: 32px; padding: 0 .5rem; border-radius: 8px; font-size: .85rem; text-decoration: none; color: var(--adam-text); border: 1px solid var(--adam-border); background: var(--adam-card); }
.cf-page:hover { background: var(--adam-hover); }
.cf-page--active { background: var(--adam-primary); color: #fff; border-color: var(--adam-primary); }
.cf-modal { position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 9999; display: none; align-items: center; justify-content: center; padding: 1rem; backdrop-filter: blur(4px); }
.cf-modal.active { display: flex; }
.cf-modal__box { background: var(--adam-card); border-radius: 14px; width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 80px rgba(0,0,0,.35); display: flex; flex-direction: column; }
.cf-modal__box--lg { max-width: 720px; }
.cf-modal__head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--adam-border); position: sticky; top: 0; background: var(--adam-card); z-index: 2; }
.cf-modal__head h3 { margin: 0; font-size: 1.05rem; font-weight: 600; }
.cf-modal__close { width: 32px; height: 32px; border: none; background: transparent; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; color: var(--adam-muted); }
.cf-modal__close::before { content: '×'; font-size: 1.5rem; line-height: 1; }
.cf-modal__close:hover { background: var(--adam-hover); color: var(--adam-text); }
.cf-modal__body { padding: 1.25rem; }
.cf-modal__foot { display: flex; justify-content: flex-end; gap: .5rem; padding: 1rem 1.25rem; border-top: 1px solid var(--adam-border); }
.cf-fields { display: grid; gap: .75rem; margin-bottom: 1rem; }
.cf-field-row { border: 1px solid var(--adam-border); border-radius: 10px; padding: .75rem; background: var(--adam-bg); }
.cf-field-row__top { display: grid; grid-template-columns: 1fr 1fr auto; gap: .5rem; align-items: end; margin-bottom: .5rem; }
.cf-field-row__opts { display: flex; gap: .75rem; align-items: center; font-size: .85rem; }
.cf-field-row__actions { display: flex; gap: .3rem; }
.cf-field-row input, .cf-field-row select { padding: .4rem .5rem; border: 1px solid var(--adam-border); border-radius: 6px; font-size: .85rem; }
.cf-field-row label { font-size: .75rem; color: var(--adam-muted); display: block; margin-bottom: .2rem; }
.cf-field { display: grid; gap: .35rem; margin-bottom: .75rem; }
.cf-field__label { font-size: .8rem; font-weight: 600; color: var(--adam-text); }
.cf-field__hint { font-size: .75rem; color: var(--adam-muted); }
.cf-detail-grid { display: grid; gap: .75rem; }
.cf-detail-item { padding: .75rem; background: var(--adam-bg); border-radius: 8px; }
.cf-detail-label { font-size: .75rem; color: var(--adam-muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: .2rem; }
.cf-detail-value { font-size: .95rem; color: var(--adam-text); white-space: pre-wrap; }
</style>

<script>
var cfFields = <?= $fieldsJson ?>;
var cfSubmissions = <?= $submissionsJson ?>;

function openCfModal(id){ var el = document.getElementById(id); if (el) el.classList.add('active'); }
function closeCfModal(id){ var el = document.getElementById(id); if (el) el.classList.remove('active'); }
function closeCfModalOnBackdrop(e){ if (e.target === e.currentTarget) e.target.classList.remove('active'); }
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') document.querySelectorAll('.cf-modal.active').forEach(function(m){ m.classList.remove('active'); }); });

function cfRenderFields(){
  var container = document.getElementById('cf-fields-list');
  container.innerHTML = '';
  cfFields.forEach(function(f, idx){
    var row = document.createElement('div');
    row.className = 'cf-field-row';
    row.innerHTML = '' +
      '<div class="cf-field-row__top">' +
        '<div><label>Label</label><input type="text" class="cf-f-label" value="' + (f.label || '') + '" data-idx="' + idx + '" placeholder="Field label"></div>' +
        '<div><label>Key</label><input type="text" class="cf-f-key" value="' + (f.key || '') + '" data-idx="' + idx + '" placeholder="machine_name"></div>' +
        '<div><label>Type</label><select class="cf-f-type" data-idx="' + idx + '">' +
          '<option value="text"' + (f.type === 'text' ? ' selected' : '') + '>Text</option>' +
          '<option value="email"' + (f.type === 'email' ? ' selected' : '') + '>Email</option>' +
          '<option value="tel"' + (f.type === 'tel' ? ' selected' : '') + '>Phone</option>' +
          '<option value="textarea"' + (f.type === 'textarea' ? ' selected' : '') + '>Textarea</option>' +
        '</select></div>' +
      '</div>' +
      '<div class="cf-field-row__opts">' +
        '<label><input type="checkbox" class="cf-f-required" data-idx="' + idx + '"' + (f.required ? ' checked' : '') + '> Required</label>' +
        '<label><input type="checkbox" class="cf-f-hidden" data-idx="' + idx + '"' + (f.hidden ? ' checked' : '') + '> Hidden</label>' +
      '</div>' +
      '<div class="cf-field-row__actions" style="margin-top:.5rem">' +
        '<button type="button" class="cf-btn cf-btn--sm" onclick="cfMoveField(' + idx + ', -1)" ' + (idx === 0 ? 'disabled' : '') + '>↑</button>' +
        '<button type="button" class="cf-btn cf-btn--sm" onclick="cfMoveField(' + idx + ', 1)" ' + (idx === cfFields.length - 1 ? 'disabled' : '') + '>↓</button>' +
        '<button type="button" class="cf-btn cf-btn--sm cf-btn--danger" onclick="cfRemoveField(' + idx + ')">Remove</button>' +
      '</div>';
    container.appendChild(row);
  });
}

function cfCollectFields(){
  var rows = document.querySelectorAll('.cf-field-row');
  rows.forEach(function(row, idx){
    cfFields[idx] = {
      id: cfFields[idx] && cfFields[idx].id ? cfFields[idx].id : 'field_' + Date.now() + '_' + idx,
      key: row.querySelector('.cf-f-key').value.trim().replace(/[^a-z0-9_]/gi, '_').toLowerCase(),
      label: row.querySelector('.cf-f-label').value.trim(),
      type: row.querySelector('.cf-f-type').value,
      required: row.querySelector('.cf-f-required').checked,
      hidden: row.querySelector('.cf-f-hidden').checked,
      order: idx
    };
  });
}

function cfAddField(){
  cfCollectFields();
  cfFields.push({ id: 'field_' + Date.now(), key: '', label: '', type: 'text', required: false, hidden: false, order: cfFields.length });
  cfRenderFields();
}

function cfRemoveField(idx){
  cfCollectFields();
  cfFields.splice(idx, 1);
  cfRenderFields();
}

function cfMoveField(idx, dir){
  cfCollectFields();
  var newIdx = idx + dir;
  if (newIdx < 0 || newIdx >= cfFields.length) return;
  var tmp = cfFields[idx];
  cfFields[idx] = cfFields[newIdx];
  cfFields[newIdx] = tmp;
  cfRenderFields();
}

function cfPrepareSave(){
  cfCollectFields();
  document.getElementById('cf-fields-json').value = JSON.stringify(cfFields);
  return true;
}

function openCfDetail(id){
  var s = cfSubmissions.find(function(x){ return x.id == id; });
  if (!s) return;
  var body = document.getElementById('cf-detail-body');
  var data = {};
  try { data = JSON.parse(s.data_json || '{}'); } catch(e){}
  var html = '<div class="cf-detail-grid">';
  cfFields.forEach(function(f){
    html += '<div class="cf-detail-item"><div class="cf-detail-label">' + (f.label || f.key) + '</div><div class="cf-detail-value">' + (data[f.key] || '-') + '</div></div>';
  });
  html += '<div class="cf-detail-item"><div class="cf-detail-label">Submitted</div><div class="cf-detail-value">' + (s.created_at || '-') + ' · IP: ' + (s.ip || '-') + '</div></div>';
  html += '</div>';
  body.innerHTML = html;
  openCfModal('cf-modal-detail');
}

function cfMarkRead(id){
  if (!confirm('Mark this submission as read?')) return;
  document.getElementById('cf-single-id').value = id;
  document.getElementById('cf-single-mark-read').value = '1';
  document.getElementById('cf-single-delete').value = '';
  document.getElementById('cf-single-form').submit();
}

function cfDelete(id){
  if (!confirm('Delete this submission permanently?')) return;
  document.getElementById('cf-single-id').value = id;
  document.getElementById('cf-single-mark-read').value = '';
  document.getElementById('cf-single-delete').value = '1';
  document.getElementById('cf-single-form').submit();
}

function cfSaveRecaptcha(){
  var fd = new FormData();
  fd.set('csrf_token', (document.querySelector('#cf-modal-recaptcha [name="csrf_token"]') || {}).value || '');
  fd.set('save_recaptcha', '1');
  if (document.getElementById('recaptcha_enabled').checked) fd.set('recaptcha_enabled', '1');
  fd.set('recaptcha_sitekey', (document.querySelector('#cf-modal-recaptcha [name="recaptcha_sitekey"]') || {}).value || '');
  fd.set('recaptcha_secret', (document.querySelector('#cf-modal-recaptcha [name="recaptcha_secret"]') || {}).value || '');
  fetch('?page=admin/tools/contact-form', { method:'POST', body:fd })
    .then(function(){ location.replace('?page=admin/tools/contact-form&recaptcha_saved=1'); })
    .catch(function(){ location.reload(); });
}

function cfMoveRight(){
  var left = document.getElementById('access-left');
  var right = document.getElementById('access-right');
  Array.from(left.selectedOptions).forEach(function(opt){
    opt.selected = false;
    right.appendChild(opt);
  });
}

function cfMoveLeft(){
  var left = document.getElementById('access-left');
  var right = document.getElementById('access-right');
  Array.from(right.selectedOptions).forEach(function(opt){
    opt.selected = false;
    left.appendChild(opt);
  });
}

function cfSaveAccess(){
  var fd = new FormData();
  fd.set('csrf_token', (document.querySelector('#cf-modal-access [name="csrf_token"]') || {}).value || '');
  fd.set('save_access', '1');
  document.querySelectorAll('#cf-modal-access [name="access_roles[]"]:checked').forEach(function(cb){ fd.append('access_roles[]', cb.value); });
  Array.from(document.getElementById('access-right').options).forEach(function(opt){ fd.append('access_users[]', opt.value); });
  fetch('?page=admin/tools/contact-form', { method:'POST', body:fd })
    .then(function(){ location.replace('?page=admin/tools/contact-form&access_saved=1'); })
    .catch(function(){ location.reload(); });
}

// initial render when builder opens
document.querySelector('[onclick*="openCfModal(\'cf-modal-builder\')"]') &&
document.querySelector('[onclick*="openCfModal(\'cf-modal-builder\')"]').addEventListener('click', function(){
  cfRenderFields();
});

// bulk select all / count
(function(){
  var selectAll = document.getElementById('cf-select-all');
  var countEl = document.getElementById('cf-selected-count');
  var bulkForm = document.getElementById('cf-bulk-form');
  function updateCount(){
    var n = document.querySelectorAll('.cf-row-check:checked').length;
    countEl.textContent = n;
  }
  if (selectAll) {
    selectAll.addEventListener('change', function(){
      document.querySelectorAll('.cf-row-check').forEach(function(cb){ cb.checked = selectAll.checked; });
      updateCount();
    });
  }
  document.querySelectorAll('.cf-row-check').forEach(function(cb){
    cb.addEventListener('change', updateCount);
  });
  if (bulkForm) {
    bulkForm.addEventListener('submit', function(e){
      var action = (bulkForm.querySelector('[name="bulk_action"]') || {}).value;
      if (!action) { e.preventDefault(); return false; }
      var selected = document.querySelectorAll('.cf-row-check:checked').length;
      if (!selected) { e.preventDefault(); alert('No submissions selected.'); return false; }
      if (action === 'delete' && !confirm('Delete selected submissions permanently?')) {
        e.preventDefault(); return false;
      }
    });
  }
  updateCount();
})();
</script>
