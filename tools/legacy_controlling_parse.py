#!/usr/bin/env python3
"""legacy_controlling_parse.py - parse "Controlling" reports (Debitoren-Controlling / Kontoauszug per account) of a former
property manager's accounting software, extracted with `pdftotext -layout`, into JSON.

Account statements ("Kontoauszug Konto Nr. ...") are parsed into bookings; the Soll/Haben
column of an amount is decided by its horizontal position relative to the header line.

Usage: legacy_controlling_parse.py REPORT2023.txt REPORT2024.txt ... > legacy.json
"""
import json
import re
import sys

RE_HEAD = re.compile(r"^Kontoauszug Konto Nr\. ([\d,]+)\s*(.*)$")
RE_DATE = re.compile(r"\b(\d{2})\.(\d{2})\.(\d{4})\b")
RE_AMT = re.compile(r"(?<![\d,.])(\d{1,3}(?:\.\d{3})+,\d{2}|\d+,\d{2})(?![\d,])")
RE_BAL = re.compile(r"^Kontostand ([\d,]+) am (\d{2}\.\d{2}\.\d{4}) (Soll|Haben) EUR\s+([\d.,]+)")


def amount(s):
    return float(s.replace(".", "").replace(",", "."))


def parse(path, accounts):
    lines = open(path, encoding="utf-8").read().splitlines()
    cur = None
    split = None          # column boundary between Soll and Haben
    for ln in lines:
        m = RE_HEAD.match(ln)
        if m:
            no, name = m.group(1), m.group(2).strip()
            cur = accounts.setdefault(no, {"no": no, "name": name, "bookings": [], "opening": {}, "closing": {}})
            if name and not cur["name"]:
                cur["name"] = name
            split = None
            continue
        if cur is None:
            continue
        if ln.startswith("Buchungstext") and "HabenUms" in ln:
            split = ln.index("HabenUms") - 2
            continue
        m = RE_BAL.match(ln)
        if m:
            sign = 1 if m.group(3) == "Soll" else -1
            d = m.group(2)
            cur["closing"][d[6:] + "-" + d[3:5] + "-" + d[:2]] = sign * amount(m.group(4))
            cur = None
            continue
        if split is None or ln.startswith("Umsätze im Berichts") or ln.startswith("Buchungen ab"):
            continue
        amts = list(RE_AMT.finditer(ln))
        if not amts:
            continue
        a = amts[-1]
        side = "S" if a.end() <= split + 4 else "H"
        val = amount(a.group(1))
        if ln.startswith("Saldovortrag"):
            year = None
            mm = re.search(r"(\d{4})", path)
            cur["opening"][path] = (1 if side == "S" else -1) * val
            continue
        dm = RE_DATE.search(ln)
        if not dm:
            continue
        text = ln[:48].rstrip()
        rest = ln[48:dm.start()].split()
        beleg = rest[0] if len(rest) >= 2 else ""
        contra = rest[-1] if rest else ""
        cur["bookings"].append({
            "date": f"{dm.group(3)}-{dm.group(2)}-{dm.group(1)}",
            "text": text.strip(), "beleg": beleg, "contra": contra,
            "soll": val if side == "S" else 0.0, "haben": val if side == "H" else 0.0,
            "src": path.rsplit("/", 1)[-1]})


def main():
    accounts = {}
    for p in sys.argv[1:]:
        parse(p, accounts)
    # de-duplicate bookings (continuation pages repeat nothing, but be safe)
    for a in accounts.values():
        seen, out = set(), []
        for b in a["bookings"]:
            k = (b["date"], b["text"], b["beleg"], b["contra"], b["soll"], b["haben"])
            if k in seen:
                b["dup"] = True
            seen.add(k)
            out.append(b)
        a["bookings"] = out
    json.dump(accounts, sys.stdout, ensure_ascii=False, indent=1)


if __name__ == "__main__":
    main()
