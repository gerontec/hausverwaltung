<?php
// WEG: Wirtschaftsplan (budget) and Jahresabrechnung (actual) per WEG unit, sub-communities by house
$year = (int)($_GET['year'] ?? date('Y'));
$mode = ($_GET['mode'] ?? 'plan') === 'actual' ? 'actual' : 'plan';
if (is_post() && ($_POST['_action'] ?? '') === 'apply') {
    $R = compute_weg($year, true);
    $n = 0;
    foreach ($R['wegs'] as $wid => $w) {
        $r = $R['res'][$wid] ?? [];
        $hg = round((($r['costs'] ?? 0) + ($r['reserve'] ?? 0)) / 12, 2);
        q("UPDATE weg_unit SET hausgeld_monthly=?, reserve_monthly=? WHERE id=?", [$hg, round(($r['reserve'] ?? 0) / 12, 2), $wid]); $n++;
    }
    flash("Hausgeld aus Wirtschaftsplan $year für $n Einheiten übernommen. Gilt erst nach Beschluss der Eigentümerversammlung (§ 28 Abs. 1 WEG).");
    redirect(url('weg', ['year' => $year]));
}
if (is_post() && ($_POST['_action'] ?? '') === 'copy') {
    $src = (int)$_POST['src'];
    $cnt = 0;
    if (!val("SELECT COUNT(*) FROM weg_budget WHERE year=?", [$year])) {
        if (val("SELECT COUNT(*) FROM weg_budget WHERE year=?", [$src])) {
            $cnt = q("INSERT INTO weg_budget (property_id, year, building_id, cost_type_id, amount, note) SELECT property_id, ?, building_id, cost_type_id, amount, CONCAT('aus Plan ', ?) FROM weg_budget WHERE year=?", [$year, $src, $src])->rowCount();
        } else {
            // from actual costs of the source year
            $cnt = q("INSERT INTO weg_budget (property_id, year, building_id, cost_type_id, amount, note)
                      SELECT 1, ?, e.building_id, e.cost_type_id, ROUND(SUM(e.amount),-1), CONCAT('Ist ', ?) FROM expense e JOIN cost_type ct ON ct.id=e.cost_type_id
                      WHERE YEAR(e.invoice_date)=? AND ct.weg_key<>'none' AND e.from_reserve=0 GROUP BY e.building_id, e.cost_type_id HAVING SUM(e.amount)>0", [$year, $src, $src])->rowCount();
        }
    }
    flash($cnt ? "$cnt Planpositionen für $year angelegt – bitte prüfen und Rücklage ergänzen." : "Für $year gibt es schon Planpositionen.", $cnt ? 'success' : 'warning');
    redirect(url('weg', ['year' => $year]));
}
$R = compute_weg($year, $mode === 'plan');
$KEYS = WEG_KEY;
page_head('WEG ' . ($mode === 'plan' ? 'Wirtschaftsplan' : 'Jahresabrechnung') . " $year"); ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
 <h1 class="h3 mb-0 me-auto"><i class="bi bi-calculator me-2"></i>WEG <?= $mode === 'plan' ? 'Wirtschaftsplan' : 'Jahresabrechnung' ?> <?= $year ?></h1>
 <div class="btn-group btn-group-sm d-print-none"><a class="btn btn-outline-primary <?= $mode === 'plan' ? 'active' : '' ?>" href="<?= url('weg', ['year' => $year, 'mode' => 'plan']) ?>">Wirtschaftsplan</a><a class="btn btn-outline-primary <?= $mode === 'actual' ? 'active' : '' ?>" href="<?= url('weg', ['year' => $year, 'mode' => 'actual']) ?>">Jahresabrechnung</a></div>
 <form class="d-flex gap-1 d-print-none"><input type="hidden" name="p" value="weg"><input type="hidden" name="mode" value="<?= $mode ?>"><input class="form-control form-control-sm" style="width:6rem" type="number" name="year" value="<?= $year ?>"><button class="btn btn-sm btn-outline-secondary">Jahr</button></form>
 <button class="btn btn-sm btn-outline-secondary d-print-none" onclick="print()"><i class="bi bi-printer"></i></button>
</div>
<?php $owners = (int)val("SELECT COUNT(*) FROM weg_unit WHERE owner_id IS NOT NULL"); if (!$owners): ?>
<div class="alert alert-warning small">Noch keine Eigentümer eingetragen. Die WEG entsteht erst mit Anlegung der Wohnungsgrundbücher. Bis dahin ist diese Seite eine Vorschau auf Grundlage der Teilungserklärung (Entwurf): MEA, Untergemeinschaften Haus 6 = Nr. 9, Haus 6a = Nr. 8, Haus 6b = Nr. 1–7 (TE § 5 Nr. 1).</div>
<?php endif; ?>

<div class="row g-3">
<div class="col-xl-5"><div class="card"><div class="card-header d-flex"><span class="me-auto">Gesamtkosten <?= $mode === 'plan' ? '(Plan)' : '(Ist)' ?></span>
 <?php if ($mode === 'plan'): ?><a class="small" href="<?= url('list', ['e' => 'weg_budget', 'year' => $year]) ?>">Planpositionen bearbeiten</a><?php endif; ?></div>
 <table class="table table-sm mb-0"><thead><tr><th>Kostenart</th><th>Bereich</th><th>Schlüssel</th><th class="text-end">Betrag</th></tr></thead><tbody>
 <?php $T = 0; foreach ($R['costs'] as $c): $T += $c['amount']; ?><tr><td><?= h($c['code'] . ' ' . $c['name']) ?></td><td><?= h($c['scope']) ?></td><td class="small"><?= h($KEYS[$c['key']] ?? $c['key']) ?></td><td class="text-end"><?= money($c['amount']) ?></td></tr><?php endforeach; ?>
 <?php if (!$R['costs']): ?><tr><td colspan="4" class="text-body-secondary">keine <?= $mode === 'plan' ? 'Planpositionen' : 'Ausgaben' ?> für <?= $year ?></td></tr><?php endif; ?>
 </tbody><tfoot><tr class="fw-semibold"><td colspan="3">Summe</td><td class="text-end"><?= money($T) ?></td></tr></tfoot></table>
 <?php if ($mode === 'plan'): ?><div class="card-footer d-flex gap-2 d-print-none">
  <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="_action" value="copy"><input class="form-control form-control-sm" style="width:6rem" type="number" name="src" value="<?= $year - 1 ?>"><button class="btn btn-sm btn-outline-secondary" title="Plan aus Vorjahresplan oder Ist-Kosten anlegen">übernehmen</button></form>
  <form method="post" class="ms-auto"><?= csrf_field() ?><input type="hidden" name="_action" value="apply"><button class="btn btn-sm btn-primary" onclick="return confirm('Monatliches Hausgeld aller Einheiten aus diesem Plan setzen?')">Hausgeld übernehmen</button></form></div><?php endif; ?>
 </div></div>

<div class="col-xl-7"><div class="card"><div class="card-header">Verteilung je Einheit</div>
 <div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Nr.</th><th>Einheit</th><th>Eigentümer</th><th class="text-end">MEA</th><th class="text-end">Kosten</th><th class="text-end">Rücklage</th>
  <?php if ($mode === 'plan'): ?><th class="text-end">Hausgeld/Monat</th><th class="text-end">aktuell</th><?php else: ?><th class="text-end">Soll-Vorschüsse</th><th class="text-end">Abrechnungsspitze</th><?php endif; ?></tr></thead><tbody>
 <?php $S = ['c' => 0, 'r' => 0, 'm' => 0, 's' => 0, 'x' => 0]; foreach ($R['wegs'] as $wid => $w): $r = $R['res'][$wid] ?? []; $c = $r['costs'] ?? 0; $rs = $r['reserve'] ?? 0;
     $S['c'] += $c; $S['r'] += $rs; ?>
  <tr><td><a href="<?= url('edit', ['e' => 'weg_unit', 'id' => $wid]) ?>"><?= h($w['no']) ?></a></td><td class="small"><?= h($w['description']) ?></td><td class="small"><?= h($w['owner'] ?: '–') ?></td><td class="text-end"><?= num($w['mea'], 0) ?></td>
   <td class="text-end"><?= money($c) ?></td><td class="text-end"><?= money($rs, true) ?></td>
   <?php if ($mode === 'plan'): $m = ($c + $rs) / 12; $S['m'] += $m; ?><td class="text-end fw-semibold"><?= money($m) ?></td><td class="text-end text-body-secondary"><?= money($w['hausgeld_monthly'], true) ?></td>
   <?php else: $S['s'] += $r['soll']; $S['x'] += $r['result']; ?><td class="text-end"><?= money($r['soll']) ?></td><?= amount_cell($r['result']) ?><?php endif; ?></tr>
 <?php endforeach; ?></tbody>
 <tfoot class="fw-semibold"><tr><td colspan="4">Summe</td><td class="text-end"><?= money($S['c']) ?></td><td class="text-end"><?= money($S['r']) ?></td>
  <?php if ($mode === 'plan'): ?><td class="text-end"><?= money($S['m']) ?></td><td></td><?php else: ?><td class="text-end"><?= money($S['s']) ?></td><?= amount_cell($S['x']) ?><?php endif; ?></tr></tfoot></table></div></div>
 <p class="small text-body-secondary mt-2"><?= $mode === 'plan'
   ? 'Wirtschaftsplan nach § 28 Abs. 1 WEG: Vorschüsse je Einheit = Anteil an den Plankosten nach Verteilerschlüssel (Standard MEA, je Kostenart einstellbar) plus Anteil an der Erhaltungsrücklage. Positionen mit Haus werden nur innerhalb dieser Untergemeinschaft verteilt.'
   : 'Jahresabrechnung nach § 28 Abs. 2 WEG: Beschlossen wird nur die Abrechnungsspitze = Kostenanteil − Soll-Vorschüsse. Ausgaben „aus Rücklage“ belasten die Rücklage, nicht das Hausgeld. Der Vermögensbericht (§ 28 Abs. 4) kommt aus Banksaldo und Rücklagenstand.' ?></p>
</div>
</div>
<?php page_foot();
