<?php
$today = date('Y-m-d');
$flats = (int)val("SELECT COUNT(*) FROM unit WHERE kind='apartment'");
$occupied = rows("SELECT u.id, u.no FROM unit u WHERE u.kind='apartment' AND EXISTS (SELECT 1 FROM lease l WHERE l.unit_id=u.id AND (l.start_date IS NULL OR l.start_date<=CURDATE()) AND (l.end_date IS NULL OR l.end_date>=CURDATE()))");
$vacant = rows("SELECT u.id, u.no, b.name house, u.floor, u.position, u.living_area FROM unit u LEFT JOIN building b ON b.id=u.building_id WHERE u.kind='apartment' AND NOT EXISTS (SELECT 1 FROM lease l WHERE l.unit_id=u.id AND (l.start_date IS NULL OR l.start_date<=CURDATE()) AND (l.end_date IS NULL OR l.end_date>=CURDATE())) ORDER BY CAST(u.no AS UNSIGNED)");
$rentSoll = (float)val("SELECT COALESCE(SUM(r.base_rent+r.operating_advance+r.heating_advance+r.parking_rent),0) FROM lease l JOIN lease_rent r ON r.id=(SELECT id FROM lease_rent WHERE lease_id=l.id AND valid_from<=CURDATE() ORDER BY valid_from DESC LIMIT 1) WHERE (l.end_date IS NULL OR l.end_date>=CURDATE())");
$coldSoll = (float)val("SELECT COALESCE(SUM(r.base_rent),0) FROM lease l JOIN lease_rent r ON r.id=(SELECT id FROM lease_rent WHERE lease_id=l.id AND valid_from<=CURDATE() ORDER BY valid_from DESC LIMIT 1) WHERE (l.end_date IS NULL OR l.end_date>=CURDATE())");
$arrears = rows("SELECT l.id, u.no, " . str_replace(['company', 'last_name', 'first_name'], ['p.company', 'p.last_name', 'p.first_name'], PERSON_LABEL_SQL) . " n, l.end_date,
                   SUM(CASE WHEN po.kind='charge' THEN po.amount ELSE -po.amount END) bal
                 FROM lease l JOIN unit u ON u.id=l.unit_id JOIN person p ON p.id=l.tenant_id JOIN posting po ON po.lease_id=l.id
                 GROUP BY l.id HAVING bal > 0.005 ORDER BY bal DESC");
$arrSum = array_sum(array_column($arrears, 'bal'));
$lastSoll = val("SELECT MAX(period) FROM posting WHERE auto_key IS NOT NULL");
$lastImport = val("SELECT MAX(posting_date) FROM posting WHERE source LIKE 'legacy%'");
$openBank = (int)val("SELECT COUNT(*) FROM bank_tx WHERE status='open'");
$lastBank = val("SELECT MAX(booking_date) FROM bank_tx");
$maint = rows("SELECT m.*, b.name house FROM maintenance_plan m LEFT JOIN building b ON b.id=m.building_id WHERE m.active=1 AND (m.next_due IS NULL OR m.next_due <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)) ORDER BY m.next_due IS NULL, m.next_due, m.building_id LIMIT 40");
$maintNoDate = count(array_filter($maint, fn($m) => !$m['next_due']));
$tickets = rows("SELECT t.*, b.name house, u.no unit_no FROM ticket t LEFT JOIN building b ON b.id=t.building_id LEFT JOIN unit u ON u.id=t.unit_id WHERE t.status IN ('open','in_progress','waiting') ORDER BY FIELD(t.priority,'emergency','high','normal','low'), t.created_at LIMIT 15");
$expected = rows("SELECT x.*, " . str_replace(['company', 'last_name', 'first_name'], ['p.company', 'p.last_name', 'p.first_name'], PERSON_LABEL_SQL) . " vendor,
                    (SELECT MAX(e.invoice_date) FROM expense e WHERE e.cost_type_id=x.cost_type_id AND (e.vendor_id=x.vendor_id OR e.vendor_id IS NULL)) last_inv
                  FROM expected_invoice x JOIN person p ON p.id=x.vendor_id WHERE x.active=1 AND x.next_expected <= DATE_ADD(CURDATE(), INTERVAL 45 DAY) ORDER BY x.next_expected");
$leasesEnding = rows("SELECT l.id, u.no, l.end_date, " . str_replace(['company', 'last_name', 'first_name'], ['p.company', 'p.last_name', 'p.first_name'], PERSON_LABEL_SQL) . " n FROM lease l JOIN unit u ON u.id=l.unit_id JOIN person p ON p.id=l.tenant_id WHERE l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) ORDER BY l.end_date");
$calib = rows("SELECT m.*, u.no FROM meter m LEFT JOIN unit u ON u.id=m.unit_id WHERE m.active=1 AND m.calibrated_until IS NOT NULL AND m.calibrated_until <= DATE_ADD(CURDATE(), INTERVAL 180 DAY) ORDER BY m.calibrated_until LIMIT 10");

page_head('Dashboard'); ?>
<div class="d-flex align-items-baseline gap-3 mb-3"><?php $prop = row("SELECT * FROM property ORDER BY id LIMIT 1"); ?><h1 class="h3 mb-0"><?= h(($prop['street'] ?? '') . ' · ' . ($prop['city'] ?? '')) ?></h1><span class="text-body-secondary"><?= dmy($today) ?></span></div>

<?php if (($lastSoll === null || $lastSoll < date('Y-m')) && ($lastSoll !== null || $lastImport !== null)): ?>
<div class="alert alert-warning d-flex align-items-center gap-3"><i class="bi bi-info-circle fs-4"></i>
 <div>Sollstellungen sind bis <b><?= $lastSoll ? date('m/Y', strtotime("$lastSoll-01")) : ($lastImport ? 'Import-Stand ' . dmy($lastImport) : '–') ?></b> gebucht, Bankumsätze bis <b><?= dmy($lastBank) ?: '–' ?></b>.
 <?= $lastImport ? 'Mieten und Mieter seit dem letzten Import (' . dmy($lastImport) . ') bitte prüfen.' : 'Bitte die fehlenden Monate buchen.' ?></div>
 <a class="btn btn-sm btn-warning ms-auto" href="?p=soll">Sollstellung</a></div>
<?php endif; ?>

<div class="row g-3 mb-3">
 <?php foreach ([
   ['Wohnungen', count($occupied) . ' / ' . $flats, 'vermietet' . (count($vacant) ? ' · ' . count($vacant) . ' leer' : ''), 'bi-door-open', url('tenants')],
   ['Sollmiete / Monat', money($rentSoll), 'davon kalt ' . money($coldSoll), 'bi-cash-stack', url('tenants')],
   ['Rückstände', money($arrSum), count($arrears) . ' Konten', 'bi-exclamation-circle', url('op')],
   ['Bank offen', (string)$openBank, 'nicht zugeordnete Umsätze', 'bi-bank', url('bank')],
   ['Vorgänge offen', (string)count($tickets), 'Schäden / Aufträge', 'bi-exclamation-triangle', url('list', ['e' => 'ticket', 'open' => 1])],
 ] as [$t, $v, $s, $i, $href]): ?>
 <div class="col-6 col-md-4 col-xl"><a class="card h-100 text-decoration-none" href="<?= $href ?>"><div class="card-body">
  <div class="text-body-secondary small"><i class="bi <?= $i ?> me-1"></i><?= h($t) ?></div>
  <div class="fs-4 fw-semibold text-body"><?= h($v) ?></div><div class="small text-body-secondary"><?= h($s) ?></div></div></a></div>
 <?php endforeach; ?>
</div>

<div class="row g-3">
 <div class="col-lg-6">
  <div class="card mb-3"><div class="card-header d-flex"><span class="me-auto"><i class="bi bi-calendar2-week me-1"></i>Wartung fällig (60 Tage)</span><a href="?p=maint" class="small">alle</a></div>
   <?php if ($maintNoDate): ?><div class="card-body py-2 small text-warning-emphasis bg-warning-subtle"><?= $maintNoDate ?> Wartungen ohne Termin – bitte „zuletzt“ oder „nächste Fälligkeit“ eintragen.</div><?php endif; ?>
   <ul class="list-group list-group-flush">
   <?php foreach (array_filter($maint, fn($m) => $m['next_due']) as $m): $late = $m['next_due'] < $today; ?>
    <li class="list-group-item d-flex gap-2"><span class="<?= $late ? 'text-danger fw-semibold' : '' ?>" style="min-width:6rem"><?= dmy($m['next_due']) ?></span>
     <a href="<?= url('edit', ['e' => 'maintenance_plan', 'id' => $m['id']]) ?>" class="me-auto"><?= h($m['title']) ?></a><small class="text-body-secondary"><?= h($m['house']) ?></small>
     <a class="btn btn-sm btn-outline-success py-0" href="<?= url('maint', ['done' => $m['id']]) ?>" title="erledigt"><i class="bi bi-check2"></i></a></li>
   <?php endforeach; ?>
   </ul></div>

  <div class="card mb-3"><div class="card-header"><i class="bi bi-calendar-check me-1"></i>Erwartete Rechnungen</div>
   <ul class="list-group list-group-flush">
   <?php foreach ($expected as $x): $late = $x['next_expected'] < $today; ?>
    <li class="list-group-item d-flex gap-2"><span class="<?= $late ? 'text-danger fw-semibold' : '' ?>" style="min-width:6rem"><?= dmy($x['next_expected']) ?></span>
     <a class="me-auto" href="<?= url('edit', ['e' => 'expected_invoice', 'id' => $x['id']]) ?>"><?= h($x['title']) ?></a>
     <small class="text-body-secondary"><?= h($x['vendor']) ?><?= $x['last_inv'] ? ' · zuletzt ' . dmy($x['last_inv']) : '' ?></small>
     <a class="btn btn-sm btn-outline-primary py-0" href="<?= url('edit', ['e' => 'expense', 'cost_type_id' => $x['cost_type_id'], 'vendor_id' => $x['vendor_id'], 'building_id' => $x['building_id'], 'back' => url('maint', ['expected' => $x['id']])]) ?>" title="Rechnung erfassen"><i class="bi bi-plus"></i></a></li>
   <?php endforeach; if (!$expected): ?><li class="list-group-item text-body-secondary">nichts fällig</li><?php endif; ?>
   </ul></div>

  <?php if ($calib): ?><div class="card mb-3"><div class="card-header"><i class="bi bi-speedometer2 me-1"></i>Eichfristen</div><ul class="list-group list-group-flush">
   <?php foreach ($calib as $m): ?><li class="list-group-item d-flex"><span style="min-width:6rem"><?= dmy($m['calibrated_until']) ?></span><a href="<?= url('edit', ['e' => 'meter', 'id' => $m['id']]) ?>"><?= h(METER_KIND[$m['kind']] . ' ' . $m['number'] . ($m['no'] ? ' · Whg ' . $m['no'] : '')) ?></a></li><?php endforeach; ?>
  </ul></div><?php endif; ?>
 </div>

 <div class="col-lg-6">
  <div class="card mb-3"><div class="card-header d-flex"><span class="me-auto"><i class="bi bi-exclamation-triangle me-1"></i>Offene Vorgänge</span><a class="small" href="<?= url('edit', ['e' => 'ticket']) ?>">+ neu</a></div>
   <ul class="list-group list-group-flush">
   <?php foreach ($tickets as $t): ?>
    <li class="list-group-item d-flex gap-2 align-items-center"><?= prio_badge($t['priority']) ?><a class="me-auto" href="<?= url('edit', ['e' => 'ticket', 'id' => $t['id']]) ?>"><?= h($t['title']) ?></a>
     <small class="text-body-secondary"><?= h(trim($t['house'] . ($t['unit_no'] ? ' · Whg ' . $t['unit_no'] : ''))) ?></small><?= status_badge($t['status']) ?></li>
   <?php endforeach; if (!$tickets): ?><li class="list-group-item text-body-secondary">keine</li><?php endif; ?>
   </ul></div>

  <div class="card mb-3"><div class="card-header d-flex"><span class="me-auto"><i class="bi bi-exclamation-circle me-1"></i>Rückstände</span><a href="?p=op" class="small">Offene Posten</a></div>
   <div class="table-responsive" style="max-height:20rem"><table class="table table-sm mb-0">
   <?php foreach ($arrears as $a): ?><tr><td>Whg <?= h($a['no']) ?></td><td><a href="<?= url('account', ['lease' => $a['id']]) ?>"><?= h($a['n']) ?></a><?= $a['end_date'] && $a['end_date'] < $today ? ' <span class="badge text-bg-secondary">ausgezogen</span>' : '' ?></td><?= amount_cell((float)$a['bal']) ?></tr><?php endforeach; ?>
   </table></div></div>

  <?php if ($vacant || $leasesEnding): ?><div class="card mb-3"><div class="card-header"><i class="bi bi-door-closed me-1"></i>Leerstand / Kündigungen</div><ul class="list-group list-group-flush">
   <?php foreach ($vacant as $v): ?><li class="list-group-item d-flex"><a class="me-auto" href="<?= url('edit', ['e' => 'unit', 'id' => $v['id']]) ?>">Whg <?= h($v['no']) ?> · <?= h($v['house'] . ' ' . $v['floor'] . ' ' . $v['position']) ?></a><span><?= num($v['living_area']) ?> m² <?= badge('leer', 'warning') ?></span></li><?php endforeach; ?>
   <?php foreach ($leasesEnding as $l): ?><li class="list-group-item d-flex"><a class="me-auto" href="<?= url('edit', ['e' => 'lease', 'id' => $l['id']]) ?>">Whg <?= h($l['no']) ?> · <?= h($l['n']) ?></a><span>Auszug <?= dmy($l['end_date']) ?></span></li><?php endforeach; ?>
  </ul></div><?php endif; ?>
 </div>
</div>
<?php page_foot();
