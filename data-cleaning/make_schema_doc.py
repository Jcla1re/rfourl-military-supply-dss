#!/usr/bin/env python3
"""Builds SCHEMA.md (tables, columns, keys, Mermaid ER diagram) from the live database."""
import os
import subprocess
import sys
from collections import OrderedDict

DB = sys.argv[1] if len(sys.argv) > 1 else "rfourl_dss"
MYSQL = r"C:\xampp\mysql\bin\mysql.exe"
HERE = os.path.dirname(os.path.abspath(__file__))


def q(sql):
    out = subprocess.run([MYSQL, "-h127.0.0.1", "-P3307", "-uroot", "-N", "-B", DB, "-e", sql],
                         capture_output=True, text=True, check=True).stdout
    return [l.split("\t") for l in out.splitlines() if l]


cols = OrderedDict()
for t, c, typ, nul, key, extra, comment in q(
        "SELECT table_name,column_name,column_type,is_nullable,column_key,extra,column_comment "
        "FROM information_schema.columns WHERE table_schema='%s' ORDER BY table_name,ordinal_position" % DB):
    cols.setdefault(t, []).append(dict(name=c, type=typ, null=nul == "YES", key=key, extra=extra, comment=comment))

fks = q("SELECT table_name,column_name,referenced_table_name,referenced_column_name "
        "FROM information_schema.key_column_usage WHERE table_schema='%s' AND referenced_table_name IS NOT NULL "
        "ORDER BY table_name,column_name" % DB)
fkcols = {(t, c) for t, c, _, _ in fks}
counts = {t: int(n) for t, n in q("SELECT table_name,table_rows FROM information_schema.tables WHERE table_schema='%s'" % DB)}
skip = {"migrations"}

GROUPS = [
    ("Sales", ["sales_transaction", "sales_item"]),
    ("Catalog & inventory", ["products", "suppliers", "inventory_log"]),
    ("Procurement", ["stock_order", "so_item"]),
    ("Decision support (computed by `php spark dss:run`)", ["dss_parameters", "pdss_computation", "cluster_segments", "reorder_alert", "analytics_snapshot"]),
    ("Users & system", ["users", "notifications", "notification_preferences", "security_log"]),
]

NOTES = {
    "products": "One row per sellable item **and size**. `size` holds the size (`7.5`, `M`, `32`, `XL+35`), `size_type` says what kind it is. "
                "`product_code` links a row to the cleaned catalog (`P-0001`...) and is set only on rows created by the history import. "
                "Rows created by the import have `is_active = 0`, no supplier and no stock, so the DSS ignores them.",
    "sales_transaction": "One receipt. Imported history uses `receipt_no` = `BIR-YYYYMMDD-NN` (the prefix is how the import is re-run safely or rolled back).",
    "sales_item": "One line of a receipt. Cascade-deleted with its receipt.",
    "pdss_computation": "EOQ / safety stock / reorder point per item per run (computed, not entered).",
    "cluster_segments": "K-Means cluster and ABC class per item (computed).",
    "security_log": "Audit trail: logins, password/email changes, archive and purge actions.",
}


def pytype(t):
    return t.split("(")[0].replace(" ", "_").replace("unsigned", "").strip("_") or "text"


md = [f"# Database schema - `{DB}`",
      "",
      "Generated from the live MariaDB database. `rfourl_dss_schema.sql` (same folder) is the importable SQL (import it in phpMyAdmin, then use Designer).",
      "",
      "## ER diagram",
      "",
      "```mermaid",
      "erDiagram"]
for t, cs in cols.items():
    if t in skip:
        continue
    md.append(f"    {t} {{")
    for c in cs:
        tag = "PK" if c["key"] == "PRI" else ("FK" if (t, c["name"]) in fkcols else ("UK" if c["key"] == "UNI" else ""))
        md.append(f"        {pytype(c['type'])} {c['name']} {tag}".rstrip())
    md.append("    }")
for t, c, rt, rc in fks:
    md.append(f'    {rt} ||--o{{ {t} : "{c}"')
md += ["```", "", "## Relationships", "", "| Table | Column | References |", "|---|---|---|"]
for t, c, rt, rc in fks:
    md.append(f"| {t} | {c} | {rt}.{rc} |")

md += ["", "## Tables", ""]
done = set()
for title, tables in GROUPS:
    md += [f"### {title}", ""]
    for t in tables:
        if t not in cols:
            continue
        done.add(t)
        md += [f"#### `{t}`  _(about {counts.get(t, 0):,} rows)_", ""]
        if t in NOTES:
            md += [NOTES[t], ""]
        md += ["| Column | Type | Null | Key | Notes |", "|---|---|---|---|---|"]
        for c in cols[t]:
            key = {"PRI": "PK", "UNI": "UNIQUE", "MUL": "index"}.get(c["key"], "")
            if (t, c["name"]) in fkcols:
                ref = next(f"{rt}.{rc}" for tt, cc, rt, rc in fks if tt == t and cc == c["name"])
                key = (key + " " if key else "") + f"FK -> {ref}"
            note = c["extra"] if c["extra"] else ""
            md.append(f"| {c['name']} | `{c['type']}` | {'yes' if c['null'] else 'no'} | {key} | {note} |")
        md.append("")
rest = [t for t in cols if t not in done and t not in skip]
if rest:
    md += ["### Other", ""] + [f"- `{t}`" for t in rest] + [""]

md += ["## What changed for the history import", "",
       "- `products.product_code` (`VARCHAR(10)`, nullable) - catalog id, only on products the import created.",
       "- `products.size_type` (`VARCHAR(10)`, nullable) - `SHOE`, `LETTER`, `WAIST`, `CAP` or `SET`.",
       "- index `idx_products_code_size (product_code, size)`.",
       "- `security_log` table (audit trail) - added by migration `2026-10-09-100000`.",
       "- No new tables for sales: imported history is ordinary `sales_transaction` + `sales_item` rows.",
       "",
       "## Notes for the ERD", "",
       "- A **product** is one item *and size*: a Combat Shoes size 8 and a size 9 are two products. Sizes live in `products.size`; there is no separate size table.",
       "- Sales do not store the size themselves; they point at the product row that carries it.",
       "- `pdss_computation`, `cluster_segments`, `reorder_alert` and `analytics_snapshot` are outputs of the DSS run. They reference `products` but nothing references them except `reorder_alert -> pdss_computation`.",
       "- `analytics_snapshot`, `notification_preferences` and `dss_parameters` (except `updated_by`) have no foreign keys; draw them as stand-alone tables.",
       "- `migrations` is framework bookkeeping and is left out of the diagram.",
       ""]

open(os.path.join(HERE, "SCHEMA.md"), "w", encoding="utf-8").write("\n".join(md))
print("SCHEMA.md written:", len(cols) - len(skip), "tables,", len(fks), "foreign keys")
