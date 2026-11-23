<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_role('admin');
require_once __DIR__ . '/config.php';

$error = '';
$notice = (string) ($_SESSION['admin_notice'] ?? '');
unset($_SESSION['admin_notice']);
$reportDateValue = $_POST['report_date'] ?? $_GET['date'] ?? date('Y-m-d');
$reportDate = is_string($reportDateValue) ? $reportDateValue : date('Y-m-d');
$dashboardViewValue = $_POST['dashboard_view'] ?? $_GET['view'] ?? 'overview';
$dashboardView = is_string($dashboardViewValue) ? $dashboardViewValue : 'overview';
if (!in_array($dashboardView, ['overview', 'sales', 'expenses', 'settings'], true)) {
    $dashboardView = 'overview';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
    $reportDate = date('Y-m-d');
}
[$reportYear, $reportMonth, $reportDay] = array_map('intval', explode('-', $reportDate));
if (!checkdate($reportMonth, $reportDay, $reportYear)) {
    $reportDate = date('Y-m-d');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create-user') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $role = $_POST['role'] ?? '';
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || strlen($username) > 80 || $fullName === '' || strlen($fullName) > 120 || $role !== 'admin') {
            $error = 'Enter a valid name and username for an administrator account.';
        } elseif (strlen($password) < 10) {
            $error = 'Passwords must be at least 10 characters.';
        } else {
            try {
                $statement = $pdo->prepare("INSERT INTO users (username, full_name, password_hash, role) VALUES (?, ?, ?, 'admin')");
                $statement->execute([$username, $fullName, password_hash($password, PASSWORD_DEFAULT)]);
                $notice = 'Account created.';
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $error = 'That username is already in use.';
                } else {
                    throw $exception;
                }
            }
        }
    } elseif ($action === 'create-item') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $itemType = $_POST['item_type'] ?? 'food';
        $price = $_POST['price'] ?? '';
        if ($name === '' || strlen($name) > 120 || $category === '' || strlen($category) > 60 || !in_array($itemType, ['food', 'drink'], true) || !is_numeric($price) || (float) $price <= 0) {
            $error = 'Enter a menu item, category, type, and price greater than zero.';
        } else {
            try {
                $statement = $pdo->prepare('INSERT INTO menu_items (name, category, item_type, price) VALUES (?, ?, ?, ?)');
                $statement->execute([$name, $category, $itemType, number_format((float) $price, 2, '.', '')]);
                $notice = 'Menu item added.';
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $error = 'An item with that name already exists in this category.';
                } else {
                    throw $exception;
                }
            }
        }
    } elseif ($action === 'toggle-item') {
        $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$itemId || $itemId < 1) {
            $error = 'Invalid menu item.';
        } else {
            $statement = $pdo->prepare('UPDATE menu_items SET is_active = 1 - is_active WHERE id = ?');
            $statement->execute([$itemId]);
            $notice = $statement->rowCount() ? 'Menu item status updated.' : 'Menu item was not found.';
        }
    } elseif ($action === 'update-item-price') {
        $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
        $price = trim((string) ($_POST['price'] ?? ''));
        if (!$itemId || $itemId < 1 || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $price) || (float) $price <= 0) {
            $error = 'Enter a valid menu item and a price greater than zero with up to two decimal places.';
        } else {
            $statement = $pdo->prepare('UPDATE menu_items SET price = ? WHERE id = ?');
            $statement->execute([number_format((float) $price, 2, '.', ''), $itemId]);
            $notice = $statement->rowCount() ? 'Menu price updated. The customer menu and cashier price list now show the new price.' : 'Menu item was not found or the price is unchanged.';
        }
    } elseif ($action === 'toggle-user') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || $userId < 1 || $userId === (int) $_SESSION['user_id']) {
            $error = 'You cannot deactivate your own account.';
        } else {
            $statement = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = ?');
            $statement->execute([$userId]);
            $target = $statement->fetch();
            if (!$target) {
                $error = 'Account not found.';
            } elseif ($target['role'] !== 'admin') {
                $error = 'Cashier sign-in has been removed; historical cashier accounts cannot be enabled.';
            } elseif ($target['role'] === 'admin' && (int) $target['is_active'] === 1) {
                $activeAdmins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
                if ($activeAdmins <= 1) {
                    $error = 'At least one active administrator must remain.';
                }
            }
            if ($error === '') {
                $statement = $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?');
                $statement->execute([$userId]);
                $notice = 'Account status updated.';
            }
        }
    } elseif ($action === 'permanent-delete-user') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || $userId < 1) {
            $error = 'Invalid staff account.';
        } elseif ($userId === (int) $_SESSION['user_id']) {
            $error = 'You cannot permanently delete your own account.';
        } else {
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = ? FOR UPDATE');
                $statement->execute([$userId]);
                $target = $statement->fetch();
                if (!$target || !in_array($target['role'], ['admin', 'cashier'], true)) {
                    $error = 'Staff account not found.';
                } elseif ($target['role'] === 'admin' && (int) $target['is_active'] === 1) {
                    $activeAdmins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
                    if ($activeAdmins <= 1) {
                        $error = 'The last active administrator cannot be deleted.';
                    }
                }

                if ($error === '') {
                    $statement = $pdo->prepare(
                        'SELECT
                            (SELECT COUNT(*) FROM sales WHERE cashier_id = ?) +
                        (SELECT COUNT(*) FROM customer_orders WHERE customer_id = ?) +
                        (SELECT COUNT(*) FROM expenses WHERE created_by = ?) AS linked_records'
                    );
                    $statement->execute([$userId, $userId, $userId]);
                    if ((int) $statement->fetchColumn() > 0) {
                        $error = 'This account has historical orders, sales, or expenses and cannot be permanently deleted. Disable it instead to preserve the records.';
                    } else {
                        $statement = $pdo->prepare('DELETE FROM users WHERE id = ? AND role IN (?, ?)');
                        $statement->execute([$userId, 'admin', 'cashier']);
                        if ($statement->rowCount() !== 1) {
                            throw new RuntimeException('The staff account could not be permanently deleted.');
                        }
                        $notice = 'Staff account permanently deleted.';
                    }
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    } elseif ($action === 'set-commission-rate') {
        $rate = trim((string) ($_POST['commission_rate'] ?? ''));
        if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $rate) || (float) $rate > 100) {
            $error = 'Commission rate must be between 0 and 100 percent.';
        } else {
            $statement = $pdo->prepare(
                "INSERT INTO app_settings (setting_key, setting_value) VALUES ('driver_commission_rate', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $statement->execute([number_format((float) $rate, 2, '.', '')]);
            $notice = 'Driver commission rate saved. This rate applies to new orders with a driver.';
        }
    } elseif ($action === 'set-pwd-discount-rate') {
        $rate = trim((string) ($_POST['pwd_discount_rate'] ?? ''));
        if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $rate) || (float) $rate > 100) {
            $error = 'PWD discount rate must be between 0 and 100 percent.';
        } else {
            $statement = $pdo->prepare(
                "INSERT INTO app_settings (setting_key, setting_value) VALUES ('pwd_discount_rate', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $statement->execute([number_format((float) $rate, 2, '.', '')]);
            $notice = 'PWD discount rate saved. This rate applies to new eligible orders.';
        }
    } elseif ($action === 'add-expense') {
        $description = trim((string) ($_POST['description'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $amount = trim((string) ($_POST['amount'] ?? ''));
        if ($description === '' || strlen($description) > 160 || $category === '' || strlen($category) > 60) {
            $error = 'Enter an expense description and category.';
        } elseif (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
            $error = 'Enter an expense amount greater than zero with up to two decimal places.';
        } else {
            $statement = $pdo->prepare('INSERT INTO expenses (created_by, description, category, amount) VALUES (?, ?, ?, ?)');
            $statement->execute([(int) $_SESSION['user_id'], $description, $category, number_format((float) $amount, 2, '.', '')]);
            $notice = 'Expense recorded.';
        }
    } else {
        $error = 'Unknown action.';
    }
    if ($notice !== '') {
        $_SESSION['admin_notice'] = $notice;
        header('Location: admin_dashboard.php?date=' . rawurlencode($reportDate) . '&view=' . rawurlencode($dashboardView));
        exit;
    }
}

$dayAfter = (new DateTimeImmutable($reportDate))->modify('+1 day')->format('Y-m-d');
$users = $pdo->query("SELECT id, username, full_name, role, is_active, created_at FROM users WHERE role IN ('admin', 'cashier') ORDER BY role, full_name")->fetchAll();
$menuItems = $pdo->query('SELECT id, name, category, item_type, price, is_active FROM menu_items ORDER BY item_type, category, name')->fetchAll();
$statement = $pdo->prepare(
    'SELECT COUNT(*) AS sale_count, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(driver_commission), 0) AS commission
     FROM sales WHERE created_at >= ? AND created_at < ?'
);
$statement->execute([$reportDate, $dayAfter]);
$dailySales = $statement->fetch();
$statement = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE created_at >= ? AND created_at < ?');
$statement->execute([$reportDate, $dayAfter]);
$dailyExpensesTotal = (float) $statement->fetchColumn();
$salesAfterExpenses = (float) $dailySales['total'] - $dailyExpensesTotal;
$dailyNet = $salesAfterExpenses;
$chartStart = (new DateTimeImmutable($reportDate))->modify('-6 days')->format('Y-m-d');
$chartEnd = $dayAfter;
$trendByDate = [];
$chartDate = new DateTimeImmutable($chartStart);
for ($dayIndex = 0; $dayIndex < 7; $dayIndex++) {
    $dateKey = $chartDate->format('Y-m-d');
    $trendByDate[$dateKey] = [
        'label' => $chartDate->format('M j'),
        'sales' => 0.0,
        'expenses' => 0.0,
    ];
    $chartDate = $chartDate->modify('+1 day');
}
$statement = $pdo->prepare(
    'SELECT DATE(created_at) AS report_day, COALESCE(SUM(total), 0) AS amount
     FROM sales WHERE created_at >= ? AND created_at < ? GROUP BY DATE(created_at)'
);
$statement->execute([$chartStart, $chartEnd]);
foreach ($statement->fetchAll() as $trendRow) {
    $trendByDate[$trendRow['report_day']]['sales'] = (float) $trendRow['amount'];
}
$statement = $pdo->prepare(
    'SELECT DATE(created_at) AS report_day, COALESCE(SUM(amount), 0) AS amount
     FROM expenses WHERE created_at >= ? AND created_at < ? GROUP BY DATE(created_at)'
);
$statement->execute([$chartStart, $chartEnd]);
foreach ($statement->fetchAll() as $trendRow) {
    $trendByDate[$trendRow['report_day']]['expenses'] = (float) $trendRow['amount'];
}
$chartMinimum = 0.0;
$chartMaximum = 0.0;
foreach ($trendByDate as $trendDay) {
    $net = $trendDay['sales'] - $trendDay['expenses'];
    $chartMinimum = min($chartMinimum, $net);
    $chartMaximum = max($chartMaximum, $trendDay['sales'], $trendDay['expenses'], $net);
}
$chartRange = max(1.0, $chartMaximum - $chartMinimum);
$chartMinimum -= $chartRange * 0.08;
$chartMaximum += $chartRange * 0.08;
$chartY = static fn(float $value): float => 28 + (($chartMaximum - $value) / ($chartMaximum - $chartMinimum) * 224);
$chartPoints = ['sales' => [], 'expenses' => [], 'net' => []];
foreach (array_values($trendByDate) as $dayIndex => $trendDay) {
    $x = 76 + ($dayIndex * 138);
    foreach ($chartPoints as $series => $_points) {
        $value = $series === 'net'
            ? $trendDay['sales'] - $trendDay['expenses']
            : $trendDay[$series];
        $chartPoints[$series][] = number_format($x, 1, '.', '') . ',' . number_format($chartY($value), 1, '.', '');
    }
}
$deductionSettings = $pdo->query(
    "SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('driver_commission_rate', 'pwd_discount_rate')"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$commissionRate = (float) ($deductionSettings['driver_commission_rate'] ?? 10.0);
$pwdDiscountRate = (float) ($deductionSettings['pwd_discount_rate'] ?? 20.0);
$statement = $pdo->prepare(
    'SELECT s.id, s.total, s.pwd_discount_amount, s.pwd_discount_rate, s.driver_name, s.driver_commission_rate, s.driver_commission, s.created_at, u.full_name AS cashier_name
     FROM sales s JOIN users u ON u.id = s.cashier_id
     WHERE s.created_at >= ? AND s.created_at < ? ORDER BY s.created_at DESC'
);
$statement->execute([$reportDate, $dayAfter]);
$dailySalesRows = $statement->fetchAll();
$statement = $pdo->prepare(
    'SELECT id, description, category, amount, rate_percent, created_at FROM expenses
     WHERE created_at >= ? AND created_at < ? ORDER BY created_at DESC'
);
$statement->execute([$reportDate, $dayAfter]);
$dailyExpenses = $statement->fetchAll();
$statement = $pdo->prepare(
    'SELECT category, SUM(amount) AS category_total
     FROM expenses WHERE created_at >= ? AND created_at < ?
     GROUP BY category ORDER BY category'
);
$statement->execute([$reportDate, $dayAfter]);
$expenseCategoryTotals = $statement->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin dashboard | La Guerta Kuzina</title>
    <link href="app.css" rel="stylesheet">
</head>
<body class="admin-dashboard-page">
<?php require __DIR__ . '/nav.php'; ?>
<main class="container-fluid admin-dashboard p-2">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h1 class="h3 mb-0">Admin dashboard</h1>
        <div class="d-flex align-items-center gap-2"><span class="small text-secondary dashboard-retention-note">Sales records are kept in the database until you remove them.</span><a class="btn btn-sm btn-primary" href="orders.php">Customer orders</a></div>
    </div>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
    <nav class="nav nav-tabs dashboard-tabs mb-3" aria-label="Dashboard sections">
        <?php foreach (['overview' => 'Overview', 'sales' => 'Sales history', 'expenses' => 'Expenses', 'settings' => 'Menu & staff'] as $view => $label): ?>
            <a class="nav-link<?= $dashboardView === $view ? ' active' : '' ?>"<?= $dashboardView === $view ? ' aria-current="page"' : '' ?> href="admin_dashboard.php?date=<?= h($reportDate) ?>&amp;view=<?= h($view) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($dashboardView === 'overview'): ?>
    <section class="dashboard-panel dashboard-overview-panel">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
            <div><h2 class="h4 mb-1">Today’s overview</h2><p class="text-secondary mb-0 dashboard-overview-help">Defaults to today. Use the date picker to view a previous day; older sales remain saved.</p></div>
            <form method="get" class="d-flex align-items-end gap-2 dashboard-date-form">
                <input type="hidden" name="view" value="overview">
                <div><label class="form-label mb-1 dashboard-date-label" for="report_date">Report date</label><input class="form-control" id="report_date" type="date" name="date" value="<?= h($reportDate) ?>" required></div>
                <button class="btn btn-primary" type="submit">View</button>
            </form>
        </div>
        <div class="row g-3">
            <div class="col-4 col-sm-6 col-md-4"><div class="card card-body h-100"><span class="text-secondary">Orders</span><strong class="fs-3"><?= (int) $dailySales['sale_count'] ?></strong></div></div>
            <div class="col-4 col-sm-6 col-md-4"><div class="card card-body h-100"><span class="text-secondary">Sales revenue</span><strong class="fs-3">₱<?= number_format((float) $dailySales['total'], 2) ?></strong></div></div>
            <div class="col-4 col-sm-6 col-md-4"><div class="card card-body h-100"><span class="text-secondary">Driver commissions (included in expenses)</span><strong class="fs-3">₱<?= number_format((float) $dailySales['commission'], 2) ?></strong></div></div>
            <div class="col-4 col-sm-6 col-md-4"><div class="card card-body h-100"><span class="text-secondary">Expenses deducted from sales</span><strong class="fs-3">−₱<?= number_format($dailyExpensesTotal, 2) ?></strong></div></div>
            <div class="col-4 col-sm-6 col-md-4"><div class="card card-body h-100"><span class="text-secondary">Sales after expenses</span><strong class="fs-3">₱<?= number_format($salesAfterExpenses, 2) ?></strong></div></div>
            <div class="col-12"><div class="card card-body border-success"><span class="text-secondary">Sales after expenses</span><strong class="fs-3 text-success">₱<?= number_format($dailyNet, 2) ?></strong><small class="text-secondary">Calculation: sales − recorded expenses. Driver commissions and PWD discounts are automatically recorded as expenses.</small></div></div>
        </div>
        <section class="card dashboard-analytics-card mt-4" aria-labelledby="analytics-title">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-2">
                    <div>
                        <h3 class="h5 mb-1" id="analytics-title">7-day analytics</h3>
                        <p class="small text-secondary mb-0">Daily sales revenue, expenses, and sales after expenses through <?= h($reportDate) ?>.</p>
                    </div>
                    <span class="small text-secondary"><?= h($chartStart) ?> – <?= h($reportDate) ?></span>
                </div>
                <div class="dashboard-chart-wrap">
                    <svg class="dashboard-line-chart" viewBox="0 0 960 300" role="img" aria-labelledby="sales-chart-title sales-chart-description">
                        <title id="sales-chart-title">Sales and expenses over seven days</title>
                        <desc id="sales-chart-description">A daily line graph comparing sales revenue, expenses, and sales after expenses.</desc>
                        <?php for ($gridIndex = 0; $gridIndex <= 4; $gridIndex++): ?>
                            <?php
                            $gridY = 28 + ($gridIndex * 56);
                            $gridValue = $chartMaximum - (($chartMaximum - $chartMinimum) * $gridIndex / 4);
                            ?>
                            <line x1="76" y1="<?= $gridY ?>" x2="904" y2="<?= $gridY ?>" class="dashboard-chart-grid"></line>
                            <text x="68" y="<?= $gridY + 4 ?>" text-anchor="end" class="dashboard-chart-axis-label">₱<?= h(number_format($gridValue, 0)) ?></text>
                        <?php endfor; ?>
                        <?php
                        $chartSeries = [
                            'sales' => ['label' => 'Sales revenue', 'class' => 'sales'],
                            'expenses' => ['label' => 'Expenses', 'class' => 'expenses'],
                            'net' => ['label' => 'Sales after expenses', 'class' => 'net'],
                        ];
                        foreach ($chartSeries as $series => $details):
                        ?>
                            <polyline class="dashboard-chart-line dashboard-chart-line-<?= h($details['class']) ?>" points="<?= h(implode(' ', $chartPoints[$series])) ?>"></polyline>
                            <?php foreach (array_values($trendByDate) as $dayIndex => $trendDay): ?>
                                <?php
                                $value = $series === 'net'
                                    ? $trendDay['sales'] - $trendDay['expenses']
                                    : $trendDay[$series];
                                $pointX = 76 + ($dayIndex * 138);
                                ?>
                                <circle class="dashboard-chart-point dashboard-chart-point-<?= h($details['class']) ?>" cx="<?= $pointX ?>" cy="<?= number_format($chartY($value), 1, '.', '') ?>" r="4">
                                    <title><?= h($trendDay['label'] . ' · ' . $details['label'] . ': ₱' . number_format($value, 2)) ?></title>
                                </circle>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <?php foreach (array_values($trendByDate) as $dayIndex => $trendDay): ?>
                            <text x="<?= 76 + ($dayIndex * 138) ?>" y="278" text-anchor="middle" class="dashboard-chart-axis-label"><?= h($trendDay['label']) ?></text>
                        <?php endforeach; ?>
                    </svg>
                </div>
                <div class="dashboard-chart-legend" aria-hidden="true">
                    <span><i class="dashboard-chart-swatch sales"></i>Sales revenue</span>
                    <span><i class="dashboard-chart-swatch expenses"></i>Expenses</span>
                    <span><i class="dashboard-chart-swatch net"></i>Sales after expenses</span>
                </div>
                <table class="visually-hidden">
                    <caption>Daily sales, expenses, and net sales for the seven-day analytics chart</caption>
                    <thead><tr><th>Date</th><th>Sales revenue</th><th>Expenses</th><th>Sales after expenses</th></tr></thead>
                    <tbody>
                    <?php foreach ($trendByDate as $dayDate => $trendDay): ?>
                        <tr>
                            <th scope="row"><?= h($dayDate) ?></th>
                            <td>₱<?= number_format($trendDay['sales'], 2) ?></td>
                            <td>₱<?= number_format($trendDay['expenses'], 2) ?></td>
                            <td>₱<?= number_format($trendDay['sales'] - $trendDay['expenses'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <p class="small text-secondary mt-3 mb-0 dashboard-overview-note">Sales for today will appear in today’s report. Tomorrow, the overview starts on the new date; prior sales remain available in Sales history.</p>
    </section>
    <?php elseif ($dashboardView === 'sales'): ?>
    <section class="dashboard-panel">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
            <div><h2 class="h4 mb-1">Sales history</h2><p class="text-secondary mb-0">Completed sales stay saved; select any date to review its receipts.</p></div>
            <form method="get" class="d-flex align-items-end gap-2">
                <input type="hidden" name="view" value="sales">
                <div><label class="form-label mb-1" for="sales_report_date">Sales date</label><input class="form-control" id="sales_report_date" type="date" name="date" value="<?= h($reportDate) ?>" required></div>
                <button class="btn btn-primary" type="submit">View</button>
            </form>
        </div>
        <div class="card dashboard-table-card"><div class="card-body">
            <h3 class="h5">Sales on <?= h($reportDate) ?></h3>
            <div class="table-responsive"><table class="table table-striped align-middle mb-0">
                <thead><tr><th>Receipt</th><th>Time</th><th>Cashier</th><th>Driver</th><th>Subtotal</th><th>PWD discount</th><th>Amount due</th><th>Commission</th></tr></thead><tbody>
                <?php foreach ($dailySalesRows as $sale): ?>
                    <tr><td><a href="receipt.php?id=<?= (int) $sale['id'] ?>">#<?= (int) $sale['id'] ?></a></td><td><?= h($sale['created_at']) ?></td>
                        <td><?= h($sale['cashier_name']) ?></td><td><?= h($sale['driver_name'] ?? '—') ?></td>
                        <td>₱<?= number_format((float) $sale['total'], 2) ?></td>
                        <td>−₱<?= number_format((float) $sale['pwd_discount_amount'], 2) ?> (<?= number_format((float) $sale['pwd_discount_rate'], 2) ?>%)</td>
                        <td>₱<?= number_format((float) $sale['total'] - (float) $sale['pwd_discount_amount'], 2) ?></td>
                        <td>₱<?= number_format((float) $sale['driver_commission'], 2) ?> (<?= number_format((float) $sale['driver_commission_rate'], 2) ?>%)</td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$dailySalesRows): ?><tr><td colspan="8" class="text-secondary">No sales recorded for this date.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div></div>
    </section>
    <?php elseif ($dashboardView === 'settings'): ?>
    <section class="dashboard-panel">
    <div class="row g-4">
        <section class="col-lg-6">
            <div class="card h-100"><div class="card-body">
                <h2 class="h4">Create administrator account</h2>
                <form method="post" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="create-user"><input type="hidden" name="report_date" value="<?= h($reportDate) ?>"><input type="hidden" name="dashboard_view" value="settings">
                    <div class="col-md-6"><label class="form-label" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" maxlength="120" required></div>
                    <div class="col-md-6"><label class="form-label" for="username">Username</label><input class="form-control" id="username" name="username" maxlength="80" required></div>
                    <input type="hidden" id="role" name="role" value="admin">
                    <div class="col-md-6"><label class="form-label" for="new_password">Temporary password</label><input class="form-control" id="new_password" name="password" type="password" minlength="10" required></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Create administrator account</button></div>
                </form>
            </div></div>
        </section>
        <section class="col-lg-6">
            <div class="card h-100"><div class="card-body">
                <h2 class="h4">Add menu item</h2>
                <form method="post" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="create-item"><input type="hidden" name="report_date" value="<?= h($reportDate) ?>"><input type="hidden" name="dashboard_view" value="settings">
                    <div class="col-md-6"><label class="form-label" for="item_name">Item name</label><input class="form-control" id="item_name" name="name" maxlength="120" required></div>
                    <div class="col-md-6"><label class="form-label" for="category">Category</label><input class="form-control" id="category" name="category" maxlength="60" required></div>
                    <div class="col-md-6"><label class="form-label" for="item_type">Menu section</label><select class="form-select" id="item_type" name="item_type" required><option value="food">Food</option><option value="drink">Drinks</option></select></div>
                    <div class="col-md-6"><label class="form-label" for="price">Price (₱)</label><input class="form-control" id="price" name="price" type="number" min="0.01" step="0.01" required></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Add item</button></div>
                </form>
            </div></div>
        </section>
    </div>
    <section id="menu-management" class="mt-4">
        <h2 class="h4">Menu items</h2>
        <p class="text-secondary">Set prices here to update the public customer menu and the cashier’s current menu price list. Existing customer orders keep the price recorded when submitted.</p>
        <div class="table-responsive"><table class="table table-striped align-middle bg-white">
            <thead><tr><th>Item</th><th>Section</th><th>Category</th><th>Price (₱)</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            <?php foreach ($menuItems as $item): ?><tr>
                <td><?= h($item['name']) ?></td><td><?= $item['item_type'] === 'drink' ? 'Drinks' : 'Food' ?></td><td><?= h($item['category']) ?></td>
                <td>
                    <form method="post" class="d-flex align-items-center gap-2 menu-price-form">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="update-item-price"><input type="hidden" name="report_date" value="<?= h($reportDate) ?>"><input type="hidden" name="dashboard_view" value="settings"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                        <label class="visually-hidden" for="menu-price-<?= (int) $item['id'] ?>">Price for <?= h($item['name']) ?></label>
                        <input class="form-control form-control-sm" id="menu-price-<?= (int) $item['id'] ?>" name="price" type="number" min="0.01" max="99999999.99" step="0.01" value="<?= h(number_format((float) $item['price'], 2, '.', '')) ?>" required>
                        <button class="btn btn-sm btn-primary text-nowrap" type="submit">Save price</button>
                    </form>
                </td>
                <td><?= (int) $item['is_active'] === 1 ? 'Available' : 'Unavailable' ?></td><td>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="toggle-item"><input type="hidden" name="report_date" value="<?= h($reportDate) ?>"><input type="hidden" name="dashboard_view" value="settings"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                        <button class="btn btn-sm btn-outline-secondary text-nowrap" type="submit"><?= (int) $item['is_active'] === 1 ? 'Set unavailable' : 'Make available' ?></button></form>
                </td>
            </tr><?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <section id="staff" class="mt-4">
        <h2 class="h4">Staff accounts</h2>
        <p class="text-secondary">Only administrator accounts can sign in. Existing cashier profiles remain for historical sales records.</p>
        <div class="table-responsive"><table class="table table-striped align-middle bg-white">
            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            <?php foreach ($users as $account): ?><tr>
                <td><?= h($account['full_name']) ?></td><td><?= h($account['username']) ?></td><td><?= h(ucfirst($account['role'])) ?></td><td><?= $account['role'] === 'cashier' ? 'Historical account · sign-in disabled' : ((int) $account['is_active'] === 1 ? 'Active' : 'Disabled') ?></td><td>
                    <?php if ((int) $account['id'] !== (int) $_SESSION['user_id']): ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if ($account['role'] === 'admin'): ?>
                                <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="toggle-user"><input type="hidden" name="report_date" value="<?= h($reportDate) ?>"><input type="hidden" name="dashboard_view" value="settings"><input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= (int) $account['is_active'] === 1 ? 'Disable' : 'Enable' ?></button></form>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-danger" type="button" data-delete-staff data-user-id="<?= (int) $account['id'] ?>" data-user-name="<?= h($account['full_name']) ?>" data-user-role="<?= h(ucfirst($account['role'])) ?>">Delete</button>
                        </div>
                    <?php else: ?>Current account<?php endif; ?>
                </td>
            </tr><?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <div class="row g-4 mt-1">
    <section id="commission" class="col-lg-6">
        <div class="card h-100"><div class="card-body">
            <h2 class="h4">Driver commission</h2>
            <p class="text-secondary">Commission is calculated on the amount due after any PWD discount when a cashier enters a driver name. It is automatically recorded under Driver Commission expenses. This rate applies to new orders only.</p>
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="set-commission-rate"><input type="hidden" name="report_date" value="<?= h($reportDate) ?>"><input type="hidden" name="dashboard_view" value="settings">
                <div class="col-auto"><label class="form-label" for="commission_rate">Commission rate (%)</label><input class="form-control" id="commission_rate" name="commission_rate" type="number" min="0" max="100" step="0.01" value="<?= h(number_format($commissionRate, 2, '.', '')) ?>" required></div>
                <div class="col-auto"><button class="btn btn-primary" type="submit">Save rate</button></div>
            </form>
        </div></div>
    </section>
    <section id="pwd-discount-rate" class="col-lg-6">
        <div class="card h-100"><div class="card-body">
            <h2 class="h4">PWD discount</h2>
            <p class="text-secondary">Apply the discount at staff checkout after verifying the PWD ID. It is recorded under PWD Discount expenses. This rate applies to new orders only.</p>
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="set-pwd-discount-rate"><input type="hidden" name="report_date" value="<?= h($reportDate) ?>"><input type="hidden" name="dashboard_view" value="settings">
                <div class="col-auto"><label class="form-label" for="pwd_discount_rate">PWD discount rate (%)</label><input class="form-control" id="pwd_discount_rate" name="pwd_discount_rate" type="number" min="0" max="100" step="0.01" value="<?= h(number_format($pwdDiscountRate, 2, '.', '')) ?>" required></div>
                <div class="col-auto"><button class="btn btn-primary" type="submit">Save rate</button></div>
            </form>
        </div></div>
    </section>
    </div>
    <?php elseif ($dashboardView === 'expenses'): ?>
    <section id="expenses" class="dashboard-panel">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
            <div><h2 class="h4 mb-1">Expense history</h2><p class="text-secondary mb-0">Review categorized expenses and the amount deducted from sales.</p></div>
            <form method="get" class="d-flex align-items-end gap-2">
                <input type="hidden" name="view" value="expenses">
                <div><label class="form-label mb-1" for="expense_report_date">Expense date</label><input class="form-control" id="expense_report_date" type="date" name="date" value="<?= h($reportDate) ?>" required></div>
                <button class="btn btn-primary" type="submit">View</button>
            </form>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-12"><div class="card border-warning"><div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div><h2 class="h5 mb-1">Daily expenses deducted from sales</h2><p class="text-secondary mb-0">Total expenses for <?= h($reportDate) ?> are subtracted from that day’s sales balance.</p></div>
                <strong class="fs-3 text-warning-emphasis">−₱<?= number_format($dailyExpensesTotal, 2) ?></strong>
            </div></div></div>
        </div>
        <div class="row g-4">
            <div class="col-lg-5"><div class="card h-100"><div class="card-body">
                <h2 class="h4">Record an expense</h2>
                <p class="text-secondary">New expenses are recorded for today and included in that day's report.</p>
                <form method="post" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="add-expense"><input type="hidden" name="report_date" value="<?= h($reportDate) ?>"><input type="hidden" name="dashboard_view" value="expenses">
                    <div class="col-12"><label class="form-label" for="expense_description">Description</label><input class="form-control" id="expense_description" name="description" maxlength="160" placeholder="e.g. ingredients or supplies" required></div>
                    <div class="col-md-6"><label class="form-label" for="expense_category">Category</label><input class="form-control" id="expense_category" name="category" maxlength="60" required></div>
                    <div class="col-md-6"><label class="form-label" for="expense_amount">Amount (₱)</label><input class="form-control" id="expense_amount" name="amount" type="number" min="0.01" max="99999999.99" step="0.01" required></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Record expense</button></div>
                </form>
            </div></div></div>
            <div class="col-lg-7"><div class="card h-100"><div class="card-body">
                <h2 class="h4">Expense history on <?= h($reportDate) ?></h2>
                <div class="table-responsive"><table class="table table-striped align-middle mb-0">
                    <thead><tr><th>Time</th><th>Description</th><th>Category</th><th>Rate</th><th>Amount</th></tr></thead><tbody>
                    <?php foreach ($dailyExpenses as $expense): ?><tr>
                        <td><?= h($expense['created_at']) ?></td><td><?= h($expense['description']) ?></td><td><?= h($expense['category']) ?></td><td><?= $expense['rate_percent'] === null ? '—' : number_format((float) $expense['rate_percent'], 2) . '%' ?></td><td>₱<?= number_format((float) $expense['amount'], 2) ?></td>
                    </tr><?php endforeach; ?>
                    <?php if (!$dailyExpenses): ?><tr><td colspan="5" class="text-secondary">No expenses recorded for this date.</td></tr><?php endif; ?>
                    </tbody>
                    <tfoot><tr class="fw-bold"><td colspan="4">Total expenses deducted</td><td>₱<?= number_format($dailyExpensesTotal, 2) ?></td></tr></tfoot>
                </table></div>
            </div></div></div>
        </div>
        <div class="card mt-3"><div class="card-body">
            <h2 class="h5">Total by expense category</h2>
            <div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0">
                <thead><tr><th>Category</th><th class="text-end">Total deducted</th></tr></thead><tbody>
                <?php foreach ($expenseCategoryTotals as $categoryTotal): ?>
                    <tr><td><?= h($categoryTotal['category']) ?></td><td class="text-end">₱<?= number_format((float) $categoryTotal['category_total'], 2) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$expenseCategoryTotals): ?><tr><td colspan="2" class="text-secondary">No expense categories for this date.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div></div>
    </section>
    <?php endif; ?>
</main>
<?php if ($dashboardView === 'settings'): ?>
<dialog class="order-confirm-dialog" id="delete-staff-dialog" aria-labelledby="delete-staff-title">
    <form method="post" id="delete-staff-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="permanent-delete-user">
        <input type="hidden" name="user_id" id="delete-staff-user-id" value="">
        <input type="hidden" name="report_date" value="<?= h($reportDate) ?>">
        <input type="hidden" name="dashboard_view" value="settings">
        <h2 class="h4" id="delete-staff-title">Permanently delete staff account?</h2>
        <p><strong id="delete-staff-user-label"></strong></p>
        <p class="text-secondary">This cannot be undone. Accounts linked to sales or expenses cannot be permanently deleted because their history must be preserved. You can disable those accounts instead.</p>
        <div class="d-flex justify-content-end gap-2">
            <button class="btn btn-outline-secondary" type="button" data-close-dialog>Keep account</button>
            <button class="btn btn-danger" type="submit">Permanently delete</button>
        </div>
    </form>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('delete-staff-dialog');
    const userId = document.getElementById('delete-staff-user-id');
    const userLabel = document.getElementById('delete-staff-user-label');

    document.querySelectorAll('[data-delete-staff]').forEach((button) => {
        button.addEventListener('click', () => {
            userId.value = button.dataset.userId;
            userLabel.textContent = `${button.dataset.userName} (${button.dataset.userRole})`;
            dialog.showModal();
        });
    });

    dialog.querySelector('[data-close-dialog]').addEventListener('click', () => dialog.close());
})();
</script>
<?php endif; ?>
<?php if ($dashboardView === 'overview'): ?>
<script>
    const nextBusinessDay = <?= (new DateTimeImmutable('tomorrow'))->setTime(0, 0)->getTimestamp() * 1000 ?>;
    window.setTimeout(() => {
        const dashboardUrl = new URL(window.location.href);
        dashboardUrl.searchParams.delete('date');
        window.location.replace(dashboardUrl);
    }, Math.max(0, nextBusinessDay - Date.now()));
</script>
<?php endif; ?>
</body>
</html>
