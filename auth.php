<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals(csrf_token(), $submittedToken)) {
        http_response_code(400);
        exit('Invalid form token. Reload the page and try again.');
    }
}

function require_login(): void
{
    if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
        header('Location: menu.php');
        exit;
    }
}

function require_role(string ...$roles): void
{
    require_login();
    if (!in_array($_SESSION['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
}

function redirect_for_role(): void
{
    $destinations = [
        'admin' => 'admin_dashboard.php',
        'cashier' => 'staff_login.php',
        'customer' => 'menu.php',
    ];
    header('Location: ' . ($destinations[$_SESSION['role'] ?? ''] ?? 'menu.php'));
    exit;
}
