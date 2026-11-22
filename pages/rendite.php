<?php
// Reporting: yield per flat on the acquisition / production costs (table investment), grouped by house or owner
$year = (int)($_GET['year'] ?? date('Y'));
$date = $year < (int)date('Y') ? "$year-12-31" : date('Y-m-d');   // key date for the rent
$kinds = array_values(array_intersect((array)($_GET['k'] ?? array_keys(INVEST_KIND)), array_keys(INVEST_KIND)));
$group = in_array($_GET['g'] ?? '', ['unit', 'building', 'owner'], true) ? $_GET['g'] : 'building';
$adm = parse_num($_GET['adm'] ?? null) ?? 300.0;    // administration EUR per flat and year (assumption)
$mnt = parse_num($_GET['mnt'] ?? null) ?? 10.0;     // maintenance EUR per m2 and year (assumption)
$loss = parse_num($_GET['loss'] ?? null) ?? 2.0;    // rent loss risk in % of the cold rent (assumption)
$sent = isset($_GET['f']);                          // form submitted: checkboxes count as given, else defaults on
$full = $sent ? isset($_GET['full']) : true;        // assume full occupancy (no vacancy)
$withP = $sent ? isset($_GET['park']) : true;       // include parking spaces and garages
$pRent = parse_num($_GET['pr'] ?? null) ?? 20.0;    // assumed rent of a free parking space, EUR/month
$gRent = parse_num($_GET['gr'] ?? null) ?? 190.0;   // assumed rent of a free garage, EUR/month

$units = report_units($withP);
// average cold rent per m2 of the let flats (per house, fallback property) for vacant flats under full occupancy
$m2 = ['all' => [0.0, 0.0]];
foreach ($units as $id => $u) {
    if ($u['kind'] !== 'apartment' || !($r = unit_rent($id, $date)) || !$r['rent'] || (float)$u['living_area'] <= 0) continue;
    foreach ([(string)$u['building_id'], 'all'] as $k) { $m2[$k] ??= [0.0, 0.0]; $m2[$k][0] += (float)$r['rent']['base_rent']; $m2[$k][1] += (float)$u['living_area']; }
}
$m2rate = fn($b) => ($m2[(string)$b][1] ?? 0) > 0 ? $m2[(string)$b][0] / $m2[(string)$b][1] : ($m2['all'][1] > 0 ? $m2['all'][0] / $m2['all'][1] : 0.0);
$invs = $kinds ? rows("SELECT * FROM investment WHERE kind IN (" . implode(',', array_fill(0, count($kinds), '?')) . ") AND acquired_date<=?", [...$kinds, $date]) : [];
$alloc = investment_alloc($invs, $units);
$charged = rent_charged($year);
$nonalloc = nonalloc_expenses($year, $units);

$data = [];
foreach ($units as $id => $u) {
    $r = unit_rent($id, $date);
    $isFlat = $u['kind'] === 'apartment';
    $cold = $r && $r['rent'] ? (float)$r['rent']['base_rent'] + ($isFlat ? 0.0 : (float)$r['rent']['parking_rent']) : 0.0;
    $area = $isFlat ? (float)$u['living_area'] : 0.0;
    $assumed = false;
    if ($full && !$r) {   // full occupancy: vacant / not yet let units get an assumed rent
        $cold = $isFlat ? round($m2rate($u['building_id']) * (float)$u['living_area'], 2) : ($u['kind'] === 'garage' ? $gRent : $pRent);
        $assumed = true;
    }
    $ahk = array_sum($alloc[$id] ?? []);
    $pa = $cold * 12;
    $costs = ($isFlat ? $adm + $mnt * $area : 0.0) + $pa * $loss / 100 + ($nonalloc[$id] ?? 0);
    $data[$id] = ['u' => $u, 'tenant' => $r['lease']['tenant'] ?? null, 'vacant' => !$r && !$assumed, 'assumed' => $assumed, 'area' => $area, 'cold' => $cold, 'pa' => $pa,
        'charged' => $charged[$id] ?? 0.0, 'ahk' => $ahk, 'costs' => $costs, 'nonalloc' => $nonalloc[$id] ?? 0.0,
        'gk' => match ($group) { 'owner' => $u['owner'] ?: '(ohne WEG-Eigentümer)', 'building' => $u['house'] ?: ($isFlat ? '–' : 'Stellplätze / Garage'), default => '' }];
}
function rsum(array $rows): array {
    $s = ['area' => 0, 'cold' => 0, 'pa' => 0, 'charged' => 0, 'ahk' => 0, 'costs' => 0, 'n' => count($rows), 'vacant' => 0, 'pa_b' => 0, 'costs_b' => 0, 'n_b' => 0, 'cold_f' => 0];
    foreach ($rows as $r) {
        foreach (['area', 'cold', 'pa', 'charged', 'ahk', 'costs'] as $k) $s[$k] += $r[$k];
        if ($r['u']['kind'] === 'apartment') $s['cold_f'] += $r['cold'];   // flats only, for EUR/m2
        $s['vacant'] += $r['vacant'] ? 1 : 0;
        // yields only over units in the cost basis; parking / garages without own costs count when the land is in the basis
        if ($r['ahk'] > 0 || ($r['u']['kind'] !== 'apartment' && $GLOBALS['parkInBasis'])) { $s['pa_b'] += $r['pa']; $s['costs_b'] += $r['costs']; $s['n_b']++; }
    }
    return $s;
}
$parkInBasis = in_array('land', $kinds, true);
$yield = fn($pa, $ahk) => $ahk > 0 ? $pa / $ahk * 100 : null;
$tot = rsum($data);
$nAssumed = count(array_filter($data, fn($r) => $r['assumed']));
$noCost = array_filter($data, fn($r) => $r['u']['kind'] !== 'apartment' && $r['ahk'] <= 0 && (int)$r['u']['mandatory_parking'] === 0);

if (isset($_GET['csv'])) {
    $out = [];
    foreach ($data as $r) $out[] = [$r['u']['no'], $r['u']['house'], trim($r['u']['floor'] . ' ' . $r['u']['position']), $r['area'], $r['u']['owner'], ($r['assumed'] ? 'unterstellt' : ($r['tenant'] ?? 'Leerstand')),
        $r['cold'], $r['area'] > 0 ? $r['cold'] / $r['area'] : null, $r['pa'], $r['charged'], $r['ahk'], $yield($r['pa'], $r['ahk']), $r['costs'], $yield($r['pa'] - $r['costs'], $r['ahk'])];
    $out[] = ['Gesamt', '', $full ? 'Vollvermietung unterstellt' : '', $tot['area'], '', '', $tot['cold'], null, $tot['pa'], $tot['charged'], $tot['ahk'], $yield($tot['pa_b'], $tot['ahk']), $tot['costs'], $yield($tot['pa_b'] - $tot['costs_b'], $tot['ahk'])];
    csv_out("rendite_$year.csv", ['Einheit', 'Haus', 'Lage', 'm²', 'Eigentümer', 'Mieter', 'Kaltmiete/Monat', '€/m²', 'Kaltmiete p.a.', "Soll Kaltmiete $year",
        'AHK-Basis', 'Bruttorendite %', 'Bewirtschaftung p.a.', 'Nettorendite %'], $out);
}

$groups = [];
foreach ($data as $id => $r) $groups[$r['gk']][$id] = $r;
$qs = fn(array $over) => url('rendite', array_merge(['year' => $year, 'g' => $group, 'k' => $kinds, 'adm' => num($adm), 'mnt' => num($mnt), 'loss' => num($loss),
    'f' => 1, 'pr' => num($pRent), 'gr' => num($gRent)] + ($full ? ['full' => 1] : []) + ($withP ? ['park' => 1] : []), $over));

page_head("Rendite $year"); ?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap"><h1 class="h3 mb-0 me-auto"><i class="bi bi-graph-up-arrow me-2"></i>Rendite <?= $year ?> <span class="fs-6 text-body-secondary">Miete zum <?= dmy($date) ?></span></h1>
 <a class="btn btn-sm btn-outline-secondary d-print-none" href="<?= $qs(['csv' => 1]) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
 <button class="btn btn-sm btn-outline-secondary d-print-none" onclick="print()"><i class="bi bi-printer"></i></button></div>

<form class="card card-body mb-3 d-print-none"><input type="hidden" name="p" value="rendite"><input type="hidden" name="f" value="1">
 <div class="row g-2 align-items-end">
  <div class="col-auto"><label class="form-label small mb-0">Jahr</label><input class="form-control form-control-sm" style="width:6rem" type="number" name="year" value="<?= $year ?>"></div>
  <div class="col-auto"><label class="form-label small mb-0">Gruppierung</label><select class="form-select form-select-sm" name="g">
   <?php foreach (['building' => 'nach Haus', 'owner' => 'nach Eigentümer', 'unit' => 'nur Einheiten'] as $k => $l): ?><option value="<?= $k ?>"<?= $k === $group ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><label class="form-label small mb-0 d-block">Kostenbasis (AHK)</label>
   <?php foreach (INVEST_KIND as $k => $l): ?><div class="form-check form-check-inline small"><input class="form-check-input" type="checkbox" name="k[]" value="<?= $k ?>" id="k<?= $k ?>"<?= in_array($k, $kinds, true) ? ' checked' : '' ?>><label class="form-check-label" for="k<?= $k ?>"><?= h($l) ?></label></div><?php endforeach; ?></div>
  <div class="col-auto"><label class="form-label small mb-0">Verwaltung €/Whg/Jahr</label><input class="form-control form-control-sm" style="width:7rem" name="adm" value="<?= num($adm) ?>"></div>
  <div class="col-auto"><label class="form-label small mb-0">Instandhaltung €/m²/Jahr</label><input class="form-control form-control-sm" style="width:7rem" name="mnt" value="<?= num($mnt) ?>"></div>
  <div class="col-auto"><label class="form-label small mb-0">Mietausfall %</label><input class="form-control form-control-sm" style="width:5rem" name="loss" value="<?= num($loss) ?>"></div>
  <div class="col-auto"><div class="form-check small"><input class="form-check-input" type="checkbox" name="full" value="1" id="full"<?= $full ? ' checked' : '' ?>><label class="form-check-label" for="full">Vollvermietung unterstellen (kein Leerstand)</label></div>
   <div class="form-check small"><input class="form-check-input" type="checkbox" name="park" value="1" id="park"<?= $withP ? ' checked' : '' ?>><label class="form-check-label" for="park">Stellplätze und Garagen einbeziehen</label></div></div>
  <div class="col-auto"><label class="form-label small mb-0">Stellplatz €/Monat (frei)</label><input class="form-control form-control-sm" style="width:6rem" name="pr" value="<?= num($pRent) ?>"></div>
  <div class="col-auto"><label class="form-label small mb-0">Garage €/Monat (frei)</label><input class="form-control form-control-sm" style="width:6rem" name="gr" value="<?= num($gRent) ?>"></div>
  <div class="col-auto"><button class="btn btn-sm btn-primary">Berechnen</button></div>
 </div></form>

<?php $nf = count(array_filter($data, fn($r) => $r['u']['kind'] === 'apartment')); $np = count($data) - $nf; ?>
<div class="alert alert-<?= $full ? 'info' : 'secondary' ?> py-2"><b>Gesamtrendite <?= $full ? 'bei Vollvermietung' : 'Ist-Stand' ?>:</b>
 <?= $nf ?> Wohnungen<?= $withP ? " und $np Stellplätze/Garagen" : '' ?>, Kaltmiete <?= money($tot['pa']) ?> p. a. auf <?= money($tot['ahk']) ?> Anschaffungs-/Herstellungskosten
 = <b><?= pct($yield($tot['pa_b'], $tot['ahk'])) ?> brutto</b>, <?= pct($yield($tot['pa_b'] - $tot['costs_b'], $tot['ahk'])) ?> netto.
 <?php if ($full && $nAssumed): ?><br><span class="small"><?= $nAssumed ?> Einheiten ohne Mietvertrag mit unterstellter Miete (Wohnungen: Ø €/m² der vermieteten Wohnungen des Hauses; Stellplatz <?= money($pRent) ?>, Garage <?= money($gRent) ?> je Monat).</span><?php endif; ?>
 <?php if ($full && $noCost): ?><br><span class="small text-danger">Für <?= count($noCost) ?> freie Stellplätze/Garagen sind keine Baukosten erfasst (<?= h(implode(', ', array_map(fn($r) => $r['u']['no'], $noCost))) ?>) – die Rendite ist dadurch zu hoch, solange die Herstellungskosten nicht als Investition (mit Einheiten-IDs) eingetragen sind.</span><?php endif; ?>
</div>
<div class="row g-3 mb-3">
 <div class="col-6 col-md"><div class="card card-body"><div class="small text-body-secondary">AHK-Basis</div><div class="fs-5 fw-semibold"><?= money($tot['ahk']) ?></div><div class="small text-body-secondary"><?= $tot['area'] > 0 ? money($tot['ahk'] / $tot['area']) . ' je m²' : '' ?></div></div></div>
 <div class="col-6 col-md"><div class="card card-body"><div class="small text-body-secondary">Kaltmiete p. a. (Stichtag × 12)</div><div class="fs-5 fw-semibold"><?= money($tot['pa']) ?></div><div class="small text-body-secondary"><?= $tot['area'] > 0 ? money($tot['cold_f'] / $tot['area']) . ' je m² und Monat (Wohnungen)' : '' ?></div></div></div>
 <div class="col-6 col-md"><div class="card card-body"><div class="small text-body-secondary">Bruttorendite</div><div class="fs-5 fw-semibold text-success"><?= pct($yield($tot['pa_b'], $tot['ahk'])) ?></div><div class="small text-body-secondary"><?= $tot['pa_b'] > 0 ? 'Vervielfältiger ' . num($tot['ahk'] / $tot['pa_b'], 1) : '' ?><?= $tot['n_b'] < $tot['n'] ? " · über {$tot['n_b']} Einheiten mit Kosten" : '' ?></div></div></div>
 <div class="col-6 col-md"><div class="card card-body"><div class="small text-body-secondary">Nettorendite</div><div class="fs-5 fw-semibold"><?= pct($yield($tot['pa_b'] - $tot['costs_b'], $tot['ahk'])) ?></div><div class="small text-body-secondary">nach <?= money($tot['costs_b']) ?> Bewirtschaftung</div></div></div>
 <div class="col-6 col-md"><div class="card card-body"><div class="small text-body-secondary">Leerstand</div><div class="fs-5 fw-semibold<?= $tot['vacant'] ? ' text-danger' : '' ?>"><?= $tot['vacant'] ?> / <?= $tot['n'] ?></div><div class="small text-body-secondary"><?= $full ? "Vollvermietung: $nAssumed unterstellt" : 'Einheiten am Stichtag' ?></div></div></div>
</div>

<?php if (!$invs): ?><div class="alert alert-warning">Keine Investitionen für die gewählte Kostenbasis erfasst. Anschaffungs- und Herstellungskosten unter <a href="<?= url('list', ['e' => 'investment']) ?>">Reporting › Investitionen</a> eintragen (z. B. aus der Bilanz / dem Anlagenverzeichnis).</div><?php endif; ?>

<div class="card mb-3"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
 <thead><tr><th>Whg</th><th>Haus</th><th>Lage</th><th class="text-end">m²</th><th>Eigentümer</th><th>Mieter</th><th class="text-end">Kaltmiete</th><th class="text-end">€/m²</th>
  <th class="text-end">p. a.</th><th class="text-end">Soll <?= $year ?></th><th class="text-end">AHK-Basis</th><th class="text-end">brutto</th><th class="text-end">Bewirtsch.</th><th class="text-end">netto</th></tr></thead>
 <tbody>
 <?php foreach ($groups as $gk => $rs): if ($group !== 'unit'): $s = rsum($rs); ?>
  <tr class="table-secondary fw-semibold"><td colspan="3"><?= h($gk) ?></td><td class="text-end"><?= num($s['area']) ?></td><td colspan="2" class="small fw-normal"><?= $s['n'] ?> Einheiten<?= $s['vacant'] ? ", {$s['vacant']} leer" : '' ?></td>
   <td class="text-end"><?= money($s['cold']) ?></td><td class="text-end"><?= $s['area'] > 0 ? num($s['cold_f'] / $s['area']) : '' ?></td><td class="text-end"><?= money($s['pa']) ?></td><td class="text-end"><?= money($s['charged']) ?></td>
   <td class="text-end"><?= money($s['ahk']) ?></td><td class="text-end"><?= pct($yield($s['pa_b'], $s['ahk'])) ?></td><td class="text-end"><?= money($s['costs']) ?></td><td class="text-end"><?= pct($yield($s['pa_b'] - $s['costs_b'], $s['ahk'])) ?></td></tr>
 <?php endif; foreach ($rs as $id => $r): ?>
  <tr<?= $r['vacant'] ? ' class="text-body-secondary"' : '' ?>><td><a href="<?= url('edit', ['e' => 'unit', 'id' => $id]) ?>"><?= h($r['u']['no']) ?></a></td><td><?= h($r['u']['house']) ?></td><td><?= h(trim($r['u']['floor'] . ' ' . $r['u']['position'])) ?></td>
   <td class="text-end"><?= num($r['area']) ?></td><td class="small"><?= h($r['u']['owner']) ?></td><td class="small"><?= $r['vacant'] ? badge('Leerstand', 'danger') : ($r['assumed'] ? badge('unterstellt', 'info') : h($r['tenant'])) ?></td>
   <td class="text-end"><?= money($r['cold']) ?></td><td class="text-end"><?= $r['area'] > 0 && $r['cold'] > 0 ? num($r['cold'] / $r['area']) : '' ?></td><td class="text-end"><?= money($r['pa']) ?></td>
   <td class="text-end"><?= money($r['charged'], true) ?></td><td class="text-end"><?= money($r['ahk']) ?></td><td class="text-end fw-semibold"><?= pct($yield($r['pa'], $r['ahk'])) ?></td>
   <td class="text-end" title="davon erfasste nicht umlagefähige Ausgaben <?= money($r['nonalloc']) ?>"><?= money($r['costs']) ?></td><td class="text-end"><?= pct($yield($r['pa'] - $r['costs'], $r['ahk'])) ?></td></tr>
 <?php endforeach; endforeach; ?>
 </tbody>
 <tfoot><tr class="fw-bold border-top border-2"><td colspan="3">Summe</td><td class="text-end"><?= num($tot['area']) ?></td><td colspan="2"></td><td class="text-end"><?= money($tot['cold']) ?></td>
  <td class="text-end"><?= $tot['area'] > 0 ? num($tot['cold_f'] / $tot['area']) : '' ?></td><td class="text-end"><?= money($tot['pa']) ?></td><td class="text-end"><?= money($tot['charged']) ?></td><td class="text-end"><?= money($tot['ahk']) ?></td>
  <td class="text-end"><?= pct($yield($tot['pa_b'], $tot['ahk'])) ?></td><td class="text-end"><?= money($tot['costs']) ?></td><td class="text-end"><?= pct($yield($tot['pa_b'] - $tot['costs_b'], $tot['ahk'])) ?></td></tr></tfoot>
</table></div></div>

<div class="card"><div class="card-header">Investitionen in der Kostenbasis</div><table class="table table-sm mb-0"><thead><tr><th>Datum</th><th>Art</th><th>Bezeichnung</th><th>verteilt auf</th><th class="text-end">Betrag</th><th>Quelle</th></tr></thead><tbody>
 <?php foreach ($invs as $i): $t = investment_targets($i, $units); ?>
  <tr><td><?= dmy($i['acquired_date']) ?></td><td><?= h(INVEST_KIND[$i['kind']]) ?></td><td><a href="<?= url('edit', ['e' => 'investment', 'id' => $i['id']]) ?>"><?= h($i['title']) ?></a></td>
   <td class="small"><?= count($t) > 6 ? count($t) . ' Wohnungen' : 'Whg ' . h(implode(', ', array_map(fn($id) => $units[$id]['no'], $t))) ?> (nach Wohnfläche)</td><td class="text-end"><?= money($i['amount']) ?></td><td class="small"><?= h($i['source']) ?></td></tr>
 <?php endforeach; ?></tbody></table></div>
<p class="small text-body-secondary mt-2">Bruttorendite = Kaltmiete am Stichtag × 12 / anteilige Anschaffungs- und Herstellungskosten (ohne Abschreibung, vor Zinsen und Steuern). Summen-Renditen nur über Einheiten mit Kosten in der Basis; Stellplätze und Garagen ohne eigene Kosten zählen mit, wenn das Grundstück in der Basis ist. Mitgerechnet werden nur Stellplätze/Garagen mit WEG-Einheit (Planung), nicht die alten Zott-Stellplatzkonten ohne Zuordnung. Kosten ohne Einheitenliste werden nach Wohnfläche auf alle Wohnungen des Hauses bzw. der Liegenschaft verteilt.
 Bewirtschaftung = Verwaltung + Instandhaltung + Mietausfall (Annahmen oben) + im Jahr erfasste nicht umlagefähige Ausgaben. „Soll <?= $year ?>“ = gebuchte Kaltmiete-Sollstellungen des Jahres.</p>
<?php page_foot();
