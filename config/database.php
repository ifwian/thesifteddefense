<?php
/*
 * DATABASE CONNECTION - the ONLY place that connects PHP to MySQL.
 * Every page gets its database from the function db() below.
 *
 * These four values are XAMPP's defaults, so you do NOT need to change anything
 * on a normal XAMPP install:
 *   host      = where MySQL runs (127.0.0.1 is the same as "localhost")
 *   database  = thesifted_db  (created by database/thesifted_db.sql)
 *   username  = root          (XAMPP's built-in MySQL account)
 *   password  = empty          (XAMPP's default root password is blank)
 * Only if you set a root password in XAMPP, type it in $db_password.
 *
 * We use PDO (PHP's database tool). The important idea is the "prepared statement":
 * the SQL has ? placeholders and the values are sent separately, so what a visitor
 * types can never change the SQL (this stops "SQL injection").
 */
$db_host     = '127.0.0.1';
$db_port     = 3306;            // XAMPP's MySQL port. If XAMPP shows another port (e.g. 3307), change it here.
$db_name     = 'thesifted_db';
$db_user     = 'root';
$db_password = '';

// Optional: put your own values in config/database.local.php (copy database.local.example.php).
// That file is ignored by Git, so your password never goes to GitHub.
if (is_file(__DIR__ . '/database.local.php')) {
    require __DIR__ . '/database.local.php';
}

/** Says WHAT kind of connection problem it is (visitors never see passwords or details). */
function db_error_kind($ex)
{
    $driverCode = 0;
    if (isset($ex->errorInfo[1])) {
        $driverCode = (int) $ex->errorInfo[1];
    }
    if (stripos($ex->getMessage(), 'could not find driver') !== false) {
        return 'db_driver';
    }
    if ($driverCode === 1045 || $driverCode === 1044) {
        return 'db_auth';       // wrong username or password
    }
    if ($driverCode === 1049) {
        return 'db_missing';    // database not imported yet
    }
    return 'db_down';           // MySQL is not running (or any other problem)
}

/** Opens a NEW connection or throws PDOException. (Used by db() and by the health check.) */
function db_open()
{
    global $db_host, $db_port, $db_name, $db_user, $db_password;
    $dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // problems become errors we can catch
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // rows come back as ['column' => value]
        PDO::ATTR_EMULATE_PREPARES   => false,                   // real prepared statements
    ];
    return new PDO($dsn, $db_user, $db_password, $options);
}

/** Gives the database connection. It connects only the first time and reuses it after that. */
function db()
{
    global $pdo;
    if (isset($pdo)) {
        return $pdo;
    }
    try {
        $pdo = db_open();
    } catch (PDOException $ex) {
        error_log('Database connection failed: ' . $ex->getMessage());   // details go to storage/logs/app.log only
        $kind = db_error_kind($ex);
        if ($kind === 'db_missing') {
            $message = 'The database was not found. Import database/thesifted_db.sql in phpMyAdmin.';
        } elseif ($kind === 'db_auth') {
            $message = 'The database login was rejected. Check the username and password in config/database.php.';
        } elseif ($kind === 'db_driver') {
            $message = 'PHP cannot use MySQL (the pdo_mysql extension is off). Enable it in php.ini and restart Apache.';
        } else {
            $message = 'The database is not running. Start MySQL in the XAMPP Control Panel and try again.';
        }
        show_error(500, $message, $kind);
    }
    return $pdo;
}
