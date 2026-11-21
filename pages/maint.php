<?php
// maintenance overview, "done" form, advancing expected invoices
if (is_post() && ($_POST['_action'] ?? '') === 'done') {
    $pl = row("SELECT * FROM maintenance_plan WHERE id=?", [(int)$_POST['plan']]);
    $d = parse_date($_POST['date'] ?? '') ?? date('Y-m-d');
    $cost = parse_num($_POST['cost'] ?? '');
    $vendor = (int)($_POST['vendor_id'] ?? 0) ?: null;
    $expId = null;
    if ($cost && !empty($_POST['cost_type_id'])) {
        q("INSERT INTO expense (property_id, building_id, cost_type_id, vendor_id, invoice_date, period_from, period_to, amount, text, source) VALUES (1,?,?,?,?,?,?,?,?, 'wartung')",
          [$pl['building_id'], (int)$_POST['cost_type_id'], $vendor, $d, substr($d, 0, 4) . '-01-01', substr($d, 0, 4) . '-12-31', $cost, $pl['title']]);
        $expId = (int)db()->lastInsertId();
    }
    q("INSERT INTO maintenance_log (plan_id, done_date, vendor_id, cost, result, expense_id) VALUES (?,?,?,?,?,?)", [$pl['id'], $d, $vendor, $cost, trim($_POST['result'] ?? '') ?: null, $expId]);
    $next = date('Y-m-d', strtotime("$d +{$pl['interval_months']} months"));
    q("UPDATE maintenance_plan SET last_done=?, next_due=?, vendor_id=COALESCE(?, vendor_id) WHERE id=?", [$d, $next, $vendor, $pl['id']]);
    if (!empty($_POST['all_houses'])) {
        // same task in the other houses done on the same day
        foreach (rows("SELECT id FROM maintenance_plan WHERE title=? AND id<>? AND active=1", [$pl['title'], $pl['id']]) as $o) {
            q("INSERT INTO maintenance_log (plan_id, done_date, vendor_id, result) VALUES (?,?,?,?)", [$o['id'], $d, $vendor, 'zusammen mit ' . $pl['id']]);
            q("UPDATE maintenance_plan SET last_done=?, next_due=? WHERE id=?", [$d, $next, $o['id']]);
        }
    }
    flash('Erledigt eingetragen, nächste Fälligkeit ' . dmy($next) . ($expId ? ', Ausgabe angelegt' : '') . '.');
    redirect(url('maint'));
}
if (is_post() && ($_POST['_action'] ?? '') === 'expected') {
    $x = row("SELECT * FROM expected_invoice WHERE id=?", [(int)$_POST['id']]);
    $n = date('Y-m-d', strtotime("{$x['next_expected']} +{$x['interval_months']} months"));
    q("UPDATE expected_invoice SET next_expected=? WHERE id=?", [$n, $x['id']]);
    flash(h($x['title']) . ': nächste erwartet ' . dmy($n) . '.');
    redirect(url('dashboard'));
}
if (isset($_GET['expected'])) {
    $x = row("SELECT * FROM expected_invoice WHERE id=?", [(int)$_GET['expected']]);
    page_head('Erwartete Rechnung'); ?>
    <div class="card col-md-6"><div class="card-body"><p>Rechnung „<?= h($x['title']) ?>“ ist erfasst. Nächste Fälligkeit weiterschieben auf <b><?= dmy(date('Y-m-d', strtotime("{$x['next_expected']} +{$x['interval_months']} months"))) ?></b>?</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="expected"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button class="btn btn-primary">Ja, weiterschieben</button> <a class="btn btn-outline-secondary" href="?p=dashboard">Nein</a></form></div></div>
    <?php page_foot(); return;
}
if (isset($_GET['done'])) {
    $pl = row("SELECT m.*, b.name house FROM maintenance_plan m LEFT JOIN building b ON b.id=m.building_id WHERE m.id=?", [(int)$_GET['done']]);
    $others = (int)val("SELECT COUNT(*) FROM maintenance_plan WHERE title=? AND id<>? AND active=1", [$pl['title'], $pl['id']]);
    page_head('Wartung erledigt'); ?>
    <h1 class="h4 mb-3"><?= h($pl['title']) ?> · <?= h($pl['house']) ?></h1>
    <form method="post" class="card col-lg-7"><div class="card-body row g-3"><?= csrf_field() ?><input type="hidden" name="_action" value="done"><input type="hidden" name="plan" value="<?= $pl['id'] ?>">
     <div class="col-md-4"><label class="form-label small">erledigt am</label><input class="form-control" type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
     <div class="col-md-8"><label class="form-label small">Dienstleister</label><select class="form-select" name="vendor_id"><option value="">–</option><?php foreach (rows("SELECT id, " . PERSON_LABEL_SQL . " l FROM person WHERE is_vendor=1 ORDER BY l") as $v): ?><option value="<?= $v['id'] ?>" <?= $v['id'] == $pl['vendor_id'] ? 'selected' : '' ?>><?= h($v['l']) ?></option><?php endforeach; ?></select></div>
     <div class="col-12"><label class="form-label small">Ergebnis / Mängel</label><input class="form-control" name="result" placeholder="ohne Befund"></div>
     <div class="col-md-4"><label class="form-label small">Kosten (optional)</label><input class="form-control text-end" name="cost" inputmode="decimal"></div>
     <div class="col-md-8"><label class="form-label small">als Ausgabe buchen unter</label><select class="form-select" name="cost_type_id"><option value="">– keine Ausgabe –</option><?php foreach (fk_options('cost_type') as $k => $v): ?><option value="<?= $k ?>"><?= h($v) ?></option><?php endforeach; ?></select></div>
     <?php if ($others): ?><div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" name="all_houses" value="1" id="ah" checked><label class="form-check-label" for="ah">gleiche Aufgabe in den anderen <?= $others ?> Häusern ebenfalls erledigt</label></div><?php endif; ?>
     <div class="col-12 small text-body-secondary">Nächste Fälligkeit: + <?= (int)$pl['interval_months'] ?> Monate. Prüfprotokoll bitte im Wartungsplan als Dokument anhängen.</div>
    </div><div class="card-footer"><button class="btn btn-success"><i class="bi bi-check2-square"></i> erledigt</button></div></form>
    <?php page_foot(); return;
}
$plans = rows("SELECT m.*, b.name house, " . str_replace(['company', 'last_name', 'first_name'], ['p.company', 'p.last_name', 'p.first_name'], PERSON_LABEL_SQL) . " vendor
               FROM maintenance_plan m LEFT JOIN building b ON b.id=m.building_id LEFT JOIN person p ON p.id=m.vendor_id WHERE m.active=1 ORDER BY m.title, b.name");
$by = [];
foreach ($plans as $p) $by[$p['title']][] = $p;
$today = date('Y-m-d');
page_head('Wartung'); ?>
<div class="d-flex align-items-center mb-3"><h1 class="h3 mb-0 me-auto"><i class="bi bi-calendar2-week me-2"></i>Wartung und Prüfungen</h1>
 <a class="btn btn-sm btn-primary" href="<?= url('edit', ['e' => 'maintenance_plan']) ?>"><i class="bi bi-plus-lg"></i> Aufgabe</a></div>
<div class="table-responsive"><table class="table table-sm align-middle">
<thead class="table-light"><tr><th>Aufgabe</th><th>Grundlage</th><th class="text-end">Intervall</th><?php foreach (fk_options('building') as $b): ?><th class="text-center"><?= h($b) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($by as $title => $ps): $hs = array_column($ps, null, 'building_id'); ?>
 <tr><td><?= h($title) ?></td><td class="small text-body-secondary"><?= h($ps[0]['legal_basis']) ?></td><td class="text-end"><?= (int)$ps[0]['interval_months'] ?> M.</td>
 <?php foreach (fk_options('building') as $bid => $b): $p = $hs[$bid] ?? null; ?>
  <td class="text-center"><?php if ($p): $cls = !$p['next_due'] ? 'secondary' : ($p['next_due'] < $today ? 'danger' : ($p['next_due'] < date('Y-m-d', strtotime('+60 days')) ? 'warning' : 'success')); ?>
   <a class="badge text-bg-<?= $cls ?> text-decoration-none" href="<?= url('edit', ['e' => 'maintenance_plan', 'id' => $p['id']]) ?>"><?= $p['next_due'] ? dmy($p['next_due']) : 'kein Termin' ?></a>
   <a class="ms-1" href="<?= url('maint', ['done' => $p['id']]) ?>" title="erledigt"><i class="bi bi-check2-square"></i></a><?php endif; ?></td>
 <?php endforeach; ?></tr>
<?php endforeach; ?></tbody></table></div>
<h2 class="h5 mt-4">Zuletzt erledigt</h2>
<table class="table table-sm"><tbody>
<?php foreach (rows("SELECT g.*, m.title, b.name house FROM maintenance_log g JOIN maintenance_plan m ON m.id=g.plan_id LEFT JOIN building b ON b.id=m.building_id ORDER BY g.done_date DESC, g.id DESC LIMIT 20") as $g): ?>
 <tr><td><?= dmy($g['done_date']) ?></td><td><?= h($g['title']) ?></td><td><?= h($g['house']) ?></td><td><?= h($g['result']) ?></td><td class="text-end"><?= money($g['cost'], true) ?></td></tr>
<?php endforeach; ?></tbody></table>
<?php page_foot();
