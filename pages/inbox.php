<?php
// Posteingang: documents from Mayan -> assign to an HV record (file moves into the HV archive)
const INBOX_DIR = APP_ROOT . '/data/inbox';

if (isset($_GET['file'])) {
    $d = row("SELECT * FROM inbox_doc WHERE id=?", [(int)$_GET['file']]);
    $path = $d ? INBOX_DIR . '/' . $d['stored_name'] : null;
    if (!$d || !is_file($path)) { http_response_code(404); exit('nicht gefunden'); }
    header('Content-Type: ' . ($d['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode($d['filename']));
    readfile($path); exit;
}

/** guess amount / date / vendor / unit / cost type from OCR text */
function inbox_guess(array $d): array {
    $t = $d['text'] ?? '';
    $g = [];
    if (preg_match_all('/(Gesamtbetrag|Rechnungsbetrag|Endbetrag|zu zahlen(?:der Betrag)?|Zahlbetrag|Bruttobetrag|Gesamtsumme|Summe brutto|Betrag)\D{0,40}?(-?\d{1,3}(?:\.\d{3})*,\d{2})/iu', $t, $m)) $g['amount'] = end($m[2]);
    if (preg_match('/(?:Rechnungsdatum|Datum)\D{0,15}(\d{1,2}\.\d{1,2}\.\d{4})/u', $t, $m) || preg_match('/\b(\d{1,2}\.\d{1,2}\.20\d{2})\b/', $t, $m)) $g['date'] = parse_date($m[1]);
    if (preg_match('/(?:Rechnungs?-?(?:nummer|nr\.?)|Rechnung Nr\.?)\s*:?\s*([A-Z0-9][\w\/-]{2,30})/iu', $t, $m)) $g['invoice_no'] = $m[1];
    $hay = norm($t . ' ' . $d['label'] . ' ' . $d['mail_from'] . ' ' . $d['mail_subject']);
    foreach (rows("SELECT id, company, last_name FROM person WHERE is_vendor=1") as $p) {
        foreach ([$p['company'], $p['last_name']] as $n) { $nn = norm(preg_replace('/\b(GmbH|GbR|AG|KG|Co\.?|&)\b/u', '', (string)$n)); if (strlen($nn) >= 5 && str_contains($hay, $nn)) { $g['vendor_id'] = $p['id']; break 2; } }
    }
    if (str_contains($hay, 'brunata') || str_contains($hay, 'techem') || str_contains($hay, 'ista') || str_contains($hay, 'heizkostenabrechnung')) $g['cost_code'] = 'HZ';
    if (str_contains($hay, 'grundsteuer')) $g['cost_code'] = 'GS';
    if (str_contains($hay, 'versicherung')) $g['cost_code'] = $g['cost_code'] ?? 'VS';
    if (str_contains($hay, 'schornstein') || str_contains($hay, 'kaminkehr')) $g['cost_code'] = 'SK';
    // tenant name -> lease
    foreach (rows("SELECT l.id, p.last_name FROM lease l JOIN person p ON p.id=l.tenant_id WHERE l.end_date IS NULL OR l.end_date>=DATE_SUB(CURDATE(), INTERVAL 1 YEAR)") as $l) {
        $ln = norm((string)$l['last_name']); if (strlen($ln) >= 4 && str_contains($hay, $ln)) { $g['lease_id'] = $l['id']; break; }
    }
    return $g;
}

if (is_post()) {
    $d = row("SELECT * FROM inbox_doc WHERE id=?", [(int)$_POST['doc']]);
    $act = $_POST['_action'] ?? '';
    if ($d && $act === 'ignore') { q("UPDATE inbox_doc SET status='ignored' WHERE id=?", [$d['id']]); flash('Dokument ignoriert (bleibt in Mayan).'); redirect(url('inbox')); }
    if ($d && $act === 'restore') { q("UPDATE inbox_doc SET status='new' WHERE id=?", [$d['id']]); redirect(url('inbox', ['id' => $d['id']])); }
    if ($d && $act === 'assign') {
        $target = $_POST['target'] ?? '';
        $ent = null; $eid = null;
        if ($target === 'expense_new') {
            $amt = parse_num($_POST['amount'] ?? ''); $dt = parse_date($_POST['date'] ?? '') ?? date('Y-m-d');
            if ($amt === null || empty($_POST['cost_type_id'])) { flash('Für eine Ausgabe sind Betrag und Kostenart nötig.', 'danger'); redirect(url('inbox', ['id' => $d['id']])); }
            $pf = parse_date($_POST['period_from'] ?? '') ?? substr($dt, 0, 4) . '-01-01'; $pt = parse_date($_POST['period_to'] ?? '') ?? substr($dt, 0, 4) . '-12-31';
            q("INSERT INTO expense (property_id, building_id, cost_type_id, vendor_id, invoice_date, invoice_no, period_from, period_to, amount, text, source) VALUES (1,?,?,?,?,?,?,?,?,?, 'posteingang')",
              [(int)($_POST['building_id'] ?? 0) ?: null, (int)$_POST['cost_type_id'], (int)($_POST['vendor_id'] ?? 0) ?: null, $dt, trim($_POST['invoice_no'] ?? '') ?: null, $pf, $pt, $amt, mb_substr($d['label'], 0, 250)]);
            $ent = 'expense'; $eid = (int)db()->lastInsertId();
        } elseif ($target === 'ticket_new') {
            q("INSERT INTO ticket (title, building_id, unit_id, description, created_at, status, priority) VALUES (?,?,?,?,CURDATE(),'open','normal')",
              [trim($_POST['title'] ?? '') ?: $d['label'], (int)($_POST['building_id'] ?? 0) ?: null, (int)($_POST['unit_id'] ?? 0) ?: null, 'aus Posteingang (Mayan ' . $d['mayan_id'] . ')']);
            $ent = 'ticket'; $eid = (int)db()->lastInsertId();
        } elseif (in_array($target, ['lease', 'unit', 'person', 'building', 'weg_unit', 'maintenance_plan', 'ticket', 'expense', 'property', 'asset'], true)) {
            $ent = $target; $eid = (int)($_POST["id_$target"] ?? 0);
        }
        if (!$ent || !$eid) { flash('Bitte Ziel wählen.', 'danger'); redirect(url('inbox', ['id' => $d['id']])); }
        $dir = UPLOAD_DIR . "/$ent/$eid";
        if (!is_dir($dir)) mkdir($dir, 0770, true);
        $stored = bin2hex(random_bytes(6)) . '_' . $d['stored_name'];
        if (!@rename(INBOX_DIR . '/' . $d['stored_name'], "$dir/$stored")) copy(INBOX_DIR . '/' . $d['stored_name'], "$dir/$stored");
        q("INSERT INTO document (entity, entity_id, filename, stored_name, mime, size, title) VALUES (?,?,?,?,?,?,?)",
          [$ent, $eid, $d['filename'], $stored, $d['mime'], $d['size'], trim($_POST['doc_title'] ?? '') ?: $d['label']]);
        $docId = (int)db()->lastInsertId();
        q("UPDATE inbox_doc SET status='assigned', assigned_entity=?, assigned_id=?, document_id=? WHERE id=?", [$ent, $eid, $docId, $d['id']]);
        $E = entities();
        flash('Zugeordnet: <a href="' . url('edit', ['e' => $ent, 'id' => $eid]) . '">' . h($E[$ent]['one'] . ' ' . (fk_options($ent)[$eid] ?? "#$eid")) . '</a>');
        $next = val("SELECT id FROM inbox_doc WHERE status='new' ORDER BY received_at, id LIMIT 1");
        redirect(url('inbox', $next ? ['id' => $next] : []));
    }
}

$status = $_GET['status'] ?? 'new';
$list = rows("SELECT id, mayan_id, label, received_at, cabinet, mail_from, status, assigned_entity, assigned_id FROM inbox_doc " . ($status === 'all' ? '' : 'WHERE status=?') . " ORDER BY received_at DESC, id DESC LIMIT 300", $status === 'all' ? [] : [$status]);
$cur = isset($_GET['id']) ? row("SELECT * FROM inbox_doc WHERE id=?", [(int)$_GET['id']]) : ($list ? row("SELECT * FROM inbox_doc WHERE id=?", [$list[0]['id']]) : null);
$last = val("SELECT MAX(fetched_at) FROM inbox_doc");
$counts = array_column(rows("SELECT status, COUNT(*) n FROM inbox_doc GROUP BY status"), 'n', 'status');
page_head('Posteingang'); ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
 <h1 class="h3 mb-0 me-auto"><i class="bi bi-inbox me-2"></i>Posteingang <small class="text-body-secondary fs-6">aus Mayan · zuletzt abgerufen <?= $last ? date('d.m.Y H:i', strtotime($last)) : '–' ?></small></h1>
 <div class="btn-group btn-group-sm"><?php foreach (['new' => 'neu', 'assigned' => 'zugeordnet', 'ignored' => 'ignoriert', 'all' => 'alle'] as $k => $l): ?><a class="btn btn-outline-secondary <?= $status === $k ? 'active' : '' ?>" href="<?= url('inbox', ['status' => $k]) ?>"><?= $l ?> <?= $k !== 'all' ? '<span class="badge text-bg-light">' . (int)($counts[$k] ?? 0) . '</span>' : '' ?></a><?php endforeach; ?></div>
</div>
<div class="row g-3">
 <div class="col-lg-3"><div class="list-group small" style="max-height:80vh;overflow:auto">
  <?php foreach ($list as $x): ?><a class="list-group-item list-group-item-action <?= $cur && $cur['id'] == $x['id'] ? 'active' : '' ?>" href="<?= url('inbox', ['status' => $status, 'id' => $x['id']]) ?>">
   <div class="fw-medium text-truncate"><?= h($x['label']) ?></div><div class="<?= $cur && $cur['id'] == $x['id'] ? '' : 'text-body-secondary' ?>"><?= $x['received_at'] ? date('d.m.Y', strtotime($x['received_at'])) : '' ?> · <?= h(preg_replace('/^Immobilien \/ /', '', (string)$x['cabinet'])) ?></div></a><?php endforeach; ?>
  <?php if (!$list): ?><div class="list-group-item text-body-secondary">leer</div><?php endif; ?>
 </div></div>
 <?php if ($cur): $g = inbox_guess($cur); $ctId = isset($g['cost_code']) ? val("SELECT id FROM cost_type WHERE code=?", [$g['cost_code']]) : null; ?>
 <div class="col-lg-5"><div class="card h-100"><div class="card-header small text-truncate">Mayan #<?= (int)$cur['mayan_id'] ?> · <?= h($cur['filename']) ?><?= $cur['mail_from'] ? ' · von ' . h($cur['mail_from']) : '' ?></div>
  <?php if (str_contains((string)$cur['mime'], 'pdf') || str_starts_with((string)$cur['mime'], 'image/')): ?>
   <iframe src="<?= url('inbox', ['file' => $cur['id']]) ?>" style="width:100%;height:75vh;border:0"></iframe>
  <?php else: ?><div class="card-body"><a href="<?= url('inbox', ['file' => $cur['id']]) ?>" target="_blank">Datei öffnen</a></div><?php endif; ?>
 </div></div>
 <div class="col-lg-4">
  <?php if ($cur['status'] !== 'new'): ?>
   <div class="alert alert-secondary">Status: <?= h($cur['status']) ?><?= $cur['assigned_entity'] ? ' → <a href="' . url('edit', ['e' => $cur['assigned_entity'], 'id' => $cur['assigned_id']]) . '">' . h(entities()[$cur['assigned_entity']]['one']) . ' #' . (int)$cur['assigned_id'] . '</a>' : '' ?>
   <?php if ($cur['status'] === 'ignored'): ?><form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="doc" value="<?= $cur['id'] ?>"><button class="btn btn-sm btn-outline-secondary" name="_action" value="restore">wieder in den Posteingang</button></form><?php endif; ?></div>
  <?php else: ?>
  <form method="post" class="card"><div class="card-body"><?= csrf_field() ?><input type="hidden" name="_action" value="assign"><input type="hidden" name="doc" value="<?= $cur['id'] ?>">
   <label class="form-label small">Titel im Archiv</label><input class="form-control form-control-sm mb-3" name="doc_title" value="<?= h($cur['label']) ?>">
   <label class="form-label small">zuordnen zu</label>
   <select class="form-select form-select-sm mb-2" name="target" data-toggle-target>
    <option value="expense_new" <?= isset($g['amount']) && empty($g['lease_id']) ? 'selected' : '' ?>>neue Ausgabe / Rechnung</option>
    <option value="lease" <?= !empty($g['lease_id']) ? 'selected' : '' ?>>Mietvertrag / Mieter</option>
    <option value="unit">Einheit (Wohnung, Stellplatz)</option>
    <option value="ticket_new">neuer Vorgang / Schaden</option>
    <option value="ticket">bestehender Vorgang</option>
    <option value="maintenance_plan">Wartung (Prüfprotokoll)</option>
    <option value="expense">bestehende Ausgabe</option>
    <option value="building">Haus</option>
    <option value="weg_unit">WEG-Einheit</option>
    <option value="person">Person / Dienstleister</option>
    <option value="property" <?= !isset($g['amount']) && empty($g['lease_id']) ? 'selected' : '' ?>>Liegenschaft allgemein (Bescheide, Pläne …)</option>
   </select>
   <div data-for="expense_new" class="row g-2">
    <div class="col-6"><label class="form-label small">Betrag</label><input class="form-control form-control-sm text-end" name="amount" value="<?= h($g['amount'] ?? '') ?>"></div>
    <div class="col-6"><label class="form-label small">Rechnungsdatum</label><input class="form-control form-control-sm" type="date" name="date" value="<?= h($g['date'] ?? '') ?>"></div>
    <div class="col-12"><select class="form-select form-select-sm" name="cost_type_id"><option value="">Kostenart …</option><?php foreach (fk_options('cost_type') as $k => $v): ?><option value="<?= $k ?>" <?= $ctId == $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
    <div class="col-12"><select class="form-select form-select-sm" name="vendor_id"><option value="">Lieferant …</option><?php foreach (rows("SELECT id, " . PERSON_LABEL_SQL . " l FROM person WHERE is_vendor=1 ORDER BY l") as $v): ?><option value="<?= $v['id'] ?>" <?= ($g['vendor_id'] ?? 0) == $v['id'] ? 'selected' : '' ?>><?= h($v['l']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6"><input class="form-control form-control-sm" name="invoice_no" placeholder="Rechnungs-Nr." value="<?= h($g['invoice_no'] ?? '') ?>"></div>
    <div class="col-6"><select class="form-select form-select-sm" name="building_id"><option value="">alle Häuser</option><?php foreach (fk_options('building') as $k => $v): ?><option value="<?= $k ?>"><?= h($v) ?></option><?php endforeach; ?></select></div>
    <div class="col-6"><label class="form-label small">Leistung von</label><input class="form-control form-control-sm" type="date" name="period_from"></div>
    <div class="col-6"><label class="form-label small">bis</label><input class="form-control form-control-sm" type="date" name="period_to"></div>
   </div>
   <div data-for="ticket_new" class="row g-2"><div class="col-12"><input class="form-control form-control-sm" name="title" placeholder="Titel des Vorgangs" value="<?= h($cur['label']) ?>"></div>
    <div class="col-6"><select class="form-select form-select-sm" name="unit_id"><option value="">Einheit …</option><?php foreach (fk_options('unit') as $k => $v): ?><option value="<?= $k ?>"><?= h($v) ?></option><?php endforeach; ?></select></div></div>
   <?php foreach (['lease', 'unit', 'ticket', 'maintenance_plan', 'expense', 'building', 'weg_unit', 'person', 'property'] as $t): ?>
    <div data-for="<?= $t ?>"><select class="form-select form-select-sm" name="id_<?= $t ?>"><?php foreach (array_slice(fk_options($t), 0, 400, true) as $k => $v): ?><option value="<?= $k ?>" <?= $t === 'lease' && ($g['lease_id'] ?? 0) == $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
   <?php endforeach; ?>
  </div><div class="card-footer d-flex gap-2"><button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> zuordnen</button>
   <button class="btn btn-outline-secondary btn-sm ms-auto" name="_action" value="ignore">ignorieren</button></div></form>
  <?php if ($cur['text']): ?><details class="mt-2"><summary class="small">OCR-Text</summary><pre class="small border rounded p-2 mt-1" style="max-height:30vh;white-space:pre-wrap"><?= h(mb_substr($cur['text'], 0, 4000)) ?></pre></details><?php endif; ?>
  <?php endif; ?>
 </div>
 <?php endif; ?>
</div>
<?php page_foot();
