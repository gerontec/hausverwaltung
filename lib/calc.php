<?php
// calc.php - Sollstellung, Betriebskostenabrechnung (tenants), WEG Wirtschaftsplan / Jahresabrechnung
declare(strict_types=1);

function month_bounds(string $ym): array {
    $first = "$ym-01";
    return [$first, date('Y-m-t', strtotime($first))];
}

/** generate monthly charges (rent, advances, parking, Hausgeld) for one month; idempotent */
function generate_charges(string $ym, bool $withWeg = true): array {
    [$first, $last] = month_bounds($ym);
    $mdays = days_between($first, $last);
    $n = 0; $skipped = 0;
    $ins = db()->prepare("INSERT IGNORE INTO posting (lease_id, weg_unit_id, posting_date, kind, category, amount, period, text, auto_key, source)
                          VALUES (?,?,?,?,?,?,?,?,?,'soll')");
    $leases = rows("SELECT l.*, u.no unit_no FROM lease l JOIN unit u ON u.id=l.unit_id
                    WHERE (l.start_date IS NULL OR l.start_date<=?) AND (l.end_date IS NULL OR l.end_date>=?)", [$last, $first]);
    foreach ($leases as $l) {
        $from = max($l['start_date'] ?? $first, $first);
        $to = min($l['end_date'] ?? $last, $last);
        $r = rent_at((int)$l['id'], $from) ?? rent_at((int)$l['id'], $to);
        if (!$r) { $skipped++; continue; }
        $f = days_between($from, $to) / $mdays;
        $due = sprintf('%s-%02d', $ym, min(max((int)$l['due_day'], 1), 28));
        foreach (['base_rent' => 'rent', 'operating_advance' => 'operating_advance', 'heating_advance' => 'heating_advance', 'parking_rent' => 'parking'] as $col => $cat) {
            $amt = round((float)$r[$col] * $f, 2);
            if ($amt == 0.0) continue;
            $txt = CATEGORY_LABEL[$cat] . ' ' . date('m/Y', strtotime($first)) . ($f < 1 ? sprintf(' (anteilig %d Tage)', days_between($from, $to)) : '');
            $ins->execute([$l['id'], null, $due, 'charge', $cat, $amt, $ym, $txt, "L{$l['id']}-$ym-$cat"]);
            $n += $ins->rowCount();
        }
    }
    if ($withWeg) {
        foreach (rows("SELECT * FROM weg_unit WHERE owner_id IS NOT NULL AND hausgeld_monthly<>0") as $w) {
            $hg = (float)$w['hausgeld_monthly'] - (float)$w['reserve_monthly'];
            foreach (['hausgeld' => $hg, 'reserve' => (float)$w['reserve_monthly']] as $cat => $amt) {
                if ($amt == 0.0) continue;
                $ins->execute([null, $w['id'], $first, 'charge', $cat, round($amt, 2), $ym, CATEGORY_LABEL[$cat] . ' ' . date('m/Y', strtotime($first)), "W{$w['id']}-$ym-$cat"]);
                $n += $ins->rowCount();
            }
        }
    }
    return [$n, $skipped];
}

/** degree-day weight (promille per year) of the days from..to inclusive; table of para 9b HeizkostenV / VDI 2067 */
function dd_weight(string $from, string $to): float {
    static $pm = [1 => 170, 2 => 150, 3 => 130, 4 => 80, 5 => 40, 6 => 40, 7 => 40, 8 => 40, 9 => 30, 10 => 80, 11 => 120, 12 => 160];
    if ($to < $from) return 0.0;
    $w = 0.0;
    $d = strtotime($from); $end = strtotime($to);
    while ($d <= $end) {
        $y = (int)date('Y', $d); $m = (int)date('n', $d);
        $mEnd = strtotime(date('Y-m-t', $d));
        $last = min($mEnd, $end);
        $days = (int)round(($last - $d) / 86400) + 1;
        $perDay = in_array($m, [6, 7, 8], true) ? 40 / 92 : $pm[$m] / (int)date('t', $d);
        $w += $perDay * $days;
        $d = strtotime(date('Y-m-d', $last) . ' +1 day');
    }
    return $w;
}

/** consumption of all active meters of a kind for one unit between two dates (linear interpolation) */
function unit_consumption(int $unitId, string $kind, string $from, string $to): float {
    if ($kind === 'water_total')   // water / sewage: cold + hot water volume (as Brunata does)
        return unit_consumption($unitId, 'water_cold', $from, $to) + unit_consumption($unitId, 'water_hot', $from, $to);
    $sum = 0.0;
    foreach (rows("SELECT id, factor FROM meter WHERE unit_id=? AND kind=? AND purpose=''", [$unitId, $kind]) as $m) {
        // readings are taken at the start and the end day of a period (heat cost allocators restart at 0 on 01.01.)
        $a = meter_value_at((int)$m['id'], $from, $kind === 'heat');
        $b = meter_value_at((int)$m['id'], $to, $kind === 'heat');
        if ($a !== null && $b !== null) $sum += max(0, $b - $a) * (float)$m['factor'];
    }
    return $sum;
}
function meter_value_at(int $meterId, string $date, bool $dd = false): ?float {
    $before = row("SELECT reading_date d, value v FROM meter_reading WHERE meter_id=? AND reading_date<=? ORDER BY reading_date DESC LIMIT 1", [$meterId, $date]);
    $after = row("SELECT reading_date d, value v FROM meter_reading WHERE meter_id=? AND reading_date>=? ORDER BY reading_date LIMIT 1", [$meterId, $date]);
    if ($before && $after) {
        if ($before['d'] === $after['d']) return (float)$before['v'];
        // heat: interpolate with degree days (para 9b HeizkostenV, VDI 2067), else by calendar days
        $t = $dd ? dd_weight(date('Y-m-d', strtotime($before['d'] . ' +1 day')), $date) / max(1e-9, dd_weight(date('Y-m-d', strtotime($before['d'] . ' +1 day')), $after['d']))
                 : (strtotime($date) - strtotime($before['d'])) / (strtotime($after['d']) - strtotime($before['d']));
        return (float)$before['v'] + $t * ((float)$after['v'] - (float)$before['v']);
    }
    return null;
}

/** WEG: weights of the WEG units for a scope (building NULL = all) and key */
function weg_weights(?int $buildingId, string $key): array {
    $ws = rows("SELECT w.*, (SELECT COALESCE(SUM(living_area),0) FROM unit u WHERE u.weg_unit_id=w.id AND u.kind='apartment') area,
                       (SELECT COUNT(*) FROM unit u WHERE u.weg_unit_id=w.id AND u.kind='apartment') flats
                FROM weg_unit w WHERE w.property_id=1 " . ($buildingId ? "AND w.building_id=" . (int)$buildingId : "") . " ORDER BY CAST(w.no AS UNSIGNED)");
    $out = [];
    foreach ($ws as $w) {
        $out[$w['id']] = match ($key) {
            'units' => 1.0,
            'area' => (float)$w['area'],
            default => (float)$w['mea'],
        };
    }
    return $out;
}

/** WEG Jahresabrechnung (or plan when $budget=true) */
function compute_weg(int $year, bool $budget = false): array {
    $from = "$year-01-01"; $to = "$year-12-31";
    $wegs = array_column(rows("SELECT w.*, " . str_replace(['company', 'last_name', 'first_name'], ['p.company', 'p.last_name', 'p.first_name'], PERSON_LABEL_SQL) . " owner
                               FROM weg_unit w LEFT JOIN person p ON p.id=w.owner_id WHERE w.property_id=1 ORDER BY CAST(w.no AS UNSIGNED)"), null, 'id');
    $lines = $budget
        ? rows("SELECT b.amount, b.building_id, NULL weg_unit_id, 0 from_reserve, b.cost_type_id, COALESCE(ct.code,'RL') code, COALESCE(ct.name,'Erhaltungsrücklage') name,
                       COALESCE(ct.weg_key,'mea') weg_key, COALESCE(ct.sort,9999) sort, b.period_from, b.period_to
                FROM (SELECT *, CONCAT(year,'-01-01') period_from, CONCAT(year,'-12-31') period_to FROM weg_budget WHERE year=?) b LEFT JOIN cost_type ct ON ct.id=b.cost_type_id", [$year])
        : rows("SELECT e.amount, e.building_id, e.weg_unit_id, e.from_reserve, e.cost_type_id, ct.code, ct.name, ct.weg_key, ct.sort, e.period_from, e.period_to
                FROM expense e JOIN cost_type ct ON ct.id=e.cost_type_id WHERE e.property_id=1 AND e.period_from<=? AND e.period_to>=? AND ct.weg_key<>'none'", [$to, $from]);
    $res = []; $costs = [];
    foreach ($lines as $e) {
        $amt = (float)$e['amount'] * overlap_days($e['period_from'], $e['period_to'], $from, $to) / days_between($e['period_from'], $e['period_to']);
        if (abs($amt) < 0.005) continue;
        $scope = $e['building_id'] ? val("SELECT name FROM building WHERE id=?", [$e['building_id']]) : 'Gesamtanlage';
        $key = $e['weg_unit_id'] ? 'direct' : $e['weg_key'];
        $ck = "{$e['code']}|$scope|$key|" . ($e['from_reserve'] ? 'R' : '');
        $costs[$ck] ??= ['code' => $e['code'], 'name' => $e['name'] . ($e['from_reserve'] ? ' (aus Rücklage)' : ''), 'scope' => $scope, 'key' => $key, 'amount' => 0.0, 'sort' => $e['sort'], 'reserve' => (bool)$e['from_reserve']];
        $costs[$ck]['amount'] += $amt;
        $w = $e['weg_unit_id'] ? [$e['weg_unit_id'] => 1.0] : weg_weights($e['building_id'] ? (int)$e['building_id'] : null, $key === 'direct' ? 'mea' : $key);
        $W = array_sum($w);
        if ($W <= 0) continue;
        foreach ($w as $wid => $x) {
            $slot = $e['from_reserve'] ? 'reserve_used' : ($e['code'] === 'RL' ? 'reserve' : 'costs');
            $res[$wid][$slot] = ($res[$wid][$slot] ?? 0) + $amt * $x / $W;
            $res[$wid]['lines'][$ck] = ($res[$wid]['lines'][$ck] ?? 0) + $amt * $x / $W;
        }
    }
    foreach ($wegs as $wid => $w) {
        $r = &$res[$wid];
        $r['costs'] ??= 0; $r['reserve'] ??= 0; $r['reserve_used'] ??= 0;
        $r['soll'] = (float)val("SELECT COALESCE(SUM(amount),0) FROM posting WHERE weg_unit_id=? AND kind='charge' AND category='hausgeld' AND posting_date BETWEEN ? AND ?", [$wid, $from, $to]);
        $r['soll_reserve'] = (float)val("SELECT COALESCE(SUM(amount),0) FROM posting WHERE weg_unit_id=? AND kind='charge' AND category='reserve' AND posting_date BETWEEN ? AND ?", [$wid, $from, $to]);
        $r['paid'] = (float)val("SELECT COALESCE(SUM(amount),0) FROM posting WHERE weg_unit_id=? AND kind='payment' AND posting_date BETWEEN ? AND ?", [$wid, $from, $to]);
        $r['result'] = $r['costs'] - $r['soll'];
        unset($r);
    }
    uasort($costs, fn($a, $b) => [$a['sort'], $a['scope']] <=> [$b['sort'], $b['scope']]);
    return ['year' => $year, 'wegs' => $wegs, 'res' => $res, 'costs' => $costs, 'budget' => $budget];
}
