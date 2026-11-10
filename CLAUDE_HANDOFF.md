# HANDOFF - The Sifted Cafe & Bakery (DBMS CS201 final project)

## LATEST: BATCH 6 DONE (testing) - see BATCH6_SUMMARY.md and BATCH6_TEST_REPORT.md
Sandbox had NO PHP / MySQL / Apache / browser, so only static review + Node/Python checks were possible. PASS (executed): JS syntax (13 files), local asset/link paths, JS element ids, demo staff hash = `Staff123!`. Everything else (DB import, customer/staff flows, uploads, CSRF/access, browser/responsive) is NOT TESTED. No code defects found by reading, so NO source file changed; schema unchanged (3 tables). Deleted stray `s1`, `c1` (curl cookie jars in project root). Known harmless leftovers: dead menu-hover code in `js/app.js` (no `data-photo` in HTML); unused `assets/images/menu/` folder. Next: run on XAMPP and execute the NOT TESTED cases first; then fix bugs; then DBMS docs (Batch 7, not started). README/PRESENTATION/CHANGELOG still outdated.

## LATEST: BATCH 5 DONE (CSS cleanup) - see BATCH5_SUMMARY.md
Only `css/style.css` changed: 134 unused rules/selectors removed (classes absent from all HTML/PHP/JS), whitespace and header comment tidied. HTML/PHP/JS/DB untouched. Override sections (9 Refinement, 10 Final polish) intentionally NOT merged (cascade risk). NOT visually/browser tested. Next: Batch 6 (not started) - remaining `functions.php`, `api/index.php` routing, DBMS docs; visually verify CSS first.

## LATEST: BATCH 4 DONE (JS simplification) - see BATCH4_SUMMARY.md
Small cleanups in `js/account.js`, `js/reserve.js`, `js/staff.js` (duplicate sign-out binding removed, clearer names, redundant checks removed). Other JS already simple. `node --check` passes; NOT browser-tested. No PHP/DB/UI changes. Next: Batch 5 (not started) - remaining `functions.php`, `api/index.php` routing, `account.php`, DBMS docs.

## LATEST: BATCH 3 DONE (staff backend) - see BATCH3_SUMMARY.md
Batch 3: NEW `require_staff_action()` in `includes/auth.php` (401 guest / 403 customer) used by `actions/staff-process.php` and `staff-proof.php`; `staff-process.php` rewritten as a simple validated switch (id>=1, whitelisted action); NEW `staff_unverify_payment()` in `includes/payments.php` + one "Undo verification" button in `includes/staff_detail.php` (existing button classes); FIXED `staff-proof.php` bug (it read the removed `proof_mime` column). Tested on REAL MariaDB 10.11 + PHP 8.3 built-in server with curl (55 checks pass, incl. real upload). Still NOT tested in a browser / XAMPP. Next: Batch 4 (not started) - remaining `functions.php`, `api/index.php` routing, `account.php`, JS, DBMS docs.


**Goal:** SIMPLIFY the existing PHP/MySQL reservation site (do NOT rebuild). Beginner-friendly code that 2nd-year CS students can explain at a defense. UI/UX must stay IDENTICAL (colors, fonts, layout, animations, images). Keep: password_hash/verify, PDO prepared statements, sessions, access checks, server-side validation, safe uploads.
Stack: plain PHP (no framework), vanilla JS, MySQL via XAMPP + phpMyAdmin. Setup: copy to `C:\xampp\htdocs\thesifted`, import `database/thesifted_db.sql`, open `http://localhost/thesifted/`.
Demo staff login: `staff@example.com` / `Staff123!`. Customers register on signup.html.

## (older) STATUS: MILESTONE 1 + BATCH 1 + BATCH 2 DONE (Batch 2: see BATCH2_SUMMARY.md)
Batch 2: `includes/reservations.php` rewritten in plain PHP (no type hints/arrow fns/destructuring); NEW `includes/payments.php` (validate_payment, inspect_proof, save/delete_proof_file, insert_payment, staff_verify_payment). `validate_reservation`/`validate_payment` now return keyed arrays (`values`,`errors`,`proof`); api/index.php updated. All function names used by pages/actions unchanged. Tested on SQLite harness (28 checks pass); NOT on real MySQL/uploads.

(older) STATUS: BATCH 1 (auth/db/session refactor, see BATCH1_SUMMARY.md)
Batch 1: NEW `includes/auth.php` (current_user, sign_in/out, register_customer, login_user, login throttle, page guards, registration_errors). `config/database.php` + `includes/bootstrap.php` simplified (no static/closures/`??` arrays); shared `show_error()` in functions.php. `api/index.php` register/login routes now one-liners calling auth.php. Database/UI untouched. Still NOT tested on real MySQL/XAMPP.

## Project structure
- `*.html` public pages; `account.php` (customer: reserve + history); `staff.php` (staff dashboard); `staff-proof.php` (staff-only proof viewer)
- `api/index.php` JSON API (register, login, logout, me, my/reservations, cancel, availability, quote, create reservation, health)
- `actions/staff-process.php` staff buttons (confirm/complete/cancel/verify/notes/archive/restore/delete); `actions/logout.php`
- `includes/bootstrap.php` (start of every page), `functions.php` (login, guards, validation), `reservations.php` (ALL reservation/payment SQL), `staff_detail.php` (staff modal HTML)
- `config/app.php` (rates, branches, upload rules), `config/database.php` (PDO connection)
- `js/` page scripts, `css/style.css`, `storage/proofs/` (uploads, not web-accessible)

## Database (exactly 3 tables) - `database/thesifted_db.sql`
- **users**(id PK, full_name, email UNIQUE, phone, password_hash, role ENUM customer|staff, created_at)
- **reservations**(id PK, user_id FK->users RESTRICT, branch, reservation_date, reservation_time, guests CHECK>=1, occasion, special_requests, status, admin_notes, created_at, updated_at, archived_at)
- **payments**(id PK, reservation_id FK->reservations UNIQUE CASCADE, method, payment_option, total_amount, amount_due, reference_number, proof_original_name, proof_file, status, verified_by FK->users SET NULL, verified_at, created_at; CHECK amount_due<=total_amount)
- Relations: users 1--M reservations; reservations 1--1 payments; users(staff) 1--M payments (verified_by).
- 3NF changes made: removed duplicated customer_name/phone/email from reservations (now JOIN users); removed derived `balance` (computed in SELECT as total_amount - amount_due); removed always-null `allergies`, `archived_by`, `proof_mime`, `proof_size`.

## Removed
Owner Dashboard (`owner.php`, `includes/owner*.php`, `js/owner.js`, `/api/owner/*`, owner CSS section, `owner` role, owner demo account); audit logs (`includes/audit.php`, `audit_logs` table + triggers, every `audit_log()/audit_reservation()` call); `database/migrations/` (legacy owner/audit migrations).

## Modified files
`database/thesifted_db.sql`, `includes/bootstrap.php`, `includes/functions.php`, `includes/reservations.php` (RES_SELECT now JOINs users; insert/archive/restore/delete/verify simplified; function signatures lost `$actor`/`$staffId` params), `includes/staff_detail.php`, `api/index.php`, `actions/staff-process.php`, `staff.php`, `staff-proof.php` (mime now derived from stored file extension), `account.php`, `js/auth.js`, `js/shared.js`, `js/reserve.js`, `css/style.css`.

## One small behavior change (UI looks the same)
Reservation form Name/Phone/Email are now **read-only** (filled from the account) because they are stored only in `users` (3NF). Server ignores submitted values for them (still validates).

## Tests done
- `php -l` on every PHP file: no syntax errors.
- Grep: no remaining references to owner/audit/removed columns in code.
- Main staff/customer SELECT (RES_SELECT + LIKE/ESCAPE filter) executed on an equivalent SQLite schema: returns customer name/phone/email from users, balance=560, verifier name correct.
- NOT tested: real MySQL import and browser run (no MySQL in the sandbox).

## Known issues / remaining
1. **Must test on XAMPP**: import SQL (needs MariaDB 10.2+/MySQL 8.0.16+ for CHECK), register, reserve with proof, staff verify/confirm/complete/cancel/archive/restore/delete, customer cancel, proof viewer.
2. `README.md`, `PRESENTATION.md`, `CHANGELOG.md` are OUTDATED (marked at top); rewrite later.
3. DONE in Batch 1: database.php, bootstrap.php, auth code. STILL TODO: `api/index.php` routing (regex), `functions.php` (type hints, health_report, CSRF origin check).
4. DONE in Batch 2: reservations.php / payments.php.

## Next steps (suggested order)
1. Run on XAMPP and fix any bugs from milestone 1.
2. Batch 3 (next): remaining `functions.php` + `api/index.php` + page files (staff.php, account.php, staff-proof.php, staff_detail.php) simplification.
3. Milestone 3: simplify JS (`reserve.js`, `account.js`, `staff.js`).
4. Milestone 4: DBMS deliverables: `database/sql_demo_queries.sql` (INSERT/UPDATE/DELETE/SELECT, WHERE/AND/OR, aggregates + GROUP BY + HAVING + ORDER BY, INNER/LEFT/RIGHT JOIN, aliases, LIMIT, LIKE), Chen ERD, normalization write-up, documentation, per-member reviewers.
