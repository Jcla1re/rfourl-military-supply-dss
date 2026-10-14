# Test plan vs. code: status (2026-10-14)

Per-case results with actual values are in `TEST_RESULTS_TABLES.md` / `.docx`.
This file lists what was changed in the code because of testing, what is left on purpose, and how to re-run.

## Changed in the code because of testing

| Area | Change |
|---|---|
| Engine ROP drives status/alerts | `dss:run` writes the computed ROP to `products.manual_rop_warning` (when > 0) |
| Alerts | Raised right after a sale or stock log; one Admin notification per alert (summary if > 5) |
| Low Stock band | 120% of ROP (`ProductModel::LOW_STOCK_BAND`) |
| Lookback window | Includes the day `lookback` days back (`DssFormulas::windowStart`) |
| Holding cost | Settings rejects 0; the engine refuses to run without it |
| Login | 3 failed attempts lock 60 s and redirect to Forgot Password; server-side "Please fill in all fields" |
| Session | Sliding 30-minute idle timeout enforced in `RoleAuthFilter` (`last_activity`); cookie 30 min; HTTPS forced in production |
| Inventory | Duplicate name + size rejected; damaged quantity above stock rejected; return needs an existing receipt number |
| POS | "Only N left of <item>" message when the quantity limit is reached |
| Orders | Supplier decline needs a reason (`stock_order.decline_reason`); shown on the Admin order page; New Orders page now shows errors |
| Suppliers / DSS | Lead time >= 1 day; service level < 100 |
| Exports | Sales CSV (`sales/export-csv?date=` or `?month=`), Trend Analysis PDF (`trend-analysis/pdf?year=&item=`) |
| Scheduling | "Run DSS engine now" button; `tools/run_dss.bat` for Task Scheduler |
| Audit trail | Database triggers make `inventory_log` append-only |

## Migrations to run on the live database

`php spark migrate` applies:
1. `2026-10-13-100000_AddDeclineReasonToStockOrder` (already applied to the live DB by accident during testing; harmless)
2. `2026-10-13-110000_MakeInventoryLogAppendOnly` (triggers; applied only to the test database so far)

## Left as is on purpose (settle in the document)

- Safety Stock is rounded **up** (19 / 159 vs the document's 18 / 158); inside the plan's own +/-1 rule.
- Standard deviation is the population value (2.83 vs the document's sample 3.16); both round to 3.
- Receipt numbers are plain sequential numbers on purpose (they match the shop's invoice booklet), not `#RFL-0142`.
- Login message uses a full stop: "Incorrect password. Please try again".
- Recovery uses an **email** code only; there is no phone OTP.

## Still open

| Case | Why |
|---|---|
| ST-18 | Firefox is not installed on the test machine; Chrome and Edge passed |
| ST-20 | The transaction rollback was simulated at database level; no real network cut |
| ST-22 | Only a 31-minute soak was run, not 8 hours |
| ST-23 | Needs three human staff-level testers with a stopwatch |

## Re-running

| Suite | Command |
|---|---|
| PHPUnit | `cd backend-ci4 && php vendor/bin/phpunit -c phpunit.dist.xml --no-coverage tests/unit tests/feature` (44 tests) |
| pytest | `cd ml-service && python -m pytest tests -v` (20 tests) |
| Newman | `npx newman@6 run backend-ci4/tests/postman/RfourL_PDSS_Integration_Tests.postman_collection.json` (156 requests, 74 assertions) |

Newman and the live-run scripts need the app on `http://localhost:8080`, `ml-service` on :5001 and a **test** database
(`rfourl_dss_test`, a mysqldump clone of `rfourl_dss`, with known passwords for admin1 / staff1 / MFUT).
Three failed logins within 15 minutes lock that role for 60 s, so wait a minute between runs.
