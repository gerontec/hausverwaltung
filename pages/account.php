<?php
// Mieterkonto / Hausgeldkonto with running balance
$lease = (int)($_GET['lease'] ?? 0); $weg = (int)($_GET['weg'] ?? 0);
$from = parse_date($_GET['from'] ?? '') ?? null; $to = parse_date($_GET['to'] ?? '') ?? date('Y-m-d');
if ($lease) {
    $hd = row("SELECT l.*, u.no, u.kind, b.name house, u.floor, u.position, p.* , l.id lid FROM lease l JOIN unit u ON u.id=l.unit_id LEFT JOIN building b ON b.id=u.building_id JOIN person p ON p.id=l.tenant_id WHERE l.id=?", [$lease]);
    $title = 'Mieterkonto ' . person_label($hd) . ' · ' . ($hd['kind'] === 'apartment' ? 'Whg ' : '') . $hd['no'];
    $col = 'lease_id'; $key = $lease;
} else {
    $hd = row("SELECT w.*, p.company, p.last_name, p.first_name FROM weg_unit w LEFT JOIN person p ON p.id=w.owner_id WHERE w.id=?", [$weg]);
    $title = 'Hausgeldkonto WEG Nr. ' . $hd['no'] . ' ' . person_label($hd);
    $col = 'weg_unit_id'; $key = $weg;
}
if (is_post() && ($_POST['_action'] ?? '') === 'add') {
    $amt = parse_num($_POST['amount'] ?? '');
    $d = parse_date($_POST['date'] ?? '');
    if ($amt !== null && $d) {
        q("INSERT INTO posting ($col, posting_date, kind, category, amount, text, source) VALUES (?,?,?,?,?,?, 'manuell')", [$key, $d, $_POST['kind'], $_POST['category'], $amt, trim($_POST['text'] ?? '')]);
        flash('Buchung gespeichert.');
    } else flash('Datum oder Betrag ungültig.', 'danger');
    redirect($_SERVER['REQUEST_URI']);
}
$open = $from ? (float)val("SELECT COALESCE(SUM(CASE WHEN kind='charge' THEN amount ELSE -amount END),0) FROM posting WHERE $col=? AND posting_date<?", [$key, $from]) : 0.0;
$ps = rows("SELECT * FROM posting WHERE $col=? AND posting_date<=?" . ($from ? " AND posting_date>=?" : '') . " ORDER BY posting_date, kind='payment', id", $from ? [$key, $to, $from] : [$key, $to]);
page_head($title); ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
 <h1 class="h4 mb-0 me-auto"><?= h($title) ?></h1>
 <form class="d-flex gap-2 d-print-none"><input type="hidden" name="p" value="account"><?php if ($lease): ?><input type="hidden" name="lease" value="<?= $lease ?>"><?php else: ?><input type="hidden" name="weg" value="<?= $weg ?>"><?php endif; ?>
  <input class="form-control form-control-sm" type="date" name="from" value="<?= h($from) ?>"><input class="form-control form-control-sm" type="date" name="to" value="<?= h($to) ?>"><button class="btn btn-sm btn-outline-secondary">Zeitraum</button></form>
 <button class="btn btn-sm btn-outline-secondary d-print-none" onclick="print()"><i class="bi bi-printer"></i></button>
 <a class="btn btn-sm btn-outline-secondary d-print-none" href="<?= $lease ? url('edit', ['e' => 'lease', 'id' => $lease]) : url('edit', ['e' => 'weg_unit', 'id' => $weg]) ?>">Vertrag</a>
</div>
<?php if ($lease): ?><p class="text-body-secondary small mb-2"><?= h(trim($hd['house'] . ' ' . $hd['floor'] . ' ' . $hd['position'])) ?> · Beginn <?= $hd['start_date'] ? dmy($hd['start_date']) : 'unbekannt' ?><?= $hd['end_date'] ? ' · Ende ' . dmy($hd['end_date']) : '' ?><?= $hd['ext_account'] ? ' · Konto Altverwaltung ' . h($hd['ext_account']) : '' ?></p><?php endif; ?>
<div class="table-responsive"><table class="table table-sm table-hover">
<thead class="table-light"><tr><th>Datum</th><th>Kategorie</th><th>Text</th><th class="text-end">Soll</th><th class="text-end">Haben</th><th class="text-end">Saldo</th><th class="d-print-none"></th></tr></thead>
<tbody>
<?php $bal = $open; if ($from): ?><tr class="table-light"><td><?= dmy($from) ?></td><td colspan="4">Saldovortrag</td><td class="text-end"><?= money($bal) ?></td><td></td></tr><?php endif;
$ts = $th = 0;
foreach ($ps as $x):
    $s = $x['kind'] === 'charge' ? (float)$x['amount'] : 0; $hb = $x['kind'] === 'payment' ? (float)$x['amount'] : 0;
    if ($s < 0) { $hb = -$s; $s = 0; } if ($hb < 0) { $s = -$hb; $hb = 0; }
    $bal += $s - $hb; $ts += $s; $th += $hb; ?>
 <tr><td class="text-nowrap"><?= dmy($x['posting_date']) ?></td><td><?= h(CATEGORY_LABEL[$x['category']] ?? $x['category']) ?></td><td class="small"><?= h($x['text']) ?></td>
  <td class="text-end text-nowrap"><?= money($s, true) ?></td><td class="text-end text-nowrap"><?= money($hb, true) ?></td><td class="text-end text-nowrap <?= $bal > 0.005 ? 'text-danger' : '' ?>"><?= money($bal) ?></td>
  <td class="d-print-none"><a class="small" href="<?= url('edit', ['e' => 'posting', 'id' => $x['id'], 'back' => $_SERVER['REQUEST_URI']]) ?>"><i class="bi bi-pencil"></i></a></td></tr>
<?php endforeach; ?>
</tbody>
<tfoot class="fw-semibold"><tr><td colspan="3">Summe / Saldo am <?= dmy($to) ?> (<?= $bal > 0.005 ? 'Rückstand' : ($bal < -0.005 ? 'Guthaben' : 'ausgeglichen') ?>)</td><td class="text-end"><?= money($ts) ?></td><td class="text-end"><?= money($th) ?></td><td class="text-end <?= $bal > 0.005 ? 'text-danger' : 'text-success' ?>"><?= money($bal) ?></td><td></td></tr></tfoot>
</table></div>
<form method="post" class="card card-body d-print-none"><?= csrf_field() ?><input type="hidden" name="_action" value="add">
 <div class="row g-2 align-items-end">
  <div class="col-md-2"><label class="form-label small">Datum</label><input class="form-control form-control-sm" type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
  <div class="col-md-2"><label class="form-label small">Art</label><select class="form-select form-select-sm" name="kind"><?php foreach (POSTING_KIND as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small">Kategorie</label><select class="form-select form-select-sm" name="category"><?php foreach (CATEGORY_LABEL as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small">Betrag</label><input class="form-control form-control-sm text-end" name="amount" inputmode="decimal"></div>
  <div class="col-md-3"><label class="form-label small">Text</label><input class="form-control form-control-sm" name="text"></div>
  <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Buchen</button></div>
 </div></form>
<?php page_foot();
