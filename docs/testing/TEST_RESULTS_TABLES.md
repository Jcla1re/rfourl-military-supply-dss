# Test result tables (filled from the runs of 2026-10-13)

Status key: **Pass**; **Fail, then Pass after retest** (found by the audit or the first run, fixed, retested); **Partial** (some of the expected result was verified, the rest was not); **Fail** (open defect); **Not yet executed**.
Evidence: PHPUnit (39+3 tests), pytest (20), Newman (156 requests, 74 assertions) against a copy of the live database (`rfourl_dss_test`), plus SQL checks. Cases marked *Not yet executed* must be run by hand before you write Pass.

## Table 4.3 Unit test cases

| ID | Test | Actual Result | Status | Verified by |
|---|---|---|---|---|
| UT-01 | Login with correct credentials | Admin was redirected to /admin/dashboard and last_login was updated to the time of login. | Pass | Live run (HTTP + SQL) |
| UT-02 | Login with wrong password | Login refused; the form shows "Incorrect password. Please try again" (full stop, not a comma). | Pass | Live run |
| UT-03 | Login with empty fields | First run: only the browser's required attribute stopped the form; the server showed no message. Server check added: "Please fill in all fields" on the Admin, Staff and Supplier login. | Fail, then Pass after retest | Live run + PHPUnit |
| UT-04 | Third failed attempt | First run: the lock needed 5 attempts. After the fix, wrong passwords 1 and 2 return to the login form and the 3rd redirects to /login/staff/forgot; the correct password is refused during the 60 s lock. | Fail, then Pass after retest | Live run |
| UT-05 | Password stored as a hash | password_hash() output starts with $2y$ (Bcrypt); verify is true for the right password and false for a wrong one. 10 of 10 user rows are Bcrypt. | Pass | PHPUnit + SQL |
| UT-06 | New password rules | 7 characters rejected; passwords that do not match rejected; 8 characters with a letter and a number accepted, and the new password logs in while the old one is refused (Admin and Supplier). | Pass | Live run + PHPUnit |
| UT-07 | Email code validation | Wrong 6-digit code rejected; correct digits with an expired code rejected ("This code has expired"); correct unexpired code accepted and leads to the reset form (Admin and Supplier). Email code only; the system has no phone OTP. | Pass | Live run (code set in the test database) |
| UT-08 | Access outside the user's role | Logged-out user sent to the role's login page for 7 protected pages; Staff on an Admin page and Supplier on a Staff or Admin page redirected to login. | Pass | PHPUnit (9) + live run |
| UT-09 | Annual demand | d = 20 gives D = 7,300 units. | Pass | PHPUnit |
| UT-10 | EOQ of the benchmark product | D = 7,300, S = 250, H = 18 gives EOQ = 450 units. | Pass | PHPUnit |
| UT-11 | Invalid holding cost | First run: H = 0 silently produced 0 and the quantity fell back to the minimum order, with no message. Now the Settings form rejects holding cost 0 and the engine refuses to run with a message ("Holding cost per unit must be greater than zero"). No division-by-zero error in either case. | Fail, then Pass after retest | Live run + PHPUnit |
| UT-12 | Z-score per class | Class A 1.645 (95%), Class B 1.282 (90%), Class C 1.036 (85%). | Pass | pytest |
| UT-13 | Safety Stock, Class A | 1.645 x 4.14 x sqrt(7) = 18.02; the engine rounds up and returns 19 (manual value 18). Within the +/-1 criterion. | Pass | PHPUnit |
| UT-14 | ROP, Class A | (20 x 7) + 18 = 158 with the manual SS; the engine's SS of 19 gives 159. Within +/-1. | Pass | PHPUnit |
| UT-15 | SS and ROP, Class B and C | Class B: SS 15, ROP 155 (manual 14 / 154). Class C: SS 12, ROP 152 (manual 11 / 151). Within +/-1. | Pass | PHPUnit |
| UT-16 | Average and standard deviation | Mean = 20. The engine uses the population standard deviation (2.83); the document's sample value is 3.16. Both round to 3. | Pass | PHPUnit |
| UT-17 | Lookback boundary | First run: a sale exactly 90 days back was excluded (the window started 89 days back). Fixed: with lookback 90, sales 30 and 90 days back are included and 91 days back is excluded. | Fail, then Pass after retest | PHPUnit |
| UT-18 | Stock status around the ROP | First run: stock 190 showed "Low Stock" because the band was 150% of ROP. Band changed to 120%; 158 = Reorder Now, 159 = Low Stock, 189 = Low Stock, 190 = In Stock. | Fail, then Pass after retest | PHPUnit |
| UT-19 | Min-Max normalization | Value 50 in 10..90 gives 0.50; identical values give 0 with no NaN or error. | Pass | pytest |
| UT-20 | Cluster the full dataset | 100 products returned 100 results, each with one label of 0, 1 or 2 and an ABC class. | Pass | pytest |
| UT-21 | Repeatable results | The same 100 products sent twice gave identical cluster labels. | Pass | pytest |
| UT-22 | Clusters mapped to ABC | Highest-demand centroid is Class A, then B, lowest is Class C; Z-scores 1.645 / 1.282 / 1.036. | Pass | pytest |
| UT-23 | Invalid clustering input | HTTP 400 with a message naming lead_time_days; HTTP 400 for only 2 products; HTTP 400 for an empty or non-JSON body. No clustering was run. | Pass | pytest |
| UT-24 | Add an out-of-stock item | Stock 0: the card reads "Out of Stock", clicking it leaves the cart at 0 (Chrome and Edge); a forced checkout is refused with "Not enough stock for <item>" and no sale is saved. | Pass | Browser run + live run |
| UT-25 | Quantity limit | Stock 3: quantity rises to 3 and stops. First run: it stopped silently. Now "Only 3 left of <item>" appears (Chrome). The server also refuses a quantity above stock (12 accepted, 13 refused). | Fail, then Pass after retest | Browser run + live run |
| UT-26 | Subtotal and total | 780 x 1 + 150 x 2 = subtotal 1,080, discount 0, total 1,080. Bulk (10+ units, 5%) and set (2%) discounts also checked. | Pass | PHPUnit |
| UT-27 | Change and insufficient payment | Total 1,080: paid 1,100 saved with change 20.00; paid 1,080 saved with change 0; paid 1,000 refused ("Insufficient cash received"), no sale saved. | Pass | Live run |
| UT-28 | Receipt numbering | Consecutive sales received 2161 and 2162: unique and sequential. The format is a plain number (matches the shop's invoice booklet), not #RFL-0142; update the document example. | Pass | Live run + SQL |
| UT-29 | Add item validation | First run: a duplicate name + size was accepted. Now rejected ("An item with this name and size already exists"); on-hand -5 rejected; the valid item saved. | Fail, then Pass after retest | Live run + SQL |
| UT-30 | Delete confirmation | The Admin screen archives the item: Cancel on the confirm dialog kept it active; Confirm set it inactive (archived, not erased). | Pass | Browser run |
| UT-31 | Manual adjustment | System 42, counted 37: stock became 37; log row change -5, type MANUAL_ADJUSTMENT, with the staff user ID. | Pass | Live run + SQL |
| UT-32 | Damaged / lost quantity | First run: an amount above stock was silently clamped to 0. Now 43 from stock 42 is rejected (stock stays 42); 1 damaged gives stock 41 with a DAMAGED_LOST log row. | Fail, then Pass after retest | Live run + SQL |
| UT-33 | Customer return with sale reference | First run: the reference was never checked. Now a valid receipt number is accepted (stock +1, RETURN logged with the reference); an unknown reference is rejected and nothing is logged. | Fail, then Pass after retest | Live run + SQL |
| UT-34 | Stock order total | 48 units x 480: the order page shows 23,040.00. | Pass | Live run |
| UT-35 | Actual lead time and late delivery | Ordered Jun 1, expected Jun 6, received Jun 8: lead time 7 days; the supplier's Completed list shows "2 days late". | Pass | Live run + SQL |
| UT-36 | Supplier form validation | First run: lead time 0 was accepted. Now the minimum is 1 day. Lead time 0 and email "supplier.a@" created no supplier; the valid supplier was saved with an auto ID (SUP-007). | Fail, then Pass after retest | Live run + SQL |
| UT-37 | DSS parameter validation | First run: service level 100% was accepted. Now service level 100 and ordering cost -250 are rejected; ordering cost 300 saved with updated_by = 1. | Fail, then Pass after retest | Live run + SQL |
| UT-38 | Notification for a reorder alert | First run: no notification was created. Now an Admin notification "Reorder alert: <item>" (category reorder_alert, unread) is saved when the alert is raised. | Fail, then Pass after retest | Live run + SQL |

**Summary.** 38 unit test cases are listed. 38 (100.0%) passed, of which 12 failed on the first run and passed after the fix. 0 are partially verified, 0 failed and are open, and 0 have not been executed yet.

## Table 4.x Integration test cases

| ID | Modules integrated | Actual Result | Status | Verified by |
|---|---|---|---|---|
| IT-01 | Login + RBAC + Dashboard | Admin, Staff and Supplier each reached their own dashboard; each was redirected to login when opening another role's page. | Pass | Newman |
| IT-02 | POS + Inventory | One sales_transaction with two sales_item rows; stock fell by 1 and 2; inventory_log has SALE entries with the staff user ID. | Pass | Newman + SQL |
| IT-03 | POS + Engine + Reorder Alerts + Notifications | First run: no alert or notification at checkout. Now selling an item down to its ROP raises an Active alert and an Admin notification, and the item shows on the dashboard and in Reorder Alerts. | Fail, then Pass after retest | Live run + SQL |
| IT-04 | K-Means (Flask) + Engine (CI4) | dss:run sent all 672 products to the service, saved 672 classes in cluster_segments, updated products.abc_category and used each class's Z-score (0 items with a mismatching Z). | Pass | spark dss:run + SQL |
| IT-05 | CI4 + Flask unavailable | With the service stopped, dss:run printed "Falling back to the last cached cluster assignment", wrote 672 new computations and left cluster_segments untouched. | Pass | Live run |
| IT-06 | Settings (DSS) + Engine | Ordering cost 300 gave EOQ 22; 500 gave EOQ 29 for the same item; both equal sqrt(2DS/H). The Admin ID was saved (updated_by = 1). | Pass | Live run + SQL |
| IT-07 | Reorder Alerts + Orders + Supplier Portal | Order SO-1340 was created from alert 52; the alert became Ordered with that order id; the supplier saw it in New Orders. | Pass | Live run + SQL |
| IT-08 | Supplier accept / decline + Notifications | Accepted order -> Preparing; declined order -> Cancelled with the stored reason; the Admin received "accepted by supplier" and "declined by supplier" notifications. First run: no reason was stored. | Fail, then Pass after retest | Newman + SQL |
| IT-09 | Supplier Deliveries + Orders | The order became Shipped with tracking JT-TEST-0001; the tracking number is shown on the Admin order page. | Pass | Newman + SQL |
| IT-10 | Orders + Inventory + Supplier Completed | Mark as Delivered: stock 12 -> 60 (+48), RESTOCK logged with the order id, delivery date saved, the alert became Fulfilled, and the supplier's Completed list shows "On Time". | Pass | Live run + SQL |
| IT-11 | Restock log + Orders | Staff restock confirmation on an already-delivered order was refused ("not ready to confirm"); only one RESTOCK row exists. | Pass | Newman + SQL |
| IT-12 | Damaged/Lost + Inventory + Notifications | Stock -1, DAMAGED_LOST logged with the staff user ID, and one Admin "Damaged/Lost" notification created. | Pass | Live run + SQL |
| IT-13 | Customer Return + POS + Inventory | First run: any reference was accepted. Now a valid receipt number gives stock +1 and a RETURN row with the reference; an invalid reference is rejected. | Fail, then Pass after retest | Live run + SQL |
| IT-14 | Staff reset request + Admin approval | The request created a notification; the Admin approved it and set a new staff password; the new password logs in and the old one is refused. | Pass | Live run |
| IT-15 | Suppliers + User Accounts + Supplier Login | Supplier SUP-007 and its user were created and linked; the new supplier logged in with the new credentials. | Pass | Live run + SQL |
| IT-16 | Settings (User Account) + Login | Staff account deactivated: its login was refused; reactivated: login works again. | Pass | Live run |
| IT-17 | Sales and Receipts + Database (CSV) | First run: no CSV export existed. Now July's CSV has 44 rows = 44 sale items in the database, 76 units = 76, and the receipt totals sum to 42,970.00 = database. (Latest data is July 2026; ?month=2026-06 exports June.) | Fail, then Pass after retest | Live run + SQL |
| IT-18 | Trend Analysis + K-Means + Database | First run: Trend Analysis had no PDF export. Added. For 2026 the PDF's total units equal the database (512); filtered to one item the PDF shows 73 = database 73. | Fail, then Pass after retest | Live run + SQL |

**Summary.** 18 integration test cases are listed. 18 (100.0%) passed, of which 5 failed on the first run and passed after the fix. 0 are partially verified, 0 failed and are open, and 0 have not been executed yet.

## Table 4.x System test cases

| ID | Test type | Actual Result | Status | Verified by |
|---|---|---|---|---|
| ST-01 | Functional (POS) | 22 counter sales (11 Cash, 11 GCash) all saved; the sum of receipts equals the expected total (41,790.00); each item's stock fell by exactly the units sold; every receipt on the day's list carries the cashier name. A sale was also completed by clicking in Chrome and Edge. | Pass | Live run + browser run |
| ST-02 | Functional (digitization) | A month of paper-style records (6 receipts, 12 lines) was encoded with the import command: 6 receipts saved, total 3,300.00 = paper total, units per item equal, and the engine's as-of date moved to the new history. A generated CSV stood in for the actual logbook. | Pass | Live run + SQL |
| ST-03 | Functional (month-end audit) | Sale, return, damaged and adjustment on one item: counted 94 -> system stock 94; four log rows (SALE, RETURN, DAMAGED_LOST, MANUAL_ADJUSTMENT), each with user and timestamp. | Pass | Live run + SQL |
| ST-04 | Functional (Procurement) | dss:run computed EOQ, Safety Stock and ROP for 672 products without manual input in 1.6 s. Changing a supplier's lead time 7 -> 10: SS 4 -> 5 and ROP 5 -> 6 for the most variable item; the other items' unrounded SS rose (2.39 -> 2.86) but stayed the same whole number; EOQ unchanged. Benchmark values (450/18/158) are covered at formula level. | Pass | Live run + PHPUnit |
| ST-05 | Functional (BI) | 672 of 672 products received exactly one class (A 471, B 129, C 72 in the last run) using the four attributes; results saved and the ABC panel loads on Trend Analysis. | Pass | Live run + SQL |
| ST-06 | Functional (Probabilistic engine) | Every item's Z equals its class Z (0 mismatches). For the same demand statistics, Class A gives SS 8 versus Class C 5, and the engine stored the Class A value. | Pass | Live run + SQL |
| ST-07 | Functional (Reorder alerts) | Sold to ROP: alert Active, Admin notification, shown on the dashboard and in Reorder Alerts. At 120% of ROP: no alert and the inventory shows "Low Stock". After the restock was delivered the alert became Fulfilled. | Pass | Live run + SQL |
| ST-08 | Functional (Analytics) | Year and item filters change only the shown data; the exported PDF matches the database (512 units; 73 for one item) and the CSV matches (44 rows, totals equal). | Pass | Live run + SQL |
| ST-09 | Functional (Supplier communication) | Accept -> Shipped with tracking -> Delivered, and decline with reason, all appeared on the Admin side with notifications; another supplier could not see the order, and accept/decline/ship on it answered "Order not found" with the status unchanged. | Pass | Live run + SQL |
| ST-10 | Performance (checkout 3.0 s) | 30 checkouts of 1-5 items on Apache/XAMPP (localhost): all completed; mean 1.47 s, median 1.41 s, max 2.26 s. Network latency is not included. | Pass | Live run (timed) |
| ST-11 | Performance (concurrent users) | 20 checkouts while Admin and Supplier browsed continuously (117 page loads, 0 server errors): mean 1.86 s, max 2.05 s. | Pass | Live run (timed) |
| ST-12 | Performance (clustering 10.0 s) | 10 runs on 100 products: 0.15 s first run, 0.025-0.032 s afterwards (max 0.15 s). Measured locally, not on the staging server. | Pass | pytest |
| ST-13 | Security (SQL injection) | Entering ' OR '1'='1 in username and password did not log in and did not redirect to a dashboard. | Pass | Newman |
| ST-14 | Security (access control) | Staff on an Admin URL and Supplier on an Admin URL were redirected to login; a second supplier acting on another supplier's order got "Order not found" and the order was unchanged. | Pass | PHPUnit + live run |
| ST-15 | Security (CSRF / XSS) | A POST without a CSRF token returned HTTP 403. An item named <script>alert(1)</script> was stored and shown as plain text on 5 pages (0 raw script tags). | Pass | Live run |
| ST-16 | Security (hash / idle / HTTPS) | 10 of 10 passwords are Bcrypt. In production mode http:// returns 307 with a Strict-Transport-Security header. Idle: first run, a session left unused for 31 minutes was still accepted (HTTP 200) because only the cookie expired. Added a server-side sliding 30-minute idle check; tested with the limit set to 60 s: a session idle 80 s was redirected to login, and one used every 40 s stayed valid. | Fail, then Pass after retest | Live run (31-min wait, then 60 s re-test) |
| ST-17 | Security (recovery / inactive accounts) | Admin and Supplier recovered through the email code; Staff through the Admin's approval; old passwords stopped working; a deactivated staff account and an Inactive supplier could not log in. | Pass | Live run |
| ST-18 | Compatibility | Chrome 154 and Edge 155: Staff login, POS sale with receipt, and 5 further pages rendered with no application console errors. Firefox is not installed on this machine and was not tested. | Partial | Selenium (headless) |
| ST-19 | Reliability (Flask down) | With the service stopped, the run used the cached classes, wrote results and kept working (the service was down at the start of the run, not killed mid-run). | Pass | Live run |
| ST-20 | Reliability (partial sale) | A failure on the second write of a sale was simulated at database level: after rollback 0 sale rows and 0 stock change remained; the checkout code wraps its writes in one transaction. The physical network cut was not performed. | Partial | Simulated (SQL/PHP) |
| ST-21 | Reliability (log immutability) | First run: no screen could edit the log, but the database allowed it. Added database triggers: a direct UPDATE or DELETE on inventory_log is now refused ("inventory_log is append-only"). | Fail, then Pass after retest | Live run (SQL) |
| ST-22 | Reliability (8-hour run) | Shortened to 31 minutes: 31 min of regular sales/stock transactions: {'sale': 188, 'damaged': 37, 'return': 26, 'pages': 752}; stock in DB 9597 = expected 9597; log rows 251 = operations; server errors/failed ops=[]; new application ERROR log lines=0; checkout mean 1.63 s, max 2.68 s. The 8-hour run was not done. | Partial | Live run (31 min) |
| ST-23 | Usability (3-item sale under 60 s) | A scripted 3-item cash sale with receipt took 16.3 s in Chrome (machine time). The test with three human staff-level testers has not been done. | Partial | Selenium (not human testers) |
| ST-24 | Maintainability | CodeIgniter 4 MVC structure; every schema change since September is a migration in app/Database/Migrations. The base schema comes from the seeder, not a migration. | Pass | Code review |
| ST-25 | Compliance (Data Privacy Act) | Scanning every Staff and Supplier page for other users' emails and phone numbers found none. A full manual review of names and other fields was not done. | Pass | Live run (scan) |

**Summary.** 25 system test cases are listed. 21 (84.0%) passed, of which 2 failed on the first run and passed after the fix. 4 are partially verified, 0 failed and are open, and 0 have not been executed yet.
