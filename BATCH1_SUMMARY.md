# Batch 1 Summary - Auth, Database Connection, Sessions

## What changed
- **NEW `includes/auth.php`**: all login/session code in one place: `current_user`, `sign_in`, `sign_out`, `public_user`, `home_page_for`, `require_login_page`, `require_staff_page`, `registration_errors`, `email_exists`, `register_customer`, `login_user`, `login_throttled`, `login_failed`. Plain `if/else`, no static cache, no ternaries/arrow functions.
- **`includes/functions.php`**: auth code moved out to auth.php; added `show_error()` (one place that prints a safe JSON/HTML error).
- **`config/database.php`**: simpler `db_error_kind()`, `db_open()`, `db()` (global `$pdo`, `if/elseif` messages, no static/arrays/closure).
- **`includes/bootstrap.php`**: closures replaced by named functions `handle_fatal_error()` / `handle_exception()`; loads auth.php.
- **`api/index.php`**: register and login routes now just call `register_customer()` / `login_user()`. Other routes untouched.

## Kept (security)
PDO prepared statements, `password_hash`/`password_verify`, `session_regenerate_id`, HttpOnly + SameSite cookie, role read from DB on every request, staff/login page guards, CSRF checks, login throttle (5 tries / 5 min), same error for wrong email or password, server-side validation.

## Not changed
Database/SQL, UI (HTML/CSS/JS), reservations, staff actions, uploads.

## Tests
`php -l` on all PHP files: OK. CLI smoke test: bootstrap loads, validation works, DB error path returns safe JSON. NOT tested on real MySQL/XAMPP browser flow (no MySQL in sandbox) - please test register, login (customer + staff), logout.

## Remaining
1. `api/index.php`: regex routing, array destructuring, `need_login` helper.
2. `functions.php`: type hints, `health_report`, CSRF origin check.
3. `reservations.php` (~540 lines) - main refactor.
4. JS simplification, DBMS deliverables (demo queries, ERD, normalization), README/PRESENTATION/CHANGELOG rewrite.
