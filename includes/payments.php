<?php
/*
 * PAYMENT LOGIC: checking the payment fields, checking the uploaded proof file,
 * saving a payment row, and staff verifying a payment.
 * The `payments` table has ONE row per reservation (reservation_id is UNIQUE).
 * Payment status: unpaid -> proof_submitted -> verified.
 */

/** Checks the payment fields and the proof file. Returns ['values' => ..., 'errors' => ..., 'proof' => file info or null]. */
function validate_payment($d, $guests, $file)
{
    $errors = [];

    $option = (string) ($d['payment_option'] ?? '');
    if ($option !== 'downpayment' && $option !== 'full') {
        $errors['payment_option'] = 'Please choose Downpayment or Full payment.';
    }
    if (($d['payment_method'] ?? '') !== 'gcash') {
        $errors['payment_method'] = 'GCash is the only payment method.';
    }

    // The GCash reference number is optional, but if typed it must be 6 to 20 digits.
    $reference = preg_replace('/\s/', '', (string) ($d['payment_reference'] ?? ''));
    if ($reference !== '' && !preg_match('/^[0-9]{6,20}$/', $reference)) {
        $errors['payment_reference'] = 'The GCash reference number can only have digits (6 to 20).';
    }
    if ($reference === '') {
        $reference = null;
    }
    if (!truthy($d['accept_policies'] ?? false)) {
        $errors['accept_policies'] = 'Please confirm that you read the policies.';
    }

    // The proof file (screenshot or PDF of the GCash payment).
    $proof = null;
    if (!isset($errors['payment_option']) && $guests > 0) {
        $amounts = compute_amounts($guests);
        $due = $amounts['options'][$option]['amount_due'];
        $hasFile = ($file !== null && $file['error'] !== UPLOAD_ERR_NO_FILE);
        if ($hasFile) {
            $result = inspect_proof($file);
            if ($result['error'] !== '') {
                $errors['payment_proof'] = $result['error'];
            } else {
                $proof = $result['info'];
            }
        } elseif (cfg('require_proof') && $due > 0) {
            $errors['payment_proof'] = 'Please attach your proof of payment.';
        }
    }

    return [
        'values' => ['payment_option' => $option, 'payment_reference' => $reference],
        'errors' => $errors,
        'proof'  => $proof,
    ];
}

/** Looks at an uploaded file. We trust the file CONTENT (finfo), not its name. Returns ['info' => ..., 'error' => text]. */
function inspect_proof($file)
{
    $max = cfg('max_proof_bytes');
    $mb = $max / 1048576;
    $tooBig = 'Proof of payment must be smaller than ' . $mb . ' MB.';

    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['info' => null, 'error' => $tooBig];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['info' => null, 'error' => 'The proof of payment could not be uploaded. Please try again.'];
    }
    if (!is_uploaded_file($file['tmp_name']) || $file['size'] <= 0 || $file['size'] > $max) {
        return ['info' => null, 'error' => $tooBig];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($file['tmp_name']);
    $types = cfg('proof_types');   // allowed: mime type => extension
    if (!isset($types[$mime])) {
        return ['info' => null, 'error' => 'Proof of payment must be a PNG, JPG or PDF file.'];
    }

    $original = clean(basename((string) $file['name']), 150);
    if ($original === '') {
        $original = 'proof.' . $types[$mime];
    }
    $info = ['tmp' => $file['tmp_name'], 'ext' => $types[$mime], 'original' => $original];
    return ['info' => $info, 'error' => ''];
}

/** Moves the uploaded proof into storage/proofs with a random name. Returns the saved file name, or '' if it failed. */
function save_proof_file($proof)
{
    $folder = cfg('proof_dir');
    if (!is_dir($folder)) {
        mkdir($folder, 0775, true);
    }
    $name = bin2hex(random_bytes(16)) . '.' . $proof['ext'];
    if (!move_uploaded_file($proof['tmp'], $folder . '/' . $name)) {
        return '';
    }
    return $name;
}

/** Delete a saved proof file (basename() keeps the path inside storage/proofs). */
function delete_proof_file($name)
{
    if ($name === null || $name === '') {
        return;
    }
    $path = cfg('proof_dir') . '/' . basename($name);
    if (is_file($path)) {
        unlink($path);
    }
}

/** INSERT the payment row for a new reservation. A proof only means 'proof_submitted', NEVER 'verified'. */
function insert_payment($pdo, $reservationId, $guests, $pay, $proof, $storedName)
{
    $amounts = compute_amounts($guests);
    $due = $amounts['options'][$pay['payment_option']]['amount_due'];

    $status = 'unpaid';
    $originalName = null;
    if ($proof !== null) {
        $status = 'proof_submitted';
        $originalName = $proof['original'];
    }
    $sql = 'INSERT INTO payments
            (reservation_id, method, payment_option, total_amount, amount_due, reference_number, proof_original_name, proof_file, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$reservationId, 'gcash', $pay['payment_option'], $amounts['total_amount'], $due,
        $pay['payment_reference'], $originalName, $storedName, $status, now_text()]);
}

/** Staff marks a proof as checked against the cafe's real GCash records. Returns '' or an error message. */
function staff_verify_payment($reservationId, $staffId)
{
    $stmt = db()->prepare('SELECT p.status AS pay_status, r.status AS res_status
                           FROM payments p JOIN reservations r ON r.id = p.reservation_id
                           WHERE p.reservation_id = ?');
    $stmt->execute([$reservationId]);
    $row = $stmt->fetch();

    if (!$row || $row['pay_status'] !== 'proof_submitted') {
        return 'Only a payment with an uploaded proof can be marked as verified.';
    }
    if ($row['res_status'] !== 'pending' && $row['res_status'] !== 'confirmed') {
        return 'Payment can only be verified for a pending or confirmed reservation.';
    }
    $stmt = db()->prepare("UPDATE payments SET status = 'verified', verified_by = ?, verified_at = ?
                           WHERE reservation_id = ? AND status = 'proof_submitted'");
    $stmt->execute([$staffId, now_text(), $reservationId]);
    if ($stmt->rowCount() === 0) {
        return 'This payment was just changed by someone else. Please reload the page.';
    }
    return '';
}

/**
 * Staff undoes a verification by mistake: verified -> proof_submitted.
 * Only while the reservation is still PENDING (a confirmed one was already promised to the customer).
 * Returns '' or an error message.
 */
function staff_unverify_payment($reservationId)
{
    $stmt = db()->prepare('SELECT p.status AS pay_status, r.status AS res_status
                           FROM payments p JOIN reservations r ON r.id = p.reservation_id
                           WHERE p.reservation_id = ?');
    $stmt->execute([$reservationId]);
    $row = $stmt->fetch();

    if (!$row || $row['pay_status'] !== 'verified') {
        return 'Only a verified payment can be set back to "awaiting verification".';
    }
    if ($row['res_status'] !== 'pending') {
        return 'Only a pending reservation can have its verification undone.';
    }
    $stmt = db()->prepare("UPDATE payments SET status = 'proof_submitted', verified_by = NULL, verified_at = NULL
                           WHERE reservation_id = ? AND status = 'verified'");
    $stmt->execute([$reservationId]);
    if ($stmt->rowCount() === 0) {
        return 'This payment was just changed by someone else. Please reload the page.';
    }
    return '';
}
