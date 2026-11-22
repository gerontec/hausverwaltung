<?php
// Hausverwaltung - front controller
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';
require __DIR__ . '/../lib/entities.php';
require __DIR__ . '/../lib/layout.php';
require __DIR__ . '/../lib/calc.php';
require __DIR__ . '/../lib/bank.php';
require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/share.php';
require __DIR__ . '/../lib/report.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_name('hvsess');
session_start();

$p = preg_replace('/[^a-z_]/', '', $_GET['p'] ?? 'dashboard');
if ($p !== 'login' && $p !== 'share') require_login();   // share: password-free read-only link (key checked in pages/share.php)
csrf_check();

$file = __DIR__ . "/../pages/$p.php";
if (!is_file($file)) { http_response_code(404); $p = 'notfound'; $file = __DIR__ . '/../pages/notfound.php'; }
try {
    require $file;
} catch (PDOException $e) {
    error_log('hv: ' . $e->getMessage());
    if (!headers_sent()) page_head('Fehler');
    echo '<div class="alert alert-danger"><b>Datenbankfehler:</b> ' . h($e->getMessage()) . '</div>';
    page_foot();
}
