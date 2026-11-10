<?php
/*
 * AUTHENTICATION: sign up, log in, log out, who is signed in, and page guards.
 *
 * How login works (simple version):
 *   1. The visitor types email + password.
 *   2. We look the email up in the users table (prepared statement).
 *   3. password_verify() compares the typed password with the saved hash.
 *   4. If correct, we save the user's id in $_SESSION. The browser keeps only a random
 *      session cookie; the id itself stays on the server.
 *   5. On every later request we read the user from the database using that id,
 *      so the role (customer / staff) always comes from the database, not from the browser.
 */

/** Returns the signed-in user (an array) or null if nobody is signed in. */
function current_user()
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, full_name, email, phone, role, created_at FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;   // the account no longer exists
    }
    return $user;
}

/** Remember the user in the session after a correct login or sign-up. */
function sign_in($userId)
{
    session_regenerate_id(true);   // new session ID after login (stops session fixation)
    $_SESSION = [];
    $_SESSION['user_id'] = (int) $userId;
}

/** Forget the user: empty the session, delete the cookie, destroy the session. */
function sign_out()
{
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => $p['path'],
        'secure'   => $p['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_destroy();
}

/** The user fields that are safe to send to the browser (never the password hash). */
function public_user($u)
{
    return [
        'full_name'    => $u['full_name'],
        'email'        => $u['email'],
        'phone'        => $u['phone'],
        'role'         => $u['role'],
        'member_since' => substr((string) $u['created_at'], 0, 10),
    ];
}

/** Where each role goes after signing in. */
function home_page_for($role)
{
    if ($role === 'staff') {
        return 'staff.php';
    }
    return 'account.php';
}

/** Page guard: any signed-in user. Guests are sent to the login page. */
function require_login_page($next)
{
    $user = current_user();
    if ($user === null) {
        header('Location: login.html?next=' . rawurlencode($next));
        exit;
    }
    return $user;
}

/** Page guard: staff only. Guests go to login; customers get a 403 message. */
function require_staff_page()
{
    $user = current_user();
    if ($user === null) {
        header('Location: login.html?next=staff.php');
        exit;
    }
    if ($user['role'] !== 'staff') {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>Staff only</title><p>This page is for cafe staff only.</p><p><a href="index.html">Back to the home page</a></p>';
        exit;
    }
    return $user;
}

/**
 * Guard for staff ACTION endpoints (no page to show): staff only, otherwise a plain 401/403 and stop.
 * 401 = not signed in, 403 = signed in but not staff (a customer). Returns the staff user.
 */
function require_staff_action()
{
    $user = current_user();
    if ($user === null) {
        http_response_code(401);
        exit('Please sign in as staff.');
    }
    if ($user['role'] !== 'staff') {
        http_response_code(403);
        exit('Staff only.');
    }
    return $user;
}

/** Same rules the pages check in the browser. The server always checks again. */
function registration_errors($d, $email)
{
    $errors = [];
    $name = clean($d['full_name'] ?? '', 100);
    $phone = clean($d['phone'] ?? '', 30);
    $digits = preg_replace('/\D/', '', $phone);
    $password = (string) ($d['password'] ?? '');

    if (mb_strlen($name) < 2) {
        $errors['full_name'] = 'Please enter your full name (at least 2 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        $errors['email'] = 'Please enter a valid email address.';
    }
    if (!preg_match('/^\+?[0-9\s\-()]+$/', $phone) || strlen($digits) < 7 || strlen($digits) > 15) {
        $errors['phone'] = 'Contact number must have 7 to 15 digits.';
    }
    if (strlen($password) < 8 || strlen($password) > 72 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors['password'] = 'Password must be 8 to 72 characters with a letter and a number.';
    }
    if (($d['confirm_password'] ?? null) !== ($d['password'] ?? '')) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }
    return $errors;
}

/** Is this email already used by an account? */
function email_exists($email)
{
    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    return $stmt->fetch() !== false;
}

/** Register a new CUSTOMER and sign them in. Sends the JSON answer and stops. */
function register_customer($d)
{
    $email = strtolower(clean($d['email'] ?? '', 150));
    $errors = registration_errors($d, $email);

    // Only look in the database if the email itself looks valid.
    $taken = false;
    if (!isset($errors['email']) && email_exists($email)) {
        $errors['email'] = 'An account with this email already exists. Please sign in.';
        $taken = true;
    }
    if (count($errors) > 0) {
        $status = 400;
        if ($taken && count($errors) === 1) {
            $status = 409;
        }
        json_out($status, ['error' => 'Please fix the highlighted fields.', 'errors' => $errors]);
    }

    // The password is saved only as a hash. Every sign-up gets the role 'customer'.
    $hash = password_hash((string) $d['password'], PASSWORD_DEFAULT);
    try {
        $stmt = db()->prepare('INSERT INTO users (full_name, email, phone, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([clean($d['full_name'], 100), $email, clean($d['phone'], 30), $hash, 'customer', now_text()]);
    } catch (PDOException $ex) {
        // Two people using the same email at the same moment: the UNIQUE key stops the second one.
        json_out(409, ['error' => 'Please fix the highlighted fields.', 'errors' => ['email' => 'An account with this email already exists. Please sign in.']]);
    }

    sign_in(db()->lastInsertId());
    json_out(201, ['customer' => public_user(current_user())]);
}

/** Log a user in (customer or staff). Sends the JSON answer and stops. */
function login_user($d)
{
    if (login_throttled()) {
        json_out(429, ['error' => 'Too many tries. Please wait a few minutes and try again.']);
    }
    $email = strtolower(clean($d['email'] ?? '', 150));
    $password = (string) ($d['password'] ?? '');

    // 1. Find the user with this email.
    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    // 2. Compare the typed password with the saved hash.
    $ok = false;
    if ($row) {
        $ok = password_verify($password, $row['password_hash']);
    }
    if (!$ok) {
        login_failed();
        // Same message for "unknown email" and "wrong password" so nobody can guess which emails exist.
        json_out(401, ['error' => 'Incorrect email or password.']);
    }

    // 3. Correct: remember the user in the session.
    sign_in($row['id']);
    json_out(200, ['customer' => public_user(current_user())]);
}

/** Small brake on password guessing: 5 wrong tries, then wait 5 minutes (per browser session). */
function login_throttled()
{
    if (!isset($_SESSION['login_fail'])) {
        return false;
    }
    $fails = $_SESSION['login_fail']['count'];
    $since = $_SESSION['login_fail']['since'];
    if (time() - $since > 300) {
        unset($_SESSION['login_fail']);   // 5 minutes passed: start fresh
        return false;
    }
    return $fails >= 5;
}

/** Count one wrong login. */
function login_failed()
{
    if (!isset($_SESSION['login_fail'])) {
        $_SESSION['login_fail'] = ['count' => 0, 'since' => time()];
    }
    $_SESSION['login_fail']['count']++;
}
