<?php
/*
 * RESERVATION LOGIC: checking the form, working out the amounts, saving a reservation,
 * listing reservations (customer + staff) and changing them (status, notes, archive, delete).
 * Payment code is in includes/payments.php.
 *
 * Reservation status: pending -> confirmed -> completed, or cancelled.
 */

// ---------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------

function branch_name($branch)
{
    $branches = cfg('branches');
    if (isset($branches[$branch])) {
        return $branches[$branch]['name'];
    }
    return $branch;
}

/** Reservation number shown to people, e.g. id 7 -> SC-00007. */
function reference_for($id)
{
    return 'SC-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

/** Max total guests per time slot for one branch (set in config/app.php). */
function branch_capacity($branch)
{
    $branches = cfg('branches');
    if (isset($branches[$branch])) {
        return (int) $branches[$branch]['capacity'];
    }
    return 0;
}

/** Largest party for one online reservation = the branch capacity. Unknown branch: the largest capacity. */
function branch_max_guests($branch)
{
    $branches = cfg('branches');
    if (isset($branches[$branch])) {
        return branch_capacity($branch);
    }
    $max = 0;
    foreach ($branches as $b) {
        if ($b['capacity'] > $max) {
            $max = $b['capacity'];
        }
    }
    return (int) $max;
}

/** Amounts are always worked out HERE. The browser never sends amounts we trust. */
function compute_amounts($guests)
{
    $fee = (int) cfg('reservation_fee');
    $perGuest = (int) cfg('minimum_spend_per_guest');
    $percent = (int) cfg('downpayment_percent');

    $minimumSpend = $guests * $perGuest;
    $total = $fee + $minimumSpend;
    $deposit = (int) ceil($total * $percent / 100);
    if ($deposit > $total) {
        $deposit = $total;
    }
    return [
        'guests' => $guests,
        'reservation_fee' => $fee,
        'minimum_spend_per_guest' => $perGuest,
        'minimum_spend' => $minimumSpend,
        'total_amount' => $total,
        'downpayment_percent' => $percent,
        'duration_hours' => (int) cfg('duration_hours'),
        'options' => [
            'downpayment' => ['amount_due' => $deposit, 'balance' => $total - $deposit],
            'full'        => ['amount_due' => $total,   'balance' => 0],
        ],
    ];
}

// ---------------------------------------------------------------------
// Validation (the server always checks, even if the browser already did)
// ---------------------------------------------------------------------

/** Checks the reservation fields. Returns ['values' => clean values, 'errors' => problems]. */
function validate_reservation($d)
{
    $errors = [];
    $branches = cfg('branches');

    $v = [];
    $v['customer_name']    = clean($d['customer_name'] ?? '', 100);
    $v['phone']            = clean($d['phone'] ?? '', 30);
    $v['email']            = strtolower(clean($d['email'] ?? '', 150));
    $v['branch']           = (string) ($d['branch'] ?? '');
    $v['reservation_date'] = (string) ($d['reservation_date'] ?? '');
    $v['reservation_time'] = substr((string) ($d['reservation_time'] ?? ''), 0, 5);
    $v['guests']           = $d['guests'] ?? '';
    $v['occasion']         = clean($d['occasion'] ?? '', 100, true);
    $v['special_requests'] = clean($d['special_requests'] ?? '', 500, true);

    if (mb_strlen($v['customer_name']) < 2) {
        $errors['customer_name'] = 'Please enter your name.';
    }
    $digits = preg_replace('/\D/', '', $v['phone']);
    if (!preg_match('/^\+?[0-9\s\-()]+$/', $v['phone']) || strlen($digits) < 7 || strlen($digits) > 15) {
        $errors['phone'] = 'Contact number must have 7 to 15 digits.';
    }
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }
    if (!isset($branches[$v['branch']])) {
        $errors['branch'] = 'Please choose a branch.';
    }

    // Guests: a whole number between the minimum and the branch capacity.
    $minGuests = cfg('min_guests');
    $maxGuests = branch_max_guests($v['branch']);
    if (!is_numeric($v['guests']) || (int) $v['guests'] != $v['guests'] || (int) $v['guests'] < $minGuests || (int) $v['guests'] > $maxGuests) {
        $errors['guests'] = 'Online reservations are for ' . $minGuests . ' to ' . $maxGuests . ' guests. For larger groups, please call or message the cafe.';
    } else {
        $v['guests'] = (int) $v['guests'];
    }

    // Date: a real date, with enough notice, not too far ahead.
    $date = DateTime::createFromFormat('!Y-m-d', $v['reservation_date']);
    if (!$date || $date->format('Y-m-d') !== $v['reservation_date']) {
        $errors['reservation_date'] = 'Please choose a date.';
    } else {
        $today = new DateTime('today');
        $days = (int) $today->diff($date)->format('%r%a');
        if ($days < cfg('min_days_ahead')) {
            $errors['reservation_date'] = 'We need at least ' . cfg('min_days_ahead') . ' day notice. Please choose tomorrow or a later date.';
        } elseif ($days > cfg('max_days_ahead')) {
            $errors['reservation_date'] = 'Please choose a date within the next ' . cfg('max_days_ahead') . ' days.';
        }
    }

    // Time: a full hour inside the branch's opening hours.
    if (!isset($errors['branch'])) {
        $hour = (int) substr($v['reservation_time'], 0, 2);
        $goodFormat = preg_match('/^\d{2}:00$/', $v['reservation_time']);
        if (!$goodFormat || $hour < $branches[$v['branch']]['first_hour'] || $hour > $branches[$v['branch']]['last_hour']) {
            $errors['reservation_time'] = 'Please pick one of the listed times for this branch (every hour while open).';
        }
    }
    return ['values' => $v, 'errors' => $errors];
}

// ---------------------------------------------------------------------
// Create (INSERT)
// ---------------------------------------------------------------------

/** Guests already booked in a time slot (pending + confirmed reservations count). */
function slot_guest_total($pdo, $branch, $date, $time)
{
    $sql = "SELECT COALESCE(SUM(guests), 0) FROM reservations
            WHERE branch = ? AND reservation_date = ? AND reservation_time = ? AND status IN ('pending','confirmed')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$branch, $date, $time . ':00']);
    return (int) $stmt->fetchColumn();
}

/** Seats left for every hourly slot of a branch on a date. */
function slot_availability($branch, $date)
{
    $branches = cfg('branches');
    $b = $branches[$branch];
    $stmt = db()->prepare("SELECT reservation_time, SUM(guests) AS taken FROM reservations
        WHERE branch = ? AND reservation_date = ? AND status IN ('pending','confirmed') GROUP BY reservation_time");
    $stmt->execute([$branch, $date]);

    $taken = [];
    foreach ($stmt->fetchAll() as $row) {
        $taken[substr((string) $row['reservation_time'], 0, 5)] = (int) $row['taken'];
    }
    $slots = [];
    for ($hour = $b['first_hour']; $hour <= $b['last_hour']; $hour++) {
        $key = str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . ':00';
        $left = branch_capacity($branch);
        if (isset($taken[$key])) {
            $left = $left - $taken[$key];
        }
        if ($left < 0) {
            $left = 0;
        }
        $slots[$key] = $left;
    }
    return ['capacity' => branch_capacity($branch), 'seats_left' => $slots];
}

/**
 * Saves a reservation AND its payment together (one transaction: both or nothing).
 * Returns ['ok' => true, 'id' => new id] or ['ok' => false, 'errors' => [...]].
 */
function create_reservation($userId, $v, $pay, $proof)
{
    $pdo = db();

    // 1. Save the proof file first (if there is one).
    $storedName = null;
    if ($proof !== null) {
        $storedName = save_proof_file($proof);
        if ($storedName === '') {
            return ['ok' => false, 'errors' => ['payment_proof' => 'The proof of payment could not be saved. Please try again.']];
        }
    }

    try {
        $pdo->beginTransaction();

        // 2. Is there still room in this time slot?
        $used = slot_guest_total($pdo, $v['branch'], $v['reservation_date'], $v['reservation_time']);
        if ($used + $v['guests'] > branch_capacity($v['branch'])) {
            $pdo->rollBack();
            delete_proof_file($storedName);
            return ['ok' => false, 'errors' => ['reservation_time' => 'Sorry, that time is fully booked for this branch. Please choose another time or date.']];
        }

        // 3. Same customer, same branch/date/time, still open: almost certainly a double click.
        $stmt = $pdo->prepare("SELECT id FROM reservations
            WHERE user_id = ? AND branch = ? AND reservation_date = ? AND reservation_time = ? AND status IN ('pending','confirmed')");
        $stmt->execute([$userId, $v['branch'], $v['reservation_date'], $v['reservation_time'] . ':00']);
        if ($stmt->fetch()) {
            $pdo->rollBack();
            delete_proof_file($storedName);
            return ['ok' => false, 'errors' => ['reservation_time' => 'You already have an open request for this branch, date and time. Check My account before sending another one.']];
        }

        // 4. INSERT the reservation. Name/phone/email are NOT saved here: they live in users (3NF).
        $now = now_text();
        $stmt = $pdo->prepare('INSERT INTO reservations
            (user_id, branch, reservation_date, reservation_time, guests, occasion, special_requests, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $v['branch'], $v['reservation_date'], $v['reservation_time'] . ':00',
            $v['guests'], $v['occasion'], $v['special_requests'], 'pending', $now, $now]);
        $reservationId = (int) $pdo->lastInsertId();

        // 5. INSERT the payment row (see payments.php).
        insert_payment($pdo, $reservationId, $v['guests'], $pay, $proof, $storedName);

        $pdo->commit();
        return ['ok' => true, 'id' => $reservationId];
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        delete_proof_file($storedName);
        throw $ex;   // bootstrap.php logs it and shows a safe message
    }
}

// ---------------------------------------------------------------------
// Read (SELECT)
// ---------------------------------------------------------------------

// One SELECT used by the customer and staff lists.
// c = the customer (users), v = the staff member who verified the payment (users).
// balance is NOT stored (3NF): it is worked out here as total - amount paid now.
const RES_SELECT = "SELECT r.*, c.full_name AS customer_name, c.phone, c.email,
        p.payment_option, p.method AS payment_method, p.total_amount, p.amount_due, (p.total_amount - p.amount_due) AS balance,
        p.reference_number AS payment_reference, p.proof_original_name AS proof_name, p.proof_file, p.status AS payment_status,
        p.verified_at, v.full_name AS verified_by_name
    FROM reservations r
    JOIN users c ON c.id = r.user_id
    LEFT JOIN payments p ON p.reservation_id = r.id
    LEFT JOIN users v ON v.id = p.verified_by";

/** Adds the fields the pages expect to one database row. Staff get extra fields. */
function shape_reservation($r, $forStaff = false)
{
    $r['id'] = (int) $r['id'];
    $r['guests'] = (int) $r['guests'];
    $r['reference'] = reference_for($r['id']);
    $r['branch_name'] = branch_name($r['branch']);
    $r['reservation_time'] = substr((string) $r['reservation_time'], 0, 5);

    // Amounts: a reservation with no payment row counts as 0.
    foreach (['total_amount', 'amount_due', 'balance'] as $key) {
        if ($r[$key] === null) {
            $r[$key] = 0;
        } else {
            $r[$key] = $r[$key] + 0;
        }
    }
    if ($r['payment_method'] === null) {
        $r['payment_method'] = 'gcash';
    }
    if ($r['payment_status'] === null) {
        $r['payment_status'] = 'unpaid';
    }
    $r['has_proof'] = !empty($r['proof_file']);

    // Tells the customer page whether the Cancel button may be shown (the server checks again on cancel).
    $r['can_cancel'] = customer_can_cancel($r['status'], $r['reservation_date'], $r['reservation_time']);
    $r['cancel_late'] = $r['can_cancel'] && cancel_is_late($r['reservation_date'], $r['reservation_time']);
    $r['cancel_deadline'] = cancel_deadline($r['reservation_date'], $r['reservation_time']);

    // Customers must not receive staff-only data.
    if (!$forStaff) {
        unset($r['admin_notes'], $r['user_id'], $r['archived_at'], $r['proof_file'], $r['verified_by_name'], $r['verified_at']);
    }
    return $r;
}

/** Reservation history of ONE customer (WHERE user_id = ? so nobody sees other people's). */
function my_reservations($userId)
{
    $stmt = db()->prepare(RES_SELECT . ' WHERE r.user_id = ? ORDER BY r.reservation_date DESC, r.reservation_time DESC');
    $stmt->execute([$userId]);

    $today = date('Y-m-d');
    $list = [];
    foreach ($stmt->fetchAll() as $row) {
        $item = shape_reservation($row, false);
        $isOpen = ($item['status'] === 'pending' || $item['status'] === 'confirmed');
        if ($isOpen && $item['reservation_date'] >= $today) {
            $item['phase'] = 'upcoming';
        } else {
            $item['phase'] = 'history';
        }
        $list[] = $item;
    }
    return $list;
}

/** Staff list with filters. $archived = false: active list. $archived = true: archived only. */
function staff_reservations($filters, $archived = false)
{
    $where = [];
    $args = [];

    if ($archived) {
        $where[] = 'r.archived_at IS NOT NULL';
    } else {
        $where[] = 'r.archived_at IS NULL';
    }

    $status = $filters['status'] ?? '';
    if (in_array($status, ['pending', 'confirmed', 'cancelled', 'completed'])) {
        $where[] = 'r.status = ?';
        $args[] = $status;
    }
    $branch = $filters['branch'] ?? '';
    if (isset(cfg('branches')[$branch])) {
        $where[] = 'r.branch = ?';
        $args[] = $branch;
    }
    $date = (string) ($filters['date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $where[] = 'r.reservation_date = ?';
        $args[] = $date;
    }
    $payment = $filters['payment'] ?? '';
    if (in_array($payment, ['unpaid', 'proof_submitted', 'verified'])) {
        $where[] = 'p.status = ?';
        $args[] = $payment;
    }
    // Search box: customer name, phone or email (LIKE). '!' is the escape character.
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        $where[] = "(c.full_name LIKE ? ESCAPE '!' OR c.phone LIKE ? ESCAPE '!' OR c.email LIKE ? ESCAPE '!')";
        $args[] = $like;
        $args[] = $like;
        $args[] = $like;
    }

    $sql = RES_SELECT . ' WHERE ' . implode(' AND ', $where);
    if ($archived) {
        $sql .= ' ORDER BY r.archived_at DESC, r.id DESC LIMIT 300';
    } else {
        $sql .= ' ORDER BY r.reservation_date ASC, r.reservation_time ASC, r.id ASC LIMIT 300';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($args);

    $list = [];
    foreach ($stmt->fetchAll() as $row) {
        $list[] = shape_reservation($row, true);
    }
    return $list;
}

/** Numbers for the staff dashboard cards. */
function staff_counts()
{
    $counts = ['pending' => 0, 'confirmed' => 0, 'cancelled' => 0, 'completed' => 0, 'today' => 0, 'proofs_to_check' => 0, 'archived' => 0];

    $counts['archived'] = (int) db()->query('SELECT COUNT(*) FROM reservations WHERE archived_at IS NOT NULL')->fetchColumn();

    // Active reservations per status (archived ones are not counted).
    $rows = db()->query('SELECT status, COUNT(*) AS n FROM reservations WHERE archived_at IS NULL GROUP BY status')->fetchAll();
    foreach ($rows as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }

    $stmt = db()->prepare("SELECT COUNT(*) FROM reservations WHERE reservation_date = ? AND status IN ('pending','confirmed')");
    $stmt->execute([date('Y-m-d')]);
    $counts['today'] = (int) $stmt->fetchColumn();

    $sql = "SELECT COUNT(*) FROM payments p JOIN reservations r ON r.id = p.reservation_id
            WHERE p.status = 'proof_submitted' AND r.status IN ('pending','confirmed')";
    $counts['proofs_to_check'] = (int) db()->query($sql)->fetchColumn();
    return $counts;
}

// ---------------------------------------------------------------------
// Customer cancel (UPDATE own reservation)
// ---------------------------------------------------------------------

/** The moment a reservation starts, as a DateTime. */
function reservation_start($date, $time)
{
    return new DateTime($date . ' ' . substr($time, 0, 5) . ':00');
}

/** Last moment a customer may cancel for free: cancel_hours_before hours before the reservation. */
function cancel_deadline($date, $time)
{
    $start = reservation_start($date, $time);
    $start->modify('-' . (int) cfg('cancel_hours_before') . ' hours');
    return $start->format('Y-m-d H:i');
}

/** Can the customer still cancel? Status must be open and the reservation must not have started. */
function customer_can_cancel($status, $date, $time)
{
    if ($status !== 'pending' && $status !== 'confirmed') {
        return false;
    }
    return date('Y-m-d H:i') < reservation_start($date, $time)->format('Y-m-d H:i');
}

/** True when cancelling now is after the free-cancel deadline (the downpayment may be forfeited). */
function cancel_is_late($date, $time)
{
    return date('Y-m-d H:i') > cancel_deadline($date, $time);
}

/** A customer cancels THEIR OWN reservation. Returns '' on success or an error message. */
function customer_cancel_reservation($userId, $reservationId)
{
    // WHERE user_id = ? : a customer can only touch their own reservations.
    $stmt = db()->prepare('SELECT status, reservation_date, reservation_time, admin_notes FROM reservations WHERE id = ? AND user_id = ?');
    $stmt->execute([$reservationId, $userId]);
    $r = $stmt->fetch();

    if (!$r) {
        return 'That reservation was not found in your account.';
    }
    if ($r['status'] !== 'pending' && $r['status'] !== 'confirmed') {
        return 'This reservation is already ' . $r['status'] . ' and cannot be cancelled.';
    }
    if (!customer_can_cancel($r['status'], $r['reservation_date'], $r['reservation_time'])) {
        return 'This reservation has already started and can no longer be cancelled online. Please contact the cafe directly.';
    }

    $note = trim((string) $r['admin_notes'] . ' [Cancelled by customer ' . now_text() . ']');
    // "AND status IN (...)" keeps this safe even if staff changed the status a moment ago.
    $stmt = db()->prepare("UPDATE reservations SET status = 'cancelled', admin_notes = ?, updated_at = ?
                           WHERE id = ? AND user_id = ? AND status IN ('pending','confirmed')");
    $stmt->execute([mb_substr($note, -500), now_text(), $reservationId, $userId]);
    if ($stmt->rowCount() === 0) {
        return 'This reservation could not be cancelled. Please refresh the page.';
    }
    return '';
}

// ---------------------------------------------------------------------
// Staff changes (UPDATE / DELETE). Callers already checked role = staff.
// ---------------------------------------------------------------------

/**
 * Where a reservation can go next:
 *   pending   -> confirmed (only after the payment is verified) or cancelled
 *   confirmed -> completed or cancelled
 *   completed and cancelled are final.
 */
function allowed_next_statuses($status, $paymentStatus = '')
{
    if ($status === 'pending') {
        if ($paymentStatus === 'verified') {
            return ['confirmed', 'cancelled'];
        }
        return ['cancelled'];
    }
    if ($status === 'confirmed') {
        return ['completed', 'cancelled'];
    }
    return [];
}

/** Staff changes a reservation's status. Returns '' or an error message. */
function staff_set_status($reservationId, $newStatus)
{
    $stmt = db()->prepare('SELECT r.status, p.status AS pay_status
                           FROM reservations r LEFT JOIN payments p ON p.reservation_id = r.id
                           WHERE r.id = ?');
    $stmt->execute([$reservationId]);
    $row = $stmt->fetch();
    if (!$row) {
        return 'That reservation was not found.';
    }
    $current = $row['status'];

    if ($newStatus === 'confirmed' && $current === 'pending' && $row['pay_status'] !== 'verified') {
        return 'Verify the payment first. A reservation can only be confirmed after its payment is verified.';
    }
    if (!in_array($newStatus, allowed_next_statuses($current, (string) $row['pay_status']))) {
        return 'A ' . $current . ' reservation cannot be changed to ' . $newStatus . '.';
    }

    // "AND status = old" stops two staff members changing it at the same moment.
    $stmt = db()->prepare('UPDATE reservations SET status = ?, updated_at = ? WHERE id = ? AND status = ?');
    $stmt->execute([$newStatus, now_text(), $reservationId, $current]);
    if ($stmt->rowCount() === 0) {
        return 'This reservation was just changed by someone else. Please reload the page.';
    }
    return '';
}

/** Staff saves internal notes on a reservation. Returns '' or an error message. */
function staff_set_notes($reservationId, $notes)
{
    $stmt = db()->prepare('UPDATE reservations SET admin_notes = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([clean($notes, 500, true), now_text(), $reservationId]);
    if ($stmt->rowCount() === 0) {
        return 'That reservation was not found.';
    }
    return '';
}

/** Archive: hides a FINISHED (completed/cancelled) reservation from the active list. Nothing is deleted. */
function staff_archive_reservation($reservationId)
{
    $stmt = db()->prepare('SELECT status, archived_at FROM reservations WHERE id = ?');
    $stmt->execute([$reservationId]);
    $row = $stmt->fetch();

    if (!$row) {
        return 'That reservation was not found.';
    }
    if ($row['archived_at'] !== null) {
        return 'That reservation is already archived.';
    }
    if ($row['status'] !== 'completed' && $row['status'] !== 'cancelled') {
        return 'Only completed or cancelled reservations can be archived. Complete or cancel it first.';
    }
    $stmt = db()->prepare('UPDATE reservations SET archived_at = ? WHERE id = ? AND archived_at IS NULL');
    $stmt->execute([now_text(), $reservationId]);
    return '';
}

/** Restore: puts an archived reservation back in the active list. */
function staff_restore_reservation($reservationId)
{
    $stmt = db()->prepare('UPDATE reservations SET archived_at = NULL WHERE id = ? AND archived_at IS NOT NULL');
    $stmt->execute([$reservationId]);
    if ($stmt->rowCount() === 0) {
        return 'That reservation is not in the archive.';
    }
    return '';
}

/**
 * Permanent delete. ONLY an archived reservation can be deleted (the server checks, whatever the page sends).
 * The payment row and the proof file are removed with it.
 */
function staff_delete_archived_reservation($reservationId)
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT r.archived_at, p.proof_file
                           FROM reservations r LEFT JOIN payments p ON p.reservation_id = r.id
                           WHERE r.id = ?');
    $stmt->execute([$reservationId]);
    $row = $stmt->fetch();
    if (!$row) {
        return 'That reservation was not found.';
    }
    if ($row['archived_at'] === null) {
        return 'Only archived reservations can be deleted. Archive it first.';
    }

    try {
        $pdo->beginTransaction();
        // Delete the child row (payment) first, then the parent (reservation).
        $stmt = $pdo->prepare('DELETE FROM payments WHERE reservation_id = ?');
        $stmt->execute([$reservationId]);
        $stmt = $pdo->prepare('DELETE FROM reservations WHERE id = ? AND archived_at IS NOT NULL');
        $stmt->execute([$reservationId]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return 'That reservation could not be deleted.';
        }
        $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return 'That reservation could not be deleted. Nothing was changed.';
    }

    delete_proof_file($row['proof_file']);   // the rows are gone; now remove the uploaded file
    return '';
}
