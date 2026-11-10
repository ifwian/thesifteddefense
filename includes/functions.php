<?php
/*
 * SMALL HELPERS used by every page: escaping text, JSON answers, cleaning input,
 * CSRF checks and the setup health check. (Login and sessions are in auth.php.)
 */

function cfg(string $key)
{
    return $GLOBALS['sifted_config'][$key];
}

/** Escape text before printing it in HTML (stops XSS). */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_text(): string
{
    return date('Y-m-d H:i:s');
}

/** Send a JSON answer and stop. */
function json_out(int $status, array $data): void
{
    while (ob_get_level() > 0) {   // drop anything printed earlier so the answer is pure JSON
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Show an error and stop: JSON for /api/ requests, a small HTML page for normal pages. */
function show_error($status, $message, $code)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message, 'code' => $code]);
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>Server error</title><p>' . htmlspecialchars($message) . '</p><p><a href="index.html">Back to the home page</a></p>';
    }
    exit;
}

/** Trim and shorten text. Empty text becomes null if $nullIfEmpty. */
function clean($value, int $max = 255, bool $nullIfEmpty = false): ?string
{
    $text = trim((string) ($value ?? ''));
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    if (mb_strlen($text) > $max) {
        $text = mb_substr($text, 0, $max);
    }
    return ($text === '' && $nullIfEmpty) ? null : $text;
}

/** The request body as an array: JSON body, or form fields (multipart / urlencoded). */
function request_data(): array
{
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($type, 'application/json') !== false) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

function truthy($value): bool
{
    return $value === true || $value === 1 || in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
}

// ---------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------

/**
 * JSON API protection. Our own pages always send the header "X-Requested-With: fetch".
 * Another website cannot add that header to a form post, and the browser blocks
 * cross-site fetch calls with custom headers. We also compare the Origin header.
 */
function api_csrf_check(): void
{
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
        json_out(403, ['error' => 'Request blocked (missing security header).']);
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $originHost = (string) parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '');
        // Behind a proxy or tunnel (ngrok, Codespaces...) the public host arrives in X-Forwarded-Host.
        $forwarded = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
        $sameHost = strcasecmp($originHost, (string) ($_SERVER['HTTP_HOST'] ?? '')) === 0
            || ($forwarded !== '' && strcasecmp($originHost, $forwarded) === 0);
        if (!$sameHost) {
            json_out(403, ['error' => 'Request blocked (wrong origin).']);
        }
    }
}

/** Token for the HTML forms on the staff page. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid(?string $token): bool
{
    return is_string($token) && $token !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/** Used by api/index.php/health: checks the setup piece by piece WITHOUT stopping at the first problem. */
function health_report(): array
{
    $checks = [];
    $checks['php_7_4_or_newer'] = PHP_VERSION_ID >= 70400;
    foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'json', 'session'] as $ext) {
        $checks['php_extension_' . $ext] = extension_loaded($ext);
    }
    $checks['folder_storage_proofs_writable'] = is_dir(cfg('proof_dir')) && is_writable(cfg('proof_dir'));
    $checks['folder_storage_logs_writable'] = is_dir(cfg('log_dir')) && is_writable(cfg('log_dir'));

    $database = 'ok';
    $pdo = null;
    try {
        $pdo = db_open();
    } catch (PDOException $ex) {
        $database = db_error_kind($ex);   // db_down | db_auth | db_missing | db_driver
    }
    $checks['database_connection'] = $database;

    if ($pdo !== null) {
        $need = ['users' => ['id', 'email', 'password_hash', 'role'],
                 'reservations' => ['id', 'user_id', 'status', 'archived_at'],
                 'payments' => ['reservation_id', 'status', 'proof_file']];
        foreach ($need as $table => $columns) {
            $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            $have = array_column($stmt->fetchAll(), 'COLUMN_NAME');
            $checks['table_' . $table] = $have === [] ? 'missing' : (array_diff($columns, array_map('strtolower', $have)) ? 'columns_missing' : 'ok');
        }
    }
    $good = true;
    foreach ($checks as $value) {
        if ($value !== true && $value !== 'ok') {
            $good = false;
        }
    }
    return ['ok' => $good, 'checks' => $checks];
}
