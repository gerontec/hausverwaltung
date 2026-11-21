#!/usr/bin/env python3
"""mayan_bridge.py - local HTTP bridge (127.0.0.1:18095) from the HV web app to Mayan on another host.

Runs as the user owning the SSH key (systemd: hv-mayan-bridge.service). Every request runs ~/hv_mayan/hv_mayan.py
on the Mayan host over a persistent SSH master connection; the Mayan token never leaves that host.
Settings: data/config.ini [mayan] ssh_host, root_cabinet. Requests must carry the shared key from
/srv/hausverwaltung/data/bridge.key in the header X-HV-Key.

  GET /search?q=...&limit=25&all=0   -> JSON
  GET /info?id=N                     -> JSON
  GET /file?id=N                     -> file bytes
  GET /import?id=N                   -> fetch document N into the HV Posteingang (tools/mayan_inbox.php --id=N)
"""
import configparser
import hmac
import json
import os
import subprocess
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

APP = '/srv/hausverwaltung'
KEY = open(f'{APP}/data/bridge.key').read().strip()
_CFG = configparser.ConfigParser()
_CFG.read(f'{APP}/data/config.ini')
HOST = _CFG.get('mayan', 'ssh_host', fallback='')
ROOT = _CFG.get('mayan', 'root_cabinet', fallback='0')
CTRL = os.path.expanduser('~/.ssh/hv-mayan-%r@%h:%p')
SSH = ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', '-o', 'ControlMaster=auto',
       '-o', f'ControlPath={CTRL}', '-o', 'ControlPersist=10m', HOST]


def remote(args, timeout=90):
    cmd = SSH + [f'HV_ROOT_CABINET={_q(ROOT)} python3 ~/hv_mayan/hv_mayan.py ' + ' '.join(_q(a) for a in args)]
    return subprocess.run(cmd, capture_output=True, timeout=timeout)


def _q(s):
    return "'" + str(s).replace("'", "'\\''") + "'"


class H(BaseHTTPRequestHandler):
    def log_message(self, fmt, *a):
        pass

    def send(self, code, body, ctype='application/json'):
        self.send_response(code)
        self.send_header('Content-Type', ctype)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if not hmac.compare_digest(self.headers.get('X-HV-Key', ''), KEY):
            return self.send(403, b'{"error":"forbidden"}')
        u = urlparse(self.path)
        p = {k: v[0] for k, v in parse_qs(u.query).items()}
        try:
            if u.path == '/search':
                args = ['search', p.get('q', ''), '--limit', str(min(int(p.get('limit', 25)), 100))]
                if p.get('all') == '1':
                    args.append('--all')
                r = remote(args)
            elif u.path == '/info':
                r = remote(['info', str(int(p['id']))])
            elif u.path == '/file':
                r = remote(['file', str(int(p['id']))], timeout=300)
                if r.returncode == 0:
                    return self.send(200, r.stdout, 'application/octet-stream')
            elif u.path == '/import':
                r = subprocess.run(['php', f'{APP}/tools/mayan_inbox.php', f'--id={int(p["id"])}'],
                                   capture_output=True, timeout=300, cwd=APP)
                return self.send(200 if r.returncode == 0 else 500,
                                 json.dumps({'out': r.stdout.decode(), 'err': r.stderr.decode()[-500:]}).encode())
            else:
                return self.send(404, b'{"error":"unknown"}')
        except Exception as e:  # noqa: BLE001
            return self.send(500, json.dumps({'error': str(e)}).encode())
        if r.returncode != 0:
            return self.send(502, json.dumps({'error': r.stderr.decode()[-500:]}).encode())
        self.send(200, r.stdout)


if __name__ == '__main__':
    ThreadingHTTPServer(('127.0.0.1', 18095), H).serve_forever()
