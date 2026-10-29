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
  same numbers): about 75% In Stock, 15% Low Stock, 10% Reorder Now and none Out of Stock (every product starts with at least 1 unit) by the app's own status rules
  (In Stock = from just above the Low Stock band up to band + EOQ), at least 8 Class A items in each of Reorder Now and Low Stock. Each quantity is an `inventory_log` entry
  ("Opening balance (demo)"). A real count replaces it: `--stock-file <csv>` (columns `Product_ID`, `Size`,
  `Current_Stock`; products not listed get 0).
- **Bulk orders** (`Bulk_Order = yes`) are flagged in `sales_item.is_bulk`. It is a label only; no DSS calculation uses it.
- **Receipt numbers** run 1001-1732 from the file; the next POS sale is 1733 (highest number + 1).
- Try it on a copy first: restore the backup into another database and add `--db <name>`.
- The old seed files and `transactions_final.csv` are in `data-cleaning/archive/`. `dss:import-history` and
  `dss:load-stock-sheet` are deprecated and do nothing but point here.

## Demo site

A second, public copy of the system (for example `demo.rfourlms.tech`) for testing and the survey. It runs the same code
with its **own database**; the live site is unchanged. (Not to be confused with the "demo opening stock" above.)

**Demo mode is off unless `DEMO_MODE = true`** in that copy's `.env`. The live `.env` never needs any of these keys.

### What demo mode does

- **Three demo logins**: Owner (`demo_owner`), Staff (`demo_staff`) and Supplier (`demo_supplier`, linked to SUP-001),
  flagged `users.is_demo = 1`. Their passwords come from `.env`.
- **Locked actions** for those accounts, enforced in the controllers and again in `UserModel` (not just hidden buttons):
  changing the password, email, username or name; password reset (owner reset, approving a request, forgot password);
  deactivate / remove; "Lock account". Each shows "Disabled in the demo version." and the buttons are disabled with that
  tooltip. New Owner accounts can't be created; staff and suppliers can. Everything else works normally.
- **All email goes to the team**: one place (`App\Libraries\DemoEmail`, wired in `Config\Services::email()`) sends every
  email to `DEMO_MAIL_TO` and adds the real recipient to the subject, e.g. `[DEMO to owner@... ] Your verification code`.
  Forgot-password codes and temporary passwords are also shown on screen in a "Demo code" box.
- **Banner** on every page, a "Demo account" card on the login pages (if `DEMO_SHOW_CREDENTIALS`), and
  `noindex, nofollow` (a meta tag on the pages).
- **No real people in the data**: `demo:setup` switches off every other login (Owner, Staff and Supplier), replaces their
  names ("Former Owner", "Cashier 1", ...), usernames, phone numbers and passwords, points every user and supplier
  email at a `+alias` of `DEMO_MAIL_TO`, replaces supplier contact people and numbers, and clears the notifications and
  security log. Supplier company names stay.

### `.env` keys (all optional)

```
DEMO_MODE = true
DEMO_MAIL_TO = ourteam@gmail.com        # every demo email goes here (as ourteam+owner@gmail.com, ourteam+staff3@gmail.com, ...)
DEMO_OWNER_PASSWORD = ...
DEMO_STAFF_PASSWORD = ...               # must differ from every other staff password (staff login is password-only)
DEMO_SUPPLIER_PASSWORD = ...
DEMO_SHOW_CREDENTIALS = true            # show the demo logins on the login pages
DEMO_RESET_TIME = '12:00 AM'            # text for the banner only; the real time is the cron job
# DEMO_MYSQLDUMP_PATH / DEMO_MYSQL_PATH only if mysqldump / mysql are not on the PATH (typical on Windows)
```

`backend-ci4/env.demo.example` has the same keys.

### Commands (`php spark ...`)

Each one refuses to run unless `DEMO_MODE = true` **and** the database name contains "demo". They log to
`writable/logs/demo.log`.

| Command | What it does |
|---|---|
| `demo:setup` | Creates or updates the three demo accounts and removes real people, as described above. Safe to run again. |
| `demo:snapshot` | Saves the demo database to `writable/demo/snapshot.sql` (git-ignored, `DEFINER` clauses removed). |
| `demo:reset` | Restores the snapshot, brings the schema up to date (`migrate`), re-runs `demo:setup` and logs everyone out. |

`mysqldump` / `mysql` get the database login through a temporary `--defaults-extra-file` (mode 600, deleted afterwards),
never on the command line.

### Setting up the demo on the server

The demo needs its own folder (for example `/var/www/rfourl-demo`), its own web host name, and its own `.env` and
database. The ML service is shared: the demo uses the same one at `127.0.0.1:5001`, which keeps no state.

1. Migrate the **live** site as usual (`php spark migrate`). This adds `users.is_demo` (harmless; nothing reads it unless demo mode is on).
2. Create a database user for the demo only, with full rights on the demo database (the demo is disposable and needs
   `CREATE/ALTER/DROP/TRIGGER` for the restore and `migrate`): `GRANT ALL ON rfourl_demo.* TO 'rfourl_demo_app'@'localhost'`.
3. Copy live to demo, as root. It only **reads** the live database, and refuses unless the target name contains "demo":
   `sudo bash tools/demo/copy_live_to_demo.sh rfourl_dss rfourl_demo` (it prints product and receipt counts for both).
4. In the demo folder's `.env` set `database.default.database = rfourl_demo`, its own `app.baseURL`, and the `DEMO_*` keys.
5. `php spark migrate` (harmless if live was already migrated: it only adds what is missing),
   then `php spark demo:setup`, then `php spark demo:snapshot`. Check the logins it prints.
6. Nightly reset (cron, as the web user):
   ```
   0 0 * * * cd /var/www/rfourl-demo/backend-ci4 && sudo -u www-data php spark demo:reset >> /var/log/rfourl-demo-reset.log 2>&1
   ```
   After a code deploy, run `php spark migrate`, `demo:setup` and `demo:snapshot` again so the snapshot matches the new code
   (`demo:reset` also runs `migrate`, so a stale snapshot still comes back usable).

### Trying it on your own computer

1. Copy your local database: `mysqldump rfourl_dss | mysql rfourl_demo` (create `rfourl_demo` first).
2. Copy `backend-ci4/env.demo.example` into `backend-ci4/.env.demo`, add your database settings with
   `database.default.database = rfourl_demo`, and set `DEMO_MAIL_TO` to an inbox you can read.
3. `powershell tools\demo\use-demo.ps1` switches `.env` to the demo (your normal `.env` is saved as `.env.normal`);
   run `php spark migrate`, `php spark demo:setup`, then `php spark serve`.
4. `powershell tools\demo\use-normal.ps1` switches back. Restart `php spark serve` after switching.

## Branching Convention

- `main` — stable, demo-ready
- `feature/<name>` — one branch per feature (e.g. `feature/eoq-rop-calc`,
  `feature/kmeans-segmentation`, `feature/pos-module`)
- Open a Pull Request into `main`; do not push directly to `main`.
