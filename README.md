# RfourL Military Apparel — Probabilistic Decision Support System

A web-based Decision Support System (DSS) for procurement optimization and stock trend
analysis at RfourL Military Apparel. Replaces manual logbook-based inventory tracking
with data-driven Reorder Point (ROP), Economic Order Quantity (EOQ), and ML-based
inventory segmentation.

## Team

| Name | Role |
|---|---|
|James L. Onia |Project Manager|
|Jasmin Claire C. Bonilla |Lead Developer |
|Cyr Michael Josef | System Analyst |
|Diana D. Pariñas |Documentation Lead |
|Kenneth Gatus |Tester|

**Adviser:**
**Panel:**

## Tech Stack

**Data Layer**
- MySQL — POS transactions, stock levels, supplier lead times, user credentials,
  and ML output tables (`cluster_segments`, `abc_class`)

**Application Layer** (decoupled)
- CodeIgniter 4 (PHP) — auth, transactional processes, ROP calculations
- Python / Flask REST API — K-Means clustering microservice (scikit-learn) for
  inventory segmentation, called by CI4 via JSON payload

**Presentation Layer**
- HTML5, CSS3, Bootstrap 5 — responsive admin/staff dashboard
- Chart.js — demand cluster charts, Pareto curves, procurement visualizations

## Repository Structure

```
/backend-ci4        CodeIgniter 4 application (MVC, auth, ROP/EOQ logic, views)
/ml-service          Python/Flask microservice (K-Means clustering, scikit-learn)
/database            Schema, migrations, seed data
/docs                Capstone paper, diagrams, defense materials
```

## Local Setup

### 1. Database
```bash
mysql -u root -p < database/schema.sql
```

### 2. Backend (CodeIgniter 4)
```bash
cd backend-ci4
composer install
cp env .env
# set database.default.hostname / username / password / database in .env
php spark serve
```

### 3. ML Microservice (Flask)
```bash
cd ml-service
python -m venv venv
source venv/bin/activate   # Windows: venv\Scripts\activate
pip install -r requirements.txt
flask run --port 5001
```

Set the Flask microservice URL in the CI4 `.env` (e.g. `ML_SERVICE_URL=http://localhost:5001`)
so the PHP controller knows where to POST clustering requests.

## Rebuild the database

Replaces the products and sales with the cleaned 2021-2026 dataset in `data-cleaning/final/`
(`RfourL_Dataset_2021_2026_CLEAN.csv` and `RfourL_Products_Master.csv`; names, IDs, sizes, prices and
amounts are loaded exactly as they are in the files). Users, suppliers, DSS parameters, notification
preferences, app settings and the security log are kept.

1. **Back up first.** Nothing below is undone by anything but a restore.
   ```bash
   mysqldump -u root -h 127.0.0.1 -P 3307 --single-transaction --routines --triggers rfourl_dss > data-cleaning/backup/rfourl_dss_before_reseed_YYYY-MM-DD.sql
   ```
   (XAMPP: `C:\xampp\mysql\bin\mysqldump.exe`; use the host, port and user from `.env`.) These backup files
   are git-ignored. `--commit` refuses to run without a complete backup from the last 24 hours.
2. **Migrate.** `php spark migrate`
3. **New, empty database only:** `php spark db:seed LegacyDataSeeder` (suppliers, user accounts, DSS parameters).
   It no longer seeds products or sales, and running it on a database that already has users fails.
4. **Start the ml-service** (K-Means needs it): `cd ml-service && python app.py` (port 5001, or set `ML_SERVICE_URL`).
5. **Dry run, then load:**
   ```bash
   php spark dss:seed-dataset            # dry run: checks the files and the database, writes nothing
   php spark dss:seed-dataset --commit   # does it
   ```
   `--commit` clears the old products, sales, stock orders, alerts, computations, clusters, snapshots, inventory log
   and the stock/reorder/order notifications; loads 890 products and 732 receipts in one transaction (and rolls back if
   any check fails); then runs `dss:run`, sets a demo opening stock, and runs `dss:run` again so statuses and
   reorder alerts match. The `inventory_log` is append-only, so its two triggers are dropped for the load and put back
   afterwards (always, even if the load fails).
6. `php spark dss:run` is already done by `--commit`; run it again later to refresh reorder points and classes.

What to know about the data:

- **Unit cost** is empty in the products master, so every product gets an *estimated* cost of 60% of its selling
  price. The list is written to `backend-ci4/writable/dss-seed-dataset/estimated_cost_products.csv`.
  Real costs: `--cost-file <csv>` (columns `Product_ID`, `Size`, `Unit_Cost`).
- **Stock** is empty in the master, so a **demo opening stock** is generated (fixed seed 2026, so every rebuild gives the
  same numbers): about 70% In Stock, 15% Low Stock, 10% Reorder Now, 5% Out of Stock by the app's own status rules
  (In Stock = from just above the Low Stock band up to band + EOQ), at least 8 Class A items in each of Reorder Now and Low Stock. Each quantity is an `inventory_log` entry
  ("Opening balance (demo)"). A real count replaces it: `--stock-file <csv>` (columns `Product_ID`, `Size`,
  `Current_Stock`; products not listed get 0).
- **Bulk orders** (`Bulk_Order = yes`) are flagged in `sales_item.is_bulk`. It is a label only; no DSS calculation uses it.
- **Receipt numbers** run 1001-1732 from the file; the next POS sale is 1733 (highest number + 1).
- Try it on a copy first: restore the backup into another database and add `--db <name>`.
- The old seed files and `transactions_final.csv` are in `data-cleaning/archive/`. `dss:import-history` and
  `dss:load-stock-sheet` are deprecated and do nothing but point here.

## Branching Convention

- `main` — stable, demo-ready
- `feature/<name>` — one branch per feature (e.g. `feature/eoq-rop-calc`,
  `feature/kmeans-segmentation`, `feature/pos-module`)
- Open a Pull Request into `main`; do not push directly to `main`.
