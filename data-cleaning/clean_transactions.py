#!/usr/bin/env python3
"""
Cleans the RfourL monthly sales sheet against the improved product catalog.

  python clean_transactions.py [monthly_csv] [catalog_csv] [out_dir]

Outputs (out_dir):
  transactions_clean.csv   one row per sale line AND size (multi-size rows are split)
  review_list.csv          only the lines a person must check (nothing is guessed)
  variants.csv             unique product + item detail + size, for the import step

Rules:
  * product_id / name / category come from the catalog, matched on the item NAME,
    because the sheet reuses one product_id for many different items.
  * sizes are extracted from the Sizes column and from the item name, normalised,
    and checked against what that product can actually have (shoe / letter /
    waist / cap / set). Doubtful sizes are left blank and put on the review list.
  * prices and amounts are never overwritten; mismatches and blanks are flagged.
"""
import csv
import os
import re
import statistics
import sys
from collections import defaultdict
from datetime import date, datetime

DL = os.path.join(os.path.expanduser("~"), "Downloads")
MONTHLY = sys.argv[1] if len(sys.argv) > 1 else os.path.join(DL, "PRODUCT_TABLE_BIR-2022Jan-2026July_cleaned.csv")
CATALOG = sys.argv[2] if len(sys.argv) > 2 else os.path.join(DL, "PRODUCT_TABLE_BIR-Improved_product_catalog-1.csv")
OUT = sys.argv[3] if len(sys.argv) > 3 else os.path.join(os.path.dirname(os.path.abspath(__file__)), "output")
os.makedirs(OUT, exist_ok=True)

MONTHS = {m: i for i, m in enumerate(
    ["JANUARY", "FEBRUARY", "MARCH", "APRIL", "MAY", "JUNE", "JULY", "AUGUST",
     "SEPTEMBER", "OCTOBER", "NOVEMBER", "DECEMBER"], 1)}

# ---------------------------------------------------------------- catalog
catalog = {}
for r in csv.reader(open(CATALOG, encoding="utf-8-sig")):
    if r and re.match(r"P-\d{4}$", r[0].strip()):
        catalog[r[0].strip()] = {
            "id": r[0].strip(), "name": r[1].strip(), "category": r[2].strip(),
            "sub": r[3].strip(), "price": r[4].strip(), "variants": r[6].strip() if len(r) > 6 else "",
        }


def norm(s):
    return re.sub(r"[^a-z0-9]", "", s.lower())


alias = defaultdict(set)
for c in catalog.values():
    for a in [c["name"]] + [v for v in re.split(r",", c["variants"]) if v.strip()]:
        alias[norm(a)].add(c["id"])

# Ids that the sheet numbered differently from the catalog (same item, different id).
ID_REMAP = {
    "P-0511*": "P-0511", "P-0252*": "P-0252", "P-0306*": "P-0306", "P-0163*": "P-0163",
    "P-0253*": "P-0253", "P-0254*": "P-0254", "P-0255*": "P-0255", "P-0263": "P-0257",
    "P-0251": "P-0312", "P-0287": "P-0429", "P-0288": "P-0430", "P-0291*": "P-0274",
    "P-0294": "P-0275", "P-0303*": "P-0432", "P-0435*": "P-0278", "P-0459": "P-0516",
    "P-0481*": "P-0282", "P-0482*": "P-0517", "P-0483*": "P-0311",
    # sheet id was used for a different item than the catalog id
    "P-0274": "P-0270",   # Hardhat Blue (catalog: Hardhat)
    "P-0275": "P-0165",   # Short Sleeve Blue
}
# Name based corrections: (regex on raw name, sheet id it applies to, correct catalog id)
NAME_FIX = [
    (r"green army shirt", "P-0279", "P-0166"),
    (r"pershing\s*cap.*case|pershingcap case", "P-0271", "P-0269"),
    (r"rack sock", "P-0419", "P-0255"),
    (r"double\s*clip", "P-0272", "P-0427"),
    (r"^commando$", "P-0434", "P-0308"),
    (r"^box$", "P-0433", "P-0277"),
    (r"flight", "P-0301", "P-0207"),
    (r"pnp np$", "P-0505", "P-0505"),
]
# names the catalog lists under one id but that the sheet sells under their own id (kept, not moved)
ALIAS_KEEP = re.compile(r"chang\w*[\s-]*unit|rig belt|^pnp np$|botak.*short|cycling short|^rank star$", re.I)
ALIAS_REVIEW = re.compile(r"patrol cs volts|cs volts midcut", re.I)
# catalog entries that are the same item twice (or pins filed as clothes) -> the id to keep
MERGE = {"P-0507": "P-0425", "P-0156": "P-0141", "P-0161": "P-0142", "P-0162": "P-0122",
         "P-0152": "P-0505", "P-0154": "P-0505"}
SERVICES = {"P-0601": "Pants/Uniform Repair", "P-0602": "Shipping Fee", "P-0603": "Patch Attachment"}

# ---------------------------------------------------------------- sizes
SHOE, LETTER, WAIST, CAP, SET = "SHOE", "LETTER", "WAIST", "CAP", "SET"
LETTERS = {"XS", "S", "M", "L", "XL", "2XL", "3XL", "4XL"}
LWORD = {"SMALL": "S", "MEDIUM": "M", "LARGE": "L", "XLARGE": "XL", "XXL": "2XL", "XXXL": "3XL", "XXS": "XS"}


def allowed_types(pid):
    c = catalog.get(pid)
    if not c:
        return set()
    if pid in ("P-0146", "P-0144", "P-0145"):
        return set()
    if pid in ("P-0104", "P-0105"):
        return {LETTER, WAIST, SET}
    if c["category"] == "Shoes":
        return {SHOE}
    if c["category"] == "Clothes":
        return {LETTER, WAIST} if c["sub"] in ("Pants", "Shorts", "Uniform", "Uniform Part") else {LETTER}
    if pid == "P-0209":
        return {LETTER}
    if pid in ("P-0223", "P-0282", "P-0407"):
        return {WAIST}
    if pid in ("P-0424", "P-0420"):
        return {CAP}
    return set()


def letter(tok):
    t = tok.upper().replace(" ", "")
    t = LWORD.get(t, t)
    return t if t in LETTERS else None


def parse_num(tok):
    t = tok.strip()
    m = re.fullmatch(r"(\d{1,2})\s+1/2", t)
    if m:
        return int(m.group(1)) + 0.5
    try:
        return float(t)
    except ValueError:
        return None


def fmt(n):
    return str(int(n)) if float(n).is_integer() else str(n)


def classify(tok, allowed):
    """-> (clean, type, problem). problem is None when the token is usable."""
    t = re.sub(r"(?i)\b(upper|lower|size)\b:?", "", tok).strip(" :.")
    if not t or t == "-":
        return None, None, "empty"
    lt = letter(t)
    if lt:
        return (lt, LETTER, None) if LETTER in allowed else (lt, LETTER, "letter size on an item that does not use letter sizes")
    n = parse_num(t)
    if n is None:
        return None, None, "not a size"
    if SHOE in allowed:
        if n * 2 != int(n * 2):
            return None, SHOE, f"{fmt(n)} is not a valid shoe size (needs whole or .5)"
        if 3 <= n <= 14:
            return fmt(n), SHOE, None
        if 35 <= n <= 48:
            return fmt(n), SHOE, f"{fmt(n)} looks like a metric/EU shoe size"
        return None, SHOE, f"{fmt(n)} is outside the shoe size range"
    if CAP in allowed and (4 <= n <= 9 or 54 <= n <= 62):
        return fmt(n), CAP, None
    if WAIST in allowed and n == int(n) and 26 <= n <= 46:
        return fmt(n), WAIST, None
    if n == int(n) and 26 <= n <= 46:
        return fmt(n), WAIST, "waist size on an item that has no waist size"
    return fmt(n), None, "number does not fit this item"


def split_tokens(s):
    return [p.strip() for p in re.split(r"\s*[,/|]\s*|\s+-\s+|\s*;\s*", s) if p.strip()]


def parse_size_cell(raw, qty, allowed):
    """-> (list of (size_clean, size_type, qty), problems list, note)."""
    raw = raw.strip()
    if raw in ("", "-"):
        return [("", "", qty)], [], ""
    probs, note = [], ""

    # "S (2), M (1)"
    m = re.findall(r"([A-Za-z0-9 .]+?)\s*\((\d+)\)", raw)
    if m and "".join(f"{a}({b})" for a, b in [(x.strip(), y) for x, y in m]).replace(" ", "") == re.sub(r"[,\s]", "", raw):
        lines, tot = [], 0
        for s, q in m:
            c, t, p = classify(s, allowed)
            if p:
                probs.append(f"{s.strip()}: {p}")
            lines.append((c or "", t or "", int(q)))
            tot += int(q)
        if tot != qty:
            probs.append(f"split quantities add to {tot} but row quantity is {qty}")
        return lines, probs, ""

    toks = [p.strip() for p in re.split(r"\s*[,/]\s*", raw) if p.strip()]
    # "3-M, 3-L" or "S-1 / M-1"
    qs = []
    for t in toks:
        a = re.fullmatch(r"(\d+)\s*-\s*([A-Za-z0-9]+)", t)
        b = re.fullmatch(r"([A-Za-z0-9]+)\s*-\s*(\d+)", t)
        if a and letter(a.group(2)) and int(a.group(1)) < 20:
            qs.append((a.group(2), int(a.group(1))))
        elif b and letter(b.group(1)) and int(b.group(2)) < 20:
            qs.append((b.group(1), int(b.group(2))))
        else:
            qs = []
            break
    if qs:
        lines, tot = [], 0
        for s, q in qs:
            c, t, p = classify(s, allowed)
            if p:
                probs.append(f"{s}: {p}")
            lines.append((c or "", t or "", q))
            tot += q
        if tot != qty:
            probs.append(f"split quantities add to {tot} but row quantity is {qty}")
        return lines, probs, ""

    # "M 31-35": a letter plus a waist range -> keep the letter, note the range
    m = re.fullmatch(r"([A-Za-z0-9]+)\s+(\d{2})\s*-\s*(\d{2})", raw)
    if m and letter(m.group(1)):
        c, t, p = classify(m.group(1), allowed)
        return [(c or "", t or "", qty)], ([p] if p else []), f"raw size also had waist range {m.group(2)}-{m.group(3)}"

    parts = [p for p in re.split(r"\s*[,|]\s*|\s+-\s+", raw) if p.strip()]
    if len(parts) == 1 and re.search(r"\s", parts[0]) and not re.fullmatch(r"\d{1,2}\s+1/2", parts[0].strip()):
        parts = parts[0].split()
    cls = [classify(p, allowed) for p in parts]

    if len(parts) == 1:
        c, t, p = cls[0]
        return [(c or "", t or "", qty)], ([p] if p else []), ""

    types = [x[1] for x in cls]
    # top + bottom of a set: one letter + one waist
    if sorted(t or "" for t in types) == sorted([LETTER, WAIST]) and not any(x[2] and "not a size" in x[2] for x in cls):
        top = next(x[0] for x in cls if x[1] == LETTER)
        bot = next(x[0] for x in cls if x[1] == WAIST)
        ps = []
        if SET not in allowed:
            ps.append("set size (top + bottom) on an item that is not a set")
        return [(f"{top}+{bot}", SET, qty)], ps, ""
    # plain list of the same kind: 33, 34
    if all(c for c, _, _ in cls) and len(set(types)) == 1:
        n = len(cls)
        if qty % n == 0:
            return [(c, t, qty // n) for c, t, _ in cls], [p for _, _, p in cls if p], ""
        return [("", "", qty)], [f"{n} sizes ({raw}) for quantity {qty}: cannot split evenly"], ""
    return [("", "", qty)], [f"could not read size '{raw}'"], ""


LTOK = r"(?:xs|s|m|l|xl|xxl|2xl|3xl|small|medium|large|xlarge)"


def extract_from_name(name, allowed):
    """Pull size words out of an item name. -> (clean_name, [tokens])."""
    if not allowed:
        return name, []
    toks = []
    n = name
    m = re.search(r"\(?\s*size\s*:?\s*(\d{1,2}(?:\.\d)?(?:\s+1/2)?|" + LTOK + r"\b)\s*\)?", n, re.I)
    if m:
        toks.append(m.group(1).strip())
        n = (n[:m.start()] + " " + n[m.end():]).strip()
    n = re.sub(r"(?i)\bsize\b", "", n).strip()
    # leading size words: "Large Six Pocket Short", "L - Vest", "Medium Vest"
    m = re.match(r"(?i)^(x?-?large|small|medium|xs|s|m|l|xl|2xl)\s*-?\s+(?=\w)", n)
    if m and LETTER in allowed and letter(m.group(1).replace("-", "")):
        toks.append(m.group(1).replace("-", ""))
        n = n[m.end():]
    desc = ""
    m = re.search(r"\s*(\([^)]*\))\s*$", n)
    if m and not letter(m.group(1).strip("()")) and not re.fullmatch(r"\(\d+\)", m.group(1)):
        desc, n = m.group(1), n[:m.start()]

    def trailing_letter(n):
        m = re.search(r"(?i)[\s\-–|,]*\(?\b(" + LTOK + r")\)?\s*$", n)
        if m and LETTER in allowed and len(n[:m.start()].strip()) >= 3:
            toks.append(m.group(1))
            return n[:m.start()], True
        return n, False

    n, _ = trailing_letter(n)
    m = re.search(r"[\s\-|,]+(\d{1,2}(?:\.\d)?(?:\s+1/2)?)\s*(?:\((?:m|f)\))?\s*$", n, re.I)
    if m:
        v = parse_num(m.group(1))
        ok = v is not None and ((SHOE in allowed and 3 <= v <= 14.5) or (WAIST in allowed and 26 <= v <= 46)
                                 or (CAP in allowed and (4 <= v <= 9 or 54 <= v <= 62)))
        if ok:
            toks.append(m.group(1))
            n = n[:m.start()]
            n, _ = trailing_letter(n)
    n = re.sub(r"(?i)\(\s*(m|f)\s*\)\s*$", "", n).strip(" -|,")
    n = (n + (" " + desc if desc else "")).strip(" -")
    return re.sub(r"\s+", " ", n), toks


# ---------------------------------------------------------------- read sheet
def parse_date(s, header):
    s = s.strip()
    hy, hm = header
    if re.fullmatch(r"\d{4}-\d\d-\d\d", s):
        return datetime.strptime(s, "%Y-%m-%d").date(), ""
    m = re.fullmatch(r"(\d{1,2})/(\d{1,2})/(\d{2,4})", s)
    if m:
        y = int(m.group(3)) + (2000 if int(m.group(3)) < 100 else 0)
        return date(y, int(m.group(1)), int(m.group(2))), ""
    m = re.fullmatch(r"(\d{1,2})/(\d{1,2})", s)
    if m:
        return date(hy, int(m.group(1)), int(m.group(2))), "year taken from month heading"
    m = re.fullmatch(r"(\d{1,2})(\d{2})/(\d{2})", s)
    if m:
        return date(2000 + int(m.group(3)), int(m.group(1)), int(m.group(2))), "typo fixed (e.g. 1128/24)"
    return None, "unreadable date"


def num(s):
    s = (s or "").strip().replace(",", "")
    if s in ("", "-"):
        return None
    try:
        return float(s)
    except ValueError:
        return None


def money(v):
    return "" if v is None else (str(int(v)) if float(v).is_integer() else f"{v:.2f}")


def smart_title(s):
    return s.title() if s.isupper() or s.islower() else s


src = []
header = (2021, 1)
for ln, r in enumerate(csv.reader(open(MONTHLY, encoding="utf-8-sig")), 1):
    if not r:
        continue
    m = re.match(r"MONTH OF (\w+) \((\d{4})\)", r[0].strip().upper())
    if m:
        header = (int(m.group(2)), MONTHS[m.group(1)])
        continue
    if len(r) < 9 or not re.match(r"(\d{4}-\d\d-\d\d|\d{1,2}/\d{1,2}(/\d{2,4})?|\d{3,4}/\d\d)$", r[0].strip()):
        continue
    src.append((ln, header, r + [""] * (10 - len(r))))

# ---------------------------------------------------------------- build lines
lines = []
prev_date, group, prev_header = None, 0, None
for ln, header, r in src:
    d, dnote = parse_date(r[0], header)
    gt_raw = r[9].strip()
    new_group = bool(gt_raw) or d != prev_date or group == 0
    if new_group:
        group += 1
    prev_date = d

    reasons, notes = [], []
    raw_name = re.sub(r"\s+", " ", r[1]).strip()
    sheet_id = r[2].strip()
    qty = int(float(r[3] or 1))
    unit = {"Pieece": "Piece"}.get(r[4].strip(), r[4].strip())
    size_cell = r[5].strip()
    if size_cell == "-":
        size_cell = ""
    price, amount = num(r[7]), num(r[8])

    # ---- date checks
    if d is None:
        reasons.append("DATE_UNREADABLE")
    else:
        if dnote:
            notes.append(f"date: {dnote}")
        if (d.year, d.month) != header:
            reasons.append(f"DATE_NOT_IN_MONTH_HEADING ({d.isoformat()} under {header[0]}-{header[1]:02d})")

    # ---- product id from the catalog
    pid = ID_REMAP.get(sheet_id, sheet_id)
    pid_note = f"{sheet_id}->{pid}" if pid != sheet_id else ""
    for rx, from_id, to_id in NAME_FIX:
        if re.search(rx, raw_name, re.I) and sheet_id.rstrip("*") == from_id:
            if to_id != pid:
                pid_note = f"{sheet_id}->{to_id} (by item name)"
            pid = to_id
            break
    if not ALIAS_KEEP.search(raw_name) and not ALIAS_REVIEW.search(raw_name):
        hit = alias.get(norm(raw_name), set()) | alias.get(norm(re.sub(r"\s*\([^)]*\)", "", raw_name)), set())
        if len(hit) == 1 and pid not in hit and pid not in SERVICES and not (pid in catalog and norm(raw_name) in {norm(catalog[pid]["name"])}):
            new = next(iter(hit))
            if not (pid_note and "by item name" in pid_note):
                pid_note = f"{sheet_id}->{new} (catalog alias match)"
                pid = new
    if MERGE.get(pid):
        pid_note = (pid_note + "; " if pid_note else "") + f"{pid}->{MERGE[pid]} (duplicate catalog entry)"
        pid = MERGE[pid]
    if not re.match(r"P-\d{4}$", pid):
        pid = ""
        reasons.append("UNKNOWN_ITEM (no product id)")

    is_service = pid in SERVICES
    c = catalog.get(pid)
    if pid and not c and not is_service:
        reasons.append(f"NOT_IN_CATALOG ({pid})")
    canon = c["name"] if c else SERVICES.get(pid, "")
    cat = c["category"] if c else ("Services" if is_service else "")
    sub = c["sub"] if c else ""
    if c and r[6].strip() and r[6].strip() != cat.split(" /")[0].strip() and cat != r[6].strip():
        notes.append(f"category sheet='{r[6].strip()}' catalog='{cat}'")

    allowed = allowed_types(pid)

    # ---- size handling
    detail, name_toks = extract_from_name(raw_name, allowed)
    cell_lines, sprobs, snote = parse_size_cell(size_cell, qty, allowed) if size_cell and not is_service else ([("", "", qty)], [], "")
    if snote:
        notes.append(snote)

    if size_cell and not allowed:
        if is_service:
            notes.append(f"size '{size_cell}' (service)")
        else:
            sprobs = [f"size '{size_cell}' given for an item that has no sizes"]
        cell_lines = [("", "", qty)]
    # tidy name still carrying a size copy of the cell
    if size_cell and norm(size_cell) and norm(detail).endswith(norm(size_cell)) and allowed:
        detail = re.sub(r"[\s\-]*" + re.escape(size_cell) + r"\s*$", "", detail).strip()

    name_lines = []
    if name_toks:
        nm_cells = [classify(t, allowed) for t in name_toks]
        letters_ = [x for x in nm_cells if x[1] == LETTER and not x[2]]
        others_ = [x for x in nm_cells if not x[2] and x[1] != LETTER]
        if letters_ and others_ and WAIST in allowed and others_[0][1] == WAIST:
            name_lines = [(f"{letters_[0][0]}+{others_[0][0]}", SET, qty)]
        elif nm_cells[0][2]:
            sprobs.append(f"size in item name: {name_toks[0]}: {nm_cells[0][2]}")
        else:
            name_lines = [(nm_cells[0][0], nm_cells[0][1], qty)]

    if name_lines and not size_cell:
        out_sizes = name_lines
    elif name_lines and size_cell:
        a = {(s, t) for s, t, _ in name_lines}
        b = {(s, t) for s, t, _ in cell_lines}
        if len(name_lines) == 1 and name_lines[0][1] == SET and len(b) == 1 and next(iter(b))[0] in name_lines[0][0].split("+"):
            out_sizes = name_lines
        elif a & b or not b - {("", "")}:
            out_sizes = cell_lines if (b - {("", "")}) else name_lines
        else:
            sprobs.append(f"size in name ({', '.join(s for s, _, _ in name_lines)}) disagrees with Sizes column ({size_cell})")
            out_sizes = [("", "", qty)]
    else:
        out_sizes = cell_lines

    if sprobs:
        reasons += [f"SIZE: {p}" for p in sprobs]
        out_sizes = [("", "", qty)]

    if not any(s for s, _, _ in out_sizes) and not sprobs and allowed and (SHOE in allowed or allowed & {LETTER, WAIST, CAP}):
        notes.append("size missing")

    # ---- money
    amount_calc = price * qty if price is not None else None
    astatus = "OK"
    if price is None and amount is None:
        astatus = "NO_PRICE"
    elif price is None:
        astatus = "PRICE_MISSING"
    elif amount is None:
        astatus = "AMOUNT_MISSING"
    elif abs(amount - amount_calc) > 1:
        astatus = "AMOUNT_MISMATCH"
    if astatus in ("NO_PRICE", "PRICE_MISSING", "AMOUNT_MISSING", "AMOUNT_MISMATCH"):
        reasons.append(astatus)

    cancel = bool(re.search(r"cancel", raw_name, re.I))
    if cancel:
        reasons.append("POSSIBLE_CANCELLED_SALE")
    if gt_raw.lower() == "unknown":
        reasons.append("STOCK_ENTRY_NOT_SALE (grand total 'Unknown', no prices)")

    for i, (sz, st, q) in enumerate(out_sizes):
        lines.append({
            "group": group, "source_line": ln, "date": d, "raw_name": raw_name, "detail": smart_title(detail) if detail else raw_name,
            "sheet_id": sheet_id, "pid": pid, "pid_note": pid_note, "canon": canon, "cat": cat, "sub": sub,
            "size_raw": size_cell, "size": sz, "stype": st, "qty": q, "unit": unit, "price": price,
            "amount_src": amount if i == 0 else None, "line_calc": (price * q if price is not None else None),
            "astatus": astatus, "gt": gt_raw, "reasons": list(reasons), "notes": list(notes),
            "split": len(out_sizes) > 1, "is_service": is_service, "stock_entry": gt_raw.lower() == "unknown",
            "cancel": cancel, "allowed": allowed,
        })

# ---------------------------------------------------------------- cross-row checks
# alias conflicts (name matches a different catalog item than the id says)
for L in lines:
    if not L["pid"] or L["is_service"]:
        continue
    hits = alias.get(norm(L["detail"]), set()) | alias.get(norm(L["raw_name"]), set())
    hits = {h for h in hits if h != L["pid"]}
    if hits and L["pid"] not in alias.get(norm(L["detail"]), set()) and not ALIAS_KEEP.search(L["raw_name"]):
        L["reasons"].append("NAME_MATCHES_OTHER_ITEM (" + "/".join(sorted(hits)) + f" {catalog[sorted(hits)[0]]['name']})")

# price outliers per product (skip products whose price legitimately varies a lot)
prices = defaultdict(list)
for L in lines:
    if L["price"] and L["pid"] and not L["stock_entry"]:
        prices[L["pid"]].append(L["price"])
wide = set()
for pid, ps in prices.items():
    if len(ps) >= 8 and max(ps) / max(min(ps), 1) >= 5:
        wide.add(pid)
for L in lines:
    ps = prices.get(L["pid"], [])
    if L["price"] and len(ps) >= 8 and L["pid"] not in wide:
        med = statistics.median(ps)
        if L["price"] > 3 * med or L["price"] < med / 3:
            L["reasons"].append(f"PRICE_OUTLIER (median {med:.0f})")

# receipts
groups = defaultdict(list)
for L in lines:
    groups[L["group"]].append(L)
sig_seen = defaultdict(list)
for g, ls in groups.items():
    tot = sum((x["amount_src"] if x["amount_src"] is not None else (x["line_calc"] or 0)) for x in ls)
    gt = num(ls[0]["gt"]) if not ls[0]["gt"].lower() == "unknown" else None
    key = tuple(sorted((x["detail"].lower(), x["size"], x["qty"], x["price"]) for x in ls))
    for x in ls:
        x["receipt_total"] = ls[0]["gt"]
        x["receipt_calc"] = tot
        x["receipt_mismatch"] = gt is not None and abs(gt - tot) > 1
    if ls[0]["gt"].lower() == "unknown":
        for x in ls:
            x["stock_entry"] = True
            if not any(r.startswith("STOCK_ENTRY") for r in x["reasons"]):
                x["reasons"].append("STOCK_ENTRY_NOT_SALE (grand total 'Unknown', no prices)")
    rows_ = [(x["detail"].lower(), x["size"], x["qty"], x["price"]) for x in ls]
    dups = {k for k in rows_ if rows_.count(k) > 1}
    seen_k = set()
    extras = []
    for x in ls:
        k = (x["detail"].lower(), x["size"], x["qty"], x["price"])
        x["drop_dup"] = False
        if k in dups:
            x["reasons"].append("DUPLICATE_LINE_IN_RECEIPT")
            if k in seen_k:
                extras.append(x)
            seen_k.add(k)
    # A repeat is noise only when the receipt total adds up WITHOUT it.
    # If the total needs the repeat, it was a real second sale.
    if extras and gt is not None:
        without = tot - sum((x["line_calc"] or 0) for x in extras)
        if abs(gt - without) <= 1 and abs(gt - tot) > 1:
            for x in extras:
                x["drop_dup"] = True
    if len(ls) >= 3:
        sig_seen[key].append((g, ls[0]["date"]))
for key, gs in sig_seen.items():
    if len(gs) > 1:
        for g, dt in gs:
            for x in groups[g]:
                x["reasons"].append("DUPLICATE_RECEIPT (same items repeated on " + ", ".join(
                    str(d2) for g2, d2 in gs if g2 != g) + ")")

# line level info
for L in lines:
    L["bulk"] = L["qty"] >= 30 or (L["line_calc"] or 0) >= 20000

# transaction ids
seq = defaultdict(int)
tid = {}
for L in lines:
    if L["group"] not in tid:
        dkey = L["date"].strftime("%Y%m%d") if L["date"] else "00000000"
        seq[dkey] += 1
        tid[L["group"]] = f"T{dkey}-{seq[dkey]:02d}"
    L["tid"] = tid[L["group"]]

# ---------------------------------------------------------------- write
def reason_codes(L):
    return sorted({r.split(" (")[0].split(":")[0] for r in L["reasons"]})


FIELDS = ["transaction_id", "source_line", "date", "item_name_raw", "item_detail", "product_id", "product_name_canonical",
          "category", "subcategory", "product_id_in_sheet", "id_change", "size_raw", "size_clean", "size_type",
          "quantity", "unit", "unit_price", "amount_in_sheet", "line_amount_calc", "amount_status",
          "receipt_grand_total", "receipt_total_matches", "is_sale", "is_service", "bulk_order", "needs_review",
          "review_reasons", "notes"]
out_rows = []
for L in lines:
    is_sale = not L["stock_entry"] and not L["is_service"] and not L["cancel"]
    out_rows.append({
        "transaction_id": L["tid"], "source_line": L["source_line"], "date": L["date"].isoformat() if L["date"] else "",
        "item_name_raw": L["raw_name"], "item_detail": L["detail"], "product_id": L["pid"],
        "product_name_canonical": L["canon"], "category": L["cat"], "subcategory": L["sub"],
        "product_id_in_sheet": L["sheet_id"], "id_change": L["pid_note"], "size_raw": L["size_raw"],
        "size_clean": L["size"], "size_type": L["stype"], "quantity": L["qty"], "unit": L["unit"],
        "unit_price": money(L["price"]), "amount_in_sheet": money(L["amount_src"]),
        "line_amount_calc": money(L["line_calc"]), "amount_status": L["astatus"],
        "receipt_grand_total": L["receipt_total"], "receipt_total_matches": "NO" if L["receipt_mismatch"] else "yes",
        "is_sale": "yes" if is_sale else "no", "is_service": "yes" if L["is_service"] else "no",
        "bulk_order": "yes" if L["bulk"] else "", "needs_review": "YES" if L["reasons"] else "",
        "review_reasons": " | ".join(dict.fromkeys(L["reasons"])), "notes": " | ".join(L["notes"]),
    })

with open(os.path.join(OUT, "transactions_clean.csv"), "w", newline="", encoding="utf-8-sig") as f:
    w = csv.DictWriter(f, FIELDS)
    w.writeheader()
    w.writerows(out_rows)

review = [r for r in out_rows if r["needs_review"]]
with open(os.path.join(OUT, "review_list.csv"), "w", newline="", encoding="utf-8-sig") as f:
    w = csv.DictWriter(f, ["source_line", "date", "item_name_raw", "product_id", "product_name_canonical", "size_raw",
                           "size_clean", "quantity", "unit_price", "amount_in_sheet", "review_reasons", "decision"])
    w.writeheader()
    for r in sorted(review, key=lambda r: (r["review_reasons"].split(" ")[0], int(r["source_line"]))):
        w.writerow({**{k: r.get(k, "") for k in w.fieldnames if k != "decision"}, "decision": ""})

var = defaultdict(lambda: [0, 0])
for r in out_rows:
    if r["is_sale"] == "yes" and r["product_id"]:
        k = (r["product_id"], r["product_name_canonical"], r["category"], r["item_detail"], r["size_clean"], r["size_type"])
        var[k][0] += int(r["quantity"])
        var[k][1] += 1
with open(os.path.join(OUT, "variants.csv"), "w", newline="", encoding="utf-8-sig") as f:
    w = csv.writer(f)
    w.writerow(["product_id", "product_name_canonical", "category", "item_detail", "size_clean", "size_type", "total_qty_sold", "sale_lines"])
    for k in sorted(var):
        w.writerow(list(k) + var[k])

# ---------------------------------------------------------------- summary
code_count = defaultdict(int)
for L in lines:
    for c in reason_codes(L):
        code_count[c] += 1
print(f"source rows: {len(src)}   output lines: {len(lines)}   receipts: {len(groups)}")
print(f"lines needing review: {len(review)}")
for k, v in sorted(code_count.items(), key=lambda kv: -kv[1]):
    print(f"  {v:5d}  {k}")
print("split rows:", sum(1 for L in lines if L["split"]))
print("id changes:", sum(1 for L in lines if L["pid_note"]))
print("stock entries:", sum(1 for L in lines if L["stock_entry"]), " bulk:", sum(1 for L in lines if L["bulk"]))


# ---------------------------------------------------------------- noise removal
# Noise = lines that are not real sales of a catalog item. Lines with only a price
# or size problem are KEPT (their quantity is real demand); the bad value is just
# marked so the DSS does not rely on it.
final, noise = [], []
for L, o in zip(lines, out_rows):
    why = None
    if L["stock_entry"]:
        why = "stock count, not a sale"
    elif not L["pid"]:
        why = "unknown item"
    elif L["cancel"]:
        why = "cancelled sale"
    elif L["is_service"]:
        why = "service (repair/shipping/attach), not inventory"
    elif L.get("drop_dup"):
        why = "repeated line - receipt total only adds up without it"
    if why:
        noise.append({**o, "removed_because": why})
        continue
    final.append({
        "transaction_id": o["transaction_id"], "date": o["date"], "product_id": o["product_id"],
        "product_name_canonical": o["product_name_canonical"], "category": o["category"],
        "item_detail": o["item_detail"], "size_clean": o["size_clean"], "size_type": o["size_type"],
        "size_known": "yes" if o["size_clean"] else "no", "quantity": o["quantity"], "unit": o["unit"],
        "unit_price": o["unit_price"] if o["amount_status"] in ("OK",) else "",
        "price_ok": "yes" if o["amount_status"] == "OK" else "no", "bulk_order": o["bulk_order"],
        "source_line": o["source_line"],
    })

with open(os.path.join(OUT, "transactions_final.csv"), "w", newline="", encoding="utf-8-sig") as f:
    w = csv.DictWriter(f, list(final[0].keys()))
    w.writeheader()
    w.writerows(final)
with open(os.path.join(OUT, "excluded_noise.csv"), "w", newline="", encoding="utf-8-sig") as f:
    w = csv.DictWriter(f, ["source_line", "date", "item_name_raw", "product_id", "quantity", "unit_price", "removed_because"])
    w.writeheader()
    for r in noise:
        w.writerow({k: r.get(k, "") for k in w.fieldnames})

# corrected catalog: duplicates merged, services added
with open(os.path.join(OUT, "catalog_clean.csv"), "w", newline="", encoding="utf-8-sig") as f:
    w = csv.writer(f)
    w.writerow(["Product_ID", "Product_Name_Canonical", "Category", "Subcategory", "Standard_Price_PHP", "Common_Raw_Variants", "Is_Inventory_Item"])
    for pid_, c in sorted(catalog.items()):
        if pid_ in MERGE:
            continue
        w.writerow([pid_, c["name"], c["category"], c["sub"], c["price"], c["variants"], "yes"])
    for pid_, nm in sorted(SERVICES.items()):
        w.writerow([pid_, nm, "Services", "Service", "", "", "no"])

print(f"\nFINAL: {len(final)} sale lines kept, {len(noise)} removed as noise")
for why, n in sorted(((w_, sum(1 for r in noise if r['removed_because'] == w_)) for w_ in {r['removed_because'] for r in noise}), key=lambda x: -x[1]):
    print(f"  {n:4d}  {why}")
print("price not usable:", sum(1 for r in final if r['price_ok'] == 'no'), "  size unknown:", sum(1 for r in final if r['size_known'] == 'no'))
