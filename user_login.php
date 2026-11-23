<?php
require_once __DIR__ . '/auth.php';
if (isset($_SESSION['user_id'])) {
    redirect_for_role();
}
header('Location: menu.php', true, 301);
exit;
