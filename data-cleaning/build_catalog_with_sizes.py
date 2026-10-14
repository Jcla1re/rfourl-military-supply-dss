#!/usr/bin/env python3
"""
Catalog_with_Sizes.csv - fills the 8 size columns of SIZE_SEGREGATAION_GUIDE.txt for every catalog product.

  has_size, size_type, size_options, size_default, split_type, variant_count,
  size_aware_stock, size_aware_dss

Sources, in order of trust:
  1. the guide's own sample rows (Part 8) and per-product notes (Part 5)
  2. the sizes actually sold (output/transactions_final.csv)
  3. the guide's category rules (shoes 5-12, letters XS-2XL, waist, caps...)
`basis` says which one decided each row; `check_with_owner` = Y where nothing was observed
and the size scheme comes only from the category rule.
"""
import csv
import os
import re
import sys
from collections import Counter, defaultdict

HERE = os.path.dirname(os.path.abspath(__file__))
DL = os.path.join(os.path.expanduser("~"), "Downloads")
CATALOG = sys.argv[1] if len(sys.argv) > 1 else os.path.join(DL, "PRODUCT_TABLE_BIR-Improved_product_catalog-1.csv")
SALES = os.path.join(HERE, "output", "transactions_final.csv")
OUT = os.path.join(HERE, "output", "Catalog_with_Sizes.csv")

LETTERS = ["XS", "S", "M", "L", "XL", "2XL", "3XL", "4XL"]
LET6 = ["XS", "S", "M", "L", "XL", "2XL"]


def shoe(n):  # guide format: 05, 05.5, 10, 10.5
    n = float(n)
    s = f"{int(n):02d}" + (".5" if n % 1 else "")
    return s


def shoe_range(a, b):
    out, n = [], a
    while n <= b + 1e-9:
        out.append(shoe(n))
        n += 0.5
    return out


def key(s):  # sort key for mixed size lists
    if s in LETTERS:
        return (0, LETTERS.index(s))
    try:
        return (1, float(s))
    except ValueError:
        return (2, s)


# ---- catalog
cat = []
for r in csv.reader(open(CATALOG, encoding="utf-8-sig")):
    if r and re.match(r"P-\d{4}$", r[0].strip()):
        cat.append(dict(id=r[0].strip(), name=r[1].strip(), category=r[2].strip(), sub=r[3].strip(),
                        std=r[4].strip(), rng=r[5].strip(), variants=r[6].strip() if len(r) > 6 else ""))

# ---- what was actually sold
obs = defaultdict(Counter)
units = Counter()
for r in csv.DictReader(open(SALES, encoding="utf-8-sig")):
    q = int(r["quantity"])
    units[r["product_id"]] += q
    if r["size_clean"]:
        obs[r["product_id"]][(r["size_clean"], r["size_type"])] += q

DUP = {"P-0507": "P-0425", "P-0156": "P-0141", "P-0161": "P-0142", "P-0162": "P-0122", "P-0152": "P-0505", "P-0154": "P-0505"}

# ---- guide sample rows (Part 8) and Part 5 notes
SAMPLE = {
    "P-0001": ("SHOE", shoe_range(5, 12), "NA"),
    "P-0002": ("SHOE", ["05.5", "06", "07", "08", "09", "10", "10.5", "11"], "NA"),
    "P-0101": ("LETTER", ["XS", "S", "M", "L", "XL"], "M"),
    "P-0106": ("WAIST", ["28", "30", "31", "32", "33", "34", "35", "36", "37", "38", "39", "40", "42", "44"], "32"),
    "P-0108": ("LETTER", LET6, "M"),
    "P-0119": ("LETTER", LET6, "M"),
    "P-0209": ("LETTER", LET6, "M"),
    "P-0223": ("WAIST", ["30", "32", "34", "36", "38", "40"], "34"),
    "P-0407": ("WAIST", ["30", "32", "33", "34", "35", "36", "38", "39", "40"], "34"),
    "P-0424": ("CAP", ["5", "6", "7", "8", "56", "57", "58"], "7"),
}
SET_ITEMS = {"P-0104", "P-0105"}
SET_OPTIONS = "XS+28..40,S+28..40,M+28..40,L+28..40,XL+28..40,2XL+28..40"
NO_SIZE = {  # guide Part 5: free size / no size
    "P-0143": "Sando - one size (guide)", "P-0423": "Bullcap - free size (guide)", "P-0421": "Bonet - free size (guide)",
    "P-0145": "Poncho - free size", "P-0144": "Raincoat - free size", "P-0146": "Socks - free size",
}
PANTS_WAIST = {"P-0106", "P-0107", "P-0117", "P-0115", "P-0116"}   # numeric waist pants
LETTER_ONLY_SUBS = {"Shirt", "Uniform", "Jacket", "Sweatshirt", "Uniform Part", "Shorts"}

rows = []
for c in cat:
    pid = c["id"]
    o = obs.get(pid, Counter())
    o_by_type = defaultdict(set)
    for (sz, t) in o:
        o_by_type[t].add(shoe(sz) if t == "SHOE" and re.fullmatch(r"\d+(\.5)?", sz) else sz)
    o_sizes = sorted({s for s, _ in o}, key=key)
    o_types = Counter()
    for (s, t), q in o.items():
        o_types[t] += q
    basis, note = "", ""
    has, stype, opts, default, split = "N", "NONE", ["NA"], "NA", "None"

    if pid in DUP:
        note = f"duplicate of {DUP[pid]} - use that product"
    if pid in SET_ITEMS:
        has, stype, split, default, basis = "Y", "SET", "Top_Bottom", "M+32", "guide sample"
        opts = [SET_OPTIONS]
        variant = 78
    elif pid in NO_SIZE:
        basis, note = "guide", NO_SIZE[pid]
        variant = 1
    elif pid in SAMPLE:
        stype, base, default = SAMPLE[pid]
        has, split, basis = "Y", "Single", "guide sample"
        opts = sorted(set(base) | o_by_type.get(stype, set()), key=key)
        if len(opts) > len(base):
            note = "extra sizes added from sales: " + ",".join(sorted(set(opts) - set(base), key=key))
        variant = len(opts)
    else:
        guess = None
        if o_types:
            guess = max(o_types, key=o_types.get)
            if guess == "SET":
                guess = "LETTER"
        elif c["category"] == "Shoes":
            guess = "SHOE"
        elif c["category"] == "Clothes":
            if c["sub"] in ("Pants",):
                guess = "WAIST" if pid in PANTS_WAIST else "LETTER"
            elif c["sub"] in LETTER_ONLY_SUBS or c["sub"] in ("Raincoat",):
                guess = "LETTER" if c["sub"] != "Raincoat" else None
        if guess:
            has, stype, split = "Y", guess, "Single"
            basis = "observed in sales" if o_sizes else "category rule (guide)"
            if stype == "SHOE":
                base = shoe_range(7, 11)
                if pid == "P-0010":
                    base = shoe_range(6, 10)
                if pid == "P-0013" or pid in ("P-0011", "P-0012", "P-0014", "P-0015"):
                    base = shoe_range(7, 11)
                opts = sorted(set(base) | o_by_type.get("SHOE", set()), key=key)
                default = "NA"
            elif stype == "LETTER":
                opts = sorted(set(LET6) | (o_by_type.get("LETTER", set()) & set(LETTERS)), key=key)
                default = "M"
            elif stype == "WAIST":
                base = ["30", "32", "34", "36", "38", "40"]
                opts = sorted(set(base) | o_by_type.get("WAIST", set()), key=key)
                default = "32"
            elif stype == "CAP":
                base = [str(n) for n in range(54, 60)] if pid == "P-0420" else ["5", "6", "7", "8", "56", "57", "58"]
                opts = sorted(set(base) | o_by_type.get("CAP", set()), key=key)
                default = "57" if pid == "P-0420" else "7"
            variant = len(opts)
            if o_sizes and any(t not in (stype, "SET") for _, t in o):
                note = "mixed size schemes seen in sales: " + ",".join(sorted({t for _, t in o}))
        else:
            variant = 1
            basis = "category rule (guide)"

    if has == "N":
        opts, default, variant = ["NA"], "NA", 1

    # stock / DSS awareness
    stock = "Y" if has == "Y" else "N"
    rows.append(dict(
        Product_ID=pid, Product_Name_Canonical=c["name"], Category=c["category"], Subcategory=c["sub"],
        Standard_Price_PHP=c["std"], Price_Range_PHP=c["rng"], Common_Raw_Variants=c["variants"],
        has_size=has, size_type=stype, size_options=",".join(opts), size_default=default, split_type=split,
        variant_count=variant, size_aware_stock=stock, size_aware_dss="",
        units_sold=units.get(pid, 0), sizes_seen_in_sales="|".join(f"{s}:{q}" for (s, _), q in sorted(o.items(), key=lambda kv: key(kv[0][0]))),
        basis=basis, check_with_owner="Y" if (has == "Y" and not o_sizes and pid not in SAMPLE and pid not in SET_ITEMS) else "",
        notes=note,
    ))

# ---- size_aware_dss: Class A and B by units sold (guide Part 2, column 8)
ranked = sorted((r for r in rows if r["units_sold"] > 0), key=lambda r: -r["units_sold"])
total = sum(r["units_sold"] for r in ranked) or 1
cum, klass = 0, {}
for r in ranked:
    cum += r["units_sold"]
    share = cum / total
    klass[r["Product_ID"]] = "A" if share <= 0.80 else ("B" if share <= 0.95 else "C")
for r in rows:
    k = klass.get(r["Product_ID"], "C")
    r["abc_by_units_sold"] = k
    r["size_aware_dss"] = "Y" if (r["has_size"] == "Y" and k in ("A", "B")) else "N"

FIELDS = ["Product_ID", "Product_Name_Canonical", "Category", "Subcategory", "Standard_Price_PHP", "Price_Range_PHP",
          "Common_Raw_Variants", "has_size", "size_type", "size_options", "size_default", "split_type", "variant_count",
          "size_aware_stock", "size_aware_dss", "abc_by_units_sold", "units_sold", "sizes_seen_in_sales", "basis",
          "check_with_owner", "notes"]
with open(OUT, "w", newline="", encoding="utf-8-sig") as f:
    w = csv.DictWriter(f, FIELDS)
    w.writeheader()
    w.writerows(rows)

sized = [r for r in rows if r["has_size"] == "Y"]
print(f"{len(rows)} products -> {OUT}")
print(f"has_size = Y: {len(sized)}   N: {len(rows) - len(sized)}")
print("by type:", dict(Counter(r["size_type"] for r in rows)))
print("by basis:", dict(Counter(r["basis"] for r in rows)))
print("total SKUs if every size were stocked:", sum(int(r["variant_count"]) for r in rows))
print("size_aware_dss = Y:", sum(r["size_aware_dss"] == "Y" for r in rows), "| check with owner:", sum(r["check_with_owner"] == "Y" for r in rows))
print("by category (sized/total):", {k: f"{sum(1 for r in sized if r['Category']==k)}/{sum(1 for r in rows if r['Category']==k)}" for k in dict.fromkeys(r['Category'] for r in rows)})
