<?php
// login; an account is locked after 3 consecutive wrong passwords (unlock: admin under "Benutzer")
const MAX_FAILED = 3;
$err = null;
if (is_post()) {
    $name = trim($_POST['user'] ?? '');
    $u = row("SELECT * FROM app_user WHERE username=?", [$name]);
    if ($u && $u['locked']) {
        auth_log('FAIL', $name);
        $err = 'Benutzer gesperrt (3 falsche Passwörter). Bitte einen Administrator entsperren lassen.';
    } elseif ($u && password_verify($_POST['pass'] ?? '', $u['pw_hash'])) {
        q("UPDATE app_user SET failed_logins=0, last_login=NOW() WHERE id=?", [$u['id']]);
        auth_log('OK', $name);
        session_regenerate_id(true);
        $_SESSION['user'] = $u['username'];
        $_SESSION['is_admin'] = (bool)$u['is_admin'];
        redirect($_SESSION['after_login'] ?? url('dashboard'));
    } else {
        if ($u) {
            q("UPDATE app_user SET failed_logins=failed_logins+1, locked=(failed_logins>=?) WHERE id=?", [MAX_FAILED, $u['id']]);
            $left = MAX_FAILED - ((int)$u['failed_logins'] + 1);
            $err = $left > 0 ? "Benutzer oder Passwort falsch. Noch $left Versuch" . ($left > 1 ? 'e' : '') . ' bis zur Sperre.' : 'Passwort falsch – der Benutzer ist jetzt gesperrt.';
            auth_log('FAIL', $name);
        } else {
            auth_log('FAIL', $name);
            $err = 'Benutzer oder Passwort falsch.';
        }
        usleep(800000);
    }
}
page_head('Anmeldung'); ?>
<div class="row justify-content-center mt-5"><div class="col-sm-8 col-md-5 col-lg-4">
 <div class="card shadow-sm"><div class="card-body p-4">
  <h1 class="h4 mb-3"><i class="bi bi-buildings me-2"></i>Hausverwaltung</h1>
  <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?>
   <div class="mb-3"><label class="form-label">Benutzer</label><input class="form-control" name="user" autofocus autocomplete="username" value="<?= h($_POST['user'] ?? '') ?>"></div>
   <div class="mb-3"><label class="form-label">Passwort</label><input class="form-control" type="password" name="pass" autocomplete="current-password"></div>
   <button class="btn btn-primary w-100">Anmelden</button>
  </form>
 </div></div>
</div></div>
<?php page_foot();
