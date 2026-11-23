<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
if (isset($_SESSION['user_id'])) {
    redirect_for_role();
}

header('Location: staff_login.php?role=admin');
exit;
