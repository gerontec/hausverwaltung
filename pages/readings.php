<?php
// bulk entry of meter readings for one date
if (is_post()) {
    $d = parse_date($_POST['date'] ?? '');
    $reason = $_POST['reason'] ?? 'regular';
    $n = 0;
    foreach ($_POST['v'] ?? [] as $mid => $v) {
        $val = parse_num($v);
        if ($val === null || !$d) continue;
        q("INSERT INTO meter_reading (meter_id, reading_date, value, reason) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value), reason=VALUES(reason)", [(int)$mid, $d, $val, $reason]);
        $n++;
    }
    flash("$n Zählerstände gespeichert.");
    redirect(url('readings', ['date' => $d, 'house' => $_POST['house'] ?? '']));
}
$d = parse_date($_GET['date'] ?? '') ?? date('Y-12-31', strtotime('-1 year'));
$house = (int)($_GET['house'] ?? 0);
$ms = rows("SELECT m.*, u.no, b.name house,
     (SELECT CONCAT(reading_date,'|',value) FROM meter_reading r WHERE r.meter_id=m.id AND r.reading_date<? ORDER BY reading_date DESC LIMIT 1) prev,
     (SELECT value FROM meter_reading r WHERE r.meter_id=m.id AND r.reading_date=?) cur
   FROM meter m LEFT JOIN unit u ON u.id=m.unit_id LEFT JOIN building b ON b.id=m.building_id
   WHERE m.active=1 " . ($house ? "AND m.building_id=$house" : '') . " ORDER BY b.name, u.id IS NULL, CAST(u.no AS UNSIGNED), m.kind", [$d, $d]);
page_head('Ablesung'); ?>
<h1 class="h3 mb-3"><i class="bi bi-123 me-2"></i>Zählerstände erfassen</h1>
<form class="d-flex gap-2 mb-3"><input type="hidden" name="p" value="readings">
 <input class="form-control form-control-sm w-auto" type="date" name="date" value="<?= h($d) ?>">
 <select class="form-select form-select-sm w-auto" name="house"><option value="0">alle Häuser</option><?php foreach (fk_options('building') as $k => $v): ?><option value="<?= $k ?>" <?= $house === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
 <button class="btn btn-sm btn-outline-secondary">laden</button></form>
<?php if (!$ms): ?><div class="alert alert-info">Noch keine Zähler angelegt. <a href="<?= url('edit', ['e' => 'meter']) ?>">Zähler anlegen</a> (Hauptzähler: ohne Einheit; Wohnungszähler / Heizkostenverteiler mit Einheit).</div><?php else: ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="date" value="<?= h($d) ?>"><input type="hidden" name="house" value="<?= $house ?>">
<table class="table table-sm align-middle"><thead class="table-light"><tr><th>Haus</th><th>Einheit</th><th>Art</th><th>Zähler</th><th class="text-end">letzter Stand</th><th style="width:10rem">Stand <?= dmy($d) ?></th></tr></thead><tbody>
<?php foreach ($ms as $m): [$pd, $pv] = $m['prev'] ? explode('|', $m['prev']) : [null, null]; ?>
 <tr><td><?= h($m['house']) ?></td><td><?= $m['no'] ? 'Whg ' . h($m['no']) : '<i>Haupt</i>' ?></td><td><?= h(METER_KIND[$m['kind']]) ?></td><td><?= h($m['number'] . ' ' . $m['location']) ?></td>
  <td class="text-end small"><?= $pd ? num($pv, 3) . ' (' . dmy($pd) . ')' : '' ?></td>
  <td><input class="form-control form-control-sm text-end" name="v[<?= $m['id'] ?>]" value="<?= $m['cur'] !== null ? num($m['cur'], 3) : '' ?>" inputmode="decimal"></td></tr>
<?php endforeach; ?></tbody></table>
<div class="d-flex gap-2"><select class="form-select w-auto" name="reason"><?php foreach (READING_REASON as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select><button class="btn btn-primary">speichern</button></div></form>
<?php endif; page_foot();
