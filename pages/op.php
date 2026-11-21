<?php
// Offene Posten: balances per lease and WEG unit
$on = parse_date($_GET['date'] ?? '') ?? date('Y-m-d');
$all = !empty($_GET['all']);
$ls = rows("SELECT l.id, u.no, u.kind, l.end_date, " . str_replace(['company', 'last_name', 'first_name'], ['p.company', 'p.last_name', 'p.first_name'], PERSON_LABEL_SQL) . " n,
      SUM(CASE WHEN po.kind='charge' THEN po.amount ELSE -po.amount END) bal,
      MAX(CASE WHEN po.kind='payment' THEN po.posting_date END) last_pay,
      (SELECT r.base_rent+r.operating_advance+r.heating_advance+r.parking_rent FROM lease_rent r WHERE r.lease_id=l.id AND r.valid_from<=? ORDER BY r.valid_from DESC LIMIT 1) monthly
    FROM lease l JOIN unit u ON u.id=l.unit_id JOIN person p ON p.id=l.tenant_id JOIN posting po ON po.lease_id=l.id AND po.posting_date<=?
    GROUP BY l.id " . ($all ? '' : 'HAVING ABS(bal)>0.005') . " ORDER BY bal DESC", [$on, $on]);
$ws = rows("SELECT w.id, w.no, SUM(CASE WHEN po.kind='charge' THEN po.amount ELSE -po.amount END) bal FROM weg_unit w JOIN posting po ON po.weg_unit_id=w.id AND po.posting_date<=? GROUP BY w.id HAVING ABS(bal)>0.005", [$on]);
page_head('Offene Posten'); ?>
<div class="d-flex align-items-center gap-2 mb-3"><h1 class="h3 mb-0 me-auto"><i class="bi bi-exclamation-circle me-2"></i>Offene Posten</h1>
 <form class="d-flex gap-2 d-print-none"><input type="hidden" name="p" value="op"><input class="form-control form-control-sm" type="date" name="date" value="<?= h($on) ?>">
  <div class="form-check align-self-center"><input class="form-check-input" type="checkbox" name="all" value="1" id="all" <?= $all ? 'checked' : '' ?>><label for="all" class="form-check-label">auch ausgeglichene</label></div><button class="btn btn-sm btn-outline-secondary">anzeigen</button></form>
 <button class="btn btn-sm btn-outline-secondary d-print-none" onclick="print()"><i class="bi bi-printer"></i></button></div>
<table class="table table-sm table-hover"><thead class="table-light"><tr><th>Einheit</th><th>Mieter</th><th>Status</th><th class="text-end">Monatssoll</th><th class="text-end">Monate</th><th>letzte Zahlung</th><th class="text-end">Saldo</th></tr></thead><tbody>
<?php $s = 0; foreach ($ls as $l): $s += $l['bal']; ?>
 <tr><td><?= ($l['kind'] === 'apartment' ? 'Whg ' : '') . h($l['no']) ?></td><td><a href="<?= url('account', ['lease' => $l['id']]) ?>"><?= h($l['n']) ?></a></td>
  <td><?= $l['end_date'] && $l['end_date'] < $on ? badge('beendet ' . dmy($l['end_date'])) : badge('laufend', 'success') ?></td>
  <td class="text-end"><?= money($l['monthly'], true) ?></td><td class="text-end"><?= $l['monthly'] > 0 && $l['bal'] > 0 ? num($l['bal'] / $l['monthly'], 1) : '' ?></td>
  <td><?= dmy($l['last_pay']) ?></td><?= amount_cell((float)$l['bal']) ?></tr>
<?php endforeach; ?></tbody>
<tfoot><tr class="fw-semibold"><td colspan="6">Summe (positiv = Forderung, negativ = Guthaben der Mieter)</td><?= amount_cell($s) ?></tr></tfoot></table>
<p class="small text-body-secondary">Ab 2 Monatsmieten Rückstand ist eine fristlose Kündigung nach § 543 Abs. 2 Nr. 3, § 569 Abs. 3 BGB möglich. Vorher eine Mahnung schicken und prüfen, ob Zahlungen noch nicht zugeordnet sind (Bank).</p>
<?php if ($ws): ?><h2 class="h5 mt-4">Hausgeld</h2><table class="table table-sm"><?php foreach ($ws as $w): ?><tr><td><a href="<?= url('account', ['weg' => $w['id']]) ?>">WEG Nr. <?= h($w['no']) ?></a></td><?= amount_cell((float)$w['bal']) ?></tr><?php endforeach; ?></table><?php endif;
page_foot();
