<?php
// Mayan: view a document (proxy) or import it into the Posteingang
require_once APP_ROOT . '/lib/mayan.php';
if (isset($_GET['file'])) {
    $id = (int)$_GET['file'];
    try {
        $info = mayan_call('/info', ['id' => $id]);
        $data = mayan_call('/file', ['id' => $id], 300, true);
    } catch (Throwable $e) { http_response_code(502); exit(h($e->getMessage())); }
    header('Content-Type: ' . ($info['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode($info['filename'] ?: "mayan_$id"));
    header('X-Content-Type-Options: nosniff');
    echo $data; exit;
}
if (is_post() && isset($_POST['import'])) {
    $id = (int)$_POST['import'];
    $have = row("SELECT id FROM inbox_doc WHERE mayan_id=?", [$id]);
    if (!$have) {
        try { mayan_call('/import', ['id' => $id], 300); }
        catch (Throwable $e) { flash(h($e->getMessage()), 'danger'); redirect($_POST['_back'] ?? url('search')); }
        $have = row("SELECT id FROM inbox_doc WHERE mayan_id=?", [$id]);
    }
    if ($have) { q("UPDATE inbox_doc SET status='new' WHERE id=? AND status='ignored'", [$have['id']]); redirect(url('inbox', ['id' => $have['id'], 'status' => 'all'])); }
    flash('Übernahme fehlgeschlagen.', 'danger');
    redirect($_POST['_back'] ?? url('search'));
}
redirect(url('search'));
