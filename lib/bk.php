<?php
// bk.php - Betriebskostenabrechnung (BetrKV, para 556 BGB) incl. HeizkostenV and CO2KostAufG
//
// Two modes for heating / hot water / water per settlement period (table hk_setting):
//   brunata  the metering service bills heating, hot water and water per user; its results (hk_result) are
//            taken over as direct costs, the matching expenses are not distributed again
//   own      the HV distributes them itself: para 9 split heating / hot water, para 7/8 consumption share,
//            para 9b degree days on tenant change, CO2KostAufG landlord share, para 6b information
declare(strict_types=1);

const CO2_STAGES = [  // CO2KostAufG Anlage: kg CO2 per m2 living area and year -> landlord share %
    [12, 0], [17, 10], [22, 20], [27, 30], [32, 40], [37, 50], [42, 60], [47, 70], [52, 80], [PHP_FLOAT_MAX, 95]];
const FUEL_KIND = ['gas_hs' => 'Erdgas (Abrechnung nach Brennwert)', 'gas_hi' => 'Erdgas (Heizwert)', 'oil' => 'Heizöl',
                   'district' => 'Wärmelieferung / Fern- und Nahwärme (kWh Wärme)', 'heatpump' => 'Wärmepumpe (Strom)', 'other' => 'sonstiger'];
const WW_METHOD = ['meter' => 'Wärmezähler Warmwasserbereitung', 'formula' => 'Formel 2,5 kWh/(m³·K) × V × (t − 10)',
                   'area' => '32 kWh/m² Wohnfläche', 'none' => 'keine zentrale Warmwasserbereitung'];
const TAX35A = ['none' => '–', 'service' => 'haushaltsnahe Dienstleistung (§ 35a Abs. 2)', 'craft' => 'Handwerkerleistung (§ 35a Abs. 3)'];

function bk_setting(string $from, string $to): array {
    $s = row("SELECT * FROM hk_setting WHERE period_from=? AND period_to=?", [$from, $to]);
    return $s ?? ['id' => null, 'period_from' => $from, 'period_to' => $to, 'mode' => 'brunata', 'advances' => 'ist', 'fuel_kind' => 'gas_hs',
                  'fuel_kwh' => null, 'ww_method' => 'meter', 'ww_temp' => 60, 'co2_kg' => null, 'co2_cost' => null, 'note' => null];
}

function co2_stage(float $kgPerM2): array {
    foreach (CO2_STAGES as $i => [$lim, $pct]) if ($kgPerM2 < $lim) return [$i + 1, $pct];
    return [10, 95];
}

/** paid advances of a lease in a period: payments settle older debts first (para 366 Abs. 2 BGB) */
function advances_paid(int $leaseId, string $from, string $to): array {
    $soll = (float)val("SELECT COALESCE(SUM(amount),0) FROM posting WHERE lease_id=? AND kind='charge' AND category IN ('operating_advance','heating_advance') AND posting_date BETWEEN ? AND ?", [$leaseId, $from, $to]);
    $charges = (float)val("SELECT COALESCE(SUM(amount),0) FROM posting WHERE lease_id=? AND kind='charge' AND category IN ('rent','operating_advance','heating_advance','parking') AND posting_date BETWEEN ? AND ?", [$leaseId, $from, $to]);
    $b0 = (float)val("SELECT COALESCE(SUM(CASE WHEN kind='charge' THEN amount ELSE -amount END),0) FROM posting WHERE lease_id=? AND posting_date<?", [$leaseId, $from]);
    $paid = (float)val("SELECT COALESCE(SUM(amount),0) FROM posting WHERE lease_id=? AND kind='payment' AND posting_date BETWEEN ? AND ?", [$leaseId, $from, date('Y-m-d', strtotime("$to +5 days"))]);
    $unpaid = max(0.0, min($charges, $b0 + $charges - $paid));
    $unpaidAdv = $charges > 0 ? $unpaid * $soll / $charges : 0.0;
    return ['soll' => $soll, 'ist' => max(0.0, $soll - $unpaidAdv), 'unpaid' => $unpaidAdv];
}

function compute_bk(string $from, string $to): array {
    $D = days_between($from, $to);
    $set = bk_setting($from, $to);
    $warn = [];
    $units = rows("SELECT * FROM unit WHERE property_id=1 AND kind='apartment'");
    $uById = array_column($units, null, 'id');
    $areaAll = array_sum(array_map(fn($u) => (float)$u['living_area'], $units));
    // occupancy segments [lease_id|null, from, to, persons, excluded codes]
    $segs = [];
    foreach ($units as $u) {
        // leases must not overlap: a lease without start date (before the data) ends where the next one begins
        $ls = rows("SELECT * FROM lease WHERE unit_id=? AND (start_date IS NULL OR start_date<=?) AND (end_date IS NULL OR end_date>=?) ORDER BY start_date IS NOT NULL, start_date, end_date IS NULL, end_date", [$u['id'], $to, $from]);
        $cur = $from;
        foreach ($ls as $i => $l) {
            $s = max($l['start_date'] ?? $from, $cur); $e = min($l['end_date'] ?? $to, $to);
            if (isset($ls[$i + 1]['start_date']) && $ls[$i + 1]['start_date'] <= $e) $e = date('Y-m-d', strtotime($ls[$i + 1]['start_date'] . ' -1 day'));
            if ($s > $e) continue;
            if ($s > $cur) $segs[$u['id']][] = [null, $cur, date('Y-m-d', strtotime("$s -1 day")), 1, []];
            $ex = array_filter(array_map('trim', explode(',', strtoupper((string)$l['no_alloc']))));
            $segs[$u['id']][] = [(int)$l['id'], $s, $e, max(1, (int)$l['persons']), $ex];
            $cur = date('Y-m-d', strtotime("$e +1 day"));
        }
        if ($cur <= $to) $segs[$u['id']][] = [null, $cur, $to, 1, []];
    }
    $res = [];
    $costs = [];
    $add = function (int $uid, array $seg, string $code, float $amt, string $basis, float $labour = 0.0, string $tax = 'none') use (&$res) {
        $k = $seg[0] ? 'L' . $seg[0] : "V$uid:{$seg[1]}";
        $res[$k] ??= ['unit_id' => $uid, 'lease_id' => $seg[0], 'from' => $seg[1], 'to' => $seg[2], 'lines' => [], 'excluded' => [], 'labour' => []];
        $slot = in_array($code, $seg[4], true) ? 'excluded' : 'lines';
        $res[$k][$slot][$code]['amount'] = ($res[$k][$slot][$code]['amount'] ?? 0) + $amt;
        $prev = $res[$k][$slot][$code]['basis'] ?? '';
        $res[$k][$slot][$code]['basis'] = $prev === '' ? $basis : ($prev === $basis || str_contains($prev, $basis) ? $prev : "$prev; $basis");
        if ($slot === 'lines' && $labour && $tax !== 'none') $res[$k]['labour'][$tax] = ($res[$k]['labour'][$tax] ?? 0) + $labour;
    };

    // ---- expenses (allocatable, period overlap, "allocatable until" cut) ----
    $exp = rows("SELECT e.*, ct.code, ct.name ct_name, ct.alloc_key, ct.consumption_share, ct.meter_kind, ct.is_heating, ct.sort, ct.tax35a, ct.allocatable_until, ct.heat_plant
                 FROM expense e JOIN cost_type ct ON ct.id=e.cost_type_id
                 WHERE ct.allocatable=1 AND e.property_id=1 AND e.period_from<=? AND e.period_to>=? ORDER BY ct.sort, e.invoice_date", [$to, $from]);
    $lines = [];
    foreach ($exp as $e) {
        $end = $e['allocatable_until'] ? min($to, $e['allocatable_until']) : $to;
        $ov = overlap_days($e['period_from'], $e['period_to'], $from, $end);
        $f = $ov / days_between($e['period_from'], $e['period_to']);
        if ($e['allocatable_until'] && $e['allocatable_until'] < $e['period_to'] && $ov < overlap_days($e['period_from'], $e['period_to'], $from, $to))
            $warn[] = "{$e['ct_name']}: nur bis " . dmy($e['allocatable_until']) . " umlagefähig, Rest trägt der Vermieter.";
        $amt = (float)$e['amount'] * $f;
        if (abs($amt) < 0.005) continue;
        $lines[] = $e + ['amt' => $amt, 'lab' => (float)$e['labour_cost'] * $f];
    }

    // ---- heating / hot water / water: metering service or own calculation ----
    $hkInfo = ['mode' => $set['mode']];
    $hk = rows("SELECT * FROM hk_result WHERE period_from=? AND period_to=?", [$from, $to]);
    if ($set['mode'] === 'brunata') {
        if (!$hk) {
            $warn[] = 'Modus „Messdienst“, aber für diesen Zeitraum ist noch keine Brunata-Abrechnung importiert – Heiz- und Wasserkosten werden vorläufig selbst verteilt.';
        } else {
            $covered = array_unique(array_column($hk, 'cost_code'));
            $hkInfo['covered'] = $covered;
            $lines = array_values(array_filter($lines, fn($l) => !in_array($l['code'], $covered, true) && !($l['heat_plant'] && in_array('HZ', $covered, true))));
            foreach ($hk as $r) {
                $ct = row("SELECT name, sort FROM cost_type WHERE code=?", [$r['cost_code']]);
                $ck = $r['cost_code'] . '|Messdienst|brunata';
                $costs[$ck] ??= ['code' => $r['cost_code'], 'name' => $ct['name'] ?? $r['cost_code'], 'scope' => 'alle Häuser', 'key' => 'brunata', 'amount' => 0.0, 'sort' => $ct['sort'] ?? 0, 'share' => 0, 'meter' => null];
                $costs[$ck]['amount'] += (float)$r['amount'];
                // assign to the occupancy segment with the largest overlap
                $best = null; $bo = 0;
                foreach ($segs[$r['unit_id']] ?? [] as $s) { $o = overlap_days($s[1], $s[2], $r['seg_from'], $r['seg_to']); if ($o > $bo) { $bo = $o; $best = $s; } }
                if ($best) $add((int)$r['unit_id'], $best, $r['cost_code'], (float)$r['amount'], 'lt. Brunata-Abrechnung (' . dmy($r['seg_from']) . '–' . dmy($r['seg_to']) . ')');
            }
        }
    }
    if ($set['mode'] === 'own' || ($set['mode'] === 'brunata' && !$hk)) {
        // pool of the central plant (fuel, operation, maintenance, metering) + explicit hot water costs
        $plant = array_filter($lines, fn($l) => $l['heat_plant']);
        $wwOnly = array_filter($lines, fn($l) => $l['code'] === 'WW' && !$l['heat_plant']);
        $lines = array_values(array_filter($lines, fn($l) => !$l['heat_plant'] && !($l['code'] === 'WW')));
        $K = array_sum(array_column($plant, 'amt')); $KL = array_sum(array_column($plant, 'lab'));
        // para 9: share of hot water
        $qww = 0.0; $s = 0.0;
        $vww = 0.0; foreach ($units as $u) $vww += unit_consumption((int)$u['id'], 'water_hot', $from, $to);
        if ($set['ww_method'] === 'meter') {
            foreach (rows("SELECT id, factor FROM meter WHERE purpose='ww_energy'") as $m) {
                $a = meter_value_at((int)$m['id'], $from); $b = meter_value_at((int)$m['id'], $to);
                if ($a !== null && $b !== null) $qww += ($b - $a) * (float)$m['factor'];
            }
            if ($qww <= 0) $warn[] = 'Kein Stand des Wärmezählers Warmwasserbereitung für Beginn/Ende des Zeitraums – Warmwasseranteil nach Formel.';
        }
        $qwwMetered = $qww > 0;
        if ($qww <= 0 && in_array($set['ww_method'], ['meter', 'formula'], true)) $qww = 2.5 * $vww * ((float)$set['ww_temp'] - 10);
        if ($set['ww_method'] === 'area') $qww = 32 * $areaAll;
        // para 9 Abs. 2: gas billed by gross calorific value x 1,11; heat delivery: /1,15 only for computed (not metered) heat
        $metered = $set['ww_method'] === 'meter' && $qwwMetered;
        $fac = match ($set['fuel_kind']) { 'gas_hs' => 1.11, 'district' => $metered ? 1.0 : 1 / 1.15, default => 1.0 };
        if ($set['ww_method'] !== 'none' && $K > 0) {
            if ((float)$set['fuel_kwh'] > 0) $s = min(0.5, $qww * $fac / (float)$set['fuel_kwh']);
            else $warn[] = 'Brennstoff-/Energieverbrauch der Anlage (kWh) fehlt – ohne ihn ist keine Aufteilung Heizung/Warmwasser nach § 9 HeizkostenV möglich (Warmwasseranteil 0 gesetzt).';
        }
        $ctHZ = row("SELECT * FROM cost_type WHERE code='HZ'"); $ctWW = row("SELECT * FROM cost_type WHERE code='WW'");
        $wwExtra = array_sum(array_column($wwOnly, 'amt'));
        $hkInfo += ['K' => $K, 'qww' => $qww, 'vww' => $vww, 's' => $s, 'fac' => $fac, 'ww_extra' => $wwExtra];
        if ($K * (1 - $s) != 0.0) $lines[] = ['code' => 'HZ', 'ct_name' => 'Heizung (§ 9 HeizkostenV: ' . num(100 * (1 - $s), 1) . ' % der Anlagenkosten)', 'alloc_key' => 'consumption',
            'consumption_share' => $ctHZ['consumption_share'], 'meter_kind' => 'heat', 'sort' => $ctHZ['sort'], 'tax35a' => $ctHZ['tax35a'], 'amt' => $K * (1 - $s), 'lab' => $KL * (1 - $s),
            'unit_id' => null, 'building_id' => null, 'dd' => true];
        if ($K * $s + $wwExtra != 0.0) $lines[] = ['code' => 'WW', 'ct_name' => 'Warmwasser (§ 9 HeizkostenV: ' . num(100 * $s, 1) . ' % der Anlagenkosten)', 'alloc_key' => 'consumption',
            'consumption_share' => $ctWW['consumption_share'], 'meter_kind' => 'water_hot', 'sort' => $ctWW['sort'], 'tax35a' => $ctWW['tax35a'], 'amt' => $K * $s + $wwExtra,
            'lab' => $KL * $s + array_sum(array_column($wwOnly, 'lab')), 'unit_id' => null, 'building_id' => null];
        // CO2KostAufG
        if ((float)$set['co2_cost'] > 0 && (float)$set['co2_kg'] > 0 && $areaAll > 0) {
            $spec = (float)$set['co2_kg'] / $areaAll * 365 / $D;
            [$stage, $pct] = co2_stage($spec);
            $hkInfo += ['co2_spec' => $spec, 'co2_stage' => $stage, 'co2_pct' => $pct, 'co2_cost' => (float)$set['co2_cost'], 'co2_kg' => (float)$set['co2_kg']];
        } elseif ($K > 0 && in_array($set['fuel_kind'], ['gas_hs', 'gas_hi', 'oil'], true)) {
            $warn[] = 'CO₂-Kosten und -Emissionen der Brennstoffrechnung fehlen – ohne sie kein Vermieteranteil nach CO2KostAufG (Mieter darf dann 3 % der auf ihn entfallenden Heizkosten kürzen, § 7 Abs. 4).';
        }
    }

    // ---- distribute all lines ----
    foreach ($lines as $e) {
        $amt = $e['amt'];
        $scope = array_filter($units, fn($u) => !empty($e['unit_id']) ? $u['id'] == $e['unit_id'] : (empty($e['building_id']) || $u['building_id'] == $e['building_id']));
        $scopeName = !empty($e['unit_id']) ? 'Whg ' . $uById[$e['unit_id']]['no'] : (!empty($e['building_id']) ? val("SELECT name FROM building WHERE id=?", [$e['building_id']]) : 'alle Häuser');
        $key = !empty($e['unit_id']) ? 'direct' : ($e['alloc_key'] === 'mea' ? 'area' : $e['alloc_key']);
        $code = $e['code']; $dd = !empty($e['dd']); $tax = $e['tax35a'] ?? 'none';
        $labRatio = $amt != 0.0 ? ($e['lab'] ?? 0) / $amt : 0.0;
        $ck = "$code|$scopeName|$key";
        $costs[$ck] ??= ['code' => $code, 'name' => $e['ct_name'], 'scope' => $scopeName, 'key' => $key, 'amount' => 0.0, 'sort' => $e['sort'], 'share' => (float)$e['consumption_share'], 'meter' => $e['meter_kind']];
        $costs[$ck]['amount'] += $amt;
        if ($key === 'direct') {
            foreach ($scope as $u) foreach ($segs[$u['id']] ?? [] as $s) { $x = $amt * days_between($s[1], $s[2]) / $D; $add((int)$u['id'], $s, $code, $x, 'direkt', $x * $labRatio, $tax); }
            continue;
        }
        $consAmt = 0.0;
        if ($key === 'consumption' && $e['meter_kind']) {
            $cons = []; $tot = 0.0;
            foreach ($scope as $u) foreach ($segs[$u['id']] ?? [] as $i => $s) { $c = unit_consumption((int)$u['id'], $e['meter_kind'], $s[1], $s[2]); $cons[$u['id']][$i] = $c; $tot += $c; }
            foreach ($scope as $u) {
                $kinds = $e['meter_kind'] === 'water_total' ? ['water_cold', 'water_hot'] : [$e['meter_kind']];
                $nm = (int)val("SELECT COUNT(*) FROM meter WHERE unit_id=? AND purpose='' AND kind IN ('" . implode("','", $kinds) . "')", [$u['id']]);
                $nr = (int)val("SELECT COUNT(DISTINCT r.meter_id) FROM meter_reading r JOIN meter m ON m.id=r.meter_id WHERE m.unit_id=? AND m.purpose='' AND m.kind IN ('" . implode("','", $kinds) . "') AND r.reading_date IN (?,?)", [$u['id'], $from, $to]);
                if ($nm && $nr < $nm) $warn[] = "Whg {$u['no']}: für " . ($nm - $nr) . " von $nm Zählern ({$e['meter_kind']}) fehlen Stände zu Beginn/Ende – Verbrauch schätzen (§ 9a HeizkostenV) oder Zwischenablesung eintragen.";
            }
            if ($tot > 0) {
                $consAmt = $amt * (float)$e['consumption_share'] / 100;
                $unitLbl = $e['meter_kind'] === 'heat' ? 'Einh.' : 'm³';
                foreach ($scope as $u) foreach ($segs[$u['id']] ?? [] as $i => $s) {
                    $x = $consAmt * $cons[$u['id']][$i] / $tot;
                    $add((int)$u['id'], $s, $code, $x, sprintf('%s von %s %s Verbrauch (%s %%)', num($cons[$u['id']][$i], $unitLbl === 'm³' ? 2 : 0), num($tot, $unitLbl === 'm³' ? 2 : 0), $unitLbl, num($e['consumption_share'], 0)), $x * $labRatio, $tax);
                }
            } else {
                $warn[] = "$code: keine Zählerstände für den Zeitraum – vollständig nach Wohnfläche verteilt" . ($e['is_heating'] ?? false || $dd ? ' (Mieter darf nach § 12 HeizkostenV um 15 % kürzen)' : '') . '.';
            }
            $key = 'area';
        }
        $rest = $amt - $consAmt;
        if (abs($rest) < 0.005) continue;
        $Ddd = dd_weight($from, $to);
        $W = 0.0; $w = [];
        foreach ($scope as $u) foreach ($segs[$u['id']] ?? [] as $i => $s) {
            $t = $dd ? dd_weight($s[1], $s[2]) / $Ddd * $D : days_between($s[1], $s[2]);
            $x = match ($key) { 'units' => $t, 'persons' => $s[3] * $t, default => (float)$u['living_area'] * $t };
            $w[$u['id']][$i] = $x; $W += $x;
        }
        if ($W <= 0) continue;
        foreach ($scope as $u) foreach ($segs[$u['id']] ?? [] as $i => $s) {
            $days = days_between($s[1], $s[2]);
            $tl = $dd ? sprintf('%s ‰ Gradtage', num(dd_weight($s[1], $s[2]) / $Ddd * 1000, 0)) : sprintf('%d/%d Tage', $days, $D);
            $basis = match ($key) { 'units' => "1 WE × $tl", 'persons' => sprintf('%d Pers. × %s', $s[3], $tl), default => sprintf('%s m² × %s', num($u['living_area']), $tl) };
            $x = $rest * $w[$u['id']][$i] / $W;
            $add((int)$u['id'], $s, $code, $x, ($consAmt ? 'Grundkosten ' . num(100 - (float)$e['consumption_share'], 0) . ' %: ' : '') . $basis, $x * $labRatio, $tax);
        }
    }

    // ---- CO2 landlord share, advances, totals ----
    foreach ($res as $k => &$r) {
        if (isset($hkInfo['co2_pct']) && $r['lease_id']) {
            $heat = ($r['lines']['HZ']['amount'] ?? 0) + ($r['lines']['WW']['amount'] ?? 0);
            $pot = ($hkInfo['K'] ?? 0) + ($hkInfo['ww_extra'] ?? 0);
            if ($pot > 0 && $heat > 0 && $hkInfo['co2_pct'] > 0) {
                $tenantCo2 = $hkInfo['co2_cost'] * $heat / $pot;
                $r['lines']['CO2'] = ['amount' => -$tenantCo2 * $hkInfo['co2_pct'] / 100,
                    'basis' => sprintf('Ihr CO₂-Kostenanteil %s × %d %% (Stufe %d)', money($tenantCo2), $hkInfo['co2_pct'], $hkInfo['co2_stage'])];
                $r['co2_tenant'] = $tenantCo2;
            }
        }
        $r['total'] = array_sum(array_column($r['lines'], 'amount'));
        $r['excluded_total'] = array_sum(array_column($r['excluded'], 'amount'));
        if ($r['lease_id']) {
            $a = advances_paid((int)$r['lease_id'], $r['from'], $r['to']);
            $r['advances_soll'] = $a['soll']; $r['advances_unpaid'] = $a['unpaid'];
            $r['advances'] = $set['advances'] === 'soll' ? $a['soll'] : $a['ist'];
        } else { $r['advances'] = $r['advances_soll'] = $r['advances_unpaid'] = 0.0; }
        $r['result'] = $r['total'] - $r['advances'];
    }
    unset($r);
    uasort($costs, fn($a, $b) => [$a['sort'], $a['scope']] <=> [$b['sort'], $b['scope']]);
    uasort($res, fn($a, $b) => [(int)$uById[$a['unit_id']]['no'], $a['from']] <=> [(int)$uById[$b['unit_id']]['no'], $b['from']]);
    return ['from' => $from, 'to' => $to, 'days' => $D, 'costs' => $costs, 'res' => $res, 'units' => $uById, 'set' => $set, 'hk' => $hkInfo, 'warn' => array_values(array_unique($warn)), 'area' => $areaAll];
}

// ---------------------------------------------------------------- Brunata ---
/** parse "Übersicht aller Nutzerabrechnungen" (pdftotext -layout) */
function brunata_parse(string $txt): array {
    if (!preg_match('/Abrechnungszeitraum\s+(\d{2}\.\d{2}\.\d{4}) - (\d{2}\.\d{2}\.\d{4})/u', $txt, $m)) throw new RuntimeException('Abrechnungszeitraum nicht gefunden – ist das eine Brunata „Übersicht aller Nutzerabrechnungen“?');
    $pf = parse_date($m[1]); $pt = parse_date($m[2]);
    $rows = []; $cur = null;
    foreach (preg_split('/\R/', $txt) as $ln) {
        if (preg_match('/^\s{1,4}(\d{4})\s+(\S+)\s+(.+?)\s{2,}\d\.\d\.\d+\s+(\d{2}\.\d{2}\.\d{4}) - (\d{2}\.\d{2}\.\d{4})/u', $ln, $m)) {
            $cur = ['no' => (string)(int)$m[1], 'name' => trim($m[3]), 'from' => parse_date($m[4]), 'to' => parse_date($m[5])];
            continue;
        }
        if (!$cur) continue;
        if (preg_match('/^\s{8,}(Heizung Grundkosten|Heizung Verbrauchskosten|Warmwasser Grundkosten|Warmwasser Verbrauchskosten|Kaltwasser Gesamt|Abwasser Gesamt|Gerätemiete [\wäöü]+|Abrechnung [\wäöü]+|Kosten bei Nutzerwechsel|CO2[\w\- ]*|[A-ZÄÖÜ][\wäöü\-]+(?: [\wäöü\-]+){0,3})\s{2,}(.*?)(-?\d{1,3}(?:\.\d{3})*,\d{2}) €/u', $ln, $m)) {
            $label = trim($m[1]);
            if (in_array($label, ['Gesamtbetrag', 'Summe aller Nutzer'], true)) continue;
            $code = match (true) {
                str_starts_with($label, 'Heizung'), $label === 'Kosten bei Nutzerwechsel', $label === 'Verbrauchsschätzung', str_starts_with($label, 'CO2') => 'HZ',
                str_starts_with($label, 'Warmwasser') => 'WW',
                str_starts_with($label, 'Abwasser') => 'EW',
                default => 'WA',
            };
            $rows[] = ['unit_no' => $cur['no'], 'name' => $cur['name'], 'seg_from' => $cur['from'], 'seg_to' => $cur['to'], 'code' => $code, 'label' => $label,
                       'basis' => trim(preg_replace('/\s+/', ' ', $m[2])), 'amount' => parse_num($m[3])];
        }
        if (preg_match('/^\s*Summe aller Nutzer/u', $ln)) $cur = null;
    }
    return ['from' => $pf, 'to' => $pt, 'rows' => $rows];
}

function brunata_store(array $p, string $source): int {
    q("DELETE FROM hk_result WHERE period_from=? AND period_to=?", [$p['from'], $p['to']]);
    $n = 0;
    foreach ($p['rows'] as $r) {
        $uid = val("SELECT id FROM unit WHERE property_id=1 AND kind='apartment' AND no=?", [$r['unit_no']]);
        if (!$uid) continue;
        q("INSERT INTO hk_result (period_from, period_to, unit_id, seg_from, seg_to, user_name, cost_code, label, basis, amount, source) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
          [$p['from'], $p['to'], $uid, $r['seg_from'], $r['seg_to'], $r['name'], $r['code'], $r['label'], $r['basis'], $r['amount'], $source]);
        $n++;
    }
    return $n;
}
