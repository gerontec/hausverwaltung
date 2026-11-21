#!/usr/bin/env python3
"""hv_mayan.py - Mayan REST API helper for the Hausverwaltung (runs on the Mayan host).

The Mayan token stays on this host (~/.config/mayan_textbot_token); the HV server calls this script over SSH.

  hv_mayan.py search QUERY [--limit N] [--all]   JSON list of documents (full text incl. OCR);
                                                  default only cabinet $HV_ROOT_CABINET and below
  hv_mayan.py info ID                             JSON with label, file, cabinets
  hv_mayan.py file ID                             raw bytes of the latest file to stdout
"""
import json
import os
import sys
import urllib.parse
import urllib.request

API = 'http://127.0.0.1:89/api/v4'
TOKEN = open(os.path.expanduser('~/.config/mayan_textbot_token')).read().strip()
ROOT_CABINET = int(os.environ.get('HV_ROOT_CABINET', '0'))


def req(path):
    if path.startswith('http'):
        path = path.split('/api/v4', 1)[1]
    r = urllib.request.Request(API + path, headers={'Authorization': 'Token ' + TOKEN})
    return urllib.request.urlopen(r, timeout=60)


def get(path):
    return json.load(req(path))


def cabinets():
    out, u = {}, '/cabinets/?page_size=100'
    while u:
        d = get(u)
        for c in d['results']:
            out[c['id']] = c
        u = d.get('next')
    return out


def in_tree(cab, cabs):
    c = cab
    while c:
        if c['id'] == ROOT_CABINET:
            return True
        c = cabs.get(c.get('parent_id'))
    return False


def doc_cabinets(did):
    return get(f'/documents/{did}/cabinets/?page_size=50')['results']


def search(q, limit=25, everything=False):
    cabs = cabinets()
    res, u = [], '/search/documents.documentsearchresult/?' + urllib.parse.urlencode({'q': q, 'page_size': 50})
    while u and len(res) < limit:
        d = get(u)
        for doc in d['results']:
            dc = doc_cabinets(doc['id'])
            if not everything and not any(in_tree(cabs.get(c['id'], c), cabs) for c in dc):
                continue
            f = doc.get('file_latest') or {}
            res.append({'id': doc['id'], 'label': doc['label'], 'created': doc.get('datetime_created'),
                        'type': (doc.get('document_type') or {}).get('label'),
                        'cabinets': [c.get('full_path') or c['label'] for c in dc],
                        'filename': f.get('filename'), 'mime': f.get('mimetype'), 'size': f.get('size')})
            if len(res) >= limit:
                break
        u = d.get('next')
    return res


def info(did):
    d = get(f'/documents/{did}/')
    f = d.get('file_latest') or {}
    return {'id': d['id'], 'label': d['label'], 'created': d.get('datetime_created'),
            'cabinets': [c.get('full_path') or c['label'] for c in doc_cabinets(did)],
            'filename': f.get('filename'), 'mime': f.get('mimetype'), 'size': f.get('size'), 'file_id': f.get('id')}


def main():
    a = sys.argv[1:]
    if not a:
        sys.exit(__doc__)
    cmd = a[0]
    if cmd == 'search':
        limit = int(a[a.index('--limit') + 1]) if '--limit' in a else 25
        q = ' '.join(x for i, x in enumerate(a[1:], 1) if x not in ('--all', '--limit') and a[i - 1] != '--limit')
        print(json.dumps(search(q, limit, '--all' in a), ensure_ascii=False))
    elif cmd == 'info':
        print(json.dumps(info(int(a[1])), ensure_ascii=False))
    elif cmd == 'file':
        i = info(int(a[1]))
        r = req(f"/documents/{i['id']}/files/{i['file_id']}/download/")
        out = sys.stdout.buffer
        while True:
            b = r.read(1 << 16)
            if not b:
                break
            out.write(b)
    else:
        sys.exit(__doc__)


if __name__ == '__main__':
    main()
