-- =====================================================================
-- THE SIFTED CAFE & BAKERY - the ONE and ONLY database setup file.
--
-- HOW TO USE: phpMyAdmin > Import tab > Choose file > Import.
-- It creates the database thesifted_db with exactly THREE tables
-- (users, reservations, payments) and one demo STAFF account.
--
-- FRESH INSTALL ONLY. If you still have the OLD database (with audit_logs
-- and an owner account), drop it first in phpMyAdmin:  DROP DATABASE thesifted_db;
--
-- THE 3 TABLES AND HOW THEY CONNECT
--   users (1) ----< (many) reservations (1) ---- (1) payments
--   One user (customer) can make many reservations.
--   One reservation has exactly one payment.
--   Staff are also rows in users (role = 'staff'); they verify payments.
--
-- NORMALIZATION (up to 3NF)
--   1NF: every column holds one single value; every table has a primary key.
--   2NF: every primary key is ONE column, so no column depends on "part" of a key.
--   3NF: no column depends on another non-key column. That is why:
--        - the customer's name / phone / email are ONLY in users (a reservation
--          just points to the customer with user_id);
--        - the balance is NOT stored (balance = total_amount - amount_due,
--          it is calculated in the SELECT query).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS thesifted_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE thesifted_db;

-- ---------------------------------------------------------------------
-- TABLE 1: users  (customers and staff)
-- role: 'customer' (default, every sign-up) or 'staff'.
-- Passwords are NEVER stored as text, only as a hash (password_hash in PHP).
-- PRIMARY KEY (id) : unique number for every user.
-- UNIQUE (email)   : no two accounts with the same email.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name     VARCHAR(100) NOT NULL,
  email         VARCHAR(150) NOT NULL,
  phone         VARCHAR(30)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('customer','staff') NOT NULL DEFAULT 'customer',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- TABLE 2: reservations  (one row per table request)
-- FOREIGN KEY user_id -> users.id : every reservation belongs to a real customer.
-- status:  pending -> confirmed -> completed, or cancelled.
-- archived_at: NULL = active; a date = moved to the staff Archive (nothing is deleted).
-- admin_notes: staff notes (customers never see them).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reservations (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,
  branch           ENUM('calamba','cabuyao') NOT NULL,
  reservation_date DATE NOT NULL,
  reservation_time TIME NOT NULL,
  guests           TINYINT UNSIGNED NOT NULL,
  occasion         VARCHAR(100) NULL,
  special_requests VARCHAR(500) NULL,
  status           ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'pending',
  admin_notes      VARCHAR(500) NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at      DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_res_user (user_id),
  KEY idx_res_slot (branch, reservation_date, reservation_time),
  KEY idx_res_status (status),
  CONSTRAINT chk_res_guests CHECK (guests >= 1),
  CONSTRAINT fk_res_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- TABLE 3: payments  (exactly one GCash payment per reservation)
-- FOREIGN KEY reservation_id -> reservations.id (UNIQUE = at most one payment per reservation).
-- FOREIGN KEY verified_by    -> users.id : the staff member who checked the payment.
-- status:  unpaid -> proof_submitted (screenshot uploaded) -> verified (staff checked it).
-- The screenshot is saved as a file in storage/proofs/; the database keeps only the file name.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_id      INT UNSIGNED NOT NULL,
  method              ENUM('gcash') NOT NULL DEFAULT 'gcash',
  payment_option      ENUM('downpayment','full') NOT NULL,
  total_amount        DECIMAL(10,2) NOT NULL,
  amount_due          DECIMAL(10,2) NOT NULL,
  reference_number    VARCHAR(20) NULL,
  proof_original_name VARCHAR(150) NULL,
  proof_file          VARCHAR(80)  NULL,
  status              ENUM('unpaid','proof_submitted','verified') NOT NULL DEFAULT 'unpaid',
  verified_by         INT UNSIGNED NULL,
  verified_at         DATETIME NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pay_reservation (reservation_id),
  KEY idx_pay_status (status),
  CONSTRAINT chk_pay_amounts CHECK (total_amount >= 0 AND amount_due >= 0 AND amount_due <= total_amount),
  CONSTRAINT fk_pay_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_pay_verifier FOREIGN KEY (verified_by) REFERENCES users (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- DEMO STAFF ACCOUNT (school demonstration only - change it before real use)
--   email: staff@example.com   password: Staff123!   -> staff.php
-- Only the HASH is stored. INSERT IGNORE + UNIQUE email = importing again
-- never creates a duplicate and never resets the password.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO users (full_name, email, phone, password_hash, role)
VALUES ('Demo Staff', 'staff@example.com', '09000000001',
        '$2y$10$GtcQIZUSOhevWhsfPZokBOqU8pQFf7gQV/k24JB0iU2giRYz4aNG2', 'staff');
