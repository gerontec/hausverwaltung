<?php
// public read-only view of one shared facility record: ?p=share&k=KEY[&doc=ID]
// no login; the key decides which record (and its facility children) is visible
$E = entities();
$s = share_resolve((string)($_GET['k'] ?? ''));
if (!$s) {
    auth_log('FAIL', 'share');
    http_response_code(404);
    exit('Link ungültig oder abgelaufen.');
}
$e = $s['entity']; $id = (int)$s['entity_id'];
$def = $E[$e];
$rec = row("SELECT * FROM `$e` WHERE id=?", [$id]);
if (!$rec) { http_response_code(404); exit('Datensatz nicht mehr vorhanden.'); }

// all records visible through this key: the record itself + children (+ grandchildren for plans -> logs)
$visible = [[$e, $id]];
$children = [];
foreach (SHARE_ENTITIES[$e] as [$ce, $cfk]) {
    $cr = rows("SELECT * FROM `$ce` WHERE `$cfk`=? ORDER BY {$E[$ce]['order']} LIMIT 200", [$id]);
    $children[] = [$ce, $cfk, $cr];
    foreach ($cr as $r) {
        $visible[] = [$ce, (int)$r['id']];
        foreach (SHARE_ENTITIES[$ce] ?? [] as [$ge, $gfk])
            foreach (rows("SELECT id FROM `$ge` WHERE `$gfk`=?", [$r['id']]) as $g) $visible[] = [$ge, (int)$g['id']];
    }
}
$docs = [];
foreach ($visible as [$ve, $vid])
    foreach (rows("SELECT * FROM document WHERE entity=? AND entity_id=? ORDER BY uploaded_at", [$ve, $vid]) as $d) $docs[$d['id']] = $d + ['_e' => $ve];

// document download, only documents attached to visible records
if (isset($_GET['doc'])) {
    $d = $docs[(int)$_GET['doc']] ?? null;
    $path = $d ? UPLOAD_DIR . "/{$d['entity']}/{$d['entity_id']}/{$d['stored_name']}" : null;
    if (!$d || !is_file($path)) { http_response_code(404); exit('Dokument nicht gefunden'); }
    header('Content-Type: ' . ($d['mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode($d['filename']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
$k = h($_GET['k']);

function share_value(array $f, mixed $v, string $k): string {
    [$label, $type] = $f; $x = $f[2] ?? null;
    return match ($type) {
        'fk' => h(share_fk_label($x, $v)),
        'date' => dmy($v),
        'bool' => $v ? 'ja' : 'nein',
        'num' => num($v),
        'int' => h($v),
        'enum' => h($x[$v] ?? $v),
        'textarea' => '<span style="white-space:pre-wrap">' . h($v) . '</span>',
        default => h($v),
    };
}
function share_fields(array $fields): array {
    return array_filter($fields, fn($f, $k) => !in_array($f[1], SHARE_HIDDEN_TYPES, true) && !in_array($k, SHARE_HIDDEN_FIELDS, true), ARRAY_FILTER_USE_BOTH);
}
$title = $def['one'] . ': ' . (fk_options($e)[$id] ?? "#$id");
?><!doctype html>
<html lang="de" data-bs-theme="auto">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?></title>
<link rel="stylesheet" href="assets/bootstrap.min.css">
<link rel="stylesheet" href="assets/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/app.css">
<script>(()=>{const m=window.matchMedia('(prefers-color-scheme: dark)');const s=()=>document.documentElement.setAttribute('data-bs-theme',m.matches?'dark':'light');s();m.addEventListener('change',s);})();</script>
</head>
<body>
<nav class="navbar bg-body-tertiary border-bottom"><div class="container">
 <span class="navbar-brand fw-semibold"><i class="bi <?= $def['icon'] ?> me-1"></i><?= h($title) ?></span>
 <span class="badge text-bg-secondary"><i class="bi bi-eye"></i> nur lesen<?= $s['expires_at'] ? ' · gültig bis ' . dmy($s['expires_at']) : '' ?></span>
</div></nav>
<main class="container py-3">
<div class="row g-3">
 <div class="col-lg-7"><div class="card"><div class="card-body"><dl class="row mb-0">
 <?php foreach (share_fields($def['fields']) as $fk => $f): $v = $rec[$fk] ?? null; if ($v === null || $v === '' || ($f[1] === 'fk' && share_fk_label($f[2], $v) === '')) continue; ?>
  <dt class="col-sm-4 text-body-secondary fw-normal"><?= h($f[0]) ?></dt><dd class="col-sm-8"><?= share_value($f, $v, $fk) ?></dd>
 <?php endforeach; ?>
 </dl></div></div></div>

 <div class="col-lg-5"><div class="card"><div class="card-header"><i class="bi bi-paperclip me-1"></i>Dokumente / Fotos</div><div class="card-body">
 <?php if (!$docs): ?><span class="text-body-secondary">keine</span><?php endif; ?>
 <div class="row g-2">
 <?php foreach ($docs as $did => $d): $u = "?p=share&k=$k&doc=$did"; ?>
  <?php if (str_starts_with((string)$d['mime'], 'image/')): ?>
   <div class="col-6"><a href="<?= $u ?>" target="_blank"><img src="<?= $u ?>" class="img-fluid rounded border" loading="lazy" alt="<?= h($d['title'] ?: $d['filename']) ?>"></a></div>
  <?php else: ?>
   <div class="col-12"><a href="<?= $u ?>" target="_blank"><i class="bi bi-file-earmark me-1"></i><?= h($d['title'] ?: $d['filename']) ?></a></div>
  <?php endif; ?>
 <?php endforeach; ?>
 </div></div></div></div>

 <?php foreach ($children as [$ce, $cfk, $crow]): if (!$crow) continue;
     $cdef = $E[$ce];
     $cols = array_values(array_filter($cdef['list'], fn($c) => $c !== $cfk && isset(share_fields($cdef['fields'])[$c]))); ?>
 <div class="col-12"><div class="card"><div class="card-header"><i class="bi <?= $cdef['icon'] ?> me-1"></i><?= h($cdef['title']) ?></div>
  <div class="table-responsive"><table class="table table-sm mb-0">
   <thead><tr><?php foreach ($cols as $c): ?><th><?= h($cdef['fields'][$c][0]) ?></th><?php endforeach; ?></tr></thead>
   <tbody>
   <?php foreach ($crow as $r): ?>
    <tr><?php foreach ($cols as $c): ?><td><?= $c === 'status' ? status_badge((string)$r[$c]) : share_value($cdef['fields'][$c], $r[$c], $c) ?></td><?php endforeach; ?></tr>
    <?php foreach (SHARE_ENTITIES[$ce] ?? [] as [$ge, $gfk]):
        $gdef = $E[$ge];
        $gcols = array_values(array_filter($gdef['list'], fn($c) => $c !== $gfk && isset(share_fields($gdef['fields'])[$c])));
        foreach (rows("SELECT * FROM `$ge` WHERE `$gfk`=? ORDER BY {$gdef['order']}", [$r['id']]) as $g): ?>
     <tr class="small text-body-secondary"><td colspan="<?= count($cols) ?>"><i class="bi bi-arrow-return-right me-1"></i><?= h($gdef['one']) ?>:
      <?= implode(' · ', array_filter(array_map(fn($c) => $g[$c] === null || $g[$c] === '' ? '' : share_value($gdef['fields'][$c], $g[$c], $c), $gcols))) ?></td></tr>
    <?php endforeach; endforeach; ?>
   <?php endforeach; ?>
   </tbody>
  </table></div></div></div>
 <?php endforeach; ?>
</div>
<p class="small text-body-secondary mt-3 mb-0">Freigegebene Ansicht, nur lesen. Bitte den Link nicht weitergeben.</p>
</main>
</body></html>
<?php exit;
