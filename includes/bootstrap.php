<?php
/*
 * START OF EVERY PHP PAGE.
 * Each page begins with:  require 'includes/bootstrap.php';
 * It loads the settings and helper files, then starts the PHP session.
 * (A session is how the server remembers who is logged in: the browser holds a
 *  cookie with a random ID, and the server keeps the user's id under that ID.)
 */
// Hold all output back until we decide what to send. A stray warning or blank line can then
// never get mixed into a JSON answer (that made the browser say "could not reach the server").
ob_start();

define('SIFTED_ROOT', dirname(__DIR__));
$GLOBALS['sifted_config'] = require SIFTED_ROOT . '/config/app.php';
date_default_timezone_set($GLOBALS['sifted_config']['timezone']);

// Visitors never see PHP error details. They are written to storage/logs/app.log instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ini_set('error_log', $GLOBALS['sifted_config']['log_dir'] . '/app.log');

// A fatal PHP error normally gives an EMPTY page. Catch it, log it, and show a safe message.
register_shutdown_function('handle_fatal_error');
function handle_fatal_error()
{
    $err = error_get_last();
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if ($err === null || !in_array($err['type'], $fatal)) {
        return;
    }
    error_log('Fatal error: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    if (!headers_sent()) {
        show_error(500, 'Something went wrong on the server. Please try again later.', 'server_error');
    }
}

require_once SIFTED_ROOT . '/config/database.php';
require_once SIFTED_ROOT . '/includes/functions.php';
require_once SIFTED_ROOT . '/includes/auth.php';
require_once SIFTED_ROOT . '/includes/reservations.php';
require_once SIFTED_ROOT . '/includes/payments.php';

// Session cookie: hidden from JavaScript and not sent from other websites.
session_name('sifted_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

// If anything unexpected goes wrong: log it and show a short, safe message.
set_exception_handler('handle_exception');
function handle_exception($e)
{
    error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    show_error(500, 'Something went wrong on the server. Please try again later.', 'server_error');
}
