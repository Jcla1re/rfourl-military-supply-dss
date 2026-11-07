# Data Dictionary (actual database)

Read from the local database `rfourl_dss` (MariaDB 10.4.32-MariaDB) on 2026-11-05, after checking that all 31 migrations had run (`php spark migrate:status`: none pending). Types, keys and defaults come from `information_schema`; nothing was guessed. Descriptions come from how the code in `backend-ci4/app` and `ml-service` uses each column.

How to read the Constraint column:

- **PK** primary key, **AUTO_INCREMENT** numbered automatically, **FK → table.column** foreign key, **UNIQUE** no two rows may share the value.
- **NOT NULL** a value is required, **NULL** the column may be empty.
- **DEFAULT** the value used when none is given. No DEFAULT means none is set.
- **ON UPDATE CURRENT_TIMESTAMP** the database sets the time automatically whenever the row changes.

Integer types show their display width as MariaDB reports it, e.g. INT(11). The width does not limit the values.

## ANALYTICS_SNAPSHOT

| Column | Type | Constraint | Description |
|---|---|---|---|
| snapshot_id | INT(11) UNSIGNED | PK, AUTO_INCREMENT, NOT NULL | Unique ID of the snapshot row. |
| user_id | INT(11) | NOT NULL | User the snapshot is credited to: the user who last saved the DSS settings, or 1. |
| generated_at | DATETIME | NOT NULL | Date and time the DSS run wrote the snapshot. |
| metric_type | VARCHAR(50) | NOT NULL | What the row measures: 'abc_distribution' or 'reorder_alerts_raised'. |
| period | VARCHAR(20) | NOT NULL | The as-of date of the DSS run (latest sale date), as text. |
| metric_value | DECIMAL(15,4) | NOT NULL | The number measured, e.g. items in a class or alerts raised. |
| raw_data | LONGTEXT | NULL, DEFAULT NULL | JSON details of the metric (class, item count, average daily demand, alert counts). |

- Index `metric_type_period` on (metric_type, period).
- Index `user_id` on (user_id).
- CHECK `raw_data`: `` json_valid(`raw_data`) ``. MariaDB stores a JSON column this way, so it shows as LONGTEXT.

## APP_SETTINGS

| Column | Type | Constraint | Description |
|---|---|---|---|
| setting_key | VARCHAR(50) | PK, NOT NULL | Name of the setting, e.g. 'sales_retention_years'. |
| setting_value | VARCHAR(255) | NULL, DEFAULT NULL | Value of the setting, stored as text. |
| updated_by | INT(11) | NULL, DEFAULT NULL | User ID of whoever last saved the setting. |
| updated_at | DATETIME | NULL, DEFAULT NULL | Date and time the setting was last saved. |

## CLUSTER_SEGMENTS

| Column | Type | Constraint | Description |
|---|---|---|---|
| item_id | VARCHAR(15) | PK, FK → products.item_id, NOT NULL | The product (size row) this K-Means result belongs to. |
| cluster_label | INT(11) | NOT NULL | Raw K-Means cluster number (0, 1 or 2) from the ML service; saved but never read. |
| cluster_name | VARCHAR(50) | NULL, DEFAULT NULL | Cluster name, e.g. 'Fast-Moving'; saved but not shown on any page. |
| abc_class | CHAR(1) | NULL, DEFAULT NULL | The item's A/B/C class, used for its service level and shown on screens. |
| service_level | DECIMAL(5,2) | NULL, DEFAULT NULL | Target availability % for the item's class (A 95, B 90, C 85). |
| z_score | DECIMAL(5,3) | NULL, DEFAULT NULL | Safety factor (Z) for that service level, used for safety stock. |
| computed_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP, ON UPDATE CURRENT_TIMESTAMP | When the row was last written. |

## DSS_PARAMETERS

| Column | Type | Constraint | Description |
|---|---|---|---|
| id | INT(11) | PK, AUTO_INCREMENT, NOT NULL | Row ID; the table has a single settings row. |
| ordering_cost | DECIMAL(10,2) | NOT NULL | Cost per order (S) in pesos, used in EOQ. |
| holding_cost_per_unit | DECIMAL(10,2) | NOT NULL | Storage cost per item per year (H) in pesos, used in EOQ. |
| service_level_target | DECIMAL(5,2) | NOT NULL | Default availability target %, used for items without a class. |
| z_score | DECIMAL(5,3) | NOT NULL | Default safety factor (Z), used for items without a class. |
| demand_lookback_days | INT(11) | NOT NULL, DEFAULT 90 | How many days of sales history the DSS uses. |
| minimum_order_qty | INT(11) | NOT NULL, DEFAULT 5 | Smallest order quantity the DSS will suggest (EOQ floor). |
| updated_by | INT(11) | FK → users.user_id, NULL, DEFAULT NULL | User who last saved the DSS settings. |
| updated_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP, ON UPDATE CURRENT_TIMESTAMP | When the DSS settings were last saved. |

## INVENTORY_LOG

| Column | Type | Constraint | Description |
|---|---|---|---|
| log_id | INT(11) | PK, AUTO_INCREMENT, NOT NULL | Unique ID of the stock movement. |
| item_id | VARCHAR(15) | FK → products.item_id, NOT NULL | The product (size row) whose stock changed. |
| user_id | INT(11) | FK → users.user_id, NULL, DEFAULT NULL | User who caused the change. |
| log_type | ENUM('MANUAL_ADJUSTMENT','RESTOCK','SALE','RETURN','DAMAGED_LOST') | NOT NULL | Kind of movement: manual adjustment, restock, sale, return or damaged/lost. |
| quantity_changed | INT(11) | NOT NULL | Units added (positive) or removed (negative). |
| stock_after_change | INT(11) | NOT NULL | Stock on hand right after the change; saved but not shown on any page. |
| reference_so_id | VARCHAR(15) | FK → stock_order.so_id, NULL, DEFAULT NULL | Stock order that caused a restock, set when a delivery is received. |
| notes | VARCHAR(255) | NULL, DEFAULT NULL | Short note about the movement, e.g. "Received from stock order …". |
| timestamp | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP | When the movement happened; rows cannot be edited or deleted (triggers). |

- Trigger `inventory_log_no_update` (BEFORE UPDATE) blocks the change, so rows can only be added.
- Trigger `inventory_log_no_delete` (BEFORE DELETE) blocks the change, so rows can only be added.

## MIGRATIONS

| Column | Type | Constraint | Description |
|---|---|---|---|
| id | BIGINT(20) UNSIGNED | PK, AUTO_INCREMENT, NOT NULL | Row ID (CodeIgniter framework table). |
| version | VARCHAR(255) | NOT NULL | Timestamp version of the migration file (framework). |
| class | VARCHAR(255) | NOT NULL | Migration class name (framework). |
| group | VARCHAR(255) | NOT NULL | Database group it ran on (framework). |
| namespace | VARCHAR(255) | NOT NULL | Code namespace of the migration (framework). |
| time | INT(11) | NOT NULL | Unix time it ran (framework). |
| batch | INT(11) UNSIGNED | NOT NULL | Batch number it ran in (framework). |

- Created and used by CodeIgniter itself to track which migrations have run. The app's own code never reads it.

## NOTIFICATIONS

| Column | Type | Constraint | Description |
|---|---|---|---|
| notification_id | INT(11) | PK, AUTO_INCREMENT, NOT NULL | Unique ID of the notification. |
| recipient_role | ENUM('Admin','Staff','Supplier') | NOT NULL | Which portal it is for: 'Admin' (the Owner), 'Staff' or 'Supplier'. |
| recipient_id | INT(11) | FK → users.user_id, NULL, DEFAULT NULL | A specific user it is for; empty means everyone in that role. |
| type | VARCHAR(50) | NOT NULL | Short label shown on the card, e.g. 'Reorder Alert', 'Order Status'. |
| category | VARCHAR(20) | NULL, DEFAULT NULL | Group used for filtering and icons, e.g. 'reorder_alert', 'order_status', 'security'. |
| title | VARCHAR(150) | NOT NULL | Headline of the notification. |
| message | TEXT | NULL, DEFAULT NULL | Body text of the notification. |
| link_url | VARCHAR(255) | NULL, DEFAULT NULL | Page opened when the notification is clicked. |
| is_read | TINYINT(1) | NULL, DEFAULT 0 | 1 once the recipient has read it. |
| status | VARCHAR(20) | NULL, DEFAULT NULL | Result of an action notification: 'approved' or 'declined'. |
| action_token | VARCHAR(64) | NULL, DEFAULT NULL | Random secret for the one-click link in a staff password-request email. |
| action_user_id | INT(11) | NULL, DEFAULT NULL | Staff user the action notification is about. |
| action_expires_at | DATETIME | NULL, DEFAULT NULL | When the one-click action link stops working. |
| created_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP | When the notification was created. |

## NOTIFICATION_PREFERENCES

| Column | Type | Constraint | Description |
|---|---|---|---|
| id | INT(11) UNSIGNED | PK, AUTO_INCREMENT, NOT NULL | Row ID; the table has a single row. |
| rop_alerts | TINYINT(1) | NOT NULL, DEFAULT 1 | Whether staff "Notify Admin" reorder messages are sent (on/off). |
| low_stock_warnings | TINYINT(1) | NOT NULL, DEFAULT 1 | Not used in code. Its switch was removed from Settings; the column remains in the table. |
| daily_sales_summary | TINYINT(1) | NOT NULL, DEFAULT 1 | Not used in code. Its switch was removed from Settings; the column remains in the table. |
| procurement_reminders | TINYINT(1) | NOT NULL, DEFAULT 1 | Not used in code. Its switch was removed from Settings; the column remains in the table. |
| weekly_trend_report | TINYINT(1) | NOT NULL, DEFAULT 0 | Not used in code. Its switch was removed from Settings; the column remains in the table. |
| updated_by | INT(11) | NULL, DEFAULT NULL | User who last changed a switch. |

## PDSS_COMPUTATION

| Column | Type | Constraint | Description |
|---|---|---|---|
| computation_id | INT(11) | PK, AUTO_INCREMENT, NOT NULL | Unique ID of one DSS result for one item. |
| item_id | VARCHAR(15) | FK → products.item_id, NOT NULL | The product (size row) this result is for. |
| computed_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP | When the DSS run calculated it; the latest row per item is the current one. |
| annual_demand | INT(11) | NULL, DEFAULT NULL | Yearly sales (D), average daily sales × 365; saved but not read back. |
| avg_daily_demand | DECIMAL(10,2) | NULL, DEFAULT NULL | Average units sold per day (d), shown as "Daily sales". |
| demand_std_dev | DECIMAL(10,2) | NULL, DEFAULT NULL | How much daily sales vary (σd); saved, not read back. |
| lead_time_days | INT(11) | NULL, DEFAULT NULL | Supplier delivery time in days (L) used for this result. |
| service_level_z | DECIMAL(5,3) | NULL, DEFAULT NULL | Safety factor (Z) used for this item. |
| safety_stock | INT(11) | NULL, DEFAULT NULL | Extra units to keep for busy days. |
| reorder_point | INT(11) | NULL, DEFAULT NULL | Reorder At level calculated by the DSS. |
| eoq_value | INT(11) | NULL, DEFAULT NULL | Suggested order quantity (EOQ, at least the minimum order qty). |
| triggered_by | VARCHAR(50) | NULL, DEFAULT NULL | What started the run; always 'spark:dss:run'. Not read back. |

## PRODUCTS

| Column | Type | Constraint | Description |
|---|---|---|---|
| item_id | VARCHAR(15) | PK, NOT NULL | Unique ID of one product size row (each size is its own row). |
| product_code | VARCHAR(10) | NULL, DEFAULT NULL | Product code shared by all sizes of the same product. |
| supplier_id | VARCHAR(10) | FK → suppliers.supplier_id, NULL, DEFAULT NULL | Supplier who provides this item. |
| item_name | VARCHAR(100) | NOT NULL | Name of the product. |
| category | VARCHAR(50) | NOT NULL | Product category, e.g. Clothes, Shoes, Patches. |
| size | VARCHAR(10) | NULL, DEFAULT NULL | Size of this row; empty for items without sizes. |
| size_type | VARCHAR(10) | NULL, DEFAULT NULL | Kind of size scale, e.g. 'SHOE', 'LETTER', 'WAIST'; only set by the data import commands, not used by any page. |
| unit_cost | DECIMAL(10,2) | NOT NULL | What the shop pays per unit; used for order prices and K-Means. |
| selling_price | DECIMAL(10,2) | NOT NULL | Current selling price per unit at the POS. |
| current_stock | INT(11) | NOT NULL, DEFAULT 0 | Units on hand now. |
| manual_rop_warning | INT(11) | NULL, DEFAULT NULL | Reorder At level used for stock status; written by the DSS run or typed in by the Owner. |
| abc_category | ENUM('A','B','C') | NULL, DEFAULT NULL | Copy of the K-Means class written by the DSS run; pages read cluster_segments.abc_class instead. |
| is_active | TINYINT(1) | NULL, DEFAULT 1 | 1 = in inventory, 0 = archived. |
| created_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP | When the row was added. |
| updated_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP, ON UPDATE CURRENT_TIMESTAMP | When the row last changed; shown as "Last updated" in Inventory. |

- Index `idx_products_code_size` on (product_code, size).

## REORDER_ALERT

| Column | Type | Constraint | Description |
|---|---|---|---|
| alert_id | INT(11) | PK, AUTO_INCREMENT, NOT NULL | Unique ID of the reorder alert. |
| item_id | VARCHAR(15) | FK → products.item_id, NOT NULL | The product (size row) that needs reordering. |
| computation_id | INT(11) | FK → pdss_computation.computation_id, NULL, DEFAULT NULL | DSS result the alert was based on; never filled by the code (always empty), so the latest result is used. |
| so_id | VARCHAR(20) | NULL, DEFAULT NULL | Stock order created for this alert. |
| alert_timestamp | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP | When the alert was raised; used for "Received This Week". |
| trigger_reason | VARCHAR(100) | NULL, DEFAULT NULL | Why it was raised, e.g. "Stock (2) at/below reorder point (3)". |
| recommended_eoq | INT(11) | NULL, DEFAULT NULL | Order quantity suggested when the alert was raised. |
| stock_at_trigger | INT(11) | NULL, DEFAULT NULL | Stock on hand when the alert was raised. |
| status | ENUM('Active','Acknowledged','Ordered','Fulfilled','Dismissed') | NOT NULL, DEFAULT 'Active' | Where the alert is in its workflow. |
| hidden_until_recovered | TINYINT(1) | NOT NULL, DEFAULT 0 | 1 = dismissed by the Owner and hidden until stock is above Reorder At again. |

## REORDER_NOTICES

| Column | Type | Constraint | Description |
|---|---|---|---|
| notice_id | INT(11) | PK, AUTO_INCREMENT, NOT NULL | Unique ID of a staff "Notify Admin" notice. |
| item_id | VARCHAR(15) | FK → products.item_id (ON DELETE CASCADE, ON UPDATE CASCADE), NOT NULL | The product (size row) staff flagged. |
| notified_by | INT(11) | FK → users.user_id (ON DELETE SET NULL, ON UPDATE CASCADE), NULL, DEFAULT NULL | Staff user who sent it. |
| notification_id | INT(11) | FK → notifications.notification_id (ON DELETE SET NULL, ON UPDATE CASCADE), NULL, DEFAULT NULL | The Owner notification that was sent. |
| created_at | DATETIME | NOT NULL | When staff sent it. |
| cleared_at | DATETIME | NULL, DEFAULT NULL | When the notice was closed; empty while still open. |
| cleared_reason | VARCHAR(20) | NULL, DEFAULT NULL | Why it closed: 'ordered' or 'stock_ok'. |

- Index `item_id_cleared_at` on (item_id, cleared_at).

## SALES_ITEM

| Column | Type | Constraint | Description |
|---|---|---|---|
| sales_item_id | INT(11) | PK, AUTO_INCREMENT, NOT NULL | Unique ID of one line on a receipt. |
| sales_id | INT(11) | FK → sales_transaction.sales_id (ON DELETE CASCADE, ON UPDATE RESTRICT), NOT NULL | The sale (receipt) this line belongs to. |
| item_id | VARCHAR(15) | FK → products.item_id, NOT NULL | The product (size row) sold. |
| quantity_sold | INT(11) | NOT NULL | Units sold on this line. |
| selling_price | DECIMAL(10,2) | NOT NULL | Price per unit at the time of the sale. |
| is_bulk | TINYINT(1) | NOT NULL, DEFAULT 0 | 1 = bulk order line; only set by the data import commands. |

## SALES_TRANSACTION

| Column | Type | Constraint | Description |
|---|---|---|---|
| sales_id | INT(11) | PK, AUTO_INCREMENT, NOT NULL | Unique ID of the sale. |
| receipt_no | VARCHAR(20) | UNIQUE, NOT NULL | Receipt number printed for the customer. |
| user_id | INT(11) | FK → users.user_id, NULL, DEFAULT NULL | User who made the sale at the POS. |
| sale_date | DATETIME | NOT NULL | Date and time of the sale. |
| payment_method | ENUM('Cash','GCash','Bank Transfer','Credit Card','Split') | NOT NULL, DEFAULT 'Cash' | How the customer paid; the POS uses 'Cash', 'GCash' and 'Split'. |
| cash_amount | DECIMAL(10,2) | NOT NULL, DEFAULT 0.00 | Part paid in cash. |
| gcash_amount | DECIMAL(10,2) | NOT NULL, DEFAULT 0.00 | Part paid by GCash. |
| gcash_reference_no | VARCHAR(50) | NULL, DEFAULT NULL | GCash reference number, if paid by GCash. |
| subtotal | DECIMAL(10,2) | NOT NULL | Total before discount. |
| discount | DECIMAL(10,2) | NULL, DEFAULT 0.00 | Discount given. |
| total_amount | DECIMAL(10,2) | NOT NULL | Amount due after discount. |

## SECURITY_LOG

| Column | Type | Constraint | Description |
|---|---|---|---|
| log_id | INT(11) UNSIGNED | PK, AUTO_INCREMENT, NOT NULL | Unique ID of the audit entry. |
| user_id | INT(11) | NULL, DEFAULT NULL | User who did the action, if known. |
| username | VARCHAR(100) | NULL, DEFAULT NULL | Username at the time of the action. |
| role | VARCHAR(20) | NULL, DEFAULT NULL | Role at the time of the action. |
| event | VARCHAR(50) | NOT NULL | What happened, e.g. 'ITEM_ARCHIVED', 'archive_printed'. |
| detail | VARCHAR(255) | NULL, DEFAULT NULL | Short description of the event. |
| ip_address | VARCHAR(45) | NULL, DEFAULT NULL | IP address the action came from; empty for command-line runs. |
| created_at | DATETIME | NULL, DEFAULT NULL | When it happened. |

- Index `created_at` on (created_at).
- Index `event` on (event).

## SO_ITEM

| Column | Type | Constraint | Description |
|---|---|---|---|
| so_item_id | INT(11) | PK, AUTO_INCREMENT, NOT NULL | Unique ID of one line on a stock order. |
| so_id | VARCHAR(15) | FK → stock_order.so_id (ON DELETE CASCADE, ON UPDATE RESTRICT), NOT NULL | The stock order this line belongs to. |
| item_id | VARCHAR(15) | FK → products.item_id, NOT NULL | The product (size row) ordered. |
| order_quantity | INT(11) | NOT NULL | Units ordered. |
| shipped_quantity | INT(11) | NULL, DEFAULT NULL | Units the supplier says it shipped; added to stock on delivery. |
| unit_price | DECIMAL(10,2) | NOT NULL | Price per unit on the order. |

## STOCK_ORDER

| Column | Type | Constraint | Description |
|---|---|---|---|
| so_id | VARCHAR(15) | PK, NOT NULL | Unique stock order number. |
| supplier_id | VARCHAR(10) | FK → suppliers.supplier_id, NOT NULL | Supplier the order was sent to. |
| user_id | INT(11) | FK → users.user_id, NULL, DEFAULT NULL | User who created the order. |
| priority | ENUM('Urgent','Order','Planned') | NULL, DEFAULT 'Order' | How urgent the order is. |
| status | ENUM('Order Confirmed','Preparing','Shipped','Delivered','Cancelled') | NULL, DEFAULT 'Order Confirmed' | Where the order is in delivery. |
| order_date | DATE | NOT NULL | Date the order was placed. |
| expected_delivery_date | DATE | NULL, DEFAULT NULL | Date the order should arrive. |
| actual_delivery_date | DATE | NULL, DEFAULT NULL | Date it was delivered; used for on-time figures in the supplier portal. |
| tracking_no | VARCHAR(30) | NULL, DEFAULT NULL | Courier tracking number. |
| decline_reason | VARCHAR(255) | NULL, DEFAULT NULL | Why the supplier declined the order. |
| cancel_reason | VARCHAR(255) | NULL, DEFAULT NULL | Why the Owner cancelled the order. |
| cancelled_by | INT(11) | FK → users.user_id (ON DELETE SET NULL, ON UPDATE RESTRICT), NULL, DEFAULT NULL | User who cancelled the order. |
| cancelled_at | DATETIME | NULL, DEFAULT NULL | When it was cancelled or declined. |

## SUPPLIERS

| Column | Type | Constraint | Description |
|---|---|---|---|
| supplier_id | VARCHAR(10) | PK, NOT NULL | Unique supplier ID, e.g. SUP-003. |
| company_name | VARCHAR(100) | NOT NULL | Supplier company name. |
| category | VARCHAR(50) | NULL, DEFAULT NULL | What the supplier provides, typed in freely. |
| lead_time_days | INT(11) | NOT NULL | Days from order to delivery; used as L in the DSS. |
| preferred_courier | VARCHAR(100) | NULL, DEFAULT NULL | Courier the supplier usually uses. |
| contact_person | VARCHAR(100) | NULL, DEFAULT NULL | Name of the contact person. |
| contact_email | VARCHAR(100) | NULL, DEFAULT NULL | Contact email address. |
| contact_number | VARCHAR(20) | NULL, DEFAULT NULL | Contact phone number. |
| is_active | TINYINT(1) | NULL, DEFAULT 1 | 1 = active, 0 = archived. |
| created_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP | When the supplier was added. |
| updated_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP, ON UPDATE CURRENT_TIMESTAMP | When the supplier row last changed. |

## USERS

| Column | Type | Constraint | Description |
|---|---|---|---|
| user_id | INT(11) | PK, AUTO_INCREMENT, NOT NULL | Unique ID of the account. |
| supplier_id | VARCHAR(10) | FK → suppliers.supplier_id, NULL, DEFAULT NULL | For supplier accounts, the supplier they belong to. |
| username | VARCHAR(50) | UNIQUE, NOT NULL | Login name. |
| email | VARCHAR(100) | NULL, DEFAULT NULL | Email address for password resets and notifications. |
| phone | VARCHAR(20) | NULL, DEFAULT NULL | Phone number, edited in Settings. |
| password_hash | VARCHAR(255) | NOT NULL | Hashed password (never the plain password). |
| role | ENUM('Admin','Staff','Supplier') | NOT NULL | Account type; 'Admin' is shown as "Owner" on screen. |
| full_name | VARCHAR(100) | NOT NULL | Name shown in the app. |
| is_active | TINYINT(1) | NULL, DEFAULT 1 | 1 = can log in, 0 = disabled. |
| last_login | DATETIME | NULL, DEFAULT NULL | When the user last logged in. |
| reset_otp_hash | VARCHAR(255) | NULL, DEFAULT NULL | Hashed one-time code for "Forgot password". |
| reset_otp_expires | DATETIME | NULL, DEFAULT NULL | When that code expires (10 minutes after sending). |
| created_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP | When the account was created. |
| deleted_at | DATETIME | NULL, DEFAULT NULL | When a staff account was removed; it is kept but hidden. |
| must_change_password | TINYINT(1) | NOT NULL, DEFAULT 0 | 1 = must set a new password at next login. |
| temp_password_kind | VARCHAR(10) | NULL, DEFAULT NULL | Why a temporary password was given: 'reset' or 'invite'. |
| temp_password_expires_at | DATETIME | NULL, DEFAULT NULL | When the temporary password stops working. |
| is_demo | TINYINT(1) | NOT NULL, DEFAULT 0 | 1 = demo account that may not change protected details. |

## Totals

- **Tables:** 19 (18 app tables + the CodeIgniter `migrations` table). There are no views.
- **Columns:** 181 (174 without `migrations`).
- **Foreign keys:** 22.
- **Other:** 1 CHECK constraint and 2 triggers, both listed under their tables.

## Foreign keys

Unless noted, each key uses ON DELETE RESTRICT and ON UPDATE RESTRICT: a referenced row cannot be deleted or renumbered while rows still point to it.

| # | From | To | Rule |
|--:|---|---|---|
| 1 | cluster_segments.item_id | products.item_id |  |
| 2 | dss_parameters.updated_by | users.user_id |  |
| 3 | inventory_log.reference_so_id | stock_order.so_id |  |
| 4 | inventory_log.item_id | products.item_id |  |
| 5 | inventory_log.user_id | users.user_id |  |
| 6 | notifications.recipient_id | users.user_id |  |
| 7 | pdss_computation.item_id | products.item_id |  |
| 8 | products.supplier_id | suppliers.supplier_id |  |
| 9 | reorder_alert.item_id | products.item_id |  |
| 10 | reorder_alert.computation_id | pdss_computation.computation_id |  |
| 11 | reorder_notices.item_id | products.item_id | ON DELETE CASCADE, ON UPDATE CASCADE |
| 12 | reorder_notices.notification_id | notifications.notification_id | ON DELETE SET NULL, ON UPDATE CASCADE |
| 13 | reorder_notices.notified_by | users.user_id | ON DELETE SET NULL, ON UPDATE CASCADE |
| 14 | sales_item.sales_id | sales_transaction.sales_id | ON DELETE CASCADE, ON UPDATE RESTRICT |
| 15 | sales_item.item_id | products.item_id |  |
| 16 | sales_transaction.user_id | users.user_id |  |
| 17 | so_item.so_id | stock_order.so_id | ON DELETE CASCADE, ON UPDATE RESTRICT |
| 18 | so_item.item_id | products.item_id |  |
| 19 | stock_order.supplier_id | suppliers.supplier_id |  |
| 20 | stock_order.user_id | users.user_id |  |
| 21 | stock_order.cancelled_by | users.user_id | ON DELETE SET NULL, ON UPDATE RESTRICT |
| 22 | users.supplier_id | suppliers.supplier_id |  |

## Inconsistent or confusing column names and types

**Missing or mismatched links**

1. **`reorder_alert.so_id` is VARCHAR(20)**, but `stock_order.so_id` is VARCHAR(15), and there is no foreign key between them.
2. **Some columns are user IDs but have no foreign key to `users.user_id`:**
   - `analytics_snapshot.user_id`
   - `app_settings.updated_by`
   - `notification_preferences.updated_by`
   - `notifications.action_user_id`
   - `security_log.user_id`
3. **`reorder_alert.computation_id` is a foreign key, but no code ever fills it.** All 90 local rows are empty. The page falls back to the latest `pdss_computation` row for the item.

**Names that don't match what the column holds**

4. **`products.manual_rop_warning` holds the Reorder At value**, and the DSS run writes it automatically. Both "manual" and "warning" are misleading.
5. **`products.abc_category` is a copy of the class in `cluster_segments.abc_class`**, with a different name and type: ENUM('A','B','C') vs CHAR(1). Every page reads `cluster_segments.abc_class`.
6. **The same idea has different names in different tables:**
   - `reorder_alert.recommended_eoq` vs `pdss_computation.eoq_value`.
   - `pdss_computation.reorder_point` vs `products.manual_rop_warning`.
   - `z_score` (in `dss_parameters` and `cluster_segments`) vs `pdss_computation.service_level_z`.
7. **`sales_item.selling_price` is the price at the time of the sale**, while `products.selling_price` is the current price. `so_item` calls its price `unit_price`.
8. **`users.role` and `notifications.recipient_role` store 'Admin'**, while the screens say "Owner".
9. **`stock_order.user_id` and `sales_transaction.user_id`** don't say what they mean: they are the user who created the order and the user who made the sale.
10. **`inventory_log.timestamp`** is named after a data type.
11. **The `pdss_` prefix on `pdss_computation`** isn't explained anywhere in the code.

**Naming patterns that change between tables**

12. **Most primary keys are `<table>_id`**, but `dss_parameters.id` and `notification_preferences.id` are plain `id`. `log_id` is the key of both `inventory_log` and `security_log`.
13. **Table names are a mix of singular and plural:** `reorder_alert`, `stock_order`, `so_item`, `pdss_computation` vs `products`, `suppliers`, `users`, `reorder_notices`, `cluster_segments`.
14. **Expiry columns use different endings:** `users.reset_otp_expires` vs `users.temp_password_expires_at` and `notifications.action_expires_at`.

**Types and defaults that don't match**

15. **`created_at` columns use different types.** Most are TIMESTAMP NOT NULL with a default, but `reorder_notices.created_at` is DATETIME, and `security_log.created_at` is DATETIME NULL.
16. **`is_active` allows NULL** in `products`, `suppliers` and `users`, while newer flags such as `is_demo`, `is_bulk`, `must_change_password` and `hidden_until_recovered` are NOT NULL.
17. **`pdss_computation.avg_daily_demand` and `demand_std_dev` keep only 2 decimals (DECIMAL(10,2))**, although the code rounds them to 4. Small values are saved as 0.00. The DSS calculates from the unrounded numbers, so this only affects what is shown.
18. **`cluster_segments.service_level` is a percent** (95.00), while the ML service returns a fraction (0.95) that `dss:run` multiplies by 100.
19. **Two column defaults differ from the values actually saved:**
   - `dss_parameters.demand_lookback_days` defaults to 90, but the saved row is 730.
   - `minimum_order_qty` defaults to 5, but the saved value is 10.
20. **`sales_transaction.payment_method` allows 'Bank Transfer' and 'Credit Card'**, but no code uses them. Local data has only 'Cash'.
21. **`notifications` has three columns that sort notifications:** `type` (free text label), `category` (code group) and `status` (free text, 'approved'/'declined').

## Saved but not used or shown

These columns are written, but no page shows them and no logic uses them:

- **`cluster_segments`:** `cluster_label` and `cluster_name`. `ClusterSegmentModel::distribution()` reads `cluster_name`, but nothing calls it.
- **`pdss_computation`:** `annual_demand`, `demand_std_dev` and `triggered_by`.
- **`inventory_log`:** `stock_after_change`.
- **`security_log`:** the whole table. It is written by `SecurityLogModel::log()`, and no page lists it.
- **`analytics_snapshot`:** the whole table. It is written by `dss:run`, and only the demo seed command reads it. `AnalyticsSnapshotModel::latestFor()` is never called.
- **`notification_preferences`:** `low_stock_warnings`, `daily_sales_summary`, `procurement_reminders` and `weekly_trend_report` are no longer used by any code, and their switches were removed from Settings. They keep their saved values only because the columns remain in the table. `rop_alerts` is the only preference still in use.
- **`reorder_alert`:** `stock_at_trigger` is only used for a count on the Ordering Report that the page never displays.
- **`products`:** `size_type`, and `sales_item.is_bulk`, are only set by the data import commands.

## Unclear points

- **Row counts aren't included**, because `information_schema` only gives estimates for InnoDB tables.
- **Local database only:** the structure was read from the local `rfourl_dss`, and the live server may differ. If it has run the same 31 migrations, it should match.
- **Integer display widths:** INT(11) is how MariaDB 10.4 reports INT. The paper can write INT, as the width doesn't change the stored values.
- **Code-based descriptions:** each description reflects what the code does today. Where the code only writes a column, the description says so.
