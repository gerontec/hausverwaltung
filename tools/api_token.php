<?php
// api_token.php - manage API tokens for public/api.php
//   php tools/api_token.php create <user> [note]   -> prints the token once (only its sha256 is stored)
//   php tools/api_token.php list
//   php tools/api_token.php revoke <id>
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';

$cmd = $argv[1] ?? '';
if ($cmd === 'create' && isset($argv[2])) {
    if (!val("SELECT 1 FROM app_user WHERE username=?", [$argv[2]])) { fwrite(STDERR, "unknown app user {$argv[2]}\n"); exit(1); }
    $t = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    q("INSERT INTO api_token (username, token_hash, note) VALUES (?,?,?)", [$argv[2], hash('sha256', $t), $argv[3] ?? null]);
    echo $t, "\n";
} elseif ($cmd === 'list') {
    foreach (rows("SELECT id, username, note, created_at, last_used, revoked FROM api_token ORDER BY id") as $r)
        echo implode("\t", array_map(fn($v) => $v ?? '-', $r)), "\n";
} elseif ($cmd === 'revoke' && isset($argv[2])) {
    q("UPDATE api_token SET revoked=1 WHERE id=?", [(int)$argv[2]]);
    echo "revoked\n";
} else {
    fwrite(STDERR, "usage: api_token.php create <user> [note] | list | revoke <id>\n"); exit(2);
}
