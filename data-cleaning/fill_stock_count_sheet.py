#!/usr/bin/env python3
"""
Pre-fills Stock_Count_Sheet.csv from the database so the owner only has to verify.

What is exact (straight from the system): unit_cost, selling_price, supplier_id, and the item's total stock.
What is an ESTIMATE: stock per size. The system holds one total per item, so that total is split across
the sizes in proportion to how many of each size were actually sold (largest-remainder rounding, so the
sizes add back up to the real total).

Rows are left blank, not guessed, when there is no basis:
  - no matching system item (new item)           - more than one system item has this name
  - one system item stands for several catalog items (stock cannot be split safely)
  - the item sold no sizes at all (no size mix to follow)
Output: Stock_Count_Sheet_prefilled.csv  (the blank sheet is kept as it was)
"""
import csv
import os
from collections import defaultdict

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, "output", "Stock_Count_Sheet.csv")
OUT = os.path.join(HERE, "output", "Stock_Count_Sheet_prefilled.csv")

rows = list(csv.DictReader(open(SRC, encoding="utf-8-sig")))
fields = list(rows[0].keys()) + ["stock_basis"]

by_item = defaultdict(list)
for r in rows:
    by_item[r["product_code"]].append(r)

# one system item standing for several catalog items?
users = defaultdict(set)
for code, rs in by_item.items():
    if rs[0]["system_item_id"]:
        users[rs[0]["system_item_id"]].add(code)


def split(total, weights):
    s = sum(weights)
    raw = [total * w / s for w in weights]
    out = [int(x) for x in raw]
    left = total - sum(out)
    for i in sorted(range(len(raw)), key=lambda i: raw[i] - out[i], reverse=True)[:left]:
        out[i] += 1
    return out


stats = dict(allocated=0, rows=0, blank_new=0, blank_multi=0, blank_shared=0, blank_nosales=0, zero_total=0)
for code, rs in by_item.items():
    first = rs[0]
    sid = first["system_item_id"]
    if not sid:
        stats["blank_new"] += 1
        for r in rs:
            r["stock_basis"] = "left blank: item not in the system"
        continue
    if first["more_than_one_system_item"] == "Y":
        stats["blank_multi"] += 1
        for r in rs:
            r["stock_basis"] = "left blank: several system items share this name"
        continue
    if len(users[sid]) > 1:
        stats["blank_shared"] += 1
        for r in rs:
            r["stock_basis"] = "left blank: system item is shared by " + ",".join(sorted(users[sid]))
        continue
    total = int(first["system_stock_all_sizes"] or 0)
    sold = [(r, int(r["units_sold_all_time"] or 0)) for r in rs]
    sizes_sold = [(r, w) for r, w in sold if w > 0]
    if not sizes_sold:
        stats["blank_nosales"] += 1
        for r in rs:
            r["stock_basis"] = "left blank: no size was ever sold, nothing to base a split on"
        continue
    alloc = split(total, [w for _, w in sizes_sold]) if total > 0 else [0] * len(sizes_sold)
    if total == 0:
        stats["zero_total"] += 1
    for (r, w), q in zip(sizes_sold, alloc):
        r["carry_this_size (Y/N)"] = "Y"
        r["stock_count"] = str(q)
        r["stock_basis"] = f"ESTIMATE: {total} in system split by sales mix"
        stats["rows"] += 1
    for r, w in sold:
        if w == 0:
            r["stock_basis"] = "not sold in any recorded sale - left for the owner"
    stats["allocated"] += 1

with open(OUT, "w", newline="", encoding="utf-8-sig") as f:
    w = csv.DictWriter(f, fields)
    w.writeheader()
    w.writerows(rows)

tot_sys = sum(int(rs[0]["system_stock_all_sizes"] or 0) for c, rs in by_item.items() if any(r["stock_count"] != "" for r in rs))
tot_set = sum(int(r["stock_count"]) for r in rows if r["stock_count"] != "")
print(f"{len(by_item)} items | split across sizes: {stats['allocated']} items ({stats['rows']} size rows)")
print(f"stock: system total for those items = {tot_sys}, sum of size counts = {tot_set}")
print({k: v for k, v in stats.items() if k.startswith('blank') or k == 'zero_total'})
print("->", OUT)
