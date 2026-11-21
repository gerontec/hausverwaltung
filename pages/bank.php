<?php
// bank: CSV import, automatic and manual assignment
if (is_post()) {
    $act = $_POST['_action'] ?? '';
    if ($act === 'upload' && !empty($_FILES['csv']['tmp_name'])) {
        try {
            $rows = bank_parse_csv(file_get_contents($_FILES['csv']['tmp_name']));
            $until = parse_date($_POST['booked_until'] ?? '');
            [$new, $dup] = bank_store($rows, $until);
            [$auto, $left] = bank_auto();
            flash(sprintf('%d Umsätze gelesen: %d neu, %d schon vorhanden. Automatisch zugeordnet: %d, offen: %d.', count($rows), $new, $dup, $auto, $left));
        } catch (Throwable $ex) { flash('Import fehlgeschlagen: ' . h($ex->getMessage()), 'danger'); }
        redirect(url('bank'));
    }
    if ($act === 'auto') { [$auto, $left] = bank_auto(); flash("Automatisch zugeordnet: $auto, offen: $left."); redirect(url('bank')); }
    if ($act === 'assign') {
        $tx = (int)$_POST['tx'];
        $a = ['action' => $_POST['action'], 'lease_id' => (int)($_POST['lease_id'] ?? 0) ?: null, 'weg_unit_id' => (int)($_POST['weg_unit_id'] ?? 0) ?: null,
              'cost_type_id' => (int)($_POST['cost_type_id'] ?? 0) ?: null, 'vendor_id' => (int)($_POST['vendor_id'] ?? 0) ?: null,
              'building_id' => (int)($_POST['building_id'] ?? 0) ?: null, 'source' => 'manuell'];
        if ($a['action'] === 'payment' && !$a['lease_id'] && !$a['weg_unit_id']) { flash('Bitte Mietvertrag oder WEG-Einheit wählen.', 'danger'); redirect($_POST['_back']); }
        if ($a['action'] === 'expense' && !$a['cost_type_id']) { flash('Bitte Kostenart wählen.', 'danger'); redirect($_POST['_back']); }
        bank_book($tx, $a);
        if (!empty($_POST['rule']) && trim($_POST['pattern'] ?? '') !== '') {
            $t = row("SELECT amount FROM bank_tx WHERE id=?", [$tx]);
            q("INSERT INTO bank_rule (pattern, direction, action, lease_id, weg_unit_id, cost_type_id, vendor_id, building_id, note) VALUES (?,?,?,?,?,?,?,?,?)",
              [trim($_POST['pattern']), $t['amount'] >= 0 ? 'in' : 'out', $a['action'], $a['lease_id'], $a['weg_unit_id'], $a['cost_type_id'], $a['vendor_id'], $a['building_id'], 'angelegt ' . date('d.m.Y')]);
            [$auto] = bank_auto();
            flash("Gebucht, Regel angelegt" . ($auto ? " und $auto weitere Umsätze zugeordnet" : '') . '.');
        } else flash('Gebucht.');
        redirect($_POST['_back']);
    }
    if ($act === 'unbook') { bank_unbook((int)$_POST['tx']); flash('Zuordnung aufgehoben.', 'warning'); redirect($_POST['_back']); }
}

$status = $_GET['status'] ?? 'open';
$qq = trim($_GET['q'] ?? '');
$dir = $_GET['dir'] ?? '';
$w = []; $args = [];
if ($status !== 'all') { $w[] = 'b.status=?'; $args[] = $status; }
if ($qq !== '') { $w[] = 'CONCAT_WS(" ", b.counterparty, b.purpose) LIKE ?'; $args[] = "%$qq%"; }
if ($dir === 'in') $w[] = 'b.amount>0'; elseif ($dir === 'out') $w[] = 'b.amount<0';
$txs = rows("SELECT b.*, (SELECT GROUP_CONCAT(CONCAT('L', COALESCE(lease_id,''), 'W', COALESCE(weg_unit_id,''))) FROM posting WHERE bank_tx_id=b.id) post,
                    (SELECT CONCAT(ct.code, ' ', ct.name) FROM expense e JOIN cost_type ct ON ct.id=e.cost_type_id WHERE e.bank_tx_id=b.id LIMIT 1) exp
             FROM bank_tx b" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY b.booking_date DESC, b.id DESC LIMIT 300", $args);
$stats = row("SELECT COUNT(*) n, SUM(status='open') o, SUM(status='booked') bk, SUM(status='ignored') ig, MIN(booking_date) mi, MAX(booking_date) ma FROM bank_tx");
$leases = fk_options('lease'); $cts = fk_options('cost_type'); $vendors = rows("SELECT id, " . PERSON_LABEL_SQL . " l FROM person WHERE is_vendor=1 ORDER BY l");
$wegs = fk_options('weg_unit'); $houses = fk_options('building');
$back = $_SERVER['REQUEST_URI'];
function opts(array $o, $sel = null): string { $s = '<option value="">–</option>'; foreach ($o as $k => $v) $s .= '<option value="' . h($k) . '"' . ((string)$sel === (string)$k ? ' selected' : '') . '>' . h($v) . '</option>'; return $s; }

page_head('Bank'); ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
 <h1 class="h3 mb-0 me-auto"><i class="bi bi-bank me-2"></i>Bank</h1>
 <span class="text-body-secondary small"><?= (int)$stats['n'] ?> Umsätze <?= dmy($stats['mi']) ?> – <?= dmy($stats['ma']) ?> · <?= (int)$stats['o'] ?> offen · <?= (int)$stats['bk'] ?> gebucht · <?= (int)$stats['ig'] ?> ignoriert</span>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="auto"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-magic"></i> automatisch zuordnen</button></form>
 <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#imp"><i class="bi bi-upload"></i> CSV-Import</button>
</div>
<div class="collapse <?= $stats['n'] ? '' : 'show' ?> mb-3" id="imp"><form method="post" enctype="multipart/form-data" class="card card-body"><?= csrf_field() ?><input type="hidden" name="_action" value="upload">
 <div class="row g-2 align-items-end">
  <div class="col-md-5"><label class="form-label small">CSV (finanzblick „Buchungsliste.csv“, Sparkasse, VR-Bank, DKB …)</label><input class="form-control" type="file" name="csv" accept=".csv,text/csv" required></div>
  <div class="col-md-3"><label class="form-label small">schon gebucht bis (Altdaten)</label><input class="form-control" type="date" name="booked_until" value=""></div>
  <div class="col-md-2"><button class="btn btn-primary">Importieren</button></div>
 </div><div class="form-text">Doppelte Umsätze werden erkannt. Umsätze bis zum Datum „schon gebucht“ werden nur archiviert, weil sie in den importierten Altdaten schon enthalten sind.</div></form></div>

<form class="d-flex gap-2 mb-2"><input type="hidden" name="p" value="bank">
 <select class="form-select form-select-sm w-auto" name="status" onchange="this.form.submit()"><?php foreach (['open' => 'offen', 'booked' => 'gebucht', 'ignored' => 'ignoriert', 'all' => 'alle'] as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
 <select class="form-select form-select-sm w-auto" name="dir" onchange="this.form.submit()"><option value="">Ein- und Ausgänge</option><option value="in" <?= $dir === 'in' ? 'selected' : '' ?>>nur Eingänge</option><option value="out" <?= $dir === 'out' ? 'selected' : '' ?>>nur Ausgänge</option></select>
 <input class="form-control form-control-sm w-auto" name="q" value="<?= h($qq) ?>" placeholder="Name / Verwendungszweck"><button class="btn btn-sm btn-outline-secondary">filtern</button>
</form>

<div class="table-responsive"><table class="table table-sm align-middle">
<thead class="table-light"><tr><th>Datum</th><th>Gegenseite / Verwendungszweck</th><th class="text-end">Betrag</th><th style="min-width:30rem">Zuordnung</th></tr></thead><tbody>
<?php foreach ($txs as $t): $sug = $t['status'] === 'open' ? bank_match($t) : null; ?>
 <tr>
  <td class="text-nowrap"><?= dmy($t['booking_date']) ?></td>
  <td><div class="fw-medium"><?= h($t['counterparty']) ?></div><div class="small text-body-secondary text-break"><?= h(mb_strimwidth($t['purpose'], 0, 220, '…')) ?></div></td>
  <td class="text-end text-nowrap fw-semibold <?= $t['amount'] < 0 ? 'text-danger' : 'text-success' ?>"><?= money($t['amount']) ?></td>
  <td>
  <?php if ($t['status'] !== 'open'): ?>
   <form method="post" class="d-flex gap-2 align-items-center"><?= csrf_field() ?><input type="hidden" name="_action" value="unbook"><input type="hidden" name="tx" value="<?= $t['id'] ?>"><input type="hidden" name="_back" value="<?= h($back) ?>">
    <?= status_badge($t['status']) ?>
    <span class="small"><?php if ($t['post']) { preg_match('/L(\d*)W(\d*)/', $t['post'], $m); echo $m[1] ? '<a href="' . url('account', ['lease' => $m[1]]) . '">' . h($leases[$m[1]] ?? '') . '</a>' : h($wegs[$m[2]] ?? ''); } elseif ($t['exp']) echo h($t['exp']); ?> <span class="text-body-secondary"><?= h($t['note']) ?></span></span>
    <?php if ($t['note'] !== 'bereits in Altdaten enthalten'): ?><button class="btn btn-sm btn-link text-danger ms-auto py-0" title="Zuordnung aufheben"><i class="bi bi-arrow-counterclockwise"></i></button><?php endif; ?>
   </form>
  <?php else: $isIn = $t['amount'] >= 0; $defAct = $sug['action'] ?? ($isIn ? 'payment' : 'expense'); ?>
   <form method="post" class="row g-1 bank-assign"><?= csrf_field() ?><input type="hidden" name="_action" value="assign"><input type="hidden" name="tx" value="<?= $t['id'] ?>"><input type="hidden" name="_back" value="<?= h($back) ?>">
    <div class="col-12 col-xl-3"><select class="form-select form-select-sm" name="action" data-toggle-target>
      <option value="payment" <?= $defAct === 'payment' ? 'selected' : '' ?>>Mieter/WEG-Zahlung</option><option value="expense" <?= $defAct === 'expense' ? 'selected' : '' ?>>Ausgabe</option><option value="ignore" <?= $defAct === 'ignore' ? 'selected' : '' ?>>ignorieren (privat)</option></select></div>
    <div class="col-12 col-xl-5" data-for="payment"><select class="form-select form-select-sm" name="lease_id"><?= opts($leases, $sug['lease_id'] ?? null) ?></select></div>
    <div class="col-12 col-xl-5" data-for="expense"><select class="form-select form-select-sm" name="cost_type_id"><?= opts($cts, $sug['cost_type_id'] ?? null) ?></select>
      <select class="form-select form-select-sm mt-1" name="building_id"><option value="">alle Häuser</option><?php foreach ($houses as $k => $v): ?><option value="<?= $k ?>"><?= h($v) ?></option><?php endforeach; ?></select></div>
    <div class="col-12 col-xl-4 d-flex gap-1"><button class="btn btn-sm btn-primary">buchen</button>
      <div class="form-check form-check-inline small ms-1 align-self-center" title="Regel für künftige Umsätze anlegen"><input class="form-check-input" type="checkbox" name="rule" value="1" id="r<?= $t['id'] ?>"><label class="form-check-label" for="r<?= $t['id'] ?>">Regel</label></div>
      <input class="form-control form-control-sm" name="pattern" value="<?= h(mb_substr($t['counterparty'], 0, 30)) ?>" title="Suchtext für die Regel"></div>
    <?php if ($sug): ?><div class="col-12 small text-success"><i class="bi bi-lightbulb"></i> Vorschlag (<?= h($sug['source']) ?>)</div><?php endif; ?>
   </form>
  <?php endif; ?>
  </td>
 </tr>
<?php endforeach; ?>
<?php if (!$txs): ?><tr><td colspan="4" class="text-center text-body-secondary py-4">keine Umsätze</td></tr><?php endif; ?>
</tbody></table></div>
<?php page_foot();
