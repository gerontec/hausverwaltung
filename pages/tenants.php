<?php
$on = $_GET['date'] ?? date('Y-m-d');
$rows = rows("SELECT u.id uid, u.no, u.kind, b.name house, u.floor, u.position, u.living_area, l.id lid, l.start_date, l.end_date, l.persons, l.ext_account,
                " . str_replace(['company', 'last_name', 'first_name'], ['p.company', 'p.last_name', 'p.first_name'], PERSON_LABEL_SQL) . " tenant, p.phone, p.email, p.iban,
                r.base_rent, r.operating_advance, r.heating_advance, r.parking_rent, r.valid_from
              FROM unit u LEFT JOIN building b ON b.id=u.building_id
              LEFT JOIN lease l ON l.unit_id=u.id AND (l.start_date IS NULL OR l.start_date<=?) AND (l.end_date IS NULL OR l.end_date>=?)
              LEFT JOIN person p ON p.id=l.tenant_id
              LEFT JOIN lease_rent r ON r.id=(SELECT id FROM lease_rent WHERE lease_id=l.id AND valid_from<=? ORDER BY valid_from DESC LIMIT 1)
              WHERE u.kind='apartment' OR l.id IS NOT NULL
              ORDER BY u.kind<>'apartment', CAST(REGEXP_REPLACE(u.no,'[^0-9]','') AS UNSIGNED), u.no", [$on, $on, $on]);
$sum = ['area' => 0, 'base' => 0, 'nk' => 0, 'hk' => 0, 'stp' => 0];
page_head('Mieterliste'); ?>
<div class="d-flex align-items-center gap-2 mb-3">
 <h1 class="h3 mb-0 me-auto"><i class="bi bi-person-lines-fill me-2"></i>Mieterliste</h1>
 <form class="d-flex gap-2 d-print-none"><input type="hidden" name="p" value="tenants"><label class="col-form-label">Stichtag</label>
  <input class="form-control form-control-sm" type="date" name="date" value="<?= h($on) ?>" onchange="this.form.submit()"></form>
 <button class="btn btn-sm btn-outline-secondary d-print-none" onclick="print()"><i class="bi bi-printer"></i></button>
</div>
<div class="table-responsive"><table class="table table-sm table-hover align-middle">
<thead class="table-light"><tr><th>Whg</th><th>Haus / Lage</th><th class="text-end">m²</th><th>Mieter</th><th>seit</th><th class="text-end">Pers.</th><th class="text-end">Kalt</th><th class="text-end">€/m²</th><th class="text-end">NK-VZ</th><th class="text-end">HK-VZ</th><th class="text-end">Stp.</th><th class="text-end">Gesamt</th><th class="text-end">Saldo</th></tr></thead>
<tbody>
<?php foreach ($rows as $r):
    $tot = $r['base_rent'] + $r['operating_advance'] + $r['heating_advance'] + $r['parking_rent'];
    if ($r['kind'] === 'apartment') $sum['area'] += $r['living_area'];
    $sum['base'] += $r['base_rent']; $sum['nk'] += $r['operating_advance']; $sum['hk'] += $r['heating_advance']; $sum['stp'] += $r['parking_rent'];
    $bal = $r['lid'] ? lease_balance((int)$r['lid']) : 0; ?>
 <tr class="<?= $r['lid'] ? '' : 'table-warning' ?>">
  <td><a href="<?= url('edit', ['e' => 'unit', 'id' => $r['uid']]) ?>"><?= h($r['no']) ?></a></td>
  <td><?= h(trim($r['house'] . ' ' . $r['floor'] . ' ' . $r['position'])) ?></td>
  <td class="text-end"><?= num($r['living_area']) ?></td>
  <td><?= $r['lid'] ? '<a href="' . url('edit', ['e' => 'lease', 'id' => $r['lid']]) . '">' . h($r['tenant']) . '</a>' : '<i>Leerstand</i>' ?></td>
  <td><?= $r['start_date'] ? dmy($r['start_date']) : ($r['lid'] ? 'unbekannt' : '') ?></td>
  <td class="text-end"><?= h($r['persons']) ?></td>
  <td class="text-end"><?= money($r['base_rent'], true) ?></td>
  <td class="text-end"><?= $r['living_area'] && $r['base_rent'] ? num($r['base_rent'] / $r['living_area']) : '' ?></td>
  <td class="text-end"><?= money($r['operating_advance'], true) ?></td>
  <td class="text-end"><?= money($r['heating_advance'], true) ?></td>
  <td class="text-end"><?= money($r['parking_rent'], true) ?></td>
  <td class="text-end fw-semibold"><?= money($tot, true) ?></td>
  <?= $r['lid'] ? amount_cell($bal) : '<td></td>' ?>
 </tr>
<?php endforeach; ?>
</tbody>
<tfoot class="fw-semibold"><tr><td colspan="2">Summe</td><td class="text-end"><?= num($sum['area']) ?></td><td colspan="3"></td><td class="text-end"><?= money($sum['base']) ?></td><td class="text-end"><?= $sum['area'] ? num($sum['base'] / $sum['area']) : '' ?></td><td class="text-end"><?= money($sum['nk']) ?></td><td class="text-end"><?= money($sum['hk']) ?></td><td class="text-end"><?= money($sum['stp']) ?></td><td class="text-end"><?= money(array_sum($sum) - $sum['area']) ?></td><td></td></tr></tfoot>
</table></div>
<p class="small text-body-secondary">Wurden Heiz- und Betriebskostenvorauszahlung nicht getrennt vereinbart, steht der Gesamtbetrag unter NK-VZ. Die €/m² beziehen sich auf die Wohnfläche lt. Plan.</p>
<?php page_foot();
