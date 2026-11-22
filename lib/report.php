<?php
// report.php - reporting helpers: allocation of acquisition costs to units, depreciation (AfA), yields
declare(strict_types=1);

/** apartments of the property, keyed by unit id; $withParking adds parking spaces / garages that belong to a WEG unit
    (the planned layout; old rental accounts without WEG unit are left out to avoid counting a space twice) */
function report_units(bool $withParking = false): array {
    $out = [];
    $where = $withParking ? "(u.kind='apartment' OR (u.kind IN ('parking','garage') AND u.weg_unit_id IS NOT NULL))" : "u.kind='apartment'";
    foreach (rows("SELECT u.*, b.name house, w.no weg_no, w.owner_id,
                          (SELECT " . PERSON_LABEL_SQL . " FROM person WHERE id=w.owner_id) owner
                     FROM unit u LEFT JOIN building b ON b.id=u.building_id LEFT JOIN weg_unit w ON w.id=u.weg_unit_id
                    WHERE $where ORDER BY u.kind='apartment' DESC, u.building_id, u.kind='garage', CAST(u.no AS UNSIGNED), u.id") as $u) $out[(int)$u['id']] = $u;
    return $out;
}

function unit_weight(array $u): float {
    foreach (['living_area', 'net_area'] as $k) if ((float)($u[$k] ?? 0) > 0) return (float)$u[$k];
    return 1.0;
}

/** target units of one investment: listed unit ids (any kind), else apartments of the building, else of the property */
function investment_targets(array $inv, array $units): array {
    $ids = array_filter(array_map('intval', preg_split('/[\s,;]+/', (string)($inv['unit_ids'] ?? ''))));
    if ($ids) return array_values(array_intersect($ids, array_keys($units)));
    return array_keys(array_filter($units, fn($u) => $u['kind'] === 'apartment' && (int)$u['property_id'] === (int)$inv['property_id']
        && (!$inv['building_id'] || (int)$u['building_id'] === (int)$inv['building_id'])));
}

/** share of every investment per unit by living area: [unit_id => [inv_id => amount]] */
function investment_alloc(array $invs, array $units): array {
    $res = [];
    foreach ($invs as $inv) {
        $t = investment_targets($inv, $units);
        $W = array_sum(array_map(fn($id) => unit_weight($units[$id]), $t));
        if ($W <= 0) continue;
        foreach ($t as $id) $res[$id][(int)$inv['id']] = (float)$inv['amount'] * unit_weight($units[$id]) / $W;
    }
    return $res;
}

/** linear AfA (§ 7 Abs. 1, 4 EStG) of one investment in a year, pro rata by month in the first year */
function afa_year(array $inv, int $year): float {
    $rate = (float)$inv['afa_rate'] / 100;
    if ($rate <= 0) return 0.0;
    $y0 = (int)substr($inv['acquired_date'], 0, 4);
    if ($year < $y0) return 0.0;
    $amount = (float)$inv['amount'];
    $before = afa_until($inv, $year - 1);
    $full = $amount * $rate * ($year === $y0 ? (13 - (int)substr($inv['acquired_date'], 5, 2)) / 12 : 1);
    return max(0.0, min($full, $amount - $before));
}

/** accumulated AfA up to the end of a year */
function afa_until(array $inv, int $year): float {
    $rate = (float)$inv['afa_rate'] / 100;
    $y0 = (int)substr($inv['acquired_date'], 0, 4);
    if ($rate <= 0 || $year < $y0) return 0.0;
    $amount = (float)$inv['amount'];
    $first = $amount * $rate * (13 - (int)substr($inv['acquired_date'], 5, 2)) / 12;
    return min($amount, $first + $amount * $rate * ($year - $y0));
}

/** current lease of a unit on a date and its rent row */
function unit_rent(int $unitId, string $date): ?array {
    $l = row("SELECT l.*, " . str_replace(['company', 'last_name', 'first_name'], ['p.company', 'p.last_name', 'p.first_name'], PERSON_LABEL_SQL) . " tenant
                FROM lease l LEFT JOIN person p ON p.id=l.tenant_id
               WHERE l.unit_id=? AND (l.start_date IS NULL OR l.start_date<=?) AND (l.end_date IS NULL OR l.end_date>=?)
               ORDER BY l.start_date DESC LIMIT 1", [$unitId, $date, $date]);
    if (!$l) return null;
    $r = rent_at((int)$l['id'], $date);
    return ['lease' => $l, 'rent' => $r];
}

/** charged cold rent (Soll, category rent) per unit in a year */
function rent_charged(int $year): array {
    return array_map('floatval', array_column(rows("SELECT l.unit_id, SUM(p.amount) s FROM posting p JOIN lease l ON l.id=p.lease_id
        WHERE p.kind='charge' AND p.category='rent' AND YEAR(p.posting_date)=? GROUP BY l.unit_id", [$year]), 's', 'unit_id'));
}

/** non-allocatable expenses (cost_type.allocatable=0) of a year per unit: direct, else by living area of the building / property */
function nonalloc_expenses(int $year, array $units): array {
    $res = [];
    foreach (rows("SELECT e.* FROM expense e JOIN cost_type ct ON ct.id=e.cost_type_id
                    WHERE ct.allocatable=0 AND YEAR(e.invoice_date)=? AND e.from_reserve=0", [$year]) as $e) {
        if ($e['unit_id'] && isset($units[(int)$e['unit_id']])) { $res[(int)$e['unit_id']] = ($res[(int)$e['unit_id']] ?? 0) + (float)$e['amount']; continue; }
        $t = array_keys(array_filter($units, fn($u) => $u['kind'] === 'apartment' && (!$e['building_id'] || (int)$u['building_id'] === (int)$e['building_id'])));
        $W = array_sum(array_map(fn($id) => unit_weight($units[$id]), $t));
        foreach ($t as $id) $res[$id] = ($res[$id] ?? 0) + (float)$e['amount'] * unit_weight($units[$id]) / max($W, 1e-9);
    }
    return $res;
}

function pct(?float $v, int $d = 2): string { return $v === null ? '–' : number_format($v, $d, ',', '.') . ' %'; }

/** send rows as CSV (semicolon, UTF-8 BOM, German decimals) */
function csv_out(string $name, array $head, array $data): never {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, $head, ';');
    foreach ($data as $r) fputcsv($f, array_map(fn($v) => is_float($v) ? number_format($v, 2, ',', '') : $v, $r), ';');
    exit;
}
