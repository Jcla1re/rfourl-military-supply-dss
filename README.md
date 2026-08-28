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
|Kenneth Gatus | |

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

## Branching Convention

- `main` — stable, demo-ready
- `feature/<name>` — one branch per feature (e.g. `feature/eoq-rop-calc`,
  `feature/kmeans-segmentation`, `feature/pos-module`)
- Open a Pull Request into `main`; do not push directly to `main`.
