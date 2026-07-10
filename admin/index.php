<?php
// /plugins/contact-form/admin/index.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) exit;

$csrf = function_exists('csrf_token') ? csrf_token() : '';
$saveMessage = '';

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

// ---- Mark as read ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read']) && isset($_POST['id'])) {
    if (!function_exists('csrf_check') || !csrf_check($_POST['csrf_token'] ?? '')) {
        $saveMessage = 'Invalid CSRF.';
    } else {
        $stmt = $pdo->prepare('UPDATE `contact_submissions` SET `is_read` = 1 WHERE `id` = ?');
        $stmt->execute([(int)$_POST['id']]);
        $saveMessage = 'Marked as read.';
    }
}

// ---- Delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete']) && isset($_POST['id'])) {
    if (!function_exists('csrf_check') || !csrf_check($_POST['csrf_token'] ?? '')) {
        $saveMessage = 'Invalid CSRF.';
    } else {
        $stmt = $pdo->prepare('DELETE FROM `contact_submissions` WHERE `id` = ?');
        $stmt->execute([(int)$_POST['id']]);
        $saveMessage = 'Deleted.';
    }
}

// ---- Fetch data ----
$fields = cf_get_fields($pdo);
$submissions = [];
try {
    $stmt = $pdo->query('SELECT * FROM `contact_submissions` ORDER BY `created_at` DESC');
    $submissions = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $submissions = [];
}

$fieldsJson = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

?>
<div class="cf-admin">
  <div class="cf-admin__head">
    <h1 class="cf-admin__title">Contact Submissions</h1>
    <div class="cf-admin__actions">
      <button type="button" class="adam-button adam-button--secondary" onclick="openCfModal('cf-modal-builder')">Form Builder</button>
    </div>
  </div>

  <?php if ($saveMessage !== ''): ?>
    <div class="cf-alert cf-alert--success"><?= htmlspecialchars($saveMessage, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="cf-table-wrap">
    <?php if (empty($submissions)): ?>
      <p class="cf-empty">No submissions yet.</p>
    <?php else: ?>
      <table class="cf-table">
        <thead>
          <tr>
            <th>Status</th>
            <th>Name</th>
            <th>Contact</th>
            <th>Message</th>
            <th>Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($submissions as $s): ?>
            <tr class="<?= (int)$s['is_read'] ? 'cf-row--read' : 'cf-row--unread' ?>">
              <td>
                <span class="cf-badge cf-badge--<?= (int)$s['is_read'] ? 'read' : 'unread' ?>">
                  <?= (int)$s['is_read'] ? 'Read' : 'New' ?>
                </span>
              </td>
              <td><?= htmlspecialchars((string)$s['name'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$s['contact'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars(mb_strimwidth((string)$s['message'], 0, 80, '...'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$s['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <button type="button" class="cf-btn cf-btn--sm" onclick="openCfDetail(<?= (int)$s['id'] ?>)">View</button>
                <?php if (!(int)$s['is_read']): ?>
                  <form method="post" action="?page=admin/tools/contact-form" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button type="submit" name="mark_read" class="cf-btn cf-btn--sm cf-btn--success">Mark Read</button>
                  </form>
                <?php endif; ?>
                <form method="post" action="?page=admin/tools/contact-form" style="display:inline" onsubmit="return confirm('Delete this submission?')">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                  <button type="submit" name="delete" class="cf-btn cf-btn--sm cf-btn--danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<!-- Form Builder Modal -->
<div id="cf-modal-builder" class="cf-modal" onclick="closeCfModalOnBackdrop(event)">
  <div class="cf-modal__box" onclick="event.stopPropagation()">
    <div class="cf-modal__head">
      <h3>Form Builder</h3>
      <button type="button" class="cf-modal__close" onclick="closeCfModal('cf-modal-builder')" aria-label="Close"></button>
    </div>
    <form method="post" action="?page=admin/tools/contact-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
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

<!-- Detail Modal -->
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

<style>
.cf-admin { color: var(--adam-text); }
.cf-admin__head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.2rem; flex-wrap: wrap; }
.cf-admin__title { font-size: 1.35rem; font-weight: 700; margin: 0; }
.cf-admin__actions { display: flex; gap: .5rem; }
.cf-alert { padding: .7rem .9rem; border-radius: 8px; margin-bottom: 1rem; font-size: .9rem; background: rgba(30, 143, 74, .12); color: var(--adam-success); border: 1px solid rgba(30, 143, 74, .25); }
.cf-empty { color: var(--adam-muted); padding: 2rem; text-align: center; }
.cf-table-wrap { overflow-x: auto; }
.cf-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
.cf-table th, .cf-table td { padding: .65rem .75rem; border-bottom: 1px solid var(--adam-border); text-align: left; }
.cf-table th { font-weight: 600; color: var(--adam-muted); font-size: .8rem; text-transform: uppercase; letter-spacing: .03em; }
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
.cf-detail-grid { display: grid; gap: .75rem; }
.cf-detail-item { padding: .75rem; background: var(--adam-bg); border-radius: 8px; }
.cf-detail-label { font-size: .75rem; color: var(--adam-muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: .2rem; }
.cf-detail-value { font-size: .95rem; color: var(--adam-text); white-space: pre-wrap; }
</style>

<script>
var cfFields = JSON.parse('<?= htmlspecialchars($fieldsJson, ENT_QUOTES, 'UTF-8') ?>'.replace(/\u0026quot;/g, '"'));
var cfSubmissions = JSON.parse('<?= htmlspecialchars(json_encode($submissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>'.replace(/\u0026quot;/g, '"'));

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
  html += '<div class="cf-detail-item"><div class="cf-detail-label">Name</div><div class="cf-detail-value">' + (s.name || '-') + '</div></div>';
  html += '<div class="cf-detail-item"><div class="cf-detail-label">Contact</div><div class="cf-detail-value">' + (s.contact || '-') + '</div></div>';
  html += '<div class="cf-detail-item"><div class="cf-detail-label">Message</div><div class="cf-detail-value">' + (s.message || '-') + '</div></div>';
  cfFields.forEach(function(f){
    if (f.key === 'name' || f.key === 'contact' || f.key === 'message') return;
    html += '<div class="cf-detail-item"><div class="cf-detail-label">' + (f.label || f.key) + '</div><div class="cf-detail-value">' + (data[f.key] || '-') + '</div></div>';
  });
  html += '<div class="cf-detail-item"><div class="cf-detail-label">Submitted</div><div class="cf-detail-value">' + (s.created_at || '-') + ' · IP: ' + (s.ip || '-') + '</div></div>';
  html += '</div>';
  body.innerHTML = html;
  openCfModal('cf-modal-detail');
}

// initial render when builder opens
document.querySelector('[onclick*=\"openCfModal(\'cf-modal-builder\')\"]') &&
document.querySelector('[onclick*=\"openCfModal(\'cf-modal-builder\')\"]').addEventListener('click', function(){
  cfRenderFields();
});
</script>
