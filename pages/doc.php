<?php
$d = row("SELECT * FROM document WHERE id=?", [(int)($_GET['id'] ?? 0)]);
$path = $d ? UPLOAD_DIR . "/{$d['entity']}/{$d['entity_id']}/{$d['stored_name']}" : null;
if (!$d || !is_file($path)) { http_response_code(404); exit('Dokument nicht gefunden'); }
header('Content-Type: ' . ($d['mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode($d['filename']));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
