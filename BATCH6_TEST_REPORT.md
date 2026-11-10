# BATCH 6 TEST REPORT

**Environment:** this sandbox has Node.js and Python only. It has **NO PHP, NO MySQL/MariaDB, NO Apache/XAMPP, NO browser**, and no network. So nothing that needs PHP, the database or a browser could be run in Batch 6.
**Rule used:** PASS = actually executed here. NOT TESTED = could not be executed here (a static code-reading result is written in Notes; reading code is NOT a passed test). No FAIL was found.
(Batch 3 earlier ran 55 curl checks on real MariaDB + PHP; those are not repeated or claimed here.)

## A. Checks that were actually executed

| # | Test case | Expected | Actual | Status | Notes |
|---|-----------|----------|--------|--------|-------|
| A1 | `node --check` on all 13 `js/*.js` | No syntax errors | No errors | PASS | Syntax only, not behavior |
| A2 | Every local `href/src` in `*.html`/`*.php`, `url()` in CSS, and asset paths in JS exist on disk | No broken paths | None missing (one regex false positive: `staff.php<`) | PASS | Remote URLs (Google Fonts) not checked |
| A3 | Every `getElementById('x')` in `js/*.js` exists in an HTML/PHP file | No missing ids | None missing (`res-`, `err-` are prefixes built at runtime) | PASS | Ids built dynamically were not checked individually |
| A4 | Demo staff hash in SQL matches `Staff123!` | Verifies | `crypt()` returned the same hash | PASS | |
| A5 | Stray files in project root | None | `s1`, `c1` (curl cookie jars from Batch 3) found, served by Apache | FIXED | Deleted |

## B. Database (static review of `database/thesifted_db.sql` vs PHP)

| # | Test case | Expected | Actual | Status | Notes |
|---|-----------|----------|--------|--------|-------|
| B1 | Clean import creates only users, reservations, payments | 3 tables | Not run | NOT TESTED | Statement order reads correctly (users, reservations, payments), FKs reference earlier tables. Needs MariaDB 10.2+ / MySQL 8.0.16+ for CHECK |
| B2 | PKs, FKs, UNIQUE, NOT NULL | As documented in handoff | Not run | NOT TESTED | Read OK: PK on all ids, UNIQUE email, UNIQUE payments.reservation_id, FKs RESTRICT/CASCADE/SET NULL |
| B3 | Every column used by PHP queries exists | No unknown columns | Not run | NOT TESTED | Compared by reading: `RES_SELECT`, inserts, updates, `health_report` all match the schema; ENUM values and column lengths match PHP validation |
| B4 | Re-import is safe | No duplicate staff | Not run | NOT TESTED | Uses IF NOT EXISTS / INSERT IGNORE |

## C. Customer functionality (all need PHP + MySQL + browser)

| # | Test case | Expected | Actual | Status | Notes |
|---|-----------|----------|--------|--------|-------|
| C1 | Register (valid) | 201, signed in, role customer | Not run | NOT TESTED | Code reads correctly: validation, hash, UNIQUE-email race handled |
| C2 | Register: bad/duplicate fields | 400/409 with field errors | Not run | NOT TESTED | Field names match JS |
| C3 | Login / wrong password | 200 / 401 same message for unknown email | Not run | NOT TESTED | password_verify used |
| C4 | Logout + session persistence | Session destroyed / user kept across pages | Not run | NOT TESTED | |
| C5 | Access restrictions (guest to account.php, staff to account.php, customer to staff.php) | Redirect / redirect / 403 | Not run | NOT TESTED | Guards in `auth.php` read correctly |
| C6 | Reservation create + required-field validation | 201 / field errors | Not run | NOT TESTED | JS form field names match what `validate_reservation`/`validate_payment` read |
| C7 | Availability and full-slot rejection | Seats shown; fully booked slot refused | Not run | NOT TESTED | |
| C8 | Reservation history and own-only access | Only own rows | Not run | NOT TESTED | `WHERE user_id = ?` present |
| C9 | Payment recording + status display | proof_submitted shown, never verified | Not run | NOT TESTED | |
| C10 | Customer cancel (before/after deadline) | Allowed until start; late warning | Not run | NOT TESTED | |
| C11 | Proof upload (PNG/JPG/PDF, size, type) | Valid saved, others refused | Not run | NOT TESTED | Needs real upload |

## D. Staff functionality (all need PHP + MySQL + browser)

| # | Test case | Expected | Actual | Status | Notes |
|---|-----------|----------|--------|--------|-------|
| D1 | Staff login to staff.php | Dashboard | Not run | NOT TESTED | |
| D2 | Guest/customer blocked from staff page, actions, proof viewer | 302/401/403 | Not run | NOT TESTED | `require_staff_page` / `require_staff_action` used everywhere |
| D3 | List, filters, search, view details modal | Correct rows and details | Not run | NOT TESTED | |
| D4 | Verify / undo payment | Status changes as designed | Not run | NOT TESTED | |
| D5 | Confirm only after verified payment | Refused otherwise | Not run | NOT TESTED | |
| D6 | Complete / cancel / archive / restore / delete / notes | Rules enforced server-side | Not run | NOT TESTED | |
| D7 | POST without valid CSRF token | 403 | Not run | NOT TESTED | |

## E. Frontend and integration

| # | Test case | Expected | Actual | Status | Notes |
|---|-----------|----------|--------|--------|-------|
| E1 | Navigation, buttons, listeners, modals, calendar | Work | Not run | NOT TESTED | No browser |
| E2 | Responsive layouts (desktop / phone) | Intact after Batch 5 CSS cleanup | Not run | NOT TESTED | Still the highest-risk untested item |
| E3 | Browser console errors | None | Not run | NOT TESTED | |
| E4 | JS to API contract (URLs, headers, field names) | Match | Compared by reading, not run | NOT TESTED | `apiUrl()` -> `api/index.php/...` matches the router; `X-Requested-With: fetch` sent |

## F. Security (static review only)

| # | Test case | Expected | Actual | Status | Notes |
|---|-----------|----------|--------|--------|-------|
| F1 | SQL injection | All queries prepared | Not run | NOT TESTED | No direct user input found in SQL; filters whitelisted; LIKE escaped. Needs a live probe |
| F2 | Passwords | Hashed, never returned | Not run | NOT TESTED | `password_hash` / `public_user()` omits hash |
| F3 | XSS | Output escaped | Not run | NOT TESTED | PHP uses `e()`; JS uses `textContent` (staff modal uses `innerHTML` for own server-escaped HTML) |
| F4 | PHP errors hidden, logged | No details to visitor | Not run | NOT TESTED | |

## Remaining issues / notes
1. **Nothing in sections B to F was executed.** Run the full flow on XAMPP; this is the first thing to do.
2. No code defect was found by reading, so no source file was changed.
3. `js/app.js` has a menu-hover preview that needs `data-photo` attributes; no HTML has them, so that code never runs (harmless, left alone).
4. `assets/images/menu/` is unused (the 6 menu photos also exist in `assets/img/menu/`, and the files differ). Left alone; not verified which set is intended.
5. Login throttling is per browser session only (documented design limit).
