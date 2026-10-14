#!/usr/bin/env python3
"""
Stock-count sheet: one row per catalog item AND size, for the owner to fill in.

  python build_stock_count_sheet.py [dump.sql]

Reads  output/Catalog_with_Sizes.csv   (which items have sizes, and which sizes)
       output/transactions_final.csv   (how many of each size were sold)
       the products / suppliers of the database dump (supplier, cost, price, stock as the system has them now)
Writes output/Stock_Count_Sheet.csv    (open in Excel; fill the yellow columns, save as CSV)
       output/Suppliers_reference.csv
"""
import csv
import os
import re
import sys
from collections import Counter, defaultdict

HERE = os.path.dirname(os.path.abspath(__file__))
DUMP = sys.argv[1] if len(sys.argv) > 1 else r"C:\xampp\mysql\dumps_2026-10-12\rfourl_dss.sql"
ASOF = "2026-07-29"          # last sale in the data
WINDOW_FROM = "2025-07-30"   # the 365-day lookback ending at ASOF


# ---------------------------------------------------------------- read table rows from a mysqldump file
def dump_rows(path, table):
    text = open(path, encoding="utf-8", errors="replace").read()
    rows = []
    for m in re.finditer(r"INSERT INTO `%s` VALUES (.*?);\n" % re.escape(table), text, re.S):
        s, i, n = m.group(1), 0, len(m.group(1))
        while i < n:
            if s[i] != "(":
                i += 1
                continue
            i += 1
            row, cur, q = [], "", False
            while i < n:
                ch = s[i]
                if q:
                    if ch == "\\" and i + 1 < n:
                        cur += {"n": "\n", "r": "\r", "t": "\t", "0": "\0"}.get(s[i + 1], s[i + 1])
                        i += 2
                        continue
                    if ch == "'":
                        if i + 1 < n and s[i + 1] == "'":
                            cur += "'"
                            i += 2
                            continue
                        q = False
                    else:
                        cur += ch
                elif ch == "'":
                    q = True
                elif ch == ",":
                    row.append(cur)
                    cur = ""
                elif ch == ")":
                    row.append(cur)
                    i += 1
                    break
                else:
                    cur += ch
                i += 1
            rows.append([None if v == "NULL" else v for v in row])
    return rows


products = []
for r in dump_rows(DUMP, "products"):
    # item_id, product_code, supplier_id, item_name, category, size, size_type, unit_cost, selling_price,
    # current_stock, manual_rop_warning, abc_category, is_active, created_at, updated_at
    products.append(dict(item_id=r[0], code=r[1], supplier=r[2] or "", name=r[3], category=r[4], cost=r[7],
                         price=r[8], stock=int(r[9] or 0), active=r[12]))
existing = [p for p in products if not p["code"]]            # the shop's own product list
suppliers = [(r[0], r[1]) for r in dump_rows(DUMP, "suppliers")]
print(f"{len(existing)} existing products, {len(suppliers)} suppliers read from {os.path.basename(DUMP)}")

# ---------------------------------------------------------------- catalog + sales
norm = lambda s: re.sub(r"[^a-z0-9]", "", s.lower())
by_name = defaultdict(list)
for p in existing:
    by_name[norm(p["name"])].append(p)

catalog = [r for r in csv.DictReader(open(os.path.join(HERE, "output", "Catalog_with_Sizes.csv"), encoding="utf-8-sig"))]
sold_all, sold_year = Counter(), Counter()
for r in csv.DictReader(open(os.path.join(HERE, "output", "transactions_final.csv"), encoding="utf-8-sig")):
    if r["size_clean"]:
        k = (r["product_id"], r["size_clean"])
        sold_all[k] += int(r["quantity"])
        if r["date"] >= WINDOW_FROM:
            sold_year[k] += int(r["quantity"])


def natural(sz):               # guide format 07.5 -> 7.5, 05 -> 5
    return re.sub(r"^0(\d)", r"\1", sz)


def key(s):
    order = ["XS", "S", "M", "L", "XL", "2XL", "3XL", "4XL"]
    top, _, low = s.partition("+")
    t = (order.index(top), 0) if top in order else (9, 0)
    try:
        return (0, float(top), 0) if not low else (1, order.index(top) if top in order else 9, float(low))
    except ValueError:
        return (0, order.index(s) if s in order else 99, 0)


def options(c):
    o = c["size_options"]
    if c["size_type"] == "SET":
        ups = ["XS", "S", "M", "L", "XL", "2XL"]
        return [f"{u}+{w}" for u in ups for w in range(28, 41)]
    return [natural(x) for x in o.split(",") if x and x != "NA"]


def parent_for(c):
    """Existing product(s) that already stand for this catalog item (exact name or listed alias)."""
    names = [c["Product_Name_Canonical"]] + [v.strip() for v in c["Common_Raw_Variants"].split(",") if v.strip()]
    names.append(c["Product_Name_Canonical"].split(" / ")[0])
    for n in names:
        if norm(n) in by_name:
            return by_name[norm(n)]
    return []


rows = []
for c in catalog:
    if c["has_size"] != "Y" or "duplicate of" in c["notes"]:
        continue
    par = parent_for(c)
    p = par[0] if par else None
    for sz in sorted(options(c), key=key):
        sk = (c["Product_ID"], sz)
        rows.append({
            "product_code": c["Product_ID"], "item_name": p["name"] if p else c["Product_Name_Canonical"],
            "category": c["Category"], "size_type": c["size_type"], "size": sz,
            "units_sold_last_365d": sold_year.get(sk, 0), "units_sold_all_time": sold_all.get(sk, 0),
            "system_item_id": p["item_id"] if p else "", "system_stock_all_sizes": p["stock"] if p else "",
            "more_than_one_system_item": "Y" if len(par) > 1 else "",
            # --- owner fills these (cost / price / supplier are pre-filled from the system, change if wrong)
            "carry_this_size (Y/N)": "", "stock_count": "",
            "unit_cost": p["cost"] if p else "", "selling_price": p["price"] if p else "",
            "supplier_id": p["supplier"] if p else "",
            "check_with_owner": c["check_with_owner"], "note": "" if p else "new item - not in the system yet: fill cost, price and supplier",
        })

path = os.path.join(HERE, "output", "Stock_Count_Sheet.csv")
fields = list(rows[0].keys())
with open(path, "w", newline="", encoding="utf-8-sig") as f:
    w = csv.DictWriter(f, fields)
    w.writeheader()
    w.writerows(sorted(rows, key=lambda r: (r["category"], r["item_name"], r["product_code"])))

with open(os.path.join(HERE, "output", "Suppliers_reference.csv"), "w", newline="", encoding="utf-8-sig") as f:
    w = csv.writer(f)
    w.writerow(["supplier_id", "name"])
    w.writerows(suppliers)

items = len({r["product_code"] for r in rows})
print(f"{len(rows)} rows for {items} items -> {path}")
print("with sales in the last 365 days:", sum(1 for r in rows if r["units_sold_last_365d"]),
      "| sold at some time:", sum(1 for r in rows if r["units_sold_all_time"]))
print("rows with an existing system item:", sum(1 for r in rows if r["system_item_id"]), "| new items:", len({r["product_code"] for r in rows if not r["system_item_id"]}))
print("by category:", dict(Counter(r["category"] for r in rows)))
