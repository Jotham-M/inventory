<?php
require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the sign-out button to end your administrator session.');
}

try {
    verifyCsrfToken();
} catch (RuntimeException $exception) {
    http_response_code(403);
    exit(e($exception->getMessage()));
}

$_SESSION = [];
$cookie = session_get_cookie_params();
if (ini_get('session.use_cookies')) {
    setcookie(session_name(), '', time() - 42000, [
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => 'Lax'
    ]);
}
session_destroy();
redirect('login.php');
