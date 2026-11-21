<?php
// mayan_inbox.php - fetch new documents from Mayan (cabinet of the property and below, data/config.ini [mayan])
// into the HV Posteingang. Mayan stays untouched; known Mayan ids are kept in inbox_doc.
//
//   php tools/mayan_inbox.php            fetch documents newer than the baseline
//   php tools/mayan_inbox.php --baseline set the baseline to the newest Mayan id (nothing fetched)
//   php tools/mayan_inbox.php --all      also fetch older documents of the cabinets
//   php tools/mayan_inbox.php --id=N     fetch one document (any cabinet), e.g. from the HV search
//
// Runs from cron as the user that owns the SSH key for the Mayan host; talks to Mayan by running a small
// Python script on that host over SSH (the Mayan API token stays there).
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';
require __DIR__ . '/../lib/config.php';

define('SSH_HOST', (string)cfg('mayan', 'ssh_host', ''));
define('ROOT_CABINET', (int)cfg('mayan', 'root_cabinet', 0));
if (SSH_HOST === '' || ROOT_CABINET === 0) { fwrite(STDERR, "mayan not configured (data/config.ini [mayan] ssh_host, root_cabinet)\n"); exit(1); }
const INBOX_DIR = APP_ROOT . '/data/inbox';

$opt = getopt('', ['baseline', 'all', 'verbose', 'id:']);
$baseline = (int)(val("SELECT v FROM app_setting WHERE k='mayan_baseline'") ?? 0);
$known = array_map('intval', array_column(rows("SELECT mayan_id FROM inbox_doc"), 'mayan_id'));

$remote = <<<'PY'
import json, os, sys, urllib.request
T = open(os.path.expanduser('~/.config/mayan_textbot_token')).read().strip()
A = 'http://127.0.0.1:89/api/v4'
args = json.loads(sys.argv[1])
def get(u):
    r = urllib.request.Request(u if u.startswith('http') else A + u, headers={'Authorization': 'Token ' + T})
    return urllib.request.urlopen(r, timeout=60)
def pages(u):
    while u:
        d = json.load(get(u)); yield from d['results']; u = d.get('next')
cabs = {c['id']: c for c in pages('/cabinets/?page_size=100')}
def under(cid):
    c = cabs.get(cid)
    while c:
        if c['id'] == args['root']: return True
        c = cabs.get(c.get('parent_id'))
    return False
tree = [cid for cid in cabs if under(cid)]
docs = {}
for did in args.get('ids', []):
    d = json.load(get(f'/documents/{did}/'))
    dc = json.load(get(f'/documents/{did}/cabinets/'))['results']
    docs[did] = (d, (dc[0].get('full_path') or dc[0]['label']) if dc else '')
for cid in ([] if args.get('ids') else tree):
    for d in pages(f'/cabinets/{cid}/documents/?page_size=100'):
        docs.setdefault(d['id'], (d, cabs[cid].get('full_path') or cabs[cid]['label']))
if args['mode'] == 'baseline':
    print(json.dumps({'max': max(docs) if docs else 0})); sys.exit()
os.makedirs('/tmp/hvinbox', exist_ok=True)
out = []
for did, (d, cab) in sorted(docs.items()):
    if did in args['known'] or (args['mode'] == 'new' and did <= args['baseline']): continue
    f = d.get('file_latest') or {}
    if not f: continue
    meta = {}
    try:
        for m in pages(f'/documents/{did}/metadata/'):
            meta[m['metadata_type']['name']] = m['value']
    except Exception: pass
    ext = os.path.splitext(f.get('filename') or '')[1] or '.bin'
    path = f'/tmp/hvinbox/{did}{ext}'
    open(path, 'wb').write(get(f"/documents/{did}/files/{f['id']}/download/").read())
    out.append({'id': did, 'label': d['label'], 'created': d.get('datetime_created'), 'cabinet': cab,
                'filename': f.get('filename'), 'mime': f.get('mimetype'), 'size': f.get('size'), 'path': path,
                'mail_from': meta.get('mail_from'), 'mail_subject': meta.get('mail_subject')})
    if len(out) >= args['limit']: break
print(json.dumps(out))
PY;

function remote(string $py, array $args): mixed {
    $cmd = ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', SSH_HOST, 'python3 - ' . escapeshellarg(json_encode($args))];
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $py); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    $rc = proc_close($p);
    if ($rc !== 0) { fwrite(STDERR, "remote failed ($rc): $err\n"); exit(1); }
    return json_decode($out, true);
}

if (isset($opt['baseline'])) {
    $r = remote($remote, ['root' => ROOT_CABINET, 'mode' => 'baseline', 'known' => [], 'baseline' => 0, 'limit' => 0]);
    q("REPLACE INTO app_setting (k, v) VALUES ('mayan_baseline', ?)", [(string)$r['max']]);
    echo "baseline = {$r['max']}\n";
    exit;
}

$ids = isset($opt['id']) ? array_map('intval', (array)$opt['id']) : [];
$docs = remote($remote, ['root' => ROOT_CABINET, 'mode' => $ids ? 'ids' : (isset($opt['all']) ? 'all' : 'new'), 'ids' => $ids,
                         'known' => $known, 'baseline' => $baseline, 'limit' => 50]);
if (!$docs) { if (isset($opt['verbose'])) echo "nothing new\n"; exit; }
if (!is_dir(INBOX_DIR)) mkdir(INBOX_DIR, 0770, true);
foreach ($docs as $d) {
    $stored = $d['id'] . '_' . preg_replace('/[^\w.\-]/u', '_', $d['filename'] ?: 'dokument');
    $dst = INBOX_DIR . '/' . $stored;
    exec('scp -q -o BatchMode=yes ' . escapeshellarg(SSH_HOST . ':' . $d['path']) . ' ' . escapeshellarg($dst), $o, $rc);
    if ($rc !== 0) { fwrite(STDERR, "scp failed for {$d['id']}\n"); continue; }
    $text = '';
    if (str_contains((string)$d['mime'], 'pdf')) $text = (string)shell_exec('pdftotext -layout -l 5 ' . escapeshellarg($dst) . ' - 2>/dev/null');
    q("INSERT IGNORE INTO inbox_doc (mayan_id, label, received_at, cabinet, mail_from, mail_subject, filename, stored_name, mime, size, text) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
      [$d['id'], $d['label'], $d['created'] ? date('Y-m-d H:i:s', strtotime($d['created'])) : null, $d['cabinet'], $d['mail_from'], $d['mail_subject'],
       $d['filename'], $stored, $d['mime'], $d['size'], mb_substr($text, 0, 60000)]);
    @chmod($dst, 0660);
    echo "fetched {$d['id']} {$d['label']}\n";
}
shell_exec('ssh -o BatchMode=yes ' . SSH_HOST . ' rm -rf /tmp/hvinbox');
