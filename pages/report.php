<?php
// income / expenses per year (cash basis), per cost type and month
$year = (int)($_GET['year'] ?? date('Y'));
$inc = rows("SELECT MONTH(posting_date) m, SUM(amount) s FROM posting WHERE kind='payment' AND lease_id IS NOT NULL AND YEAR(posting_date)=? GROUP BY m", [$year]);
$soll = rows("SELECT category, SUM(amount) s FROM posting WHERE kind='charge' AND lease_id IS NOT NULL AND YEAR(posting_date)=? GROUP BY category", [$year]);
$exp = rows("SELECT ct.code, ct.name, ct.allocatable, SUM(e.amount) s, SUM(e.labour_cost) lab FROM expense e JOIN cost_type ct ON ct.id=e.cost_type_id WHERE YEAR(e.invoice_date)=? GROUP BY ct.id ORDER BY ct.sort", [$year]);
$incM = array_column($inc, 's', 'm');
$expM = array_column(rows("SELECT MONTH(invoice_date) m, SUM(amount) s FROM expense WHERE YEAR(invoice_date)=? GROUP BY m", [$year]), 's', 'm');
$I = array_sum($incM); $X = array_sum(array_column($exp, 's'));
$max = max(1, max(array_merge(array_values($incM), array_values($expM), [1])));
page_head("Einnahmen/Ausgaben $year"); ?>
<div class="d-flex align-items-center gap-2 mb-3"><h1 class="h3 mb-0 me-auto"><i class="bi bi-bar-chart me-2"></i>Einnahmen / Ausgaben <?= $year ?></h1>
 <form class="d-flex gap-1 d-print-none"><input type="hidden" name="p" value="report"><input class="form-control form-control-sm" style="width:6rem" type="number" name="year" value="<?= $year ?>"><button class="btn btn-sm btn-outline-secondary">Jahr</button></form>
 <button class="btn btn-sm btn-outline-secondary d-print-none" onclick="print()"><i class="bi bi-printer"></i></button></div>
<div class="row g-3 mb-3">
 <div class="col-md-4"><div class="card card-body"><div class="small text-body-secondary">Mieteingänge (Ist)</div><div class="fs-4 fw-semibold text-success"><?= money($I) ?></div></div></div>
 <div class="col-md-4"><div class="card card-body"><div class="small text-body-secondary">Ausgaben</div><div class="fs-4 fw-semibold text-danger"><?= money($X) ?></div></div></div>
 <div class="col-md-4"><div class="card card-body"><div class="small text-body-secondary">Überschuss</div><div class="fs-4 fw-semibold"><?= money($I - $X) ?></div></div></div>
</div>
<div class="card mb-3"><div class="card-header">Monatsverlauf <span class="ms-2 small"><span class="legend-dot bg-success"></span> Eingänge <span class="legend-dot bg-danger ms-2"></span> Ausgaben</span></div><div class="card-body">
 <div class="d-flex align-items-end gap-2" style="height:180px">
 <?php for ($m = 1; $m <= 12; $m++): $a = $incM[$m] ?? 0; $b = $expM[$m] ?? 0; ?>
  <div class="flex-fill d-flex flex-column align-items-center h-100 justify-content-end" title="<?= sprintf('%02d/%d: Eingänge %s, Ausgaben %s', $m, $year, money($a), money($b)) ?>">
   <div class="d-flex align-items-end gap-1 w-100 justify-content-center" style="height:150px">
    <div class="bg-success rounded-top" style="width:40%;height:<?= max(0, $a) / $max * 100 ?>%"></div><div class="bg-danger rounded-top" style="width:40%;height:<?= max(0, $b) / $max * 100 ?>%"></div></div>
   <div class="small text-body-secondary"><?= $m ?></div></div>
 <?php endfor; ?></div></div></div>
<div class="row g-3">
 <div class="col-lg-6"><div class="card"><div class="card-header">Ausgaben nach Kostenart</div><table class="table table-sm mb-0"><tbody>
  <?php foreach ($exp as $e): ?><tr><td><?= h($e['code'] . ' ' . $e['name']) ?></td><td><?= $e['allocatable'] ? badge('umlagefähig', 'info') : '' ?></td><td class="text-end"><a href="<?= url('list', ['e' => 'expense', 'year' => $year, 'cost_type_id' => val("SELECT id FROM cost_type WHERE code=?", [$e['code']])]) ?>"><?= money($e['s']) ?></a></td></tr><?php endforeach; ?>
 </tbody></table></div></div>
 <div class="col-lg-6"><div class="card"><div class="card-header">Sollstellungen Mieter</div><table class="table table-sm mb-0"><tbody>
  <?php foreach ($soll as $s): ?><tr><td><?= h(CATEGORY_LABEL[$s['category']] ?? $s['category']) ?></td><td class="text-end"><?= money($s['s']) ?></td></tr><?php endforeach; ?>
 </tbody></table></div>
 <p class="small text-body-secondary mt-2">Kassenprinzip nach Zahlungs- bzw. Rechnungsdatum (Anlage V: Zufluss/Abfluss § 11 EStG). Private Umsätze auf dem Konto werden in der Bank-Ansicht ignoriert und erscheinen hier nicht.</p></div>
</div>
<?php page_foot();
