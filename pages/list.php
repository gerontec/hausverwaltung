<?php
// generic list page: ?p=list&e=<entity>[&<filter>=..][&q=..]
$E = entities();
$e = $_GET['e'] ?? '';
if (!isset($E[$e])) { require __DIR__ . '/notfound.php'; return; }
$def = $E[$e];
$fields = $def['fields'];
$where = []; $args = [];
foreach ($_GET as $k => $v) {
    if ($v === '' || in_array($k, ['p', 'e', 'q', 'page'], true)) continue;
    if ($k === 'active' && $e === 'lease') { $where[] = '(t.end_date IS NULL OR t.end_date >= CURDATE())'; continue; }
    if ($k === 'open' && $e === 'ticket') { $where[] = "t.status IN ('open','in_progress','waiting')"; continue; }
    if ($k === 'year' && isset($fields['invoice_date'])) { $where[] = 'YEAR(t.invoice_date)=?'; $args[] = (int)$v; continue; }
    if (isset($fields[$k])) { $where[] = "t.`$k` = ?"; $args[] = $v; }
}
$qtxt = trim($_GET['q'] ?? '');
if ($qtxt !== '') {
    $or = [];
    foreach ($fields as $k => [$l, $type]) if (in_array($type, ['text', 'textarea'], true)) { $or[] = "t.`$k` LIKE ?"; $args[] = "%$qtxt%"; }
    foreach ($fields as $k => $f) if ($f[1] === 'fk' && ($x = $f[2])) { $or[] = '(' . fk_label_sql($x, 'f_' . $k) . ") LIKE ?"; $args[] = "%$qtxt%"; }
    if ($or) $where[] = '(' . implode(' OR ', $or) . ')';
}
$joins = '';
$sel = ['t.*'];
foreach ($fields as $k => $f) { $type = $f[1]; $x = $f[2] ?? null;
    if ($type === 'fk') { $joins .= " LEFT JOIN `$x` f_$k ON f_$k.id = t.`$k`"; $sel[] = fk_label_sql($x, "f_$k") . " AS `_$k`"; }
}
$sql = "SELECT * FROM (SELECT " . implode(', ', $sel) . " FROM `$e` t $joins" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ") x";
$data = rows("$sql ORDER BY {$def['order']}", $args);
$limit = 500;
$total = count($data);
$data = array_slice($data, 0, $limit);

if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $e . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map(fn($k) => $fields[$k][0], array_keys($fields)), ';', '"', '');
    foreach ($data as $r) fputcsv($out, array_map(fn($k) => $fields[$k][1] === 'fk' ? $r["_$k"] : $r[$k], array_keys($fields)), ';', '"', '');
    exit;
}

function cell(array $r, string $k, array $f): string {
    [$l, $type] = $f; $x = $f[2] ?? null;
    $v = $r[$k];
    return match ($type) {
        'fk' => $v ? '<a href="' . url('edit', ['e' => $x, 'id' => $v]) . '">' . h($r["_$k"]) . '</a>' : '',
        'money' => money($v, true),
        'num' => num($v),
        'date' => dmy($v),
        'bool' => $v ? '<i class="bi bi-check-lg text-success"></i>' : '',
        'enum' => ($k === 'status' ? status_badge((string)$v) : ($k === 'priority' ? prio_badge((string)$v) : h($x[$v] ?? $v))),
        default => h(mb_strimwidth((string)$v, 0, 90, '…')),
    };
}

page_head($def['title']); ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0 me-auto"><i class="bi <?= $def['icon'] ?> me-2"></i><?= h($def['title']) ?> <small class="text-body-secondary fs-6"><?= $total ?></small></h1>
  <form class="d-flex gap-2" method="get">
    <input type="hidden" name="p" value="list"><input type="hidden" name="e" value="<?= h($e) ?>">
    <?php foreach (($def['filters'] ?? []) as $fk):
        if ($fk === 'active' || $fk === 'open') { ?>
        <div class="form-check form-switch align-self-center"><input class="form-check-input" type="checkbox" name="<?= $fk ?>" value="1" id="f<?= $fk ?>" <?= !empty($_GET[$fk]) ? 'checked' : '' ?> onchange="this.form.submit()"><label class="form-check-label" for="f<?= $fk ?>"><?= $fk === 'active' ? 'nur aktive' : 'nur offene' ?></label></div>
        <?php continue; }
        if ($fk === 'year') { ?>
        <select class="form-select form-select-sm" name="year" onchange="this.form.submit()"><option value="">Jahr</option>
          <?php for ($y = (int)date('Y'); $y >= 2023; $y--): ?><option <?= ($_GET['year'] ?? '') == $y ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select>
        <?php continue; }
        [$fl, $ft] = $fields[$fk]; $fx = $fields[$fk][2] ?? null;
        $opts = $ft === 'fk' ? fk_options($fx) : ($ft === 'enum' ? $fx : ($ft === 'bool' ? ['1' => 'ja', '0' => 'nein'] : []));
        if (count($opts) > 400) continue; ?>
        <select class="form-select form-select-sm" name="<?= $fk ?>" onchange="this.form.submit()" style="max-width:14rem">
          <option value=""><?= h($fl) ?>: alle</option>
          <?php foreach ($opts as $ov => $ol): ?><option value="<?= h($ov) ?>" <?= (string)($_GET[$fk] ?? '') === (string)$ov ? 'selected' : '' ?>><?= h($ol) ?></option><?php endforeach; ?>
        </select>
    <?php endforeach; ?>
    <input class="form-control form-control-sm" name="q" value="<?= h($qtxt) ?>" placeholder="filtern …" style="max-width:12rem">
  </form>
  <a class="btn btn-sm btn-outline-secondary" href="<?= h($_SERVER['REQUEST_URI']) ?>&format=csv" title="CSV-Export"><i class="bi bi-download"></i></a>
  <a class="btn btn-sm btn-primary" href="<?= url('edit', ['e' => $e] + array_intersect_key($_GET, $fields)) ?>"><i class="bi bi-plus-lg"></i> Neu</a>
</div>
<div class="table-responsive">
<table class="table table-sm table-hover align-middle">
 <thead class="table-light"><tr><?php foreach ($def['list'] as $k): ?><th class="<?= in_array($fields[$k][1], ['money', 'num'], true) ? 'text-end' : '' ?>"><?= h($fields[$k][0]) ?></th><?php endforeach; ?><th></th></tr></thead>
 <tbody>
 <?php foreach ($data as $r): ?>
  <tr>
   <?php foreach ($def['list'] as $i => $k): ?>
    <td class="<?= in_array($fields[$k][1], ['money', 'num'], true) ? 'text-end text-nowrap' : '' ?>"><?= $i === 0 ? '<a class="fw-medium" href="' . url('edit', ['e' => $e, 'id' => $r['id']]) . '">' . (cell($r, $k, $fields[$k]) ?: '#' . $r['id']) . '</a>' : cell($r, $k, $fields[$k]) ?></td>
   <?php endforeach; ?>
   <td class="text-end"><a class="btn btn-sm btn-link py-0" href="<?= url('edit', ['e' => $e, 'id' => $r['id']]) ?>"><i class="bi bi-pencil"></i></a></td>
  </tr>
 <?php endforeach; ?>
 <?php if (!$data): ?><tr><td colspan="99" class="text-body-secondary text-center py-4">Keine Einträge.</td></tr><?php endif; ?>
 </tbody>
</table>
</div>
<?php if ($total > $limit): ?><p class="text-body-secondary">Es werden <?= $limit ?> von <?= $total ?> Einträgen angezeigt – bitte filtern.</p><?php endif;
page_foot();
