<?php
// generic view/edit page: ?p=edit&e=<entity>&id=<id>  (no id = new; GET params prefill)
$E = entities();
$e = $_GET['e'] ?? '';
if (!isset($E[$e])) { require __DIR__ . '/notfound.php'; return; }
$def = $E[$e];
$fields = $def['fields'];
$id = (int)($_GET['id'] ?? 0);
$rec = $id ? row("SELECT * FROM `$e` WHERE id=?", [$id]) : null;
if ($id && !$rec) { require __DIR__ . '/notfound.php'; return; }

if (is_post()) {
    $act = $_POST['_action'] ?? 'save';
    if ($act === 'delete' && $id) {
        try {
            q("DELETE FROM `$e` WHERE id=?", [$id]);
            flash(h($def['one']) . ' gelöscht.');
            redirect(url('list', ['e' => $e]));
        } catch (PDOException $ex) {
            flash('Löschen nicht möglich – der Datensatz wird noch verwendet (' . h($ex->errorInfo[2] ?? $ex->getMessage()) . ').', 'danger');
            redirect(url('edit', ['e' => $e, 'id' => $id]));
        }
    }
    if ($act === 'note' && $id && trim($_POST['text'] ?? '') !== '') {
        q("INSERT INTO note_log (entity, entity_id, author, text) VALUES (?,?,?,?)", [$e, $id, current_user(), trim($_POST['text'])]);
        redirect(url('edit', ['e' => $e, 'id' => $id]) . '#notes');
    }
    if ($act === 'upload' && $id && !empty($_FILES['file']['name'])) {
        $files = $_FILES['file'];
        $n = is_array($files['name']) ? count($files['name']) : 1;
        for ($i = 0; $i < $n; $i++) {
            $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
            $tmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $err = is_array($files['error']) ? $files['error'][$i] : $files['error'];
            if ($err !== UPLOAD_ERR_OK) { flash('Upload fehlgeschlagen: ' . h($name), 'danger'); continue; }
            $dir = UPLOAD_DIR . "/$e/$id";
            if (!is_dir($dir)) mkdir($dir, 0770, true);
            $stored = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^\w.\-]/u', '_', $name);
            move_uploaded_file($tmp, "$dir/$stored");
            q("INSERT INTO document (entity, entity_id, filename, stored_name, mime, size, title) VALUES (?,?,?,?,?,?,?)",
              [$e, $id, $name, $stored, mime_content_type("$dir/$stored") ?: 'application/octet-stream', filesize("$dir/$stored"), trim($_POST['title'] ?? '') ?: null]);
        }
        flash('Dokument(e) gespeichert.');
        redirect(url('edit', ['e' => $e, 'id' => $id]) . '#docs');
    }
    // save
    $vals = []; $errs = [];
    foreach ($fields as $k => $f) {
        [$label, $type] = $f;
        $raw = $_POST[$k] ?? null;
        $v = match ($type) {
            'bool' => isset($_POST[$k]) ? 1 : 0,
            'int' => ($raw === null || trim($raw) === '') ? null : (int)$raw,
            'num', 'money' => parse_num($raw),
            'date' => parse_date($raw),
            'fk' => ($raw === null || $raw === '') ? null : (int)$raw,
            'enum' => ($raw === null || $raw === '') ? null : $raw,
            default => ($raw === null || trim($raw) === '') ? null : trim($raw),
        };
        if ($type === 'date' && $raw && !$v) $errs[] = "$label: Datum ungültig";
        $vals[$k] = $v;
    }
    if (!$errs) {
        try {
            if ($id) {
                q("UPDATE `$e` SET " . implode(', ', array_map(fn($k) => "`$k`=?", array_keys($vals))) . " WHERE id=?", [...array_values($vals), $id]);
            } else {
                $vals = array_filter($vals, fn($v) => $v !== null);
                q("INSERT INTO `$e` (" . implode(',', array_map(fn($k) => "`$k`", array_keys($vals))) . ") VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ")", array_values($vals));
                $id = (int)db()->lastInsertId();
            }
            flash(h($def['one']) . ' gespeichert.');
            redirect(!empty($_POST['_back']) ? $_POST['_back'] : url('edit', ['e' => $e, 'id' => $id]));
        } catch (PDOException $ex) {
            $errs[] = $ex->errorInfo[2] ?? $ex->getMessage();
        }
    }
    $rec = $vals + ($rec ?? []);
    foreach ($errs as $m) flash(h($m), 'danger');
}
$rec ??= array_map(fn() => null, $fields);
if (!$id) foreach ($fields as $k => $f) {
    if (isset($_GET[$k])) $rec[$k] = $_GET[$k];
    if ($k === 'property_id' && !$rec[$k]) $rec[$k] = 1;
    if ($f[1] === 'bool' && in_array($k, ['active'], true) && $rec[$k] === null) $rec[$k] = 1;
    if ($f[1] === 'date' && in_array($k, ['created_at', 'invoice_date', 'reading_date', 'posting_date', 'valid_from'], true) && !$rec[$k]) $rec[$k] = date('Y-m-d');
}

function field_input(string $k, array $f, mixed $v): string {
    [$label, $type] = $f; $x = $f[2] ?? null;
    $name = h($k);
    switch ($type) {
        case 'textarea': return "<textarea class=\"form-control\" name=\"$name\" rows=\"3\">" . h($v) . '</textarea>';
        case 'bool': return "<div class=\"form-check form-switch mt-2\"><input class=\"form-check-input\" type=\"checkbox\" name=\"$name\" value=\"1\"" . ($v ? ' checked' : '') . '></div>';
        case 'date': return "<input class=\"form-control\" type=\"date\" name=\"$name\" value=\"" . h($v) . '">';
        case 'money': case 'num':
            $s = $v === null || $v === '' ? '' : number_format((float)$v, $type === 'money' ? 2 : (fmod((float)$v, 1) ? 3 : 0), ',', '');
            if ($type === 'num' && $s !== '') $s = rtrim(rtrim($s, '0'), ',');
            return "<div class=\"input-group\"><input class=\"form-control text-end\" inputmode=\"decimal\" name=\"$name\" value=\"" . h($s) . '">' . ($type === 'money' ? '<span class="input-group-text">€</span>' : '') . '</div>';
        case 'int': return "<input class=\"form-control\" type=\"number\" name=\"$name\" value=\"" . h($v) . '">';
        case 'enum':
            $o = '';
            foreach ($x as $ov => $ol) $o .= '<option value="' . h($ov) . '"' . ((string)$v === (string)$ov ? ' selected' : '') . '>' . h($ol) . '</option>';
            return "<select class=\"form-select\" name=\"$name\">$o</select>";
        case 'fk':
            $o = '<option value="">–</option>';
            foreach (fk_options($x) as $ov => $ol) $o .= '<option value="' . h($ov) . '"' . ((string)$v === (string)$ov ? ' selected' : '') . '>' . h($ol) . '</option>';
            return "<select class=\"form-select\" name=\"$name\" data-search>$o</select>";
        default: return "<input class=\"form-control\" name=\"$name\" value=\"" . h($v) . '">';
    }
}

$title = $id ? $def['one'] . ' ' . (fk_options($e)[$id] ?? "#$id") : 'Neu: ' . $def['one'];
page_head($title); ?>
<nav aria-label="breadcrumb" class="d-print-none"><ol class="breadcrumb mb-2">
 <li class="breadcrumb-item"><a href="<?= url('list', ['e' => $e]) ?>"><?= h($def['title']) ?></a></li>
 <li class="breadcrumb-item active"><?= h($id ? (fk_options($e)[$id] ?? "#$id") : 'neu') ?></li>
</ol></nav>
<div class="d-flex align-items-center gap-2 mb-3">
 <h1 class="h3 mb-0 me-auto"><i class="bi <?= $def['icon'] ?> me-2"></i><?= h($title) ?></h1>
 <?php if ($id && $e === 'lease'): ?>
  <a class="btn btn-sm btn-outline-primary" href="<?= url('account', ['lease' => $id]) ?>"><i class="bi bi-journal-text"></i> Mieterkonto</a>
 <?php elseif ($id && $e === 'weg_unit'): ?>
  <a class="btn btn-sm btn-outline-primary" href="<?= url('account', ['weg' => $id]) ?>"><i class="bi bi-journal-text"></i> Hausgeldkonto</a>
 <?php elseif ($id && $e === 'maintenance_plan'): ?>
  <a class="btn btn-sm btn-outline-success" href="<?= url('maint', ['done' => $id]) ?>"><i class="bi bi-check2-square"></i> erledigt melden</a>
 <?php endif; ?>
</div>

<div class="row g-3">
<div class="col-xl-7">
 <form method="post" class="card"><div class="card-body">
  <?= csrf_field() ?><input type="hidden" name="_back" value="<?= h($_GET['back'] ?? '') ?>">
  <div class="row g-3">
  <?php foreach ($fields as $k => $f): $wide = in_array($f[1], ['textarea'], true) || in_array($k, ['text', 'title', 'description', 'purpose'], true); ?>
   <div class="<?= $wide ? 'col-12' : 'col-md-6' ?>">
    <label class="form-label small text-body-secondary mb-1"><?= h($f[0]) ?></label>
    <?= field_input($k, $f, $rec[$k] ?? null) ?>
   </div>
  <?php endforeach; ?>
  </div>
 </div>
 <div class="card-footer d-flex gap-2">
  <button class="btn btn-primary"><i class="bi bi-save"></i> Speichern</button>
  <a class="btn btn-outline-secondary" href="<?= url('list', ['e' => $e]) ?>">Zurück</a>
  <?php if ($id): ?><button class="btn btn-outline-danger ms-auto" name="_action" value="delete" onclick="return confirm('Wirklich löschen?')"><i class="bi bi-trash"></i></button><?php endif; ?>
 </div>
 </form>
</div>

<?php if ($id): ?>
<div class="col-xl-5">
 <?php if ($e === 'lease'):
     $bal = lease_balance($id);
     $r = rent_at($id, date('Y-m-d')) ?? row("SELECT * FROM lease_rent WHERE lease_id=? ORDER BY valid_from DESC LIMIT 1", [$id]); ?>
  <div class="card mb-3"><div class="card-body">
   <div class="d-flex justify-content-between"><span>Saldo Mieterkonto</span><b class="<?= $bal > 0.005 ? 'text-danger' : 'text-success' ?>"><?= money($bal) ?></b></div>
   <?php if ($r): ?><hr class="my-2"><div class="small">
     Aktuelle Miete ab <?= dmy($r['valid_from']) ?>: Kalt <?= money($r['base_rent']) ?> · NK <?= money($r['operating_advance']) ?> · HK <?= money($r['heating_advance']) ?> · Stp <?= money($r['parking_rent']) ?>
     = <b><?= money($r['base_rent'] + $r['operating_advance'] + $r['heating_advance'] + $r['parking_rent']) ?></b>
     <?php $u = row("SELECT living_area FROM unit WHERE id=?", [$rec['unit_id']]); if ($u && $u['living_area']): ?> · <?= num($r['base_rent'] / $u['living_area']) ?> €/m² kalt<?php endif; ?>
   </div><?php endif; ?>
  </div></div>
 <?php endif; ?>
 <?php if ($e === 'unit'):
     $cur = row("SELECT l.id, " . str_replace(['company', 'last_name', 'first_name'], ['p.company', 'p.last_name', 'p.first_name'], PERSON_LABEL_SQL) . " n FROM lease l JOIN person p ON p.id=l.tenant_id WHERE l.unit_id=? AND (l.end_date IS NULL OR l.end_date>=CURDATE()) ORDER BY l.start_date DESC LIMIT 1", [$id]); ?>
  <div class="card mb-3"><div class="card-body d-flex justify-content-between"><span>Aktueller Mieter</span>
   <?= $cur ? '<a href="' . url('edit', ['e' => 'lease', 'id' => $cur['id']]) . '">' . h($cur['n']) . '</a>' : badge('leer', 'warning') ?></div></div>
 <?php endif; ?>

 <div class="card mb-3" id="docs"><div class="card-header"><i class="bi bi-paperclip me-1"></i>Dokumente</div>
  <ul class="list-group list-group-flush">
  <?php foreach (rows("SELECT * FROM document WHERE entity=? AND entity_id=? ORDER BY uploaded_at DESC", [$e, $id]) as $d): ?>
   <li class="list-group-item d-flex justify-content-between"><a href="<?= url('doc', ['id' => $d['id']]) ?>" target="_blank"><?= h($d['title'] ?: $d['filename']) ?></a><small class="text-body-secondary"><?= dmy($d['uploaded_at']) ?></small></li>
  <?php endforeach; ?>
  </ul>
  <div class="card-body"><form method="post" enctype="multipart/form-data" class="d-flex gap-2"><?= csrf_field() ?>
   <input type="hidden" name="_action" value="upload">
   <input class="form-control form-control-sm" type="file" name="file[]" multiple>
   <input class="form-control form-control-sm" name="title" placeholder="Titel (optional)">
   <button class="btn btn-sm btn-outline-primary"><i class="bi bi-upload"></i></button></form></div>
 </div>

 <div class="card mb-3" id="notes"><div class="card-header"><i class="bi bi-chat-left-text me-1"></i>Notizen / Verlauf</div>
  <ul class="list-group list-group-flush">
  <?php foreach (rows("SELECT * FROM note_log WHERE entity=? AND entity_id=? ORDER BY created_at DESC", [$e, $id]) as $n): ?>
   <li class="list-group-item"><small class="text-body-secondary"><?= date('d.m.Y H:i', strtotime($n['created_at'])) ?> · <?= h($n['author']) ?></small><div style="white-space:pre-wrap"><?= h($n['text']) ?></div></li>
  <?php endforeach; ?>
  </ul>
  <div class="card-body"><form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="note">
   <textarea class="form-control form-control-sm mb-2" name="text" rows="2" placeholder="Telefonat, Absprache, Mängelanzeige …"></textarea>
   <button class="btn btn-sm btn-outline-primary">Notiz speichern</button></form></div>
 </div>
</div>

<?php foreach (($def['children'] ?? []) as [$ce, $cfk]):
    $cdef = $E[$ce];
    $cf = $cdef['fields'];
    $sel = ['t.*']; $joins = '';
    foreach ($cdef['list'] as $k) if ($cf[$k][1] === 'fk') { $x = $cf[$k][2]; $joins .= " LEFT JOIN `$x` f_$k ON f_$k.id=t.`$k`"; $sel[] = fk_label_sql($x, "f_$k") . " AS `_$k`"; }
    $crow = rows("SELECT * FROM (SELECT " . implode(',', $sel) . " FROM `$ce` t $joins WHERE t.`$cfk`=?) x ORDER BY {$cdef['order']} LIMIT 200", [$id]);
    $cols = array_values(array_filter($cdef['list'], fn($k) => $k !== $cfk)); ?>
 <div class="col-12">
  <div class="card"><div class="card-header d-flex align-items-center">
   <span class="me-auto"><i class="bi <?= $cdef['icon'] ?> me-1"></i><?= h($cdef['title']) ?> <span class="badge text-bg-secondary"><?= count($crow) ?></span></span>
   <a class="btn btn-sm btn-outline-secondary me-2" href="<?= url('list', ['e' => $ce, $cfk => $id]) ?>">alle</a>
   <a class="btn btn-sm btn-outline-primary" href="<?= url('edit', ['e' => $ce, $cfk => $id, 'back' => url('edit', ['e' => $e, 'id' => $id])]) ?>"><i class="bi bi-plus-lg"></i></a>
  </div>
  <?php if ($crow): ?><div class="table-responsive" style="max-height:24rem"><table class="table table-sm table-hover mb-0">
   <thead class="table-light sticky-top"><tr><?php foreach ($cols as $k): ?><th class="<?= in_array($cf[$k][1], ['money', 'num'], true) ? 'text-end' : '' ?>"><?= h($cf[$k][0]) ?></th><?php endforeach; ?></tr></thead>
   <tbody><?php foreach ($crow as $r): ?><tr style="cursor:pointer" onclick="location='<?= url('edit', ['e' => $ce, 'id' => $r['id']]) ?>'">
    <?php foreach ($cols as $k): [$l, $t] = $cf[$k]; $x = $cf[$k][2] ?? null; $v = $r[$k]; ?>
     <td class="<?= in_array($t, ['money', 'num'], true) ? 'text-end text-nowrap' : '' ?>"><?= match ($t) { 'fk' => h($r["_$k"] ?? ''), 'money' => money($v, true), 'num' => num($v), 'date' => dmy($v), 'bool' => $v ? '✓' : '', 'enum' => $k === 'status' ? status_badge((string)$v) : h($x[$v] ?? $v), default => h(mb_strimwidth((string)$v, 0, 70, '…')) } ?></td>
    <?php endforeach; ?></tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
  </div>
 </div>
<?php endforeach; ?>
<?php endif; ?>
</div>
<?php page_foot();
