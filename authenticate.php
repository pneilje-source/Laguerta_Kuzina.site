<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: menu.php');
    exit;
}
verify_csrf();

$role = $_POST['role'] ?? '';
$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
if (!is_string($role) || $role !== 'admin') {
    header('Location: staff_login.php?error=1');
    exit;
}

if ($username === '' || $password === '') {
    header('Location: staff_login.php?role=' . $role . '&error=1');
    exit;
}

$statement = $pdo->prepare(
    'SELECT id, username, full_name, password_hash, role FROM users WHERE username = ? AND role = ? AND is_active = 1 LIMIT 1'
);
$statement->execute([$username, $role]);
$user = $statement->fetch();

if (!$user) {
    header('Location: staff_login.php?role=' . $role . '&error=1');
    exit;
}

$passwordHash = $user['password_hash'];
$validPassword = password_verify($password, $passwordHash);
$legacyPlaintextPassword = false;
if (!$validPassword && password_get_info($passwordHash)['algoName'] === 'unknown') {
    $legacyPlaintextPassword = hash_equals($passwordHash, $password);
    $validPassword = $legacyPlaintextPassword;
}

if (!$validPassword) {
    header('Location: staff_login.php?role=' . $role . '&error=1');
    exit;
}

if ($legacyPlaintextPassword) {
    $statement = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND password_hash = ?');
    $statement->execute([
        password_hash($password, PASSWORD_DEFAULT),
        (int) $user['id'],
        $passwordHash,
    ]);
    if ($statement->rowCount() !== 1) {
        header('Location: staff_login.php?role=' . $role . '&error=1');
        exit;
    }
}

session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role'] = $user['role'];
redirect_for_role();
