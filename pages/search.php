<?php
$qq = trim($_GET['q'] ?? '');
$like = "%$qq%";
$res = [];
if ($qq !== '') {
    foreach (rows("SELECT id, " . PERSON_LABEL_SQL . " l, CONCAT_WS(' ', IF(is_tenant,'Mieter',NULL), IF(is_owner,'Eigentümer',NULL), IF(is_vendor,trade,NULL)) d FROM person WHERE CONCAT_WS(' ',company,first_name,last_name,email,phone,iban,trade) LIKE ? LIMIT 30", [$like]) as $r) $res[] = ['person', $r['id'], $r['l'], $r['d']];
    foreach (rows("SELECT id, CONCAT(no,' ',COALESCE(floor,''),' ',COALESCE(position,'')) l, ext_ref d FROM unit WHERE CONCAT_WS(' ',no,floor,position,ext_ref,note) LIKE ? LIMIT 30", [$like]) as $r) $res[] = ['unit', $r['id'], $r['l'], $r['d']];
    foreach (rows("SELECT id, title l, status d FROM ticket WHERE CONCAT_WS(' ',title,description) LIKE ? LIMIT 30", [$like]) as $r) $res[] = ['ticket', $r['id'], $r['l'], $r['d']];
    foreach (rows("SELECT id, CONCAT(DATE_FORMAT(invoice_date,'%d.%m.%Y'),' ',FORMAT(amount,2,'de_DE'),' €') l, text d FROM expense WHERE CONCAT_WS(' ',text,invoice_no) LIKE ? ORDER BY invoice_date DESC LIMIT 30", [$like]) as $r) $res[] = ['expense', $r['id'], $r['l'], $r['d']];
    foreach (rows("SELECT id, title l, legal_basis d FROM maintenance_plan WHERE CONCAT_WS(' ',title,note,legal_basis) LIKE ? LIMIT 30", [$like]) as $r) $res[] = ['maintenance_plan', $r['id'], $r['l'], $r['d']];
    foreach (rows("SELECT id, CONCAT(DATE_FORMAT(booking_date,'%d.%m.%Y'),' ',FORMAT(amount,2,'de_DE'),' €') l, CONCAT(counterparty,' – ',LEFT(purpose,80)) d FROM bank_tx WHERE CONCAT_WS(' ',counterparty,purpose) LIKE ? ORDER BY booking_date DESC LIMIT 30", [$like]) as $r) $res[] = ['bank', $r['id'], $r['l'], $r['d']];
}
$E = entities();
require_once APP_ROOT . '/lib/mayan.php';
$mAll = !empty($_GET['mall']);
$mRes = []; $mErr = null;
if ($qq !== '' && empty($_GET['nomayan'])) {
    try { $mRes = mayan_search($qq, $mAll, 20); } catch (Throwable $e) { $mErr = $e->getMessage(); }
}
$inHv = $mRes ? array_column(rows("SELECT mayan_id, id, status, assigned_entity, assigned_id FROM inbox_doc WHERE mayan_id IN (" . implode(',', array_map(fn($d) => (int)$d['id'], $mRes)) . ")"), null, 'mayan_id') : [];
page_head('Suche'); ?>
<h1 class="h3 mb-3">Suche: „<?= h($qq) ?>“ <small class="text-body-secondary fs-6"><?= count($res) ?> Treffer</small></h1>
<div class="list-group">
<?php foreach ($res as [$e, $id, $l, $d]): ?>
 <a class="list-group-item list-group-item-action" href="<?= $e === 'bank' ? url('bank', ['status' => 'all', 'q' => $qq]) : url('edit', ['e' => $e, 'id' => $id]) ?>">
  <span class="badge text-bg-secondary me-2"><?= h($e === 'bank' ? 'Bank' : $E[$e]['one']) ?></span><?= h($l) ?> <small class="text-body-secondary ms-2"><?= h($d) ?></small></a>
<?php endforeach; ?>
</div>
<div class="d-flex align-items-center mt-4 mb-2 gap-3"><h2 class="h5 mb-0"><i class="bi bi-archive me-1"></i>Mayan-Volltext <small class="text-body-secondary fs-6"><?= count($mRes) ?> Treffer<?= $mAll ? ' (alle Aktenschränke)' : ' (Aktenschrank der Liegenschaft)' ?></small></h2>
 <a class="small" href="<?= url('search', ['q' => $qq, 'mall' => $mAll ? 0 : 1]) ?>"><?= $mAll ? 'nur Liegenschaft' : 'in ganz Mayan suchen' ?></a></div>
<?php if ($mErr): ?><div class="alert alert-warning small"><?= h($mErr) ?></div><?php endif; ?>
<div class="list-group">
<?php foreach ($mRes as $d): $hv = $inHv[$d['id']] ?? null; ?>
 <div class="list-group-item d-flex gap-2 align-items-center">
  <span class="badge text-bg-secondary">Mayan #<?= (int)$d['id'] ?></span>
  <a class="me-auto" href="<?= url('mayan', ['file' => $d['id']]) ?>" target="_blank"><?= h($d['label']) ?></a>
  <small class="text-body-secondary"><?= h(implode(', ', array_map(fn($c) => preg_replace('/^Immobilien \/ /', '', $c), $d['cabinets']))) ?> · <?= $d['created'] ? date('d.m.Y', strtotime($d['created'])) : '' ?></small>
  <?php if ($hv && $hv['status'] === 'assigned'): ?><a class="btn btn-sm btn-outline-success py-0" href="<?= url('edit', ['e' => $hv['assigned_entity'], 'id' => $hv['assigned_id']]) ?>">in HV</a>
  <?php else: ?><form method="post" action="?p=mayan"><?= csrf_field() ?><input type="hidden" name="_back" value="<?= h($_SERVER['REQUEST_URI']) ?>"><button class="btn btn-sm btn-outline-primary py-0" name="import" value="<?= (int)$d['id'] ?>" title="in den Posteingang übernehmen und zuordnen"><i class="bi bi-inbox"></i> übernehmen</button></form><?php endif; ?>
 </div>
<?php endforeach; ?>
</div>
<?php page_foot();
