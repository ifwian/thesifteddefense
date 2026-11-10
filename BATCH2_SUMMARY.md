# Batch 2 Summary - Reservation & Payment Backend

## Changes
- **`includes/reservations.php`** rewritten in plain PHP: no type hints, arrow functions, `[$a,$b]=` destructuring, `min/max/array_column` tricks or `Throwable`. Sections: helpers, validation, create (INSERT), read (SELECT), customer cancel, staff changes (UPDATE/DELETE).
- **NEW `includes/payments.php`** (payment code split out): `validate_payment`, `inspect_proof` (finfo content check), `save_proof_file`, `delete_proof_file`, `insert_payment`, `staff_verify_payment`.
- `validate_reservation()` / `validate_payment()` / `inspect_proof()` now return keyed arrays (`values`, `errors`, `proof`, `info`, `error`) instead of positional arrays.
- `api/index.php`: only the "create reservation" route updated for the new return shape.
- `bootstrap.php`: loads payments.php.
- `staff_verify_payment` is now one JOIN query plus an `UPDATE ... AND status='proof_submitted'` (safe against double clicks); `staff_set_status` uses one LEFT JOIN instead of a subquery; `staff_set_notes` reports "not found".
- Unchanged function names/behaviour: `my_reservations`, `staff_reservations`, `staff_counts`, `shape_reservation`, `allowed_next_statuses`, `staff_*`, `customer_cancel_reservation`, `slot_availability`, `RES_SELECT`, `reference_for`, etc. Pages and `actions/staff-process.php` untouched.

## Rules kept
Prepared statements everywhere; customer queries filtered by `user_id`; staff actions only via staff-guarded `staff-process.php`; amounts computed on the server; status flow pending -> confirmed -> completed/cancelled (confirm only after payment verified); archive before delete; transaction for reservation + payment; capacity + duplicate checks; proof never auto-verified.

## Testing
- `php -l` on all PHP files: OK.
- 28-check script on an in-memory SQLite copy of the schema: validation (good/bad), proof-required, create (reservation + payment in one transaction), duplicate and capacity blocking, customer history isolation, cancelling another user's reservation refused, verify/confirm/complete flow and refusals, notes, staff filters + verifier name + balance, counts, archive/restore/delete rules, payment row removed on delete, availability, own cancel: all PASS.
- NOT tested: real MySQL/XAMPP, real file upload (`move_uploaded_file` needs a web request), browser UI.

## Database observations (documented, schema NOT changed)
1. Cancelling a reservation leaves its payment status as-is (`unpaid/proof_submitted/verified`); there is no `refunded` status. Refunds are handled manually by staff.
2. `reservations.guests` has CHECK >= 1 but the 2-guest minimum and branch capacity are enforced in PHP only.
3. `reservation_date`/`reservation_time` have no DB rule against past dates; PHP enforces notice days.
4. `idx_res_slot (branch, date, time)` exists, good for the capacity SUM; no index on `archived_at` (fine at this size).
5. Customer name/phone/email on a reservation come from `users`, so editing a user's phone changes it on old reservations (expected under 3NF).

## Remaining
1. Test on XAMPP (register, reserve with proof, staff flow, cancel, proof viewer).
2. Batch 3 candidates: rest of `functions.php`, `api/index.php` routing, `staff.php`/`account.php`/`staff_detail.php`/`staff-proof.php`, then JS.
3. DBMS deliverables (demo queries, ERD, normalization) and rewriting README/PRESENTATION/CHANGELOG.
