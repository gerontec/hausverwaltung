<?php
// Betriebskostenabrechnung (tenants) incl. HeizkostenV options
require_once APP_ROOT . '/lib/bk.php';
require_once APP_ROOT . '/lib/mayan.php';
$year = (int)($_GET['year'] ?? ((int)date('Y') - 1));
$from = parse_date($_GET['from'] ?? '') ?? "$year-01-01";
$to = parse_date($_GET['to'] ?? '') ?? "$year-12-31";
$house = (int)($_GET['house'] ?? 0);
$one = $_GET['k'] ?? null;
$self = url('bk', ['from' => $from, 'to' => $to]);

if (is_post()) {
    $act = $_POST['_action'] ?? '';
    if ($act === 'settings') {
        $v = [$_POST['mode'] === 'own' ? 'own' : 'brunata', $_POST['advances'] === 'soll' ? 'soll' : 'ist', array_key_exists($_POST['fuel_kind'] ?? '', FUEL_KIND) ? $_POST['fuel_kind'] : 'gas_hs',
              parse_num($_POST['fuel_kwh'] ?? ''), array_key_exists($_POST['ww_method'] ?? '', WW_METHOD) ? $_POST['ww_method'] : 'meter', parse_num($_POST['ww_temp'] ?? '') ?? 60,
              parse_num($_POST['co2_kg'] ?? ''), parse_num($_POST['co2_cost'] ?? ''), trim($_POST['note'] ?? '') ?: null];
        q("INSERT INTO hk_setting (period_from, period_to, mode, advances, fuel_kind, fuel_kwh, ww_method, ww_temp, co2_kg, co2_cost, note) VALUES (?,?,?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE mode=VALUES(mode), advances=VALUES(advances), fuel_kind=VALUES(fuel_kind), fuel_kwh=VALUES(fuel_kwh), ww_method=VALUES(ww_method), ww_temp=VALUES(ww_temp),
           co2_kg=VALUES(co2_kg), co2_cost=VALUES(co2_cost), note=VALUES(note)", [$from, $to, ...$v]);
        flash('Einstellungen für ' . dmy($from) . '–' . dmy($to) . ' gespeichert.');
        redirect($self);
    }
    if ($act === 'brunata') {
        try {
            if (!empty($_FILES['pdf']['tmp_name'])) { $pdf = $_FILES['pdf']['tmp_name']; $src = 'Upload ' . $_FILES['pdf']['name']; }
            elseif ((int)($_POST['mayan_id'] ?? 0) > 0) { $pdf = tempnam(sys_get_temp_dir(), 'bru'); file_put_contents($pdf, mayan_call('/file', ['id' => (int)$_POST['mayan_id']], 300, true)); $src = 'Mayan #' . (int)$_POST['mayan_id']; }
            else throw new RuntimeException('Bitte PDF wählen oder Mayan-Nummer angeben.');
            $txt = (string)shell_exec('pdftotext -layout ' . escapeshellarg($pdf) . ' - 2>/dev/null');
            $p = brunata_parse($txt);
            if ($p['from'] !== $from || $p['to'] !== $to) flash('Hinweis: Die Brunata-Abrechnung gilt für ' . dmy($p['from']) . '–' . dmy($p['to']) . ' und wurde für diesen Zeitraum gespeichert.', 'warning');
            $n = brunata_store($p, $src);
            q("INSERT INTO hk_setting (period_from, period_to, mode) VALUES (?,?,'brunata') ON DUPLICATE KEY UPDATE mode='brunata'", [$p['from'], $p['to']]);
            $sum = array_sum(array_column($p['rows'], 'amount'));
            flash("$n Positionen aus der Brunata-Abrechnung übernommen (Summe " . money($sum) . ').');
            redirect(url('bk', ['from' => $p['from'], 'to' => $p['to']]));
        } catch (Throwable $e) { flash(h($e->getMessage()), 'danger'); redirect($self); }
    }
    if ($act === 'brunata_delete') { q("DELETE FROM hk_result WHERE period_from=? AND period_to=?", [$from, $to]); flash('Brunata-Ergebnisse für den Zeitraum gelöscht.', 'warning'); redirect($self); }
}

$R = compute_bk($from, $to);
$set = $R['set']; $HK = $R['hk'];
$KEY = ALLOC_KEY + ['direct' => 'direkt', 'brunata' => 'Messdienst (Brunata) je Nutzer'];
$prop = row("SELECT * FROM property WHERE id=1");
$lbl = fn($code) => $code === 'CO2' ? 'CO₂-Kosten: Anteil Vermieter (CO2KostAufG)' : (val("SELECT name FROM cost_type WHERE code=?", [$code]) ?? $code);

// ---------------------------------------------------------------- single statement ---
if ($one && isset($R['res'][$one])) {
    $r = $R['res'][$one];
    $u = $R['units'][$r['unit_id']];
    $l = row("SELECT l.*, p.salutation, p.first_name, p.last_name, p.company, p.street, p.zip, p.city FROM lease l JOIN person p ON p.id=l.tenant_id WHERE l.id=?", [$r['lease_id']]);
    $b = row("SELECT * FROM building WHERE id=?", [$u['building_id']]);
    page_head('Betriebskostenabrechnung ' . person_label($l)); ?>
<div class="d-print-none mb-3 d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="<?= $self ?>">&larr; Übersicht</a><button class="btn btn-sm btn-primary" onclick="print()"><i class="bi bi-printer"></i> Drucken / PDF</button></div>
<div class="statement mx-auto">
 <div class="small text-body-secondary mb-4">Absender: Hausverwaltung <?= h($prop['name']) ?>, <?= h($prop['zip'] . ' ' . $prop['city']) ?></div>
 <div class="mb-5"><?= h(trim(($l['salutation'] ?? '') . ' ' . ($l['first_name'] ?? '') . ' ' . $l['last_name'])) ?><?= $l['co_tenant'] ? '<br>' . h($l['co_tenant']) : '' ?><br><?= h($l['street'] ?: $b['street']) ?><br><?= h(($l['zip'] ?: $prop['zip']) . ' ' . ($l['city'] ?: $prop['city'])) ?></div>
 <div class="text-end mb-3"><?= h($prop['city']) ?>, <?= dmy(date('Y-m-d')) ?></div>
 <h1 class="h5">Betriebskostenabrechnung <?= dmy($from) ?> – <?= dmy($to) ?></h1>
 <p class="mb-1">Mietobjekt: <?= h($b['street']) ?>, Wohnung Nr. <?= h($u['no']) ?> (<?= h($u['floor'] . ' ' . $u['position']) ?>), <?= num($u['living_area']) ?> m² Wohnfläche</p>
 <p>Ihr Nutzungszeitraum: <?= dmy($r['from']) ?> – <?= dmy($r['to']) ?> (<?= days_between($r['from'], $r['to']) ?> von <?= $R['days'] ?> Tagen)</p>
 <table class="table table-sm">
  <thead><tr><th>Kostenart</th><th>Verteilung</th><th class="text-end">Gesamtkosten</th><th>Ihr Anteil nach</th><th class="text-end">Ihr Anteil</th></tr></thead><tbody>
  <?php foreach ($r['lines'] as $code => $ln):
      $cs = array_filter($R['costs'], fn($y) => $y['code'] === $code && in_array($y['scope'], ['alle Häuser', $b['name'], 'Whg ' . $u['no']], true));
      $c = $cs ? reset($cs) : null; $tot = array_sum(array_column($cs, 'amount')); ?>
   <tr><td><?= h($c['name'] ?? $lbl($code)) ?></td>
    <td class="small"><?= $c ? h($KEY[$c['key']] ?? $c['key']) . ($c['key'] === 'consumption' ? ' (' . num($c['share'], 0) . ' % Verbrauch, Rest Fläche)' : '') . ' · ' . h($c['scope']) : '' ?></td>
    <td class="text-end"><?= $c ? money($tot) : '' ?></td><td class="small"><?= h($ln['basis']) ?></td><td class="text-end"><?= money($ln['amount']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
   <tr class="fw-semibold"><td colspan="4">Summe Ihrer Betriebskosten</td><td class="text-end"><?= money($r['total']) ?></td></tr>
   <tr><td colspan="4">abzüglich Ihrer <?= $set['advances'] === 'soll' ? 'vereinbarten' : 'geleisteten' ?> Vorauszahlungen im Zeitraum<?= $set['advances'] === 'ist' && $r['advances_unpaid'] > 0.005 ? ' (vereinbart ' . money($r['advances_soll']) . ', davon nicht gezahlt ' . money($r['advances_unpaid']) . ')' : '' ?></td><td class="text-end">– <?= money($r['advances']) ?></td></tr>
   <tr class="fw-bold fs-6"><td colspan="4"><?= $r['result'] >= 0 ? 'Nachzahlung' : 'Guthaben' ?></td><td class="text-end"><?= money(abs($r['result'])) ?></td></tr>
  </tfoot>
 </table>
 <?php if ($r['excluded']): ?><p class="small">Nicht berechnet, weil im Mietvertrag nicht vereinbart: <?= h(implode(', ', array_map($lbl, array_keys($r['excluded'])))) ?> (<?= money($r['excluded_total']) ?>, trägt der Vermieter).</p><?php endif; ?>
 <?php $nr = rent_at((int)$r['lease_id'], $to); $months = max(1, round(days_between($r['from'], $r['to']) / 30.4)); $newAdv = ceil($r['total'] / $months); ?>
 <p><?= $r['result'] >= 0 ? 'Bitte überweisen Sie die Nachzahlung innerhalb von 30 Tagen nach Zugang dieser Abrechnung.' : 'Das Guthaben überweisen wir Ihnen bzw. verrechnen es mit der nächsten Miete.' ?>
 <?= $set['advances'] === 'ist' && $r['advances_unpaid'] > 0.005 ? ' Nicht gezahlte Vorauszahlungen sind hier nicht abgezogen; sie bleiben als Mietrückstand auf Ihrem Mieterkonto.' : '' ?></p>
 <p>Nach § 560 Abs. 4 BGB passen wir Ihre monatliche Vorauszahlung an die Abrechnung an: künftig <b><?= money($newAdv) ?></b> (bisher <?= money(($nr['operating_advance'] ?? 0) + ($nr['heating_advance'] ?? 0)) ?>), ab dem übernächsten Monat nach Zugang.</p>

 <?php if (!empty($r['labour'])): ?>
 <h2 class="h6 mt-4">Bescheinigung für die Steuerermäßigung nach § 35a EStG</h2>
 <table class="table table-sm w-auto"><tbody>
  <?php foreach ($r['labour'] as $t => $v): ?><tr><td><?= h(TAX35A[$t]) ?></td><td class="text-end"><?= money($v) ?></td></tr><?php endforeach; ?>
 </tbody></table>
 <p class="small text-body-secondary">Ihr Anteil an den in den Betriebskosten enthaltenen Arbeitskosten (ohne Material), nach demselben Schlüssel wie die Kosten verteilt. Absetzbar sind davon 20 % (Abs. 2 bis 4.000 €, Abs. 3 bis 1.200 € Steuerermäßigung im Jahr).</p>
 <?php endif; ?>

 <?php if ($HK['mode'] === 'own' && isset($HK['K']) && $HK['K'] > 0): ?>
 <h2 class="h6 mt-4">Heizkosten – Abrechnungsinformationen (§ 6b, Anlage HeizkostenV)</h2>
 <table class="table table-sm small"><tbody>
  <tr><td>Energieträger</td><td><?= h(FUEL_KIND[$set['fuel_kind']]) ?><?= $set['fuel_kwh'] ? ', Verbrauch der Anlage ' . num($set['fuel_kwh'], 0) . ' kWh' : '' ?></td></tr>
  <tr><td>Kosten der zentralen Anlage</td><td><?= money($HK['K']) ?>, davon Warmwasser <?= num(100 * $HK['s'], 1) ?> % (§ 9: <?= h(WW_METHOD[$set['ww_method']]) ?>, Wärmemenge <?= num($HK['qww'], 0) ?> kWh<?= $HK['fac'] != 1 ? ', Faktor ' . num($HK['fac'], 2) : '' ?>)</td></tr>
  <?php if (isset($HK['co2_stage'])): ?><tr><td>CO₂ (CO2KostAufG)</td><td><?= num($HK['co2_kg'], 0) ?> kg CO₂, <?= num($HK['co2_spec'], 1) ?> kg/(m²·a) → Stufe <?= $HK['co2_stage'] ?>: Vermieter <?= $HK['co2_pct'] ?> %, Mieter <?= 100 - $HK['co2_pct'] ?> %. CO₂-Kosten gesamt <?= money($HK['co2_cost']) ?><?= isset($r['co2_tenant']) ? ', auf Sie entfallend ' . money($r['co2_tenant']) . ', davon trägt der Vermieter ' . money(-($r['lines']['CO2']['amount'] ?? 0)) : '' ?>.</td></tr><?php endif; ?>
  <?php $cy = unit_consumption((int)$u['id'], 'heat', $r['from'], $r['to']); $py = unit_consumption((int)$u['id'], 'heat', date('Y-m-d', strtotime("$from -1 year")), date('Y-m-d', strtotime("$to -1 year")));
        $all = 0.0; foreach ($R['units'] as $x) $all += unit_consumption((int)$x['id'], 'heat', $from, $to);
        $wc = unit_consumption((int)$u['id'], 'water_hot', $r['from'], $r['to']); ?>
  <tr><td>Ihr Verbrauch Heizung</td><td><?= num($cy, 0) ?> Einheiten<?= $py > 0 ? ' (Vorjahr ' . num($py, 0) . ')' : '' ?> · Durchschnitt aller Nutzer <?= $R['area'] > 0 ? num($all / $R['area'] * (float)$u['living_area'], 0) : '–' ?> Einheiten bei Ihrer Wohnfläche</td></tr>
  <tr><td>Ihr Verbrauch Warmwasser</td><td><?= num($wc, 2) ?> m³</td></tr>
 </tbody></table>
 <p class="small text-body-secondary">Informationen zu Energieeffizienzmaßnahmen und Energieberatung: Verbraucherzentrale Bayern (verbraucherzentrale-bayern.de), Bundesamt für Wirtschaft und Ausfuhrkontrolle (bafa.de). Bei Streitigkeiten: Universalschlichtungsstelle des Bundes, Zentrum für Schlichtung e. V., Straßburger Str. 8, 77694 Kehl (verbraucher-schlichter.de). Abrechnungs- und Verbrauchsinformationen nach § 6a HeizkostenV erhalten Sie, sobald fernablesbare Messgeräte installiert sind.</p>
 <?php endif; ?>
 <p class="small text-body-secondary">Sie können die Abrechnungsbelege nach Terminvereinbarung einsehen (§ 259 BGB). Einwendungen sind bis spätestens zum Ablauf des zwölften Monats nach Zugang dieser Abrechnung mitzuteilen (§ 556 Abs. 3 BGB).</p>
</div>
<?php page_foot(); return;
}

// ---------------------------------------------------------------- overview ---
$hkCount = (int)val("SELECT COUNT(*) FROM hk_result WHERE period_from=? AND period_to=?", [$from, $to]);
$hkSum = (float)val("SELECT COALESCE(SUM(amount),0) FROM hk_result WHERE period_from=? AND period_to=?", [$from, $to]);
page_head('Betriebskostenabrechnung'); ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
 <h1 class="h3 mb-0 me-auto"><i class="bi bi-file-spreadsheet me-2"></i>Betriebskostenabrechnung</h1>
 <form class="d-flex gap-2 d-print-none"><input type="hidden" name="p" value="bk">
  <input class="form-control form-control-sm" type="date" name="from" value="<?= h($from) ?>"><input class="form-control form-control-sm" type="date" name="to" value="<?= h($to) ?>">
  <select class="form-select form-select-sm" name="house"><option value="0">alle Häuser</option><?php foreach (fk_options('building') as $k => $v): ?><option value="<?= $k ?>" <?= $house === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
  <button class="btn btn-sm btn-primary">berechnen</button></form>
 <button class="btn btn-sm btn-outline-secondary d-print-none" onclick="print()"><i class="bi bi-printer"></i></button>
</div>
<?php foreach ($R['warn'] as $w): ?><div class="alert alert-warning py-2 small"><?= h($w) ?></div><?php endforeach; ?>

<div class="accordion mb-3 d-print-none" id="acc">
 <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button <?= $set['id'] ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#cset">
  Heizkosten und Vorauszahlungen: <b class="ms-1"><?= $set['mode'] === 'own' ? 'eigene Abrechnung nach HeizkostenV' : 'Messdienst (Brunata)' ?></b><span class="ms-2 text-body-secondary">· Vorauszahlungen <?= $set['advances'] === 'ist' ? 'Ist (gezahlt)' : 'Soll' ?><?= $set['mode'] === 'brunata' ? ' · ' . ($hkCount ? "$hkCount Brunata-Positionen, " . money($hkSum) : 'Brunata noch nicht importiert') : '' ?></span></button></h2>
 <div id="cset" class="accordion-collapse collapse <?= $set['id'] ? '' : 'show' ?>"><div class="accordion-body">
  <div class="row g-4">
   <form method="post" class="col-lg-7 row g-2"><?= csrf_field() ?><input type="hidden" name="_action" value="settings">
    <div class="col-md-6"><label class="form-label small">Heiz-, Warmwasser- und Wasserkosten</label><select class="form-select form-select-sm" name="mode" data-toggle-target>
      <option value="brunata" <?= $set['mode'] === 'brunata' ? 'selected' : '' ?>>Messdienst (Brunata) – Ergebnisse je Nutzer übernehmen</option>
      <option value="own" <?= $set['mode'] === 'own' ? 'selected' : '' ?>>selbst nach HeizkostenV abrechnen</option></select></div>
    <div class="col-md-6"><label class="form-label small">Vorauszahlungen abziehen</label><select class="form-select form-select-sm" name="advances">
      <option value="ist" <?= $set['advances'] === 'ist' ? 'selected' : '' ?>>Ist – tatsächlich gezahlt (empfohlen)</option><option value="soll" <?= $set['advances'] === 'soll' ? 'selected' : '' ?>>Soll – vereinbart</option></select></div>
    <div data-for="own" class="col-12 row g-2 m-0 p-0">
     <div class="col-md-6"><label class="form-label small">Energieträger</label><select class="form-select form-select-sm" name="fuel_kind"><?php foreach (FUEL_KIND as $k => $v): ?><option value="<?= $k ?>" <?= $set['fuel_kind'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
     <div class="col-md-6"><label class="form-label small">Verbrauch der Anlage im Zeitraum (kWh lt. Rechnung)</label><input class="form-control form-control-sm text-end" name="fuel_kwh" value="<?= h($set['fuel_kwh'] !== null ? num($set['fuel_kwh'], 0) : '') ?>"></div>
     <div class="col-md-8"><label class="form-label small">Warmwasseranteil nach § 9</label><select class="form-select form-select-sm" name="ww_method"><?php foreach (WW_METHOD as $k => $v): ?><option value="<?= $k ?>" <?= $set['ww_method'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
     <div class="col-md-4"><label class="form-label small">Warmwassertemperatur °C</label><input class="form-control form-control-sm text-end" name="ww_temp" value="<?= h(num($set['ww_temp'], 0)) ?>"></div>
     <div class="col-md-6"><label class="form-label small">CO₂-Emissionen lt. Brennstoffrechnung (kg)</label><input class="form-control form-control-sm text-end" name="co2_kg" value="<?= h($set['co2_kg'] !== null ? num($set['co2_kg'], 0) : '') ?>"></div>
     <div class="col-md-6"><label class="form-label small">CO₂-Kosten lt. Brennstoffrechnung (€)</label><input class="form-control form-control-sm text-end" name="co2_cost" value="<?= h($set['co2_cost'] !== null ? num($set['co2_cost']) : '') ?>"></div>
     <div class="col-12 small text-body-secondary">Kosten der zentralen Anlage sind alle Ausgaben der Kostenarten mit „Heizanlage“ (z. B. HZ Heizung, Energie-/Wärmelieferung). Verteilung Heizung nach Heizkostenverteilern, Warmwasser nach Warmwasserzählern, Anteil Verbrauch lt. Kostenart (50–70 %, § 7/8). Ohne Zwischenablesung bei Mieterwechsel: Gradtagszahlen (§ 9b).</div>
    </div>
    <div class="col-12"><input class="form-control form-control-sm" name="note" placeholder="Notiz" value="<?= h($set['note']) ?>"></div>
    <div class="col-12"><button class="btn btn-sm btn-primary">Einstellungen speichern</button></div>
   </form>
   <div class="col-lg-5">
    <form method="post" enctype="multipart/form-data" class="border rounded p-2"><?= csrf_field() ?><input type="hidden" name="_action" value="brunata">
     <div class="small fw-medium mb-1">Brunata „Übersicht aller Nutzerabrechnungen“ importieren</div>
     <input class="form-control form-control-sm mb-1" type="file" name="pdf" accept="application/pdf">
     <div class="input-group input-group-sm mb-1"><span class="input-group-text">oder Mayan-Nr.</span><input class="form-control" name="mayan_id" inputmode="numeric" placeholder="z. B. 99"></div>
     <button class="btn btn-sm btn-outline-primary">importieren</button>
     <div class="form-text">Übernimmt Heizung, Warmwasser, Kalt- und Abwasser je Nutzer und Zeitraum. Die zugehörigen Ausgaben werden dann nicht noch einmal verteilt.</div>
    </form>
    <?php if ($hkCount): ?><form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="_action" value="brunata_delete"><button class="btn btn-sm btn-link text-danger p-0" onclick="return confirm('Brunata-Ergebnisse dieses Zeitraums löschen?')">Brunata-Ergebnisse dieses Zeitraums löschen</button></form><?php endif; ?>
   </div>
  </div>
 </div></div></div>
</div>

<div class="row g-3">
<div class="col-xl-5"><div class="card"><div class="card-header">Kosten <?= dmy($from) ?> – <?= dmy($to) ?></div>
 <table class="table table-sm mb-0"><thead><tr><th>Kostenart</th><th>Bereich</th><th>Schlüssel</th><th class="text-end">Betrag</th></tr></thead><tbody>
 <?php $T = 0; foreach ($R['costs'] as $c): $T += $c['amount']; ?>
  <tr><td><?= h($c['code'] . ' ' . $c['name']) ?></td><td><?= h($c['scope']) ?></td><td class="small"><?= h($KEY[$c['key']] ?? $c['key']) ?><?= $c['key'] === 'consumption' ? ' ' . num($c['share'], 0) . ' %' : '' ?></td><td class="text-end"><?= money($c['amount']) ?></td></tr>
 <?php endforeach; ?></tbody>
 <tfoot><tr class="fw-semibold"><td colspan="3">Summe umlagefähig</td><td class="text-end"><?= money($T) ?></td></tr></tfoot></table>
 <?php $nonAlloc = (float)val("SELECT COALESCE(SUM(e.amount),0) FROM expense e JOIN cost_type ct ON ct.id=e.cost_type_id WHERE ct.allocatable=0 AND e.period_from<=? AND e.period_to>=?", [$to, $from]); ?>
 <div class="card-footer small text-body-secondary">nicht umlagefähig (Verwaltung, Instandhaltung …): <?= money($nonAlloc) ?></div></div></div>

<div class="col-xl-7"><div class="card"><div class="card-header">Ergebnis je Nutzer</div>
 <div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Whg</th><th>Nutzer</th><th>Zeitraum</th><th class="text-end">Kosten</th><th class="text-end">Vorausz. <?= $set['advances'] ?></th><th class="text-end">Ergebnis</th><th></th></tr></thead><tbody>
 <?php $sumC = $sumA = $sumV = $sumX = 0; foreach ($R['res'] as $k => $r): $u = $R['units'][$r['unit_id']];
     if ($house && (int)$u['building_id'] !== $house) continue;
     $name = $r['lease_id'] ? val("SELECT " . PERSON_LABEL_SQL . " FROM person WHERE id=(SELECT tenant_id FROM lease WHERE id=?)", [$r['lease_id']]) : null;
     $sumX += $r['excluded_total'];
     if ($r['lease_id']) { $sumC += $r['total']; $sumA += $r['advances']; } else $sumV += $r['total']; ?>
  <tr class="<?= $r['lease_id'] ? '' : 'table-warning' ?>"><td><?= h($u['no']) ?></td><td><?= $name ? h($name) : '<i>Leerstand (Eigentümer)</i>' ?></td><td class="small text-nowrap"><?= dmy($r['from']) ?> – <?= dmy($r['to']) ?></td>
   <td class="text-end"><?= money($r['total']) ?></td><td class="text-end"><?= $r['lease_id'] ? money($r['advances']) . ($r['advances_unpaid'] > 0.005 ? ' <i class="bi bi-exclamation-circle text-danger" title="nicht gezahlt: ' . h(money($r['advances_unpaid'])) . '"></i>' : '') : '' ?></td>
   <?= $r['lease_id'] ? amount_cell($r['result']) : '<td></td>' ?>
   <td><?php if ($r['lease_id']): ?><a class="btn btn-sm btn-link py-0" href="<?= url('bk', ['from' => $from, 'to' => $to, 'k' => $k]) ?>" title="Einzelabrechnung"><i class="bi bi-file-earmark-text"></i></a><?php endif; ?></td></tr>
 <?php endforeach; ?></tbody>
 <tfoot class="fw-semibold"><tr><td colspan="3">Mieter gesamt</td><td class="text-end"><?= money($sumC) ?></td><td class="text-end"><?= money($sumA) ?></td><?= amount_cell($sumC - $sumA) ?><td></td></tr>
 <tr><td colspan="3">Leerstand (trägt der Eigentümer)</td><td class="text-end"><?= money($sumV) ?></td><td colspan="3"></td></tr>
 <?php if ($sumX): ?><tr><td colspan="3">im Mietvertrag nicht vereinbart (trägt der Eigentümer)</td><td class="text-end"><?= money($sumX) ?></td><td colspan="3"></td></tr><?php endif; ?></tfoot></table></div></div>
 <p class="small text-body-secondary mt-2">Frist: Die Abrechnung muss dem Mieter spätestens 12 Monate nach Ende des Abrechnungszeitraums zugehen (§ 556 Abs. 3 BGB), für diesen Zeitraum also bis <?= dmy(date('Y-m-d', strtotime("$to +12 months"))) ?>.</p>
</div>
</div>
<?php page_foot();
