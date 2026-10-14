#!/usr/bin/env python3
"""
FINAL_DATASET.csv - one row per sale line, exactly as it sits in the database
(receipt, product, quantity, price) plus the cleaned size and data-quality flags.

product_size is the size on the database product (the shop's product list often has the size inside the
name, e.g. "Gibson Lites 10"); size_on_receipt is the cleaned size from the sales sheet, which is kept
even when the matching product has no size of its own.

Lines are paired with transactions_final.csv by receipt and position, and each pair
is checked (same quantity) so a mismatch stops the export instead of mislabelling rows.
"""
import csv
import os
import subprocess
import sys
from collections import defaultdict

HERE = os.path.dirname(os.path.abspath(__file__))
MYSQL = r"C:\xampp\mysql\bin\mysql.exe"
DB = sys.argv[1] if len(sys.argv) > 1 else "rfourl_dss"

SQL = """
SELECT t.receipt_no, DATE(t.sale_date), i.item_id, COALESCE(p.product_code,''), p.item_name, p.category,
       COALESCE(p.size,''), COALESCE(p.size_type,''), i.quantity_sold, i.selling_price
FROM sales_transaction t
JOIN sales_item i ON i.sales_id = t.sales_id
JOIN products p ON p.item_id = i.item_id
WHERE t.receipt_no LIKE 'BIR-%'
ORDER BY t.sales_id, i.sales_item_id
"""
raw = subprocess.run([MYSQL, "-h127.0.0.1", "-P3307", "-uroot", "-N", "-B", DB, "-e", SQL],
                     capture_output=True, text=True, check=True, encoding="utf-8").stdout
db_rows = [l.split("\t") for l in raw.splitlines() if l]

src = defaultdict(list)
for r in csv.DictReader(open(os.path.join(HERE, "output", "transactions_final.csv"), encoding="utf-8-sig")):
    src["BIR-" + r["transaction_id"][1:]].append(r)

out, pos = [], defaultdict(int)
for receipt, date, item_id, code, name, cat, size, stype, qty, price in db_rows:
    s = src[receipt][pos[receipt]]
    pos[receipt] += 1
    assert int(s["quantity"]) == int(qty), (receipt, s["quantity"], qty)
    out.append({
        "receipt_no": receipt, "sale_date": date, "item_id": item_id, "item_name": name, "category": cat,
        "product_size": size, "item_name_on_receipt": s["item_detail"], "size_on_receipt": s["size_clean"],
        "size_type": s["size_type"], "quantity": qty, "unit_price": price, "line_total": f"{int(qty) * float(price):.2f}",
        "price_source": "receipt" if s["price_ok"] == "yes" else "estimated", "bulk_order": s["bulk_order"],
        "product_code": code, "source_line": s["source_line"],
    })
assert all(pos[k] == len(v) for k, v in src.items()), "line count mismatch between database and cleaned file"

path = os.path.join(HERE, "FINAL_DATASET.csv")
with open(path, "w", newline="", encoding="utf-8-sig") as f:
    w = csv.DictWriter(f, list(out[0].keys()))
    w.writeheader()
    w.writerows(out)
print(len(out), "lines,", len({r["receipt_no"] for r in out}), "receipts ->", path)
