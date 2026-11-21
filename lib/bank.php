<?php
// bank.php - CSV import (finanzblick, Sparkasse/VR/DKB style) and matching of bank transactions
declare(strict_types=1);

/** lower-case, umlauts folded, only a-z0-9 - for robust substring matching */
function norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'ç' => 'c', 'ğ' => 'g', 'ş' => 's', 'ı' => 'i']);
    return preg_replace('/[^a-z0-9]/', '', $s);
}

const BANK_COLS = [
    'date' => ['buchungsdatum', 'buchungstag', 'datum', 'buchung'],
    'amount' => ['betrag', 'betrageur', 'umsatz', 'betraginEUR'],
    'counterparty' => ['empfaenger', 'zahlungsempfaenger', 'beguenstigterzahlungspflichtiger', 'nameuebersenderzahlungsempfaenger', 'namezahlungsbeteiligter', 'auftraggeberempfaenger', 'auftraggeberbeguenstigter', 'empfaengerauftraggeber'],
    'iban' => ['iban', 'kontonummeriban', 'ibanzahlungsbeteiligter', 'kontonummer'],
    'purpose' => ['verwendungszweck', 'buchungsdetails', 'vorgangverwendungszweck'],
    'text' => ['buchungstext', 'umsatzart'],
    'account' => ['konto', 'auftragskonto', 'ibanauftragskonto', 'eigeneskonto'],
    'category' => ['kategorie'],
];

/** parse a bank CSV into normalized rows */
function bank_parse_csv(string $raw): array {
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $lines = preg_split('/\r\n|\n|\r/', $raw);
    // find header line
    $hdrIdx = null; $delim = ';';
    foreach ($lines as $i => $ln) {
        $d = substr_count($ln, ';') >= substr_count($ln, ',') ? ';' : ',';
        $cells = array_map('norm', str_getcsv($ln, $d, '"', ''));
        $hasDate = array_intersect($cells, BANK_COLS['date']);
        $hasAmt = array_intersect($cells, BANK_COLS['amount']);
        if ($hasDate && $hasAmt) { $hdrIdx = $i; $delim = $d; break; }
        if ($i > 30) break;
    }
    if ($hdrIdx === null) throw new RuntimeException('Keine Kopfzeile mit Datum und Betrag gefunden.');
    $hdr = array_map('norm', str_getcsv($lines[$hdrIdx], $delim, '"', ''));
    $col = [];
    foreach (BANK_COLS as $k => $aliases) {
        foreach ($aliases as $a) { $j = array_search($a, $hdr, true); if ($j !== false) { $col[$k] = $j; break; } }
    }
    $out = [];
    for ($i = $hdrIdx + 1; $i < count($lines); $i++) {
        if (trim($lines[$i]) === '') continue;
        $c = str_getcsv($lines[$i], $delim, '"', '');
        $g = fn($k) => isset($col[$k]) ? trim((string)($c[$col[$k]] ?? '')) : '';
        $date = parse_date($g('date'));
        $amt = parse_num($g('amount'));
        if (!$date || $amt === null) continue;
        $purpose = preg_replace('/\s+/', ' ', $g('purpose'));
        $cp = $g('counterparty');
        $iban = strtoupper(str_replace(' ', '', $g('iban')));
        if ($iban === '' && preg_match('/IBAN\s*((?:DE|AT|CH|NL|IT|FR)\d{2}(?:\s?\d){12,30})/i', $purpose, $m)) {
            $iban = substr(strtoupper(str_replace(' ', '', $m[1])), 0, 22);
        }
        if ($cp === '') {
            // finanzblick: counterparty is the start of the purpose
            $cp = trim(preg_split('/\s(BIC|IBAN|EMPFAENGER|DE\d{2}\d)/', preg_replace('/^(ECHTZEITUEBERWEISUNG|ECHTZEITUeBERWEISUNG|FOLGELASTSCHRIFT|ERSTLASTSCHRIFT|LASTSCHRIFT|GUTSCHRIFT)\s+/i', '', $purpose))[0]);
            $cp = mb_substr($cp, 0, 60);
        }
        $account = str_replace(' ', '', $g('account'));
        $out[] = ['account' => $account, 'date' => $date, 'amount' => $amt, 'counterparty' => $cp, 'iban' => $iban,
                  'purpose' => trim($purpose . ($g('text') ? ' / ' . $g('text') : '')), 'category' => $g('category'),
                  'hash' => sha1(implode('|', [$account, $date, number_format($amt, 2, '.', ''), $purpose, $cp]))];
    }
    return $out;
}

/** insert rows into bank_tx; returns [new, duplicates] */
function bank_store(array $rows, ?string $bookedUntil = null): array {
    $new = 0; $dup = 0;
    $occ = [];
    $st = db()->prepare("INSERT IGNORE INTO bank_tx (account,booking_date,amount,counterparty,iban,purpose,hash,status,note,category) VALUES (?,?,?,?,?,?,?,?,?,?)");
    foreach ($rows as $r) {
        // identical rows on the same day get a running index so they are not lost
        $k = $r['hash']; $occ[$k] = ($occ[$k] ?? 0) + 1;
        $hash = $occ[$k] > 1 ? sha1($k . '#' . $occ[$k]) : $k;
        $pre = $bookedUntil && $r['date'] <= $bookedUntil;
        $st->execute([$r['account'], $r['date'], $r['amount'], $r['counterparty'], $r['iban'] ?: null, $r['purpose'], $hash,
                      $pre ? 'booked' : 'open', $pre ? 'bereits in Altdaten enthalten' : null, $r['category'] ?: null]);
        $st->rowCount() ? $new++ : $dup++;
    }
    return [$new, $dup];
}

/** propose an assignment for an open transaction */
function bank_match(array $tx): ?array {
    $tx['amount'] = (float)$tx['amount'];
    $hay = norm(($tx['counterparty'] ?? '') . ' ' . ($tx['purpose'] ?? ''));
    $dir = $tx['amount'] >= 0 ? 'in' : 'out';
    foreach (rows("SELECT * FROM bank_rule WHERE direction IN ('any', ?) ORDER BY priority, LENGTH(pattern) DESC", [$dir]) as $r) {
        $p = norm($r['pattern']);
        if ($p !== '' && str_contains($hay, $p)) return ['source' => 'Regel „' . $r['pattern'] . '“'] + $r;
    }
    // IBAN of a person
    if (!empty($tx['iban'])) {
        $l = row("SELECT l.id FROM lease l JOIN person p ON p.id=l.tenant_id WHERE REPLACE(p.iban,' ','')=? ORDER BY l.end_date IS NULL DESC, l.start_date DESC LIMIT 1", [$tx['iban']]);
        if ($l && $dir === 'in') return ['action' => 'payment', 'lease_id' => $l['id'], 'source' => 'IBAN'];
    }
    if ($dir !== 'in') return null;
    // tenant name in purpose; lease active around the booking date
    $cands = rows("SELECT l.id, l.unit_id, u.kind, p.last_name, p.first_name FROM lease l JOIN person p ON p.id=l.tenant_id JOIN unit u ON u.id=l.unit_id
                   WHERE p.last_name IS NOT NULL AND (l.start_date IS NULL OR l.start_date <= DATE_ADD(?, INTERVAL 45 DAY))
                     AND (l.end_date IS NULL OR l.end_date >= DATE_SUB(?, INTERVAL 75 DAY))", [$tx['booking_date'], $tx['booking_date']]);
    $hits = [];
    foreach ($cands as $c) {
        $ln = norm($c['last_name']);
        if (strlen($ln) < 4 || !str_contains($hay, $ln)) continue;
        $score = 1;
        foreach (preg_split('/[\s,]+/', (string)$c['first_name']) as $fn) {
            $fn = norm($fn);
            if (strlen($fn) >= 3 && str_contains($hay, $fn)) { $score = 2; break; }
        }
        $hits[] = $c + ['score' => $score];
    }
    if (!$hits) return null;
    usort($hits, fn($a, $b) => $b['score'] <=> $a['score']);
    $best = array_values(array_filter($hits, fn($h) => $h['score'] === $hits[0]['score']));
    // same tenant with flat + parking: small amounts go to the parking lease
    $flat = array_values(array_filter($best, fn($h) => $h['kind'] === 'apartment'));
    $park = array_values(array_filter($best, fn($h) => $h['kind'] !== 'apartment'));
    if (count($flat) === 1 && (abs($tx['amount']) >= 100 || !$park)) return ['action' => 'payment', 'lease_id' => $flat[0]['id'], 'source' => 'Name'];
    if (count($park) === 1 && abs($tx['amount']) < 100) return ['action' => 'payment', 'lease_id' => $park[0]['id'], 'source' => 'Name'];
    if (count($best) === 1) return ['action' => 'payment', 'lease_id' => $best[0]['id'], 'source' => 'Name'];
    return null;
}

/** book a transaction according to an assignment */
function bank_book(int $txId, array $a): void {
    $tx = row("SELECT * FROM bank_tx WHERE id=?", [$txId]);
    if (!$tx || $tx['status'] !== 'open') return;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $text = mb_substr(trim($tx['counterparty'] . ' – ' . $tx['purpose']), 0, 250);
        if ($a['action'] === 'payment') {
            q("INSERT INTO posting (lease_id, weg_unit_id, posting_date, kind, category, amount, text, bank_tx_id, source) VALUES (?,?,?,?,?,?,?,?,?)",
              [($a['lease_id'] ?? null) ?: null, ($a['weg_unit_id'] ?? null) ?: null, $tx['booking_date'], 'payment', $a['category'] ?? 'other', $tx['amount'], $text, $txId, 'bank']);
        } elseif ($a['action'] === 'expense') {
            $y = substr($tx['booking_date'], 0, 4);
            q("INSERT INTO expense (property_id, building_id, cost_type_id, vendor_id, invoice_date, period_from, period_to, amount, paid_date, text, bank_tx_id, source)
               VALUES (1,?,?,?,?,?,?,?,?,?,?,?)",
              [($a['building_id'] ?? null) ?: null, $a['cost_type_id'], ($a['vendor_id'] ?? null) ?: null, $tx['booking_date'], $a['period_from'] ?? "$y-01-01", $a['period_to'] ?? "$y-12-31",
               -(float)$tx['amount'], $tx['booking_date'], $text, $txId, 'bank']);
        }
        q("UPDATE bank_tx SET status=?, note=? WHERE id=?", [$a['action'] === 'ignore' ? 'ignored' : 'booked', mb_substr($a['source'] ?? $a['note'] ?? '', 0, 250) ?: null, $txId]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

/** undo a booking */
function bank_unbook(int $txId): void {
    q("DELETE FROM posting WHERE bank_tx_id=?", [$txId]);
    q("DELETE FROM expense WHERE bank_tx_id=?", [$txId]);
    q("UPDATE bank_tx SET status='open', note=NULL WHERE id=?", [$txId]);
}

/** run the automatic matching over all open transactions */
function bank_auto(): array {
    $n = 0; $left = 0;
    foreach (rows("SELECT * FROM bank_tx WHERE status='open' ORDER BY booking_date") as $tx) {
        $m = bank_match($tx);
        if ($m && in_array($m['action'], ['payment', 'expense', 'ignore'], true) && ($m['action'] !== 'expense' || $m['cost_type_id'])) {
            bank_book((int)$tx['id'], $m); $n++;
        } else $left++;
    }
    return [$n, $left];
}
