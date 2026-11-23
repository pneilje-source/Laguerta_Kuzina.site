<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin') {
    redirect_for_role();
}

$error = isset($_GET['error']) ? 'Invalid username or password for this account type.' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin sign in | La Guerta Kuzina</title>
    <link href="app.css" rel="stylesheet">
</head>
<body class="bg-light auth-page">
    <main class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
        <div class="row w-100 justify-content-center">
            <section class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5">
                <div class="bg-white border rounded shadow-sm p-4 p-md-5">
                    <a class="d-inline-flex align-items-center gap-2 text-decoration-none mb-4" href="menu.php">
                        <img src="Image/laguerta.jpg" alt="" width="44" height="44" class="rounded-circle">
                        <span class="fw-semibold text-dark">La Guerta Kuzina</span>
                    </a>
                    <h1 class="h2 fw-bold mb-2">Admin sign in</h1>
                    <p class="text-secondary mb-4">Owner and administrator access only. Sign in with your administrator account.</p>

                    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert">Invalid administrator username or password.</div><?php endif; ?>

                    <form id="login-form" method="post" action="authenticate.php">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="role" value="admin">
                        <div class="mb-3">
                            <label for="staff-username" class="form-label fw-semibold">Username</label>
                            <input class="form-control" type="text" id="staff-username" name="username" autocomplete="username" required>
                        </div>
                        <div class="mb-4">
                            <label for="staff-password" class="form-label fw-semibold">Password</label>
                            <input class="form-control" type="password" id="staff-password" name="password" autocomplete="current-password" required>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Sign in</button>
                    </form>

                    <p class="text-center mt-4 mb-0"><a href="menu.php">Back to customer menu</a></p>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
