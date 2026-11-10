# SQL OPERATIONS GUIDE - The Sifted Café & Bakery

**Course:** Database Management System (final project)  **Database:** `thesifted_db`  **Tables:** `users`, `reservations`, `payments`

This guide shows practical SQL on the **real tables and columns** of `database/thesifted_db.sql`. Every example says which tables it uses, gives a café situation, shows the statement, explains it in plain words and shows a sample result.

## How to run the examples

1. Start **Apache** and **MySQL** in XAMPP and open `http://localhost/phpmyadmin`.
2. Import `database/thesifted_db.sql` (see `DATABASE_DOCUMENTATION.md`, Section 4).
3. Click the database **thesifted_db** in the left list, open the **SQL** tab.
4. Run the **Setup** block below once, then run the examples in order (Part A changes data; Parts B and C use that data).
5. In phpMyAdmin you may select one statement at a time and press **Go** (some boxes hold more than one statement).

> **How these examples were checked (honest note).** The sandbox used to write this guide has **no MySQL/MariaDB**. Every statement below was executed in an automated test against a copy of the real schema in SQLite 3.45 (ENUM written as a CHECK list; `NOW()`, `CURDATE()`, `LPAD()` supplied by the test; foreign keys switched on), and the sample results were copied from that run. The statements use plain standard SQL plus `NOW()`, `CONCAT()` and `LPAD()`, which MySQL has, but they were **not executed on a real MySQL server**. Run them once in phpMyAdmin before the defense. Money columns would show as `800.00` in MySQL; sums and averages may show extra decimals unless `ROUND()` is used.

## Setup: sample data (run once)

The demo staff account (`id` 1, `staff@example.com`) already exists after the import. This block adds one more staff member, four customers, six reservations and six payments. `id` values are written by hand **only so the examples are repeatable**; the website lets AUTO_INCREMENT choose them. Reservations use ids 101-106, so a real sign-up/booking on the same database will not clash.

```sql
INSERT INTO users (id, full_name, email, phone, password_hash, role) VALUES
 (2, 'Demo Staff Two', 'staff2@example.com', '09000000002', 'sample-hash-not-real', 'staff'),
 (3, 'Ana Reyes',  'ana.reyes@example.com',  '09171110001', 'sample-hash-not-real', 'customer'),
 (4, 'Ben Santos', 'ben.santos@example.com', '09171110002', 'sample-hash-not-real', 'customer'),
 (5, 'Carla Dizon','carla.dizon@example.com','09171110003', 'sample-hash-not-real', 'customer'),
 (6, 'Dan Lopez',  'dan.lopez@example.com',  '09171110004', 'sample-hash-not-real', 'customer');

INSERT INTO reservations (id, user_id, branch, reservation_date, reservation_time, guests, occasion, status) VALUES
 (101, 3, 'calamba', '2026-12-05', '18:00:00', 4, 'Birthday',      'pending'),
 (102, 3, 'cabuyao', '2026-12-12', '10:00:00', 6, NULL,            'confirmed'),
 (103, 4, 'calamba', '2026-12-05', '18:00:00', 2, 'Anniversary',   'confirmed'),
 (104, 4, 'cabuyao', '2026-11-20', '14:00:00', 8, 'Meeting',       'completed'),
 (105, 5, 'calamba', '2026-12-20', '19:00:00', 5, 'Family dinner', 'cancelled'),
 (106, 5, 'cabuyao', '2026-12-05', '12:00:00', 3, NULL,            'pending');

INSERT INTO payments (reservation_id, payment_option, total_amount, amount_due, reference_number, status, verified_by, verified_at) VALUES
 (101, 'downpayment',  800.00,  240.00, '1234567890123', 'proof_submitted', NULL, NULL),
 (102, 'full',        1200.00, 1200.00, '2234567890123', 'verified', 1, '2026-11-02 10:00:00'),
 (103, 'downpayment',  400.00,  120.00, '3234567890123', 'verified', 2, '2026-11-03 11:30:00'),
 (104, 'full',        1600.00, 1600.00, '4234567890123', 'verified', 1, '2026-11-01 09:15:00'),
 (105, 'downpayment', 1000.00,  300.00, NULL,            'unpaid',   NULL, NULL),
 (106, 'downpayment',  600.00,  180.00, '6234567890123', 'proof_submitted', NULL, NULL);
```

Resulting data (what the examples work with):

| Customer | Reservation | Branch | Date | Guests | Status | Payment (total / due) | Payment status |
|---|---|---|---|---|---|---|---|
| Ana Reyes | 101 | calamba | 2026-12-05 | 4 | pending | 800 / 240 | proof_submitted |
| Ana Reyes | 102 | cabuyao | 2026-12-12 | 6 | confirmed | 1200 / 1200 | verified (staff 1) |
| Ben Santos | 103 | calamba | 2026-12-05 | 2 | confirmed | 400 / 120 | verified (staff 2) |
| Ben Santos | 104 | cabuyao | 2026-11-20 | 8 | completed | 1600 / 1600 | verified (staff 1) |
| Carla Dizon | 105 | calamba | 2026-12-20 | 5 | cancelled | 1000 / 300 | unpaid |
| Carla Dizon | 106 | cabuyao | 2026-12-05 | 3 | pending | 600 / 180 | proof_submitted |
| Dan Lopez | - | - | - | - | (no reservations) | - | - |


---

## Part A - DML (Data Manipulation Language): INSERT, UPDATE, DELETE

DML statements **change the data** inside the tables. They are the SQL behind every button that saves something in the website.

### 1. INSERT - add a new customer

**Tables involved:** `users`  
**Café situation:** A visitor signs up on `signup.html`. PHP (`register_customer()` in `includes/auth.php`) runs this kind of INSERT.

```sql
INSERT INTO users (full_name, email, phone, password_hash, role)
VALUES ('Ella Cruz', 'ella.cruz@example.com', '09171110005', 'sample-hash-not-real', 'customer');
```

**What it does:** Adds ONE new row to `users`. We do not give `id` (AUTO_INCREMENT makes the next number) or `created_at` (it defaults to the current time). Real passwords are never typed in SQL: PHP stores only the result of `password_hash()`.

**Check that it worked:**

```sql
SELECT id, full_name, email, role FROM users WHERE email = 'ella.cruz@example.com';
```

| id | full_name | email | role |
|---|---|---|---|
| 7 | Ella Cruz | ella.cruz@example.com | customer |

### 2. INSERT - add a reservation for that customer

**Tables involved:** `reservations`, `users`  
**Café situation:** Ella books a table. We find her `id` with a small sub-query so the foreign key `user_id` is correct.

```sql
INSERT INTO reservations (user_id, branch, reservation_date, reservation_time, guests, occasion, status)
VALUES ((SELECT id FROM users WHERE email = 'ella.cruz@example.com'),
        'calamba', '2026-12-24', '17:00:00', 4, 'Christmas Eve dinner', 'pending');
```

**What it does:** Adds a row to `reservations`. `user_id` must match an existing `users.id` (foreign key), otherwise MySQL refuses the INSERT. New reservations always start as `pending`.

**Check that it worked:**

```sql
SELECT id, user_id, branch, reservation_date, guests, status FROM reservations WHERE occasion = 'Christmas Eve dinner';
```

| id | user_id | branch | reservation_date | guests | status |
|---|---|---|---|---|---|
| 107 | 7 | calamba | 2026-12-24 | 4 | pending |

### 3. INSERT - add the payment of that reservation

**Tables involved:** `payments`, `reservations`  
**Café situation:** Every reservation gets exactly one GCash payment row, created in the same step by `insert_payment()` in `includes/payments.php`.

```sql
INSERT INTO payments (reservation_id, payment_option, total_amount, amount_due, reference_number, status)
VALUES ((SELECT id FROM reservations WHERE occasion = 'Christmas Eve dinner'),
        'downpayment', 800.00, 240.00, '7234567890123', 'proof_submitted');
```

**What it does:** Adds a row to `payments`. `method` is left out because its default is `gcash`. The CHECK rule requires `amount_due` to be between 0 and `total_amount` (240 <= 800, so it passes).

**Check that it worked:**

```sql
SELECT id, reservation_id, payment_option, total_amount, amount_due, status FROM payments WHERE reference_number = '7234567890123';
```

| id | reservation_id | payment_option | total_amount | amount_due | status |
|---|---|---|---|---|---|
| 7 | 107 | downpayment | 800 | 240 | proof_submitted |

### 4. UPDATE - staff verifies a payment

**Tables involved:** `payments`  
**Café situation:** Staff checks the GCash screenshot and clicks **Verify payment** (`staff_verify_payment()` in `includes/payments.php`).

```sql
UPDATE payments
SET status = 'verified', verified_by = 1, verified_at = NOW()
WHERE reservation_id = 101 AND status = 'proof_submitted';
```

**What it does:** Changes ONE existing row. `WHERE` limits it to the payment of reservation 101, and only if it is still `proof_submitted`. `verified_by = 1` stores which staff member (a `users.id`) did the check. Without `WHERE`, EVERY payment would be changed.

**Check that it worked:**

```sql
SELECT reservation_id, status, verified_by, verified_at FROM payments WHERE reservation_id = 101;
```

| reservation_id | status | verified_by | verified_at |
|---|---|---|---|
| 101 | verified | 1 | 2026-11-10 09:00:00 |

### 5. UPDATE - staff confirms a reservation

**Tables involved:** `reservations`  
**Café situation:** After the payment is verified, staff clicks **Confirm** (`staff_set_status()` in `includes/reservations.php`).

```sql
UPDATE reservations
SET status = 'confirmed', updated_at = NOW()
WHERE id = 101 AND status = 'pending';
```

**What it does:** Moves reservation 101 from `pending` to `confirmed`. The extra `AND status = 'pending'` makes sure it only changes if nobody else changed it first.

**Check that it worked:**

```sql
SELECT id, status, updated_at FROM reservations WHERE id = 101;
```

| id | status | updated_at |
|---|---|---|
| 101 | confirmed | 2026-11-10 09:00:00 |

### 6. UPDATE - staff writes a private note

**Tables involved:** `reservations`  
**Café situation:** Staff adds a note in the detail window. Customers never see `admin_notes`.

```sql
UPDATE reservations
SET admin_notes = 'Customer asked for a window seat.'
WHERE id = 102;
```

**What it does:** Changes one column of one row. Other columns keep their old values.

**Check that it worked:**

```sql
SELECT id, admin_notes FROM reservations WHERE id = 102;
```

| id | admin_notes |
|---|---|
| 102 | Customer asked for a window seat. |

### 7. UPDATE - archive a record (soft delete)

**Tables involved:** `reservations`  
**Café situation:** Instead of deleting, staff moves finished reservations to the Archive (`staff_archive_reservation()`).

```sql
UPDATE reservations
SET archived_at = NOW()
WHERE id = (SELECT x.id FROM (SELECT id FROM reservations WHERE occasion = 'Christmas Eve dinner') AS x)
  AND archived_at IS NULL;
```

**What it does:** Sets a date in `archived_at`, which means "archived". `NULL` means the reservation is still active. Nothing is lost, and **Restore** simply sets it back to `NULL`. (The sub-query is wrapped in `AS x` because MySQL does not allow an UPDATE to read the same table directly in a sub-query.)

**Check that it worked:**

```sql
SELECT id, occasion, archived_at FROM reservations WHERE archived_at IS NOT NULL;
```

| id | occasion | archived_at |
|---|---|---|
| 107 | Christmas Eve dinner | 2026-11-10 09:00:00 |

### 8. DELETE - permanently remove an ARCHIVED reservation

**Tables involved:** `payments`, `reservations`  
**Café situation:** The staff **Delete** button only works on archived records (`staff_delete_archived_reservation()`). The app deletes the payment first, then the reservation, inside one transaction.

```sql
DELETE FROM payments
WHERE reservation_id = (SELECT id FROM reservations WHERE occasion = 'Christmas Eve dinner');

DELETE FROM reservations
WHERE occasion = 'Christmas Eve dinner' AND archived_at IS NOT NULL;
```

**What it does:** The first statement removes the child row (payment); the second removes the parent row (reservation). The condition `archived_at IS NOT NULL` is the safety rule: an active reservation can never be deleted by this statement. **Always write a `WHERE`. `DELETE FROM reservations;` with no WHERE removes every reservation.** (Because `fk_pay_reservation` is `ON DELETE CASCADE`, deleting only the reservation would also remove its payment automatically.)

**Check that it worked:**

```sql
SELECT COUNT(*) AS christmas_rows FROM reservations WHERE occasion = 'Christmas Eve dinner';
```

| christmas_rows |
|---|
| 0 |

### 9. DELETE - what the foreign keys protect (this statement is REFUSED)

**Tables involved:** `users`, `reservations`  
**Café situation:** Someone tries to delete a customer who still has reservations.

```sql
DELETE FROM users WHERE id = 3;
```

**What it does:** MySQL refuses with a foreign-key error because `fk_res_user` is `ON DELETE RESTRICT` and Ana (id 3) still has reservations. This protects the history. The error is the *expected* result; nothing is deleted.

**Result:** refused with an error, as designed (`FOREIGN KEY constraint failed` in the SQLite test; MySQL words it differently, e.g. error 1062 duplicate entry, 3819 check constraint violated, or 1451 cannot delete a parent row).

---

## Part B - Constraint demonstrations (statements that are REFUSED on purpose)

These show that the rules written in the SQL file really work. Each one is expected to fail.

### 10. UNIQUE - duplicate email

**Tables involved:** `users`  
**Café situation:** Two accounts cannot share one email (`uq_users_email`).

```sql
INSERT INTO users (full_name, email, phone, password_hash)
VALUES ('Fake Ana', 'ana.reyes@example.com', '09170000000', 'x');
```

**What it does:** Fails with a duplicate-entry error because that email already exists. PHP catches this error in `register_customer()` and shows "An account with this email already exists".

**Result:** refused with an error, as designed (`UNIQUE constraint failed: users.email` in the SQLite test; MySQL words it differently, e.g. error 1062 duplicate entry, 3819 check constraint violated, or 1451 cannot delete a parent row).

### 11. UNIQUE - a second payment for one reservation

**Tables involved:** `payments`  
**Café situation:** One reservation can have only one payment (`uq_pay_reservation`).

```sql
INSERT INTO payments (reservation_id, payment_option, total_amount, amount_due)
VALUES (102, 'full', 100.00, 100.00);
```

**What it does:** Fails: reservation 102 already has a payment row. This is what makes the relationship **1:1**.

**Result:** refused with an error, as designed (`UNIQUE constraint failed: payments.reservation_id` in the SQLite test; MySQL words it differently, e.g. error 1062 duplicate entry, 3819 check constraint violated, or 1451 cannot delete a parent row).

### 12. CHECK - amount_due larger than the total

**Tables involved:** `payments`  
**Café situation:** The rule `chk_pay_amounts` stops impossible amounts.

```sql
INSERT INTO payments (reservation_id, payment_option, total_amount, amount_due)
VALUES (106, 'full', 100.00, 500.00);
```

**What it does:** Fails the CHECK because `amount_due` (500) is more than `total_amount` (100). (It would also hit the UNIQUE rule, since 106 already has a payment.) CHECK is enforced only by MySQL 8.0.16+ / MariaDB 10.2+.

**Result:** refused with an error, as designed (`CHECK constraint failed: chk_pay_amounts` in the SQLite test; MySQL words it differently, e.g. error 1062 duplicate entry, 3819 check constraint violated, or 1451 cannot delete a parent row).

---

## Part C - DQL (Data Query Language): SELECT operations

DQL statements only **read** data; they never change it. All results below come from the sample data in the "Setup" section (including the changes made in Part A).

### 13. SELECT - choose columns

**Tables involved:** `users`  
**Café situation:** Show the name and email of everyone in the system.

```sql
SELECT full_name, email, role FROM users;
```

**What it does:** `SELECT` lists the columns you want, `FROM` names the table. `SELECT *` would give every column, but naming columns is clearer (and never shows `password_hash` by accident).

**Sample result:**

| full_name | email | role |
|---|---|---|
| Demo Staff | staff@example.com | staff |
| Demo Staff Two | staff2@example.com | staff |
| Ana Reyes | ana.reyes@example.com | customer |
| Ben Santos | ben.santos@example.com | customer |
| Carla Dizon | carla.dizon@example.com | customer |
| Dan Lopez | dan.lopez@example.com | customer |
| Ella Cruz | ella.cruz@example.com | customer |

### 14. WHERE with = (comparison)

**Tables involved:** `reservations`  
**Café situation:** Staff opens the list of pending requests.

```sql
SELECT id, branch, reservation_date, guests, status FROM reservations WHERE status = 'pending';
```

**What it does:** `WHERE` keeps only the rows where the condition is true. `=` means "equal to".

**Sample result:**

| id | branch | reservation_date | guests | status |
|---|---|---|---|---|
| 106 | cabuyao | 2026-12-05 | 3 | pending |

### 15. Comparison operators: >, >=, <, <=, <>

**Tables involved:** `reservations`  
**Café situation:** Find big groups that need extra tables.

```sql
SELECT id, guests FROM reservations WHERE guests >= 5;       -- 5 or more
SELECT id, guests FROM reservations WHERE guests < 3;        -- fewer than 3
SELECT id, status FROM reservations WHERE status <> 'cancelled';  -- anything except cancelled
```

**What it does:** `>` greater, `>=` greater or equal, `<` less, `<=` less or equal, `<>` (or `!=`) not equal. They work on numbers, dates and text. The three statements are run one after another; the sample result below is for the first one.

**Sample result:**

| id | guests |
|---|---|
| 102 | 6 |
| 104 | 8 |
| 105 | 5 |

### 16. WHERE with BETWEEN, IN and IS NULL

**Tables involved:** `reservations`  
**Café situation:** Reservations in a date range, in two branches, and without an occasion.

```sql
SELECT id, reservation_date FROM reservations
WHERE reservation_date BETWEEN '2026-12-01' AND '2026-12-15';

SELECT id, branch FROM reservations WHERE branch IN ('calamba', 'cabuyao');

SELECT id FROM reservations WHERE occasion IS NULL;
```

**What it does:** `BETWEEN a AND b` includes both ends. `IN (...)` matches any value in the list. `IS NULL` finds empty values. **Never write `= NULL`; it never matches.** Sample result is for the first statement.

**Sample result:**

| id | reservation_date |
|---|---|
| 101 | 2026-12-05 |
| 102 | 2026-12-12 |
| 103 | 2026-12-05 |
| 106 | 2026-12-05 |

### 17. Logical operators: AND, OR, NOT

**Tables involved:** `reservations`  
**Café situation:** Pending OR confirmed reservations at Calamba, for 4 or more guests.

```sql
SELECT id, branch, guests, status
FROM reservations
WHERE branch = 'calamba'
  AND (status = 'pending' OR status = 'confirmed')
  AND NOT guests < 4;
```

**What it does:** `AND` needs both conditions true, `OR` needs at least one, `NOT` flips a condition. The brackets matter: they decide that `OR` is checked before the other `AND`s.

**Sample result:**

| id | branch | guests | status |
|---|---|---|---|
| 101 | calamba | 4 | confirmed |

### 18. COUNT

**Tables involved:** `reservations`  
**Café situation:** How many reservations are in the system?

```sql
SELECT COUNT(*) AS total_reservations FROM reservations;
```

**What it does:** `COUNT(*)` counts rows. This same function is used in `staff_counts()` for the dashboard cards.

**Sample result:**

| total_reservations |
|---|
| 6 |

### 19. SUM

**Tables involved:** `payments`  
**Café situation:** How much money was verified so far?

```sql
SELECT SUM(amount_due) AS verified_money FROM payments WHERE status = 'verified';
```

**What it does:** `SUM` adds up the numbers of the chosen rows. Here only `verified` payments are added.

**Sample result:**

| verified_money |
|---|
| 3160 |

### 20. AVG

**Tables involved:** `reservations`  
**Café situation:** What is the average party size?

```sql
SELECT ROUND(AVG(guests), 2) AS average_guests FROM reservations;
```

**What it does:** `AVG` gives the average. `ROUND(x, 2)` keeps two decimals.

**Sample result:**

| average_guests |
|---|
| 4.67 |

### 21. MIN and MAX

**Tables involved:** `payments`  
**Café situation:** Smallest and biggest booking total.

```sql
SELECT MIN(total_amount) AS smallest_total, MAX(total_amount) AS biggest_total FROM payments;
```

**What it does:** `MIN` returns the lowest value and `MAX` the highest. They also work on dates (earliest/latest reservation).

**Sample result:**

| smallest_total | biggest_total |
|---|---|
| 400 | 1600 |

### 22. GROUP BY

**Tables involved:** `reservations`  
**Café situation:** How many reservations does each branch have?

```sql
SELECT branch, COUNT(*) AS reservations, SUM(guests) AS total_guests
FROM reservations
GROUP BY branch;
```

**What it does:** `GROUP BY` puts rows with the same `branch` into one group, then the aggregate functions work on each group. The app uses the same idea in `slot_availability()` (guests per time slot) and `staff_counts()` (reservations per status).

**Sample result:**

| branch | reservations | total_guests |
|---|---|---|
| cabuyao | 3 | 17 |
| calamba | 3 | 11 |

### 23. HAVING

**Tables involved:** `reservations`  
**Café situation:** Only statuses that have 2 or more reservations.

```sql
SELECT status, COUNT(*) AS reservations
FROM reservations
GROUP BY status
HAVING COUNT(*) >= 2;
```

**What it does:** `WHERE` filters rows *before* grouping; `HAVING` filters the *groups* after counting. You cannot use `COUNT(*)` inside `WHERE`.

**Sample result:**

| status | reservations |
|---|---|
| confirmed | 3 |

### 24. ORDER BY

**Tables involved:** `reservations`  
**Café situation:** Upcoming reservations, earliest first; biggest group first when the time is equal.

```sql
SELECT id, reservation_date, reservation_time, guests
FROM reservations
ORDER BY reservation_date ASC, reservation_time ASC, guests DESC;
```

**What it does:** `ORDER BY` sorts the result. `ASC` = small to big (default), `DESC` = big to small. The staff list in `staff_reservations()` sorts by date and time like this.

**Sample result:**

| id | reservation_date | reservation_time | guests |
|---|---|---|---|
| 104 | 2026-11-20 | 14:00:00 | 8 |
| 106 | 2026-12-05 | 12:00:00 | 3 |
| 101 | 2026-12-05 | 18:00:00 | 4 |
| 103 | 2026-12-05 | 18:00:00 | 2 |
| 102 | 2026-12-12 | 10:00:00 | 6 |
| 105 | 2026-12-20 | 19:00:00 | 5 |

### 25. INNER JOIN

**Tables involved:** `reservations`, `users`  
**Café situation:** Show each reservation with the customer's name (the name is NOT stored in `reservations`; it is looked up in `users`).

```sql
SELECT r.id, u.full_name, r.branch, r.reservation_date, r.status
FROM reservations AS r
INNER JOIN users AS u ON u.id = r.user_id
ORDER BY r.id;
```

**What it does:** `INNER JOIN` joins two tables by matching the foreign key `r.user_id` with the primary key `u.id`. Only rows that match on both sides appear. This is how the design avoids repeating customer names (3NF).

**Sample result:**

| id | full_name | branch | reservation_date | status |
|---|---|---|---|---|
| 101 | Ana Reyes | calamba | 2026-12-05 | confirmed |
| 102 | Ana Reyes | cabuyao | 2026-12-12 | confirmed |
| 103 | Ben Santos | calamba | 2026-12-05 | confirmed |
| 104 | Ben Santos | cabuyao | 2026-11-20 | completed |
| 105 | Carla Dizon | calamba | 2026-12-20 | cancelled |
| 106 | Carla Dizon | cabuyao | 2026-12-05 | pending |

### 26. INNER JOIN with three tables

**Tables involved:** `reservations`, `users`, `payments`  
**Café situation:** The reservation, the customer and the payment in one result.

```sql
SELECT r.id, u.full_name, p.payment_option, p.total_amount, p.amount_due,
       (p.total_amount - p.amount_due) AS balance, p.status AS payment_status
FROM reservations r
INNER JOIN users u    ON u.id = r.user_id
INNER JOIN payments p ON p.reservation_id = r.id
ORDER BY r.id;
```

**What it does:** Joins are chained. `balance` is **calculated** here because it is not stored in any table (that is why the design is 3NF).

**Sample result:**

| id | full_name | payment_option | total_amount | amount_due | balance | payment_status |
|---|---|---|---|---|---|---|
| 101 | Ana Reyes | downpayment | 800 | 240 | 560 | verified |
| 102 | Ana Reyes | full | 1200 | 1200 | 0 | verified |
| 103 | Ben Santos | downpayment | 400 | 120 | 280 | verified |
| 104 | Ben Santos | full | 1600 | 1600 | 0 | verified |
| 105 | Carla Dizon | downpayment | 1000 | 300 | 700 | unpaid |
| 106 | Carla Dizon | downpayment | 600 | 180 | 420 | proof_submitted |

### 27. LEFT JOIN

**Tables involved:** `users`, `reservations`  
**Café situation:** Which customers have never made a reservation?

```sql
SELECT u.id, u.full_name, COUNT(r.id) AS reservations
FROM users u
LEFT JOIN reservations r ON r.user_id = u.id
WHERE u.role = 'customer'
GROUP BY u.id, u.full_name
ORDER BY reservations ASC, u.id;
```

**What it does:** `LEFT JOIN` keeps **every row of the left table** (`users`) even when there is no match on the right; the missing side becomes NULL (so `COUNT(r.id)` is 0). Dan Lopez has no reservation but still appears (so does Ella Cruz, whose only reservation was deleted in example 8). An INNER JOIN would hide both. The real dashboard query in `RES_SELECT` uses `LEFT JOIN payments` too.

**Sample result:**

| id | full_name | reservations |
|---|---|---|
| 6 | Dan Lopez | 0 |
| 7 | Ella Cruz | 0 |
| 3 | Ana Reyes | 2 |
| 4 | Ben Santos | 2 |
| 5 | Carla Dizon | 2 |

### 28. RIGHT JOIN

**Tables involved:** `payments`, `users`  
**Café situation:** How many payments did each staff member verify? (Include staff who verified none.)

```sql
SELECT u.id, u.full_name, COUNT(p.id) AS payments_verified
FROM payments p
RIGHT JOIN users u ON u.id = p.verified_by
WHERE u.role = 'staff'
GROUP BY u.id, u.full_name
ORDER BY u.id;
```

**What it does:** `RIGHT JOIN` is the mirror of LEFT JOIN: it keeps **every row of the right table** (`users`). It uses the second foreign key, `payments.verified_by -> users.id`. (`users u LEFT JOIN payments p ON ...` gives the same result; RIGHT JOIN is just written the other way round.) The application itself does not use RIGHT JOIN; this is a practice query.

**Sample result:**

| id | full_name | payments_verified |
|---|---|---|
| 1 | Demo Staff | 3 |
| 2 | Demo Staff Two | 1 |

### 29. Column and table aliases

**Tables involved:** `reservations`, `users`  
**Café situation:** Make the output headings friendly and the query shorter.

```sql
SELECT r.id                      AS reservation_no,
       u.full_name               AS customer,
       r.guests                  AS party_size,
       CONCAT('SC-', LPAD(r.id, 5, '0')) AS reference
FROM reservations AS r
INNER JOIN users    AS u ON u.id = r.user_id
WHERE r.id <= 103;
```

**What it does:** `AS` after a column renames the heading in the result (a **column alias**). `AS` after a table gives it a short name such as `r` or `u` (a **table alias**), so we can write `r.id` instead of `reservations.id`. The reference `SC-00101` is built like PHP's `reference_for()`.

**Sample result:**

| reservation_no | customer | party_size | reference |
|---|---|---|---|
| 101 | Ana Reyes | 4 | SC-00101 |
| 102 | Ana Reyes | 6 | SC-00102 |
| 103 | Ben Santos | 2 | SC-00103 |

### 30. LIMIT

**Tables involved:** `reservations`  
**Café situation:** Show the 3 most recently scheduled reservations (first page), then the next 3 (second page).

```sql
SELECT id, reservation_date, status
FROM reservations
ORDER BY reservation_date DESC, id DESC
LIMIT 3;

SELECT id, reservation_date, status
FROM reservations
ORDER BY reservation_date DESC, id DESC
LIMIT 3 OFFSET 3;
```

**What it does:** `LIMIT n` returns at most `n` rows; `OFFSET` skips rows first (useful for pages). Always combine LIMIT with `ORDER BY`, otherwise "first 3" has no meaning. The staff list uses `LIMIT 300`. Sample result is for the first statement.

**Sample result:**

| id | reservation_date | status |
|---|---|---|
| 105 | 2026-12-20 | cancelled |
| 102 | 2026-12-12 | confirmed |
| 106 | 2026-12-05 | pending |

### 31. LIKE

**Tables involved:** `users`, `reservations`  
**Café situation:** Search box on the staff page: customers whose name starts with "A" or whose email contains "santos"; reservations with "day" in the occasion.

```sql
SELECT id, full_name, email FROM users WHERE full_name LIKE 'A%';

SELECT id, full_name, email FROM users WHERE email LIKE '%santos%';

SELECT id, occasion FROM reservations WHERE occasion LIKE '%day%';
```

**What it does:** `LIKE` searches text with wildcards: `%` = any number of characters, `_` = exactly one character. `'A%'` starts with A, `'%santos%'` contains santos. `staff_reservations()` builds the same kind of `LIKE ?` condition (on name, phone and email) from the search box. Sample result is for the second statement.

**Sample result:**

| id | full_name | email |
|---|---|---|
| 4 | Ben Santos | ben.santos@example.com |

### 32. Putting it together: the dashboard query of the website

**Tables involved:** `reservations`, `users`, `payments`  
**Café situation:** The real query `RES_SELECT` in `includes/reservations.php` (shortened to a few columns), with a filter and sort like the staff page.

```sql
SELECT r.id, c.full_name AS customer_name, r.branch, r.status,
       p.status AS payment_status, p.total_amount, p.amount_due,
       (p.total_amount - p.amount_due) AS balance,
       v.full_name AS verified_by_name
FROM reservations r
JOIN users c         ON c.id = r.user_id
LEFT JOIN payments p ON p.reservation_id = r.id
LEFT JOIN users v    ON v.id = p.verified_by
WHERE r.archived_at IS NULL AND r.status IN ('pending', 'confirmed')
ORDER BY r.reservation_date ASC, r.reservation_time ASC
LIMIT 300;
```

**What it does:** `users` is used **twice** with two aliases: `c` = the customer who booked and `v` = the staff who verified the payment. This one query combines JOIN, LEFT JOIN, WHERE, IN, aliases, a calculated column, ORDER BY and LIMIT.

**Sample result:**

| id | customer_name | branch | status | payment_status | total_amount | amount_due | balance | verified_by_name |
|---|---|---|---|---|---|---|---|---|
| 106 | Carla Dizon | cabuyao | pending | proof_submitted | 600 | 180 | 420 | NULL |
| 101 | Ana Reyes | calamba | confirmed | verified | 800 | 240 | 560 | Demo Staff |
| 103 | Ben Santos | calamba | confirmed | verified | 400 | 120 | 280 | Demo Staff Two |
| 102 | Ana Reyes | cabuyao | confirmed | verified | 1200 | 1200 | 0 | Demo Staff |

---

## Quick reference: what the professor asked for and where it is

| Requirement | Examples in this guide |
|---|---|
| INSERT | 1, 2, 3 (and the Setup block) |
| UPDATE | 4, 5, 6, 7 |
| DELETE | 8, 9 |
| SELECT / WHERE | 13, 14, 15, 16, 17 |
| Comparison operators | 14, 15, 16 (`=`, `>`, `>=`, `<`, `<>`, `BETWEEN`, `IN`, `IS NULL`) |
| Logical operators | 17 (`AND`, `OR`, `NOT`) |
| COUNT, SUM, AVG, MIN, MAX | 18, 19, 20, 21 |
| GROUP BY | 22 |
| HAVING | 23 |
| ORDER BY | 24 |
| INNER JOIN | 25, 26 |
| LEFT JOIN | 27 |
| RIGHT JOIN | 28 |
| Column and table aliases | 29 (also used in most other examples) |
| LIMIT | 30 |
| LIKE | 31 |
| Constraints (extra) | 9, 10, 11, 12 |
| Everything combined | 32 |

## Safety rules to remember

1. **Always check the `WHERE` before pressing Go** on UPDATE and DELETE. Try the same `WHERE` in a `SELECT` first.
2. Use the **primary key** (`id`) in the `WHERE` whenever you want to change exactly one row.
3. Delete **child before parent** (payments before reservations), or let `ON DELETE CASCADE` do it.
4. Do the destructive examples only on the sample rows (ids 101-107), never on real customer data.
5. Real passwords are never inserted as text; only hashes made by PHP's `password_hash()`.
