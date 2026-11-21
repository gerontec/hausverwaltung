<?php
// api.php - JSON REST API over the generic entities (lib/entities.php)
//
// Auth: header "Authorization: Bearer <token>" (or "X-HV-Token: <token>"); tokens live hashed (sha256) in table api_token,
//       created with `php tools/api_token.php create <user> [note]`. Failures go to auth.log (fail2ban).
//
//   GET    api.php                         -> list of entities with their fields
//   GET    api.php?e=asset                 -> records (filters: any field, e.g. &building_id=1; &q=text; &limit=, &offset=)
//   GET    api.php?e=asset&id=1            -> one record + documents + notes
//   POST   api.php?e=asset                 -> create (JSON body {field: value}), returns the record
//   PATCH  api.php?e=asset&id=1            -> update only the given fields
//   DELETE api.php?e=asset&id=1
//   POST   api.php?e=asset&id=1&action=upload   multipart file[] (+ title)  -> attach documents
//   POST   api.php?e=asset&id=1&action=note     JSON {"text": "..."}       -> add a note
//   POST   api.php?e=asset&id=1&action=share    JSON {"note": "Firma X", "days": 90} -> read-only link with key (facility entities)
//   GET    api.php?doc=ID                  -> download a document
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';
require __DIR__ . '/../lib/entities.php';
require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/share.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(mixed $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit;
}
function fail(string $msg, int $code = 400): never { out(['error' => $msg], $code); }

// --- auth
$hdrs = array_change_key_case(function_exists('getallheaders') ? (getallheaders() ?: []) : []);
$hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $hdrs['authorization'] ?? '';
if ($hdr === '' && isset($_SERVER['HTTP_X_HV_TOKEN'])) $hdr = 'Bearer ' . $_SERVER['HTTP_X_HV_TOKEN'];  // fallback header
if (!preg_match('/^Bearer\s+([A-Za-z0-9_\-]{20,128})$/', $hdr, $m)) {
    auth_log('FAIL', 'api');
    fail('missing or malformed bearer token', 401);
}
$tok = row("SELECT id, username FROM api_token WHERE token_hash=? AND revoked=0", [hash('sha256', $m[1])]);
if (!$tok) { auth_log('FAIL', 'api'); fail('invalid token', 401); }
$user = row("SELECT username, locked FROM app_user WHERE username=?", [$tok['username']]);
if (!$user || $user['locked']) fail('user locked or unknown', 403);
q("UPDATE api_token SET last_used=NOW() WHERE id=?", [$tok['id']]);
$API_USER = 'api:' . $tok['username'];

$E = entities();
$method = $_SERVER['REQUEST_METHOD'];

// --- document download
if (isset($_GET['doc'])) {
    $d = row("SELECT * FROM document WHERE id=?", [(int)$_GET['doc']]);
    if (!$d) fail('not found', 404);
    $f = UPLOAD_DIR . "/{$d['entity']}/{$d['entity_id']}/{$d['stored_name']}";
    if (!is_file($f)) fail('file missing', 404);
    header('Content-Type: ' . ($d['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $d['filename']) . '"');
    readfile($f);
    exit;
}

// --- schema
$e = $_GET['e'] ?? '';
if ($e === '') {
    $s = [];
    foreach ($E as $k => $def) {
        $f = [];
        foreach ($def['fields'] as $fk => $fd) $f[$fk] = ['label' => $fd[0], 'type' => $fd[1]] + (isset($fd[2]) ? ($fd[1] === 'fk' ? ['ref' => $fd[2]] : ['options' => $fd[2]]) : []);
        $s[$k] = ['title' => $def['title'], 'fields' => $f];
    }
    out(['user' => $tok['username'], 'entities' => $s]);
}
if (!isset($E[$e])) fail("unknown entity '$e'", 404);
$def = $E[$e];
$fields = $def['fields'];
$id = (int)($_GET['id'] ?? 0);

/** convert JSON input values to DB values like the edit form does; rejects unknown fields */
function normalize(array $in, array $fields): array {
    $vals = [];
    foreach ($in as $k => $raw) {
        if (!isset($fields[$k])) fail("unknown field '$k'");
        [$label, $type] = $fields[$k]; $x = $fields[$k][2] ?? null;
        if ($raw === null || $raw === '') { $vals[$k] = $type === 'bool' ? 0 : null; continue; }
        $vals[$k] = match ($type) {
            'bool' => $raw ? 1 : 0,
            'int', 'fk' => is_numeric($raw) ? (int)$raw : fail("$k: integer expected"),
            'num', 'money' => is_int($raw) || is_float($raw) ? (float)$raw : (parse_num((string)$raw) ?? fail("$k: number expected")),
            'date' => parse_date((string)$raw) ?? fail("$k: date expected (YYYY-MM-DD)"),
            'enum' => isset($x[$raw]) ? $raw : fail("$k: one of " . implode(', ', array_keys($x))),
            default => is_scalar($raw) ? trim((string)$raw) : fail("$k: string expected"),
        };
    }
    return $vals;
}
function body(): array {
    $j = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($j)) fail('JSON object expected');
    return $j;
}
function one(string $e, int $id): array {
    $r = row("SELECT * FROM `$e` WHERE id=?", [$id]);
    if (!$r) fail('not found', 404);
    $r['_label'] = fk_options($e)[$id] ?? null;
    $r['_documents'] = rows("SELECT id, filename, title, mime, size, uploaded_at FROM document WHERE entity=? AND entity_id=? ORDER BY uploaded_at", [$e, $id]);
    $r['_notes'] = rows("SELECT created_at, author, text FROM note_log WHERE entity=? AND entity_id=? ORDER BY created_at", [$e, $id]);
    return $r;
}

try {
    // --- list
    if ($method === 'GET' && !$id) {
        $where = []; $args = [];
        foreach ($_GET as $k => $v) if (isset($fields[$k])) { $where[] = "`$k`=?"; $args[] = $v; }
        if (($qs = trim($_GET['q'] ?? '')) !== '') {
            $tf = array_keys(array_filter($fields, fn($f) => in_array($f[1], ['text', 'textarea'], true)));
            if ($tf) { $where[] = '(' . implode(' OR ', array_map(fn($k) => "`$k` LIKE ?", $tf)) . ')'; array_push($args, ...array_fill(0, count($tf), "%$qs%")); }
        }
        $limit = max(1, min(1000, (int)($_GET['limit'] ?? 200))); $offset = max(0, (int)($_GET['offset'] ?? 0));
        $sql = "SELECT * FROM `$e`" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY {$def['order']} LIMIT $limit OFFSET $offset";
        out(['entity' => $e, 'items' => rows($sql, $args)]);
    }
    if ($method === 'GET') out(one($e, $id));

    // --- create
    if ($method === 'POST' && !$id) {
        $vals = array_filter(normalize(body(), $fields), fn($v) => $v !== null);
        if (!$vals) fail('no fields');
        q("INSERT INTO `$e` (" . implode(',', array_map(fn($k) => "`$k`", array_keys($vals))) . ") VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ")", array_values($vals));
        $id = (int)db()->lastInsertId();
        q("INSERT INTO note_log (entity, entity_id, author, text) VALUES (?,?,?,?)", [$e, $id, $API_USER, 'angelegt über API']);
        out(one($e, $id), 201);
    }
    if (!val("SELECT 1 FROM `$e` WHERE id=?", [$id])) fail('not found', 404);
    $action = $_GET['action'] ?? '';

    // --- upload
    if ($method === 'POST' && $action === 'upload') {
        $files = $_FILES['file'] ?? null;
        if (!$files) fail('multipart field file[] missing');
        $n = is_array($files['name']) ? count($files['name']) : 1; $done = [];
        for ($i = 0; $i < $n; $i++) {
            $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
            $tmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            if ((is_array($files['error']) ? $files['error'][$i] : $files['error']) !== UPLOAD_ERR_OK) fail("upload failed: $name");
            $dir = UPLOAD_DIR . "/$e/$id";
            if (!is_dir($dir)) mkdir($dir, 0770, true);
            $stored = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^\w.\-]/u', '_', $name);
            move_uploaded_file($tmp, "$dir/$stored");
            q("INSERT INTO document (entity, entity_id, filename, stored_name, mime, size, title) VALUES (?,?,?,?,?,?,?)",
              [$e, $id, $name, $stored, mime_content_type("$dir/$stored") ?: 'application/octet-stream', filesize("$dir/$stored"), trim($_POST['title'] ?? '') ?: null]);
            $done[] = (int)db()->lastInsertId();
        }
        out(['uploaded' => $done], 201);
    }
    // --- note
    if ($method === 'POST' && $action === 'note') {
        $t = trim((string)(body()['text'] ?? ''));
        if ($t === '') fail('text missing');
        q("INSERT INTO note_log (entity, entity_id, author, text) VALUES (?,?,?,?)", [$e, $id, $API_USER, $t]);
        out(['ok' => true], 201);
    }
    // --- share link
    if ($method === 'POST' && $action === 'share') {
        if (!isset(SHARE_ENTITIES[$e])) fail("entity $e cannot be shared (only " . implode(', ', array_keys(SHARE_ENTITIES)) . ')');
        $b = body();
        $key = share_create($e, $id, isset($b['note']) ? trim((string)$b['note']) : null, (int)($b['days'] ?? 90), $API_USER);
        q("INSERT INTO note_log (entity, entity_id, author, text) VALUES (?,?,?,?)", [$e, $id, $API_USER, 'Freigabe-Link erstellt' . (!empty($b['note']) ? ' für ' . $b['note'] : '')]);
        out(['url' => share_url($key), 'expires_days' => (int)($b['days'] ?? 90)], 201);
    }
    // --- update
    if ($method === 'PATCH' || $method === 'PUT') {
        $vals = normalize(body(), $fields);
        if (!$vals) fail('no fields');
        q("UPDATE `$e` SET " . implode(', ', array_map(fn($k) => "`$k`=?", array_keys($vals))) . " WHERE id=?", [...array_values($vals), $id]);
        q("INSERT INTO note_log (entity, entity_id, author, text) VALUES (?,?,?,?)", [$e, $id, $API_USER, 'geändert über API: ' . implode(', ', array_keys($vals))]);
        out(one($e, $id));
    }
    // --- delete
    if ($method === 'DELETE') {
        q("DELETE FROM `$e` WHERE id=?", [$id]);
        out(['deleted' => $id]);
    }
    fail('method not allowed', 405);
} catch (PDOException $ex) {
    error_log('hv api: ' . $ex->getMessage());
    fail($ex->errorInfo[2] ?? 'database error', 409);
}
