<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

$adminExists = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$adminExists) {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');

    if ($username === '' || $fullName === '' || strlen($username) > 80) {
        $error = 'Enter a name and a username (up to 80 characters).';
    } elseif (strlen($password) < 10) {
        $error = 'The administrator password must be at least 10 characters.';
    } elseif ($password !== $confirmation) {
        $error = 'The passwords do not match.';
    } else {
        try {
            $statement = $pdo->prepare(
                "INSERT INTO users (username, full_name, password_hash, role) VALUES (?, ?, ?, 'admin')"
            );
            $statement->execute([$username, $fullName, password_hash($password, PASSWORD_DEFAULT)]);
            $adminExists = true;
            $success = 'Administrator created. You can now sign in.';
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $error = 'That username is already in use.';
            } else {
                throw $exception;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Set up La Guerta Kuzina</title>
    <link href="app.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
    <section class="col-12 col-md-7 col-lg-5 bg-white border rounded shadow-sm p-4">
        <h1 class="h3 mb-3">Initial administrator setup</h1>
        <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
        <?php if ($adminExists): ?>
            <p>The first administrator is already set up. For safety, this page only creates the first admin account.</p>
            <a class="btn btn-primary" href="staff_login.php?role=admin">Go to owner sign in</a>
        <?php else: ?>
            <p>Create the first admin account. The password is stored as a secure hash.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <label class="form-label" for="full_name">Full name</label>
                <input class="form-control mb-3" id="full_name" name="full_name" maxlength="120" required>
                <label class="form-label" for="username">Username</label>
                <input class="form-control mb-3" id="username" name="username" maxlength="80" autocomplete="username" required>
                <label class="form-label" for="password">Password (minimum 10 characters)</label>
                <input class="form-control mb-3" type="password" id="password" name="password" minlength="10" autocomplete="new-password" required>
                <label class="form-label" for="password_confirmation">Confirm password</label>
                <input class="form-control mb-3" type="password" id="password_confirmation" name="password_confirmation" minlength="10" autocomplete="new-password" required>
                <button class="btn btn-primary w-100" type="submit">Create admin account</button>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
