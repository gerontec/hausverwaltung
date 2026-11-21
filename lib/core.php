<?php
// core.php - database, session, auth, helpers
declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');

const APP_ROOT = __DIR__ . '/..';
const UPLOAD_DIR = APP_ROOT . '/data/uploads';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $ini = parse_ini_file(APP_ROOT . '/data/my.cnf', true, INI_SCANNER_RAW);
        $c = $ini['client'];
        $pdo = new PDO('mysql:host=localhost;dbname=' . $c['database'] . ';charset=utf8mb4', $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function q(string $sql, array $args = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}
function rows(string $sql, array $args = []): array { return q($sql, $args)->fetchAll(); }
function row(string $sql, array $args = []): ?array { $r = q($sql, $args)->fetch(); return $r === false ? null : $r; }
function val(string $sql, array $args = []): mixed { $v = q($sql, $args)->fetchColumn(); return $v === false ? null : $v; }

function h(mixed $s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

function money(mixed $v, bool $blankZero = false): string {
    if ($v === null || $v === '') return '';
    $f = (float)$v;
    if ($blankZero && abs($f) < 0.005) return '';
    return number_format($f, 2, ',', '.') . ' €';
}
function num(mixed $v, int $d = 2): string { return $v === null || $v === '' ? '' : number_format((float)$v, $d, ',', '.'); }
function dmy(?string $d): string { return $d ? date('d.m.Y', strtotime($d)) : ''; }
/** parse German or ISO number input */
function parse_num(?string $s): ?float {
    $s = trim((string)$s);
    if ($s === '') return null;
    $s = str_replace([' ', '€'], '', $s);
    if (preg_match('/^-?[\d.]*,\d+$/', $s)) $s = str_replace(['.', ','], ['', '.'], $s);
    return is_numeric($s) ? (float)$s : null;
}
/** parse dd.mm.yyyy or yyyy-mm-dd */
function parse_date(?string $s): ?string {
    $s = trim((string)$s);
    if ($s === '') return null;
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $s, $m)) {
        $y = (int)$m[3]; if ($y < 100) $y += 2000;
        return sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    return null;
}

function url(string $p, array $args = []): string {
    return '?' . http_build_query(['p' => $p] + $args);
}
function redirect(string $to): never { header('Location: ' . $to); exit; }

function flash(?string $msg = null, string $type = 'success'): array {
    if ($msg !== null) { $_SESSION['flash'][] = [$type, $msg]; return []; }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">'; }
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf_token(), $_POST['_csrf'] ?? '')) {
        http_response_code(400); exit('CSRF-Token ungültig – Seite neu laden.');
    }
}
function is_post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }

function current_user(): ?string { return $_SESSION['user'] ?? null; }
function require_login(): void {
    if (!current_user()) redirect(url('login'));
}

function person_label(?array $p): string {
    if (!$p) return '';
    $n = trim(($p['last_name'] ?? '') . (($p['first_name'] ?? '') ? ', ' . $p['first_name'] : ''));
    if (!empty($p['company'])) $n = $p['company'] . ($n ? " ($n)" : '');
    return $n;
}
const PERSON_LABEL_SQL = "TRIM(CONCAT(COALESCE(company,''), IF(company IS NOT NULL AND last_name IS NOT NULL,' – ',''), COALESCE(last_name,''), IF(first_name IS NOT NULL AND first_name<>'', CONCAT(', ', first_name), '')))";

const CATEGORY_LABEL = [
    'rent' => 'Kaltmiete', 'operating_advance' => 'NK-Vorauszahlung', 'heating_advance' => 'HK-Vorauszahlung',
    'parking' => 'Stellplatz/Garage', 'hausgeld' => 'Hausgeld', 'reserve' => 'Erhaltungsrücklage',
    'deposit' => 'Kaution', 'settlement' => 'Abrechnung', 'dunning_fee' => 'Mahngebühr', 'other' => 'Sonstiges',
];

/** balance of a lease (positive = tenant owes) up to a date */
function lease_balance(int $leaseId, ?string $until = null): float {
    $until ??= '9999-12-31';
    return (float)val("SELECT COALESCE(SUM(CASE WHEN kind='charge' THEN amount ELSE -amount END),0) FROM posting WHERE lease_id=? AND posting_date<=?", [$leaseId, $until]);
}
function weg_balance(int $wegId, ?string $until = null): float {
    $until ??= '9999-12-31';
    return (float)val("SELECT COALESCE(SUM(CASE WHEN kind='charge' THEN amount ELSE -amount END),0) FROM posting WHERE weg_unit_id=? AND posting_date<=?", [$wegId, $until]);
}

/** rent row valid on a date */
function rent_at(int $leaseId, string $date): ?array {
    return row("SELECT * FROM lease_rent WHERE lease_id=? AND valid_from<=? ORDER BY valid_from DESC LIMIT 1", [$leaseId, $date]);
}

function days_between(string $a, string $b): int { // inclusive
    return (int)((strtotime($b) - strtotime($a)) / 86400) + 1;
}
function overlap_days(?string $s1, ?string $e1, string $s2, string $e2): int {
    $s = max($s1 ?? '0000-01-01', $s2);
    $e = min($e1 ?? '9999-12-31', $e2);
    return $s > $e ? 0 : days_between($s, $e);
}

/** auth log for fail2ban (jail hv-login): one line per login attempt */
function auth_log(string $result, string $user): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    $line = sprintf("%s %s user=%s ip=%s\n", date('Y-m-d H:i:s'), $result, preg_replace('/[^\w.@-]/', '_', mb_substr($user, 0, 40)), $ip);
    @file_put_contents('/var/log/hausverwaltung/auth.log', $line, FILE_APPEND | LOCK_EX);
}
