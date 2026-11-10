<?php
/*
 * Shows an uploaded payment proof to STAFF ONLY.
 * The screenshots are saved in storage/proofs/, a folder the browser cannot open
 * directly, so the only way to see a proof is through this page (which checks the role).
 */
require __DIR__ . '/includes/bootstrap.php';
require_staff_action();   // guests -> 401, customers -> 403

$reservationId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT proof_file FROM payments WHERE reservation_id = ?');
$stmt->execute([$reservationId]);
$p = $stmt->fetch();

$file = '';
if ($p && $p['proof_file']) {
    $file = cfg('proof_dir') . '/' . basename((string) $p['proof_file']);   // basename() keeps it inside storage/proofs
}
// The file type is read from the saved file's extension (png, jpg or pdf) -> mime type.
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime = array_search($ext, cfg('proof_types'), true);
if ($file === '' || !is_file($file) || $mime === false) {
    http_response_code(404);
    exit('No proof found.');
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: inline; filename="proof.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
header('Cache-Control: private, no-store');
while (ob_get_level() > 0) {
    ob_end_clean();   // nothing may be printed before the file bytes
}
readfile($file);
