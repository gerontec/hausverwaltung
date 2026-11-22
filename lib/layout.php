<?php
// layout.php - Bootstrap 5 page frame
declare(strict_types=1);

const NAV = [
    'Übersicht' => [['dashboard', 'Dashboard', 'bi-speedometer']],
    'Posteingang' => [['inbox', 'Posteingang', 'bi-inbox']],
    'Objekt' => [['list&e=building', 'Häuser', 'bi-house'], ['list&e=unit', 'Einheiten', 'bi-door-open'], ['list&e=weg_unit', 'WEG-Einheiten', 'bi-diagram-3'], ['list&e=person', 'Personen', 'bi-people']],
    'Miete' => [['tenants', 'Mieterliste', 'bi-person-lines-fill'], ['list&e=lease', 'Mietverträge', 'bi-file-earmark-text'], ['soll', 'Sollstellung', 'bi-calendar-plus'], ['op', 'Offene Posten', 'bi-exclamation-circle'], ['bk', 'Betriebskosten&shy;abrechnung', 'bi-file-spreadsheet']],
    'Finanzen' => [['bank', 'Bank', 'bi-bank'], ['list&e=expense', 'Ausgaben', 'bi-receipt'], ['list&e=expected_invoice', 'Erwartete Rechnungen', 'bi-calendar-check'], ['list&e=cost_type', 'Kostenarten', 'bi-tags'], ['list&e=bank_rule', 'Bankregeln', 'bi-funnel']],
    'Reporting' => [['rendite', 'Rendite', 'bi-graph-up-arrow'], ['afa', 'AfA / Anlagevermögen', 'bi-building-down'], ['report', 'Einnahmen/Ausgaben', 'bi-bar-chart'], ['list&e=investment', 'Investitionen (AHK)', 'bi-piggy-bank']],
    'WEG' => [['weg', 'Hausgeld / Abrechnung', 'bi-calculator'], ['list&e=weg_budget', 'Wirtschaftsplan', 'bi-clipboard-data']],
    'Facility' => [['maint', 'Wartung', 'bi-calendar2-week'], ['list&e=ticket', 'Vorgänge / Schäden', 'bi-exclamation-triangle'], ['list&e=asset', 'Anlagen', 'bi-tools'], ['list&e=meter', 'Zähler', 'bi-speedometer2'], ['readings', 'Ablesung erfassen', 'bi-123']],
];

function page_head(string $title): void {
    $cur = $_GET['p'] ?? 'dashboard';
    $curE = $_GET['e'] ?? null;
    ?><!doctype html>
<html lang="de" data-bs-theme="auto">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Hausverwaltung</title>
<link rel="stylesheet" href="assets/bootstrap.min.css">
<link rel="stylesheet" href="assets/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/app.css">
<script>
(()=>{const m=window.matchMedia('(prefers-color-scheme: dark)');const s=()=>document.documentElement.setAttribute('data-bs-theme',m.matches?'dark':'light');s();m.addEventListener('change',s);})();
</script>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom d-print-none sticky-top">
 <div class="container-fluid">
  <a class="navbar-brand fw-semibold" href="?p=dashboard"><i class="bi bi-buildings me-1"></i><?= h(current_user() ? (val("SELECT name FROM property ORDER BY id LIMIT 1") ?? 'Hausverwaltung') : 'Hausverwaltung') ?></a>
  <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
  <div class="collapse navbar-collapse" id="nav">
   <ul class="navbar-nav me-auto">
   <?php foreach (NAV as $grp => $items):
       $active = false;
       foreach ($items as [$p]) { parse_str('p=' . $p, $pp); if ($pp['p'] === $cur && (($pp['e'] ?? null) === $curE)) $active = true; }
       if (count($items) === 1): [$p, $label, $icon] = $items[0]; ?>
     <li class="nav-item"><a class="nav-link<?= $active ? ' active' : '' ?>" href="?p=<?= $p ?>"><i class="bi <?= $icon ?> me-1"></i><?= $label ?></a></li>
   <?php else: ?>
     <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle<?= $active ? ' active' : '' ?>" href="#" data-bs-toggle="dropdown"><?= $grp ?></a>
      <ul class="dropdown-menu">
      <?php foreach ($items as [$p, $label, $icon]): ?>
        <li><a class="dropdown-item" href="?p=<?= $p ?>"><i class="bi <?= $icon ?> me-2"></i><?= $label ?></a></li>
      <?php endforeach; ?>
      </ul>
     </li>
   <?php endif; endforeach; ?>
   </ul>
   <form class="d-flex me-2" role="search" method="get"><input type="hidden" name="p" value="search">
     <input class="form-control form-control-sm" type="search" name="q" placeholder="Suche …" value="<?= h($_GET['q'] ?? '') ?>"></form>
   <?php if (current_user()): ?><a class="btn btn-sm btn-outline-secondary me-1" href="?p=users" title="Benutzer / Passwort"><i class="bi bi-person-gear"></i> <?= h(current_user()) ?></a><a class="btn btn-sm btn-outline-secondary" href="?p=logout" title="Abmelden"><i class="bi bi-box-arrow-right"></i></a><?php endif; ?>
  </div>
 </div>
</nav>
<main class="container-fluid py-3">
<?php foreach (flash() as [$t, $m]): ?>
 <div class="alert alert-<?= h($t) ?> alert-dismissible fade show" role="alert"><?= $m ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endforeach;
}

function page_foot(): void { ?>
</main>
<script src="assets/bootstrap.bundle.min.js"></script>
<script src="assets/app.js"></script>
</body></html>
<?php }

function badge(string $text, string $color = 'secondary'): string {
    return '<span class="badge text-bg-' . $color . '">' . h($text) . '</span>';
}
function status_badge(string $s): string {
    $c = ['open' => 'danger', 'in_progress' => 'primary', 'waiting' => 'warning', 'done' => 'success', 'rejected' => 'secondary', 'booked' => 'success', 'ignored' => 'secondary'][$s] ?? 'secondary';
    return badge(TICKET_STATUS[$s] ?? ['booked' => 'gebucht', 'ignored' => 'ignoriert'][$s] ?? $s, $c);
}
function prio_badge(string $p): string {
    return badge(PRIORITY[$p] ?? $p, ['emergency' => 'danger', 'high' => 'warning', 'normal' => 'info', 'low' => 'secondary'][$p] ?? 'secondary');
}
function amount_cell(float $v): string {
    $cls = $v > 0.005 ? 'text-danger' : ($v < -0.005 ? 'text-success' : 'text-body-secondary');
    return '<td class="text-end text-nowrap ' . $cls . '">' . money($v) . '</td>';
}
