<?php
// Reporting: depreciation (AfA) and book values of the acquisition / production costs, total and per owner
$year = (int)($_GET['year'] ?? date('Y'));
$invs = rows("SELECT i.*, b.name house FROM investment i LEFT JOIN building b ON b.id=i.building_id ORDER BY i.acquired_date, i.id");
$units = report_units();
$alloc = investment_alloc($invs, $units);

$lines = [];
$T = ['amount' => 0, 'before' => 0, 'afa' => 0, 'book' => 0];
foreach ($invs as $i) {
    if ((int)substr($i['acquired_date'], 0, 4) > $year) continue;
    $before = afa_until($i, $year - 1);
    $afa = afa_year($i, $year);
    $book = (float)$i['amount'] - $before - $afa;
    $lines[] = ['i' => $i, 'before' => $before, 'afa' => $afa, 'book' => $book];
    foreach (['amount' => (float)$i['amount'], 'before' => $before, 'afa' => $afa, 'book' => $book] as $k => $v) $T[$k] += $v;
}

// per owner and house: share of each investment by the allocation to the flats
$byOwner = [];
foreach ($lines as $l) {
    $id = (int)$l['i']['id'];
    $amt = (float)$l['i']['amount'];
    if ($amt == 0.0) continue;
    foreach ($alloc as $uid => $parts) {
        if (!isset($parts[$id])) continue;
        $f = $parts[$id] / $amt;
        $u = $units[$uid];
        $key = ($u['owner'] ?: '(ohne WEG-Eigentümer)') . "\t" . ($u['house'] ?: '–');
        $byOwner[$key] ??= ['amount' => 0, 'afa' => 0, 'book' => 0];
        $byOwner[$key]['amount'] += $parts[$id];
        $byOwner[$key]['afa'] += $l['afa'] * $f;
        $byOwner[$key]['book'] += $l['book'] * $f;
    }
}
ksort($byOwner);

// per flat: costs split by kind (land / building / ...), AfA of the year and book value
$invById = array_column($invs, null, 'id');
$perUnit = [];
foreach ($alloc as $uid => $parts) {
    $r = ['kinds' => array_fill_keys(array_keys(INVEST_KIND), 0.0), 'sum' => 0.0, 'afa' => 0.0, 'book' => 0.0];
    foreach ($parts as $iid => $v) {
        $i = $invById[$iid];
        if ((int)substr($i['acquired_date'], 0, 4) > $year) continue;
        $f = $v / (float)$i['amount'];
        $r['kinds'][$i['kind']] += $v;
        $r['sum'] += $v;
        $r['afa'] += afa_year($i, $year) * $f;
        $r['book'] += ((float)$i['amount'] - afa_until($i, $year)) * $f;
    }
    $perUnit[$uid] = $r;
}

if (isset($_GET['csv'])) {
    $out = [];
    foreach ($lines as $l) $out[] = [$l['i']['acquired_date'], INVEST_KIND[$l['i']['kind']], $l['i']['title'], (float)$l['i']['amount'], (float)$l['i']['afa_rate'], $l['before'], $l['afa'], $l['book'], $l['i']['source']];
    csv_out("afa_$year.csv", ['Datum', 'Art', 'Bezeichnung', 'AHK', 'AfA %', 'AfA bis Vorjahr', "AfA $year", "Restbuchwert 31.12.$year", 'Quelle'], $out);
}

page_head("AfA $year"); ?>
<div class="d-flex align-items-center gap-2 mb-3"><h1 class="h3 mb-0 me-auto"><i class="bi bi-building-down me-2"></i>AfA / Anlagevermögen <?= $year ?></h1>
 <form class="d-flex gap-1 d-print-none"><input type="hidden" name="p" value="afa"><input class="form-control form-control-sm" style="width:6rem" type="number" name="year" value="<?= $year ?>"><button class="btn btn-sm btn-outline-secondary">Jahr</button></form>
 <a class="btn btn-sm btn-outline-secondary d-print-none" href="<?= url('afa', ['year' => $year, 'csv' => 1]) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
 <button class="btn btn-sm btn-outline-secondary d-print-none" onclick="print()"><i class="bi bi-printer"></i></button></div>
<div class="row g-3 mb-3">
 <div class="col-md-4"><div class="card card-body"><div class="small text-body-secondary">Anschaffungs-/Herstellungskosten</div><div class="fs-4 fw-semibold"><?= money($T['amount']) ?></div></div></div>
 <div class="col-md-4"><div class="card card-body"><div class="small text-body-secondary">AfA <?= $year ?></div><div class="fs-4 fw-semibold text-danger"><?= money($T['afa']) ?></div></div></div>
 <div class="col-md-4"><div class="card card-body"><div class="small text-body-secondary">Restbuchwert 31.12.<?= $year ?></div><div class="fs-4 fw-semibold"><?= money($T['book']) ?></div></div></div>
</div>
<div class="card mb-3"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
 <thead><tr><th>Datum</th><th>Art</th><th>Bezeichnung</th><th>Haus / Einheiten</th><th class="text-end">AHK</th><th class="text-end">AfA %</th><th class="text-end">AfA bis <?= $year - 1 ?></th><th class="text-end">AfA <?= $year ?></th><th class="text-end">Restbuchwert</th><th>Quelle</th></tr></thead><tbody>
 <?php foreach ($lines as $l): $i = $l['i']; ?>
  <tr><td><?= dmy($i['acquired_date']) ?></td><td><?= h(INVEST_KIND[$i['kind']]) ?></td><td><a href="<?= url('edit', ['e' => 'investment', 'id' => $i['id']]) ?>"><?= h($i['title']) ?></a></td>
   <td class="small"><?= h($i['unit_ids'] ? 'Whg-ID ' . $i['unit_ids'] : ($i['house'] ?: 'Liegenschaft')) ?></td><td class="text-end"><?= money($i['amount']) ?></td><td class="text-end"><?= num($i['afa_rate']) ?></td>
   <td class="text-end"><?= money($l['before'], true) ?></td><td class="text-end"><?= money($l['afa'], true) ?></td><td class="text-end"><?= money($l['book']) ?></td><td class="small"><?= h($i['source']) ?></td></tr>
 <?php endforeach; if (!$lines): ?><tr><td colspan="10" class="text-body-secondary">Noch keine Investitionen erfasst – <a href="<?= url('edit', ['e' => 'investment']) ?>">neu anlegen</a>.</td></tr><?php endif; ?>
 </tbody><tfoot><tr class="fw-bold"><td colspan="4">Summe</td><td class="text-end"><?= money($T['amount']) ?></td><td></td><td class="text-end"><?= money($T['before']) ?></td><td class="text-end"><?= money($T['afa']) ?></td><td class="text-end"><?= money($T['book']) ?></td><td></td></tr></tfoot>
</table></div></div>
<div class="card mb-3"><div class="card-header">Je Wohnung: Grundstücks- und Gebäudeanteil (nach Wohnfläche der zugeordneten Wohnungen)</div><div class="table-responsive"><table class="table table-sm table-hover mb-0">
 <thead><tr><th>Whg</th><th>Haus</th><th>Lage</th><th class="text-end">m²</th><?php foreach (INVEST_KIND as $l): ?><th class="text-end"><?= h($l) ?></th><?php endforeach; ?><th class="text-end">Summe</th><th class="text-end">€/m²</th><th class="text-end">AfA <?= $year ?></th><th class="text-end">Restbuchwert</th></tr></thead><tbody>
 <?php $S = ['kinds' => array_fill_keys(array_keys(INVEST_KIND), 0.0), 'sum' => 0, 'afa' => 0, 'book' => 0, 'area' => 0];
 foreach ($units as $uid => $u): $r = $perUnit[$uid] ?? null; if (!$r) continue;
   foreach (INVEST_KIND as $k => $_) $S['kinds'][$k] += $r['kinds'][$k]; foreach (['sum', 'afa', 'book'] as $k) $S[$k] += $r[$k]; $S['area'] += (float)$u['living_area']; ?>
  <tr><td><a href="<?= url('edit', ['e' => 'unit', 'id' => $uid]) ?>"><?= h($u['no']) ?></a></td><td><?= h($u['house']) ?></td><td class="small"><?= h($u['floor']) ?></td><td class="text-end"><?= num($u['living_area']) ?></td>
   <?php foreach (INVEST_KIND as $k => $_): ?><td class="text-end"><?= money($r['kinds'][$k], true) ?></td><?php endforeach; ?>
   <td class="text-end fw-semibold"><?= money($r['sum']) ?></td><td class="text-end"><?= (float)$u['living_area'] > 0 ? num($r['sum'] / (float)$u['living_area']) : '' ?></td><td class="text-end"><?= money($r['afa']) ?></td><td class="text-end"><?= money($r['book']) ?></td></tr>
 <?php endforeach; ?></tbody>
 <tfoot><tr class="fw-bold"><td colspan="3">Summe</td><td class="text-end"><?= num($S['area']) ?></td><?php foreach (INVEST_KIND as $k => $_): ?><td class="text-end"><?= money($S['kinds'][$k], true) ?></td><?php endforeach; ?>
  <td class="text-end"><?= money($S['sum']) ?></td><td></td><td class="text-end"><?= money($S['afa']) ?></td><td class="text-end"><?= money($S['book']) ?></td></tr></tfoot></table></div></div>
<div class="card"><div class="card-header">Aufteilung nach Eigentümer und Haus (nach Wohnfläche der zugeordneten Wohnungen)</div><table class="table table-sm mb-0">
 <thead><tr><th>Eigentümer</th><th>Haus</th><th class="text-end">AHK-Anteil</th><th class="text-end">AfA <?= $year ?></th><th class="text-end">Restbuchwert</th></tr></thead><tbody>
 <?php $last = null; foreach ($byOwner as $k => $v): [$o, $hs] = explode("\t", $k); ?>
  <tr><td><?= $o === $last ? '' : h($o) ?></td><td><?= h($hs) ?></td><td class="text-end"><?= money($v['amount']) ?></td><td class="text-end"><?= money($v['afa']) ?></td><td class="text-end"><?= money($v['book']) ?></td></tr>
 <?php $last = $o; endforeach; ?></tbody></table></div>
<p class="small text-body-secondary mt-2">Lineare AfA nach § 7 Abs. 1 und 4 EStG, im Anschaffungsjahr monatsgenau (pro rata temporis). Grundstücke (AfA 0 %) werden nicht abgeschrieben. Die Aufteilung nach Eigentümer folgt der Zuordnung der Wohnungen zu den WEG-Einheiten; sie ist eine Planungshilfe, keine Steuerberatung.</p>
<?php page_foot();
