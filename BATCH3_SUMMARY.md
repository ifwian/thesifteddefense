# Batch 3 Summary - Staff Backend Operations

## Changes (UI untouched except one button)
- `includes/auth.php`: NEW `require_staff_action()` - one guard for staff endpoints. Guest = 401, customer = 403. (`require_staff_page()` for pages unchanged.)
- `actions/staff-process.php`: rewritten in plain, commented PHP. Order: staff guard -> POST + CSRF -> validate `id` (>= 1) and whitelist `action` -> call one CRUD function -> flash message -> redirect to `staff.php` with only known filter keys.
- `includes/payments.php`: NEW `staff_unverify_payment()` (verified -> proof_submitted, only while the reservation is still pending, clears verified_by/verified_at, race-safe UPDATE).
- `includes/staff_detail.php`: one extra "Undo verification" button (existing `btn btn-outline btn-small` classes, same form pattern). Shown only for verified + pending.
- `staff-proof.php`: **bug fix** - it read the removed `proof_mime` column (warning + wrong filename). Now uses the file extension; guard via `require_staff_action()`; output buffer cleared before the file is sent.
- Unchanged and verified: `staff_set_status`, `staff_verify_payment`, `staff_set_notes`, archive/restore/delete, `staff_reservations` filters, `staff_counts`, `staff.php`, `staff.js`, CSS, SQL file (no schema change).

## Staff operations map (CRUD)
| Button | Function | SQL |
|---|---|---|
| list / search / filter / detail | `staff_reservations`, `RES_SELECT` | SELECT + JOIN |
| Confirm / Complete / Cancel | `staff_set_status` | UPDATE reservations.status |
| Verify / Undo verification | `staff_verify_payment` / `staff_unverify_payment` | UPDATE payments.status |
| Save notes | `staff_set_notes` | UPDATE admin_notes |
| Archive / Restore | `staff_archive_reservation` / `staff_restore_reservation` | UPDATE archived_at |
| Delete permanently | `staff_delete_archived_reservation` | DELETE payments, DELETE reservations (transaction) |

## Testing (real MariaDB 10.11 + PHP 8.3 built-in server + curl; DB imported from `database/thesifted_db.sql`): 55/55 PASS
- Access: guest -> staff.php redirect to login; guest 401 on staff-proof and staff-process; customer 403 on staff.php, staff-proof, staff-process (also with a replayed staff action); wrong password 401; staff login OK; staff account refused from creating reservations; bad CSRF 403.
- Real multipart proof upload (PNG) by a customer, 3 reservations created.
- View: list, search (name/email, `%` escaped), payment filter, detail modal (200 / 404), proof viewer (200, image/png, 404 for unknown id).
- Status: confirm refused before payment verified; confirm after verify; complete; completed -> cancel/confirm refused; pending -> cancel; setting 'pending' or unknown action refused; id=0 / id=abc handled without error.
- Payments: verify (verified_by set), double verify, undo verification (verified_by cleared), undo refused when not verified or reservation confirmed, verify refused on cancelled reservation.
- Notes saved and HTML-escaped on display; archive refused for pending; delete refused when not archived; archive/restore; delete removes reservation, payment row and proof file.
- `storage/logs/app.log` empty after the run (no PHP warnings).

## NOT tested
- Real browser (modals, animations, responsive layout, `staff.js`) and XAMPP/Apache (`.htaccess` rules; tests used PHP's built-in server with a small router).
- Session-expiry behaviour, login throttle (5 fails), concurrent two-staff clicks (race guards are in the SQL but not exercised).
- Proof upload of PDF type or files over 3 MB.

## Notes
- A cancelled reservation keeps its payment status (no `refunded` status in the schema; refunds handled manually). Unchanged from Batch 2.
- Customers cannot re-upload a rejected proof, so no "reject proof" action was added (would need a new customer flow).
