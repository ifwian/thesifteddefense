# BATCH 6 SUMMARY - testing and bug fixing

**Limit:** the sandbox had no PHP, MySQL, Apache or browser, so the database, customer, staff and browser tests could NOT be run. Nothing is claimed as passed unless it was executed (see `BATCH6_TEST_REPORT.md`).

## Done
- Read all PHP (auth, API, reservations, payments, staff pages/actions, proof viewer), the SQL file, config and JS, and cross-checked schema vs queries, JS vs API field names, and ids.
- Executed: JS syntax check (13 files, pass), local asset/link path check (pass), JS element-id check (pass), demo staff password hash check (pass).
- Found and removed two stray curl cookie-jar files in the project root (`s1`, `c1`).

## Not done (honestly)
- Real database import, registration/login/reservation/payment/staff flows, upload, CSRF and access tests, browser/responsive/console checks.
- No code bug was found by reading, so no PHP/JS/CSS/SQL file was changed. Schema unchanged (3 tables).

## Next
Run the system on XAMPP (import SQL, open `http://localhost/thesifted/`, then `api/index.php/health`) and walk through the cases marked NOT TESTED.
