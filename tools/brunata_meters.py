#!/usr/bin/env python3
"""brunata_meters.py - meters and readings from the Brunata "Aufstellung der Einzelverbräuche" (pdftotext -layout)
into SQL for the Hausverwaltung DB.

  brunata_meters.py v2019.txt v2020.txt > brunata_meters.sql

Per user unit (Brunata-Nr. 0001-0021 = flat id) and segment (tenant period): heat cost allocators (kind heat,
location = room, note with the rating), hot and cold water meters, plus the main heat meter of the hot water
preparation in the boiler room. Readings are stored at the start and end of every segment. The calibration list
(Eichjahr) gives calibrated_until: hot water 5 years, cold water 6 years (MessEG/MessEV).
"""
import os
import re
import sys

MAIN_BUILDING = int(os.environ.get('HV_MAIN_BUILDING', '1'))   # building id of the boiler room (main meters)

RE_USER = re.compile(r"^\s{1,4}(\d{4})\s{2,}(.+?)\s{2,}(\d\.\d\.\d)\s+(\d{2}\.\d{2}\.\d{4}) - (\d{2}\.\d{2}\.\d{4})")
RE_ALLG = re.compile(r"^\s+Allgemein\s+(\d{2}\.\d{2}\.\d{4}) - (\d{2}\.\d{2}\.\d{4})")
RE_SECT = re.compile(r"^\s+(Heizung in Einheiten|Warmwasser in m³|Kaltwasser in m³|Warmwasser in MWh)")
RE_NUM = r"(\d{1,3}(?:\.\d{3})*(?:,\d+)?|\d+(?:,\d+)?)"
RE_HKV = re.compile(r"^\s(\S.*?)\s{2,}(\d{3,})\s+(\d{3})\s+" + RE_NUM + r"\s+" + RE_NUM + r"\s+" + RE_NUM + r"\s*(.*)$")
RE_HKV_EST = re.compile(r"^\s(\S.*?)\s{2,}(\d{3,})\s+(\d{3})\s+" + RE_NUM + r"\s+(siehe Erläuterungen.*)$")   # estimated (para 9a)
RE_WAT = re.compile(r"^\s(\S.*?)\s{2,}(\d{3,})(\*)?\s*(?:\((\w)\))?\s+(Warmwasserzähler|Kaltwasserzähler|Wärmezähler|Durchflusszähler)"
                    r"(?:\s+" + RE_NUM + r"\s+" + RE_NUM + r"\s+" + RE_NUM + r")?\s*(.*)$")
RE_EICH = re.compile(r"^\s(\d{4})\s+.+?\s(\d\.\d\.\d)\s+(Warmwasserzähler|Kaltwasserzähler)\s+(\S+)\s+(\d+)\*?\s+(\d{4})\s*$")
KIND = {'Heizung in Einheiten': 'heat', 'Warmwasser in m³': 'water_hot', 'Kaltwasser in m³': 'water_cold', 'Warmwasser in MWh': 'heat'}
TYPE_KIND = {'Warmwasserzähler': 'water_hot', 'Kaltwasserzähler': 'water_cold', 'Wärmezähler': 'heat', 'Durchflusszähler': 'water_hot'}


def num(s):
    return float(s.replace('.', '').replace(',', '.'))


def iso(d):
    return f"{d[6:]}-{d[3:5]}-{d[:2]}"


def q(v):
    return 'NULL' if v is None else "'" + str(v).replace("'", "''") + "'"


meters = {}      # (unit, kind, number) -> dict
readings = {}    # (key, date) -> (value, reason, note)
calib = {}       # number -> (kind, year)
for path in sys.argv[1:]:
    user = seg = sect = None
    for ln in open(path, encoding='utf-8'):
        ln = ln.rstrip('\n')
        m = RE_EICH.match(ln)
        if m:
            calib[m.group(5)] = (TYPE_KIND[m.group(3)], int(m.group(6)))
            continue
        m = RE_USER.match(ln)
        if m:
            user, seg, sect = int(m.group(1)), (iso(m.group(4)), iso(m.group(5)), m.group(2).strip()), None
            continue
        m = RE_ALLG.match(ln)
        if m:
            user, seg, sect = 0, (iso(m.group(1)), iso(m.group(2)), 'Allgemein'), None
            continue
        m = RE_SECT.match(ln)
        if m and user is not None:
            sect = m.group(1)
            continue
        if user is None or sect is None or 'RAUMBEZEICHNUNG' in ln or ln.strip().startswith('Summe'):
            continue
        if sect == 'Heizung in Einheiten':
            m = RE_HKV.match(ln)
            if not m:
                me = RE_HKV_EST.match(ln)
                if not me:
                    continue
                room, dev, rating, cons, rem = me.groups()
                a, b = '0', cons            # estimate: allocator restarts at 0, estimated consumption at the end
                est = True
            else:
                room, dev, rating, a, b, cons, rem = m.groups()
                est = False
            key = (user, 'heat', dev)
            meters.setdefault(key, {'room': room.strip(), 'note': f'Brunata HKV, Bewertung {rating}', 'type': 'HKV'})
        else:
            m = RE_WAT.match(ln)
            if not m:
                continue
            room, dev, star, ab, typ, a, b, cons, rem = m.groups()
            est = False
            key = (user, TYPE_KIND[typ], dev)
            meters.setdefault(key, {'room': room.strip(), 'note': f'Brunata {typ}' + (' (Warmwasserbereitung, MWh)' if typ == 'Wärmezähler' else ''), 'type': typ})
            if ab == 'A':
                meters[key]['note'] += '; 2019 ausgebaut'
                meters[key]['inactive'] = True
            if a is None:
                continue
        reason_start = 'move_in' if seg[0][5:] != '01-01' else 'regular'
        reason_end = 'estimate' if est else ('move_out' if seg[1][5:] != '12-31' else 'regular')
        readings.setdefault((key, seg[0]), (num(a), reason_start, seg[2]))
        readings[(key, seg[1])] = (num(b), reason_end, seg[2] + (' (Schätzung Brunata, § 9a HeizkostenV)' if est else ''))

out = ['SET NAMES utf8mb4;', 'START TRANSACTION;',
       "DELETE r FROM meter_reading r JOIN meter m ON m.id=r.meter_id WHERE m.note LIKE 'Brunata%';",
       "DELETE FROM meter WHERE note LIKE 'Brunata%';"]
for (unit, kind, dev), mt in sorted(meters.items()):
    cu = None
    if dev in calib:
        k, y = calib[dev]
        cu = f"{y + (5 if k == 'water_hot' else 6)}-12-31"
    note = mt['note'] + (f'; Eichjahr {calib[dev][1]} (lt. Brunata 2020 nicht mehr eichgültig)' if dev in calib else '')
    if unit:
        out.append(f"INSERT INTO meter (building_id, unit_id, kind, number, location, calibrated_until, active, note) "
                   f"SELECT u.building_id, u.id, '{kind}', {q(dev)}, {q(mt['room'])}, {q(cu)}, 1, {q(note)} FROM unit u WHERE u.property_id=1 AND u.no='{unit}';")
    else:
        out.append(f"INSERT INTO meter (building_id, unit_id, kind, number, location, calibrated_until, active, note) VALUES "
                   f"({MAIN_BUILDING}, NULL, '{kind}', {q(dev)}, {q(mt['room'])}, {q(cu)}, {0 if mt.get('inactive') else 1}, {q(note + '; Hauptzähler')});")
    for (key, d), (v, reason, who) in sorted(readings.items()):
        if key != (unit, kind, dev):
            continue
        out.append(f"INSERT INTO meter_reading (meter_id, reading_date, value, reason, note) SELECT MAX(m.id), '{d}', {v}, '{reason}', {q('Brunata: ' + who)} "
                   f"FROM meter m WHERE m.number={q(dev)} AND m.kind='{kind}' AND m.note LIKE 'Brunata%' "
                   + (f"AND m.unit_id=(SELECT id FROM unit WHERE property_id=1 AND no='{unit}') " if unit else "AND m.unit_id IS NULL ")
                   + "ON DUPLICATE KEY UPDATE value=VALUES(value);")
out.append('COMMIT;')
print('\n'.join(out))
print(f"-- meters {len(meters)}, readings {len(readings)}, calibration entries {len(calib)}", file=sys.stderr)
