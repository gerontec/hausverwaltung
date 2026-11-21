<?php
// generate monthly charges
if (is_post()) {
    $from = $_POST['from'] ?? date('Y-m'); $to = $_POST['to'] ?? $from;
    if (!preg_match('/^\d{4}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}$/', $to) || $from > $to) { flash('Zeitraum ungültig.', 'danger'); redirect(url('soll')); }
    $tot = 0; $skip = 0;
    for ($m = $from; $m <= $to; $m = date('Y-m', strtotime("$m-01 +1 month"))) { [$n, $s] = generate_charges($m, !empty($_POST['weg'])); $tot += $n; $skip = max($skip, $s); }
    flash("$tot Sollbuchungen erzeugt ($from bis $to)." . ($skip ? " $skip Verträge ohne Mietstand übersprungen." : ''));
    redirect(url('soll'));
}
if (isset($_GET['undo']) && preg_match('/^\d{4}-\d{2}$/', $_GET['undo'])) {
    // shown as confirm form below
}
if (is_post() === false && isset($_GET['delete']) && preg_match('/^\d{4}-\d{2}$/', $_GET['delete']) && ($_GET['confirm'] ?? '') === csrf_token()) {
    $n = q("DELETE FROM posting WHERE period=? AND auto_key IS NOT NULL", [$_GET['delete']])->rowCount();
    flash("$n automatische Sollbuchungen für {$_GET['delete']} gelöscht.", 'warning');
    redirect(url('soll'));
}
$months = rows("SELECT period, COUNT(*) n, SUM(amount) s, SUM(lease_id IS NOT NULL) nl, SUM(weg_unit_id IS NOT NULL) nw FROM posting WHERE auto_key IS NOT NULL GROUP BY period ORDER BY period DESC LIMIT 36");
$last = val("SELECT MAX(period) FROM posting WHERE auto_key IS NOT NULL") ?? '2024-12';
$next = date('Y-m', strtotime("$last-01 +1 month"));
$active = rows("SELECT l.id, u.no, " . str_replace(['company', 'last_name', 'first_name'], ['p.company', 'p.last_name', 'p.first_name'], PERSON_LABEL_SQL) . " n,
   (SELECT COUNT(*) FROM lease_rent r WHERE r.lease_id=l.id) nr FROM lease l JOIN unit u ON u.id=l.unit_id JOIN person p ON p.id=l.tenant_id
   WHERE l.end_date IS NULL OR l.end_date>=CURDATE() HAVING nr=0");
page_head('Sollstellung'); ?>
<h1 class="h3 mb-3"><i class="bi bi-calendar-plus me-2"></i>Sollstellung</h1>
<div class="row g-3">
<div class="col-lg-5">
 <form method="post" class="card"><div class="card-body"><?= csrf_field() ?>
  <p>Erzeugt für jeden laufenden Mietvertrag die Monatsforderungen (Kaltmiete, Vorauszahlungen, Stellplatz) aus dem gültigen Mietstand, bei Ein- oder Auszug im Monat anteilig. Bereits vorhandene Monate werden übersprungen.</p>
  <div class="row g-2">
   <div class="col"><label class="form-label small">von Monat</label><input class="form-control" type="month" name="from" value="<?= h($next) ?>"></div>
   <div class="col"><label class="form-label small">bis Monat</label><input class="form-control" type="month" name="to" value="<?= h(max($next, date('Y-m'))) ?>"></div>
  </div>
  <div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="weg" value="1" id="weg" checked><label class="form-check-label" for="weg">auch Hausgeld der WEG-Einheiten (mit Eigentümer und Hausgeld)</label></div>
 </div><div class="card-footer"><button class="btn btn-primary">Sollstellung buchen</button></div></form>
 <?php if ($active): ?><div class="alert alert-warning mt-3"><b>Ohne Mietstand</b> (werden übersprungen):
  <?php foreach ($active as $a): ?><br><a href="<?= url('edit', ['e' => 'lease', 'id' => $a['id']]) ?>">Whg <?= h($a['no']) ?> <?= h($a['n']) ?></a><?php endforeach; ?></div><?php endif; ?>
 <div class="alert alert-info mt-3 small">Mietänderungen bitte zuerst im Mietvertrag unter „Mietentwicklung“ eintragen, dann die Monate buchen.</div>
</div>
<div class="col-lg-7"><div class="card"><div class="card-header">Gebuchte Monate</div>
 <table class="table table-sm mb-0"><thead><tr><th>Monat</th><th class="text-end">Buchungen</th><th class="text-end">Mieter</th><th class="text-end">WEG</th><th class="text-end">Summe</th><th></th></tr></thead><tbody>
 <?php foreach ($months as $m): ?><tr><td><?= h($m['period']) ?></td><td class="text-end"><?= $m['n'] ?></td><td class="text-end"><?= $m['nl'] ?></td><td class="text-end"><?= $m['nw'] ?></td><td class="text-end"><?= money($m['s']) ?></td>
  <td class="text-end"><a class="btn btn-sm btn-link text-danger py-0" onclick="return confirm('Alle automatischen Sollbuchungen <?= h($m['period']) ?> löschen?')" href="<?= url('soll', ['delete' => $m['period'], 'confirm' => csrf_token()]) ?>"><i class="bi bi-trash"></i></a></td></tr><?php endforeach; ?>
 <?php if (!$months): ?><tr><td colspan="6" class="text-body-secondary">noch keine automatischen Sollstellungen</td></tr><?php endif; ?>
 </tbody></table></div></div>
</div>
<?php page_foot();
