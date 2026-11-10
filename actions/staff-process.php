<?php
/*
 * Handles the buttons on the staff dashboard (one simple CRUD action per button):
 *   confirmed / completed / cancelled  -> UPDATE reservations.status
 *   verify / unverify                  -> UPDATE payments.status
 *   notes                              -> UPDATE reservations.admin_notes
 *   archive / restore                  -> UPDATE reservations.archived_at
 *   delete                             -> DELETE (archived records only)
 * Only a logged-in STAFF user may use it, and only with POST.
 * (The hidden "csrf" value proves the click came from our own page, not another website.)
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_staff_action();   // guests -> 401, customers -> 403
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Invalid request. Go back, reload the page and try again.');
}

// Validate the input on the server (the browser can send anything).
$id = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$error = '';
$okText = '';

if ($id < 1) {
    $error = 'Unknown reservation.';
} else {
    $ref = reference_for($id);   // read before a delete removes the row
    switch ($action) {
        case 'confirmed':
        case 'completed':
        case 'cancelled':
            $error = staff_set_status($id, $action);
            $okText = 'Reservation ' . $ref . ' is now ' . $action . '.';
            break;
        case 'archive':
            $error = staff_archive_reservation($id);
            $okText = 'Reservation ' . $ref . ' was moved to the Archive.';
            break;
        case 'restore':
            $error = staff_restore_reservation($id);
            $okText = 'Reservation ' . $ref . ' was restored to the active list.';
            break;
        case 'delete':
            $error = staff_delete_archived_reservation($id);
            $okText = 'Reservation ' . $ref . ' was permanently deleted.';
            break;
        case 'verify':
            $error = staff_verify_payment($id, (int) $user['id']);
            $okText = 'Payment for ' . $ref . ' was marked as verified.';
            break;
        case 'unverify':
            $error = staff_unverify_payment($id);
            $okText = 'Payment for ' . $ref . ' was set back to awaiting verification.';
            break;
        case 'notes':
            $error = staff_set_notes($id, (string) ($_POST['notes'] ?? ''));
            $okText = 'Notes saved.';
            break;
        default:
            $error = 'Unknown action.';
    }
}

if ($error === '') {
    $_SESSION['flash'] = ['type' => 'ok', 'text' => $okText];
} else {
    $_SESSION['flash'] = ['type' => 'error', 'text' => $error];
}

// only keep known filter keys when going back (no open redirect: always staff.php)
parse_str((string) ($_POST['return'] ?? ''), $back);
$keep = array_intersect_key($back, array_flip(['id', 'view', 'status', 'branch', 'payment', 'date', 'q']));
$query = '';
if (count($keep) > 0) {
    $query = '?' . http_build_query($keep);
}
header('Location: ../staff.php' . $query . '#detail');
exit;
