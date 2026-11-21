<?php
// share.php - password-free read-only share links ("Link mit Key") for facility records
// The key is shown once at creation; only its sha256 is stored (table share_link).
declare(strict_types=1);

/** entities that may be shared, with the child tables shown on the share page */
const SHARE_ENTITIES = [
    'asset' => [['maintenance_plan', 'asset_id'], ['ticket', 'asset_id']],
    'maintenance_plan' => [['maintenance_log', 'plan_id']],
    'ticket' => [],
    'meter' => [['meter_reading', 'meter_id']],
];
/** fields never shown on a share page (money, internal) */
const SHARE_HIDDEN_TYPES = ['money'];
const SHARE_HIDDEN_FIELDS = ['reported_by', 'bearer', 'allocatable'];

function share_base_url(): string {
    $b = cfg('app', 'base_url');
    if ($b) return rtrim($b, '/') . '/';
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}
function share_url(string $key): string { return share_base_url() . '?p=share&k=' . $key; }

function share_create(string $e, int $id, ?string $note, int $days, string $by): string {
    if (!isset(SHARE_ENTITIES[$e])) throw new InvalidArgumentException("entity $e cannot be shared");
    $key = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    q("INSERT INTO share_link (token_hash, entity, entity_id, note, created_by, expires_at) VALUES (?,?,?,?,?,?)",
      [hash('sha256', $key), $e, $id, $note ?: null, $by, $days > 0 ? date('Y-m-d 23:59:59', strtotime("+$days days")) : null]);
    return $key;
}
function share_resolve(string $key): ?array {
    if (!preg_match('/^[A-Za-z0-9_\-]{20,64}$/', $key)) return null;
    $s = row("SELECT * FROM share_link WHERE token_hash=? AND revoked=0 AND (expires_at IS NULL OR expires_at>=NOW())", [hash('sha256', $key)]);
    if ($s) q("UPDATE share_link SET last_used=NOW(), uses=uses+1 WHERE id=?", [$s['id']]);
    return $s;
}

/** fk label for the share page: persons only if they are vendors (firms), never tenants/owners */
function share_fk_label(string $ref, mixed $v): string {
    if ($v === null || $v === '') return '';
    if ($ref === 'person') {
        $p = row("SELECT * FROM person WHERE id=?", [$v]);
        return $p && $p['is_vendor'] ? person_label($p) : '';
    }
    return fk_options($ref)[$v] ?? '';
}
