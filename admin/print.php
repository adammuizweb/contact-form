<?php
// /plugins/contact-form/admin/print.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) exit;

function cf_export_print_dispatcher(PDO $pdo, string $action, string $basePage, string $csrf): void {
    $type = in_array(($_GET['type'] ?? 'list'), ['list', 'detail'], true) ? ($_GET['type'] ?? 'list') : 'list';
    $statusFilter = $_GET['status'] ?? 'all';
    $search = trim((string)($_GET['q'] ?? ''));

    if (!cf_validate_csrf($csrf)) {
        http_response_code(403);
        exit('Invalid CSRF');
    }

    $ids = cf_selected_ids();
    $rows = cf_fetch_rows($pdo, $statusFilter, $search, $ids);
    $fields = cf_get_fields($pdo);

    if ($action === 'export') {
        cf_export_xlsx($rows, $fields, $type);
        exit;
    }

    if ($action === 'print') {
        cf_print_html($rows, $fields, $type, $basePage, $csrf);
        exit;
    }
}

function cf_validate_csrf(string $csrf): bool {
    $token = $_GET['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    return function_exists('csrf_check') ? csrf_check(is_string($token) ? $token : '') : false;
}

function cf_selected_ids(): array {
    $raw = $_GET['ids'] ?? $_POST['ids'] ?? [];
    if (is_string($raw) && $raw !== '') $raw = explode(',', $raw);
    if (!is_array($raw)) return [];
    return array_values(array_filter(array_map('intval', $raw)));
}

function cf_fetch_rows(PDO $pdo, string $statusFilter, string $search, array $ids = []): array {
    $where = "WHERE 1=1";
    $params = [];

    if ($statusFilter === 'unread') {
        $where .= " AND `is_deleted` = 0 AND `is_spam` = 0 AND `is_read` = 0";
    } elseif ($statusFilter === 'read') {
        $where .= " AND `is_deleted` = 0 AND `is_spam` = 0 AND `is_read` = 1";
    } elseif ($statusFilter === 'spam') {
        $where .= " AND `is_deleted` = 0 AND `is_spam` = 1";
    } elseif ($statusFilter === 'trash') {
        $where .= " AND `is_deleted` = 1";
    } else {
        $where .= " AND `is_deleted` = 0";
    }

    if ($search !== '') {
        $where .= " AND (`name` LIKE :q OR `contact` LIKE :q OR `message` LIKE :q OR `data_json` LIKE :q)";
        $params[':q'] = '%' . $search . '%';
    }
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $where .= " AND `id` IN ($ph)";
        $params = array_merge($params, $ids);
    }

    $stmt = $pdo->prepare("SELECT * FROM `contact_submissions` $where ORDER BY `created_at` DESC");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function cf_status_label(array $row): string {
    if ((int)$row['is_deleted']) return 'Trash';
    if ((int)$row['is_spam']) return 'Spam';
    if ((int)$row['is_read']) return 'Read';
    return 'New';
}

function cf_export_xlsx(array $rows, array $fields, string $type): void {
    $vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($vendorAutoload)) {
        http_response_code(500);
        exit('Export requires PhpSpreadsheet. Run composer install or use the vendor-included zip.');
    }
    require_once $vendorAutoload;
    if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
        http_response_code(500);
        exit('PhpSpreadsheet not loaded.');
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    if ($type === 'detail') {
        $id = (int)($_GET['id'] ?? 0);
        $row = $id > 0 ? array_values(array_filter($rows, function ($r) use ($id) { return (int)$r['id'] === $id; }))[0] ?? null : null;
        if (!$row) { http_response_code(404); exit('Submission not found'); }

        $data = json_decode($row['data_json'] ?? '{}', true) ?: [];
        $sheet->setCellValue('A1', 'Contact Submission #' . $row['id']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->mergeCells('A1:B1');

        $r = 2;
        $pairs = [
            'ID' => $row['id'],
            'Status' => cf_status_label($row),
            'Name' => $row['name'],
            'Contact' => $row['contact'],
            'Message' => $row['message'],
        ];
        foreach ($fields as $f) {
            $pairs[$f['label'] ?: $f['key']] = $data[$f['key']] ?? '';
        }
        $pairs['IP'] = $row['ip'] ?? '';
        $pairs['Submitted'] = $row['created_at'] ?? '';

        foreach ($pairs as $label => $value) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            $sheet->setCellValue("B{$r}", (string)$value);
            if ($label === 'Message') {
                $sheet->getStyle("B{$r}")->getAlignment()->setWrapText(true);
            }
            $r++;
        }
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);
        $filename = 'contact-submission-' . $row['id'] . '.xlsx';
    } else {
        $headers = array_merge(['ID', 'Status', 'Name', 'Contact', 'Message'], array_map(function ($f) { return $f['label'] ?: $f['key']; }, $fields), ['IP', 'Submitted']);
        $col = 1;
        foreach ($headers as $h) {
            $sheet->setCellValue([$col, 1], $h);
            $sheet->getStyle([$col, 1])->getFont()->setBold(true);
            $col++;
        }

        $r = 2;
        foreach ($rows as $row) {
            $data = json_decode($row['data_json'] ?? '{}', true) ?: [];
            $values = array_merge(
                [$row['id'], cf_status_label($row), $row['name'], $row['contact'], $row['message']],
                array_map(function ($f) use ($data) { return $data[$f['key']] ?? ''; }, $fields),
                [$row['ip'] ?? '', $row['created_at'] ?? '']
            );
            $col = 1;
            foreach ($values as $v) {
                $sheet->setCellValue([$col, $r], (string)$v);
                $col++;
            }
            $r++;
        }

        for ($i = 1; $i <= count($headers); $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
        $filename = 'contact-submissions-' . date('Y-m-d_H-i') . '.xlsx';
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
}

function cf_print_html(array $rows, array $fields, string $type, string $basePage, string $csrf): void {
    $isDetail = $type === 'detail';
    if ($isDetail) {
        $id = (int)($_GET['id'] ?? 0);
        $row = $id > 0 ? array_values(array_filter($rows, function ($r) use ($id) { return (int)$r['id'] === $id; }))[0] ?? null : null;
        if (!$row) { http_response_code(404); exit('Submission not found'); }
        $rows = [$row];
    }

    $title = $isDetail ? 'Contact Submission #' . $rows[0]['id'] : 'Contact Submissions';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title><?= cf_s($title) ?></title>
      <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #1f2937; padding: 24px; }
        h1 { font-size: 20px; margin: 0 0 16px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border: 1px solid #d1d5db; padding: 8px 10px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-weight: 600; }
        .meta { color: #6b7280; font-size: 12px; margin-bottom: 12px; }
        .label { font-weight: 600; color: #374151; }
        .value { white-space: pre-wrap; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #e5e7eb; }
        .badge--new { background: #dbeafe; color: #1e40af; }
        .badge--read { background: #f3f4f6; color: #4b5563; }
        .badge--spam { background: #fef3c7; color: #92400e; }
        .badge--trash { background: #fee2e2; color: #991b1b; }
        @media print { body { padding: 0; } .no-print { display: none; } }
      </style>
    </head>
    <body>
      <div class="no-print" style="margin-bottom:16px">
        <button onclick="window.print()">Print</button>
        <a href="<?= cf_s($basePage) ?>">← Back</a>
      </div>
      <h1><?= cf_s($title) ?></h1>
      <div class="meta">Printed on <?= date('Y-m-d H:i') ?></div>

      <?php if ($isDetail): ?>
        <?php $row = $rows[0]; $data = json_decode($row['data_json'] ?? '{}', true) ?: []; ?>
        <div class="meta">Status: <span class="badge badge--<?= cf_s(strtolower(cf_status_label($row))) ?>"><?= cf_s(cf_status_label($row)) ?></span> · Submitted: <?= cf_s($row['created_at']) ?> · IP: <?= cf_s($row['ip']) ?></div>
        <table>
          <tbody>
            <tr><td class="label">Name</td><td class="value"><?= nl2br(cf_s($row['name'])) ?></td></tr>
            <tr><td class="label">Contact</td><td class="value"><?= nl2br(cf_s($row['contact'])) ?></td></tr>
            <tr><td class="label">Message</td><td class="value"><?= nl2br(cf_s($row['message'])) ?></td></tr>
            <?php foreach ($fields as $f): ?>
              <tr><td class="label"><?= cf_s($f['label'] ?: $f['key']) ?></td><td class="value"><?= nl2br(cf_s($data[$f['key']] ?? '')) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Status</th>
              <th>Name</th>
              <th>Contact</th>
              <th>Message</th>
              <?php foreach ($fields as $f): ?>
                <th><?= cf_s($f['label'] ?: $f['key']) ?></th>
              <?php endforeach; ?>
              <th>IP</th>
              <th>Submitted</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <?php $data = json_decode($row['data_json'] ?? '{}', true) ?: []; ?>
              <tr>
                <td><?= (int)$row['id'] ?></td>
                <td><span class="badge badge--<?= cf_s(strtolower(cf_status_label($row))) ?>"><?= cf_s(cf_status_label($row)) ?></span></td>
                <td><?= cf_s($row['name']) ?></td>
                <td><?= cf_s($row['contact']) ?></td>
                <td><?= cf_s(mb_strimwidth((string)$row['message'], 0, 120, '...')) ?></td>
                <?php foreach ($fields as $f): ?>
                  <td><?= cf_s($data[$f['key']] ?? '') ?></td>
                <?php endforeach; ?>
                <td><?= cf_s($row['ip']) ?></td>
                <td><?= cf_s($row['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </body>
    </html>
    <?php
}
