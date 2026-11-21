<?php
// own password; admins: unlock, reset password, add users
$me = row("SELECT * FROM app_user WHERE username=?", [current_user()]);
$admin = (bool)$me['is_admin'];
if (is_post()) {
    $act = $_POST['_action'] ?? '';
    if ($act === 'own') {
        if (!password_verify($_POST['old'] ?? '', $me['pw_hash'])) flash('Bisheriges Passwort falsch.', 'danger');
        elseif (strlen($_POST['new'] ?? '') < 8) flash('Neues Passwort: mindestens 8 Zeichen.', 'danger');
        elseif ($_POST['new'] !== ($_POST['new2'] ?? '')) flash('Die beiden neuen Passwörter sind verschieden.', 'danger');
        else { q("UPDATE app_user SET pw_hash=? WHERE id=?", [password_hash($_POST['new'], PASSWORD_DEFAULT), $me['id']]); flash('Passwort geändert.'); }
    } elseif ($admin && $act === 'unlock') {
        q("UPDATE app_user SET locked=0, failed_logins=0 WHERE id=?", [(int)$_POST['id']]); flash('Benutzer entsperrt.');
    } elseif ($admin && $act === 'reset' && strlen($_POST['pw'] ?? '') >= 6) {
        q("UPDATE app_user SET pw_hash=?, locked=0, failed_logins=0 WHERE id=?", [password_hash($_POST['pw'], PASSWORD_DEFAULT), (int)$_POST['id']]); flash('Passwort gesetzt.');
    } elseif ($admin && $act === 'add' && preg_match('/^[a-z0-9._-]{2,30}$/', $_POST['username'] ?? '') && strlen($_POST['pw'] ?? '') >= 6) {
        q("INSERT INTO app_user (username, full_name, pw_hash, is_admin) VALUES (?,?,?,?)", [$_POST['username'], trim($_POST['full_name'] ?? '') ?: null, password_hash($_POST['pw'], PASSWORD_DEFAULT), !empty($_POST['is_admin']) ? 1 : 0]);
        flash('Benutzer angelegt.');
    } else flash('Eingabe unvollständig.', 'danger');
    redirect(url('users'));
}
page_head('Benutzer'); ?>
<h1 class="h3 mb-3"><i class="bi bi-person-gear me-2"></i>Benutzer</h1>
<div class="row g-3">
<div class="col-lg-4"><form method="post" class="card"><div class="card-header">Eigenes Passwort ändern (<?= h($me['username']) ?>)</div><div class="card-body"><?= csrf_field() ?><input type="hidden" name="_action" value="own">
 <input class="form-control mb-2" type="password" name="old" placeholder="bisheriges Passwort" autocomplete="current-password">
 <input class="form-control mb-2" type="password" name="new" placeholder="neues Passwort (mind. 8 Zeichen)" autocomplete="new-password">
 <input class="form-control mb-2" type="password" name="new2" placeholder="neues Passwort wiederholen" autocomplete="new-password">
 <button class="btn btn-primary">ändern</button></div></form></div>
<?php if ($admin): ?>
<div class="col-lg-8"><div class="card"><div class="card-header">Alle Benutzer</div>
 <table class="table table-sm mb-0 align-middle"><thead><tr><th>Benutzer</th><th>Name</th><th>Admin</th><th>Fehlversuche</th><th>Status</th><th>letzte Anmeldung</th><th></th></tr></thead><tbody>
 <?php foreach (rows("SELECT * FROM app_user ORDER BY username") as $u): ?>
  <tr><td><?= h($u['username']) ?></td><td><?= h($u['full_name']) ?></td><td><?= $u['is_admin'] ? '✓' : '' ?></td><td><?= (int)$u['failed_logins'] ?></td>
   <td><?= $u['locked'] ? badge('gesperrt', 'danger') : badge('aktiv', 'success') ?></td><td><?= $u['last_login'] ? date('d.m.Y H:i', strtotime($u['last_login'])) : '' ?></td>
   <td class="text-end"><form method="post" class="d-flex gap-1 justify-content-end"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>">
    <?php if ($u['locked']): ?><button class="btn btn-sm btn-outline-success py-0" name="_action" value="unlock">entsperren</button><?php endif; ?>
    <input class="form-control form-control-sm py-0" style="width:9rem" type="password" name="pw" placeholder="neues Passwort" autocomplete="new-password"><button class="btn btn-sm btn-outline-secondary py-0" name="_action" value="reset">setzen</button></form></td></tr>
 <?php endforeach; ?></tbody></table>
 <form method="post" class="card-footer d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="_action" value="add">
  <input class="form-control form-control-sm" name="username" placeholder="Benutzername"><input class="form-control form-control-sm" name="full_name" placeholder="Name">
  <input class="form-control form-control-sm" type="password" name="pw" placeholder="Passwort" autocomplete="new-password">
  <div class="form-check align-self-center"><input class="form-check-input" type="checkbox" name="is_admin" value="1" id="ia"><label class="form-check-label small" for="ia">Admin</label></div>
  <button class="btn btn-sm btn-primary">anlegen</button></form>
</div></div>
<?php endif; ?>
</div>
<?php page_foot();
