<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_role('admin');
require_once __DIR__ . '/config.php';

$error = '';
$notice = (string) ($_SESSION['orders_notice'] ?? '');
unset($_SESSION['orders_notice']);
$serviceLabels = [
    'dine_in' => 'Dine in',
    'takeout' => 'Take out',
    'advance_booking' => 'Advance booking',
    'catering' => 'Catering',
];
$statusLabels = [
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'preparing' => 'Preparing',
    'ready' => 'Ready for pickup/service',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];
$nextStatuses = [
    'pending' => ['confirmed', 'cancelled'],
    'confirmed' => ['preparing', 'cancelled'],
    'preparing' => ['ready', 'cancelled'],
    'ready' => ['completed', 'cancelled'],
    'completed' => [],
    'cancelled' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'update';
    $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$orderId || $orderId < 1 || !is_string($action)) {
        $error = 'Invalid order request.';
    } elseif (in_array($action, ['trash', 'restore', 'permanent-delete'], true)) {
        if ($action === 'permanent-delete' && $_SESSION['role'] !== 'admin') {
            http_response_code(403);
            exit('Only the owner can permanently delete orders.');
        }

        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT id, deleted_at FROM customer_orders WHERE id = ? FOR UPDATE');
            $statement->execute([$orderId]);
            $order = $statement->fetch();
            if (!$order) {
                $error = 'Order not found.';
            } elseif ($action === 'trash' && $order['deleted_at'] !== null) {
                $error = 'This order is already in the bin.';
            } elseif ($action === 'restore' && $order['deleted_at'] === null) {
                $error = 'This order is not in the bin.';
            } elseif ($action === 'permanent-delete' && $order['deleted_at'] === null) {
                $error = 'Move this order to the bin before permanently deleting it.';
            } elseif ($action === 'trash') {
                $statement = $pdo->prepare('UPDATE customer_orders SET deleted_at = NOW() WHERE id = ?');
                $statement->execute([$orderId]);
                $notice = 'Order #' . $orderId . ' moved to the bin.';
            } elseif ($action === 'restore') {
                $statement = $pdo->prepare('UPDATE customer_orders SET deleted_at = NULL WHERE id = ?');
                $statement->execute([$orderId]);
                $notice = 'Order #' . $orderId . ' restored.';
            } else {
                $statement = $pdo->prepare('DELETE FROM customer_orders WHERE id = ? AND deleted_at IS NOT NULL');
                $statement->execute([$orderId]);
                if ($statement->rowCount() !== 1) {
                    throw new RuntimeException('The order could not be permanently deleted.');
                }
                $notice = 'Order #' . $orderId . ' permanently deleted. Its sale record, if any, was kept.';
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        if ($notice !== '') {
            $_SESSION['orders_notice'] = $notice;
            header('Location: orders.php' . ($action === 'trash' ? '' : '?view=trash'));
            exit;
        }
    } elseif ($action === 'update') {
        $newStatus = $_POST['status'] ?? '';
        $staffNotes = trim((string) ($_POST['staff_notes'] ?? ''));
        $pwdDiscountInput = $_POST['pwd_discount'] ?? '';
        $hasPwdDiscount = $pwdDiscountInput === '1';
        if (strlen($staffNotes) > 500 || ($pwdDiscountInput !== '' && !$hasPwdDiscount)) {
            $error = 'Invalid order update.';
        } else {
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare('SELECT * FROM customer_orders WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
                $statement->execute([$orderId]);
                $order = $statement->fetch();
                if (!$order) {
                    $error = 'Order not found.';
                } elseif (!in_array($newStatus, $nextStatuses[$order['status']] ?? [], true)) {
                    $error = 'That order status change is not allowed.';
                } else {
                    $saleId = $order['sale_id'] === null ? null : (int) $order['sale_id'];
                    if ($newStatus === 'completed') {
                        if ($saleId !== null) {
                            throw new RuntimeException('This customer order is already linked to a sale.');
                        }
                        $subtotalCents = (int) round((float) $order['total'] * 100);
                        $pwdDiscountRate = (float) $pdo->query(
                            "SELECT setting_value FROM app_settings WHERE setting_key = 'pwd_discount_rate'"
                        )->fetchColumn();
                        $pwdDiscountCents = $hasPwdDiscount ? (int) round($subtotalCents * $pwdDiscountRate / 100) : 0;
                        $pwdDiscount = number_format($pwdDiscountCents / 100, 2, '.', '');
                        $statement = $pdo->prepare(
                            'INSERT INTO sales (cashier_id, total, pwd_discount_amount, pwd_discount_rate, driver_name, driver_commission_rate, driver_commission) VALUES (?, ?, ?, ?, NULL, 0, 0)'
                        );
                        $statement->execute([
                            (int) $_SESSION['user_id'],
                            $order['total'],
                            $pwdDiscount,
                            $hasPwdDiscount ? number_format($pwdDiscountRate, 2, '.', '') : 0,
                        ]);
                        $saleId = (int) $pdo->lastInsertId();
                        if ($pwdDiscountCents > 0) {
                            $statement = $pdo->prepare(
                                "INSERT INTO expenses (created_by, description, category, amount, rate_percent) VALUES (?, ?, 'PWD Discount', ?, ?)"
                            );
                            $statement->execute([
                                (int) $_SESSION['user_id'],
                                'PWD discount on sale #' . $saleId,
                                $pwdDiscount,
                                number_format($pwdDiscountRate, 2, '.', ''),
                            ]);
                        }

                        $statement = $pdo->prepare(
                            'INSERT INTO sale_items (sale_id, menu_item_id, item_name, quantity, unit_price)
                             SELECT ?, menu_item_id, item_name, quantity, unit_price
                             FROM customer_order_items WHERE order_id = ?'
                        );
                        $statement->execute([$saleId, $orderId]);
                    }

                    $statement = $pdo->prepare(
                        'UPDATE customer_orders SET status = ?, staff_notes = ?, sale_id = ? WHERE id = ?'
                    );
                    $statement->execute([
                        $newStatus,
                        $staffNotes === '' ? null : $staffNotes,
                        $saleId,
                        $orderId,
                    ]);
                    $notice = 'Order #' . $orderId . ' updated to ' . ($statusLabels[$newStatus] ?? $newStatus) . '.';
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            if ($notice !== '') {
                $_SESSION['orders_notice'] = $notice;
                header('Location: orders.php');
                exit;
            }
        }
    } else {
        $error = 'Unknown order action.';
    }
}

$viewValue = $_GET['view'] ?? 'active';
$view = is_string($viewValue) && $viewValue === 'trash' ? 'trash' : 'active';
$isTrash = $view === 'trash';
$trashedOrderCount = (int) $pdo->query('SELECT COUNT(*) FROM customer_orders WHERE deleted_at IS NOT NULL')->fetchColumn();
$currentMenuItems = $pdo->query(
    'SELECT name, category, item_type, price FROM menu_items WHERE is_active = 1 ORDER BY item_type, category, name'
)->fetchAll();
$pwdDiscountRate = (float) $pdo->query(
    "SELECT setting_value FROM app_settings WHERE setting_key = 'pwd_discount_rate'"
)->fetchColumn();
$orders = $pdo->query(
    'SELECT o.*, u.full_name AS customer_name, u.phone_number AS customer_phone, cashier.full_name AS cashier_name
     FROM customer_orders o
     JOIN users u ON u.id = o.customer_id
     LEFT JOIN sales s ON s.id = o.sale_id
     LEFT JOIN users cashier ON cashier.id = s.cashier_id
     WHERE o.deleted_at IS ' . ($isTrash ? 'NOT NULL' : 'NULL') . '
     ORDER BY CASE o.status
         WHEN \'pending\' THEN 0 WHEN \'confirmed\' THEN 1 WHEN \'preparing\' THEN 2
         WHEN \'ready\' THEN 3 WHEN \'completed\' THEN 4 ELSE 5 END,
         o.created_at DESC
     LIMIT 200'
)->fetchAll();

$orderItems = [];
if ($orders) {
    $orderIds = array_map(static fn(array $order): int => (int) $order['id'], $orders);
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $statement = $pdo->prepare(
        "SELECT order_id, item_name, quantity, unit_price FROM customer_order_items WHERE order_id IN ($placeholders) ORDER BY id"
    );
    $statement->execute($orderIds);
    foreach ($statement->fetchAll() as $item) {
        $orderItems[(int) $item['order_id']][] = $item;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customer orders | La Guerta Kuzina</title>
    <link href="app.css" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/nav.php'; ?>
<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><h1 class="h2 mb-1"><?= $isTrash ? 'Deleted orders' : 'Customer orders' ?></h1><p class="text-secondary mb-0"><?= $isTrash ? 'Restore an order or permanently delete it.' : 'Review menu items, estimated totals, service type, and allergy/additional notes. Deleted orders move to the bin first.' ?></p></div>
        <?php if ($_SESSION['role'] === 'admin'): ?><a class="btn btn-outline-primary" href="admin_dashboard.php">Back to dashboard</a><?php endif; ?>
    </div>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
    <nav class="nav nav-pills order-view-tabs mb-3" aria-label="Order views">
        <a class="nav-link order-view-link<?= !$isTrash ? ' active' : '' ?>" href="orders.php"<?= !$isTrash ? ' aria-current="page"' : '' ?>>Active orders</a>
        <a class="nav-link order-view-link<?= $isTrash ? ' active' : '' ?>" href="orders.php?view=trash"<?= $isTrash ? ' aria-current="page"' : '' ?>>Bin <span class="badge order-view-count ms-1"><?= $trashedOrderCount ?></span></a>
    </nav>
    <details class="card mb-3 cashier-current-menu">
        <summary class="card-body fw-semibold">Current menu prices <span class="text-secondary fw-normal">(<?= count($currentMenuItems) ?> available items)</span></summary>
        <div class="table-responsive px-3 pb-3">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead><tr><th>Item</th><th>Category</th><th>Section</th><th class="text-end">Current price</th></tr></thead>
                <tbody>
                <?php foreach ($currentMenuItems as $menuItem): ?>
                    <tr>
                        <td><?= h($menuItem['name']) ?></td>
                        <td><?= h($menuItem['category']) ?></td>
                        <td><?= $menuItem['item_type'] === 'drink' ? 'Drinks' : 'Food' ?></td>
                        <td class="text-end">₱<?= number_format((float) $menuItem['price'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$currentMenuItems): ?><tr><td colspan="4" class="text-secondary">No available menu items.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <p class="small text-secondary mt-2 mb-0">Prices match the public menu. Reload this page to see price changes made by the owner; existing customer orders keep their submitted prices.</p>
        </div>
    </details>
    <?php if (!$orders): ?>
        <div class="alert alert-info"><?= $isTrash ? 'The deleted orders bin is empty.' : 'No customer orders have been submitted yet.' ?></div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($orders as $order): ?>
                <div class="col-12 col-xl-6"><article class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div><h2 class="h5 mb-1">Order #<?= (int) $order['id'] ?> · <?= h($order['customer_name']) ?></h2><span class="badge text-bg-<?= $order['status'] === 'pending' ? 'warning' : ($order['status'] === 'completed' ? 'success' : 'secondary') ?>"><?= h($statusLabels[$order['status']] ?? $order['status']) ?></span></div>
                            <strong class="fs-5">₱<?= number_format((float) $order['total'], 2) ?></strong>
                        </div>
                        <p class="small text-secondary mt-2 mb-2">
                            <?= h($serviceLabels[$order['service_type']] ?? $order['service_type']) ?> · Submitted <?= h($order['created_at']) ?>
                            <?php if ($order['event_at']): ?><br>Booking: <?= h($order['event_at']) ?> · <?= (int) $order['party_size'] ?> people<?php endif; ?>
                            <br>Contact: <?= h($order['customer_phone'] ?? 'Not provided') ?>
                        </p>
                        <ul class="mb-2"><?php foreach ($orderItems[(int) $order['id']] ?? [] as $item): ?><li><?= (int) $item['quantity'] ?> × <?= h($item['item_name']) ?> · ₱<?= number_format((float) $item['unit_price'] * (int) $item['quantity'], 2) ?></li><?php endforeach; ?></ul>
                        <?php if ($order['customer_notes']): ?><div class="alert alert-warning py-2"><strong>Allergy/additional request:</strong><br><?= nl2br(h($order['customer_notes'])) ?></div><?php endif; ?>
                        <?php if ($order['staff_notes']): ?><p><strong>Staff note:</strong> <?= nl2br(h($order['staff_notes'])) ?></p><?php endif; ?>
                        <?php if ($order['sale_id']): ?><a href="receipt.php?id=<?= (int) $order['sale_id'] ?>" class="btn btn-sm btn-outline-primary">View receipt #<?= (int) $order['sale_id'] ?></a><?php endif; ?>
                        <?php if ($isTrash): ?>
                            <div class="d-flex flex-wrap gap-2 border-top mt-3 pt-3">
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <button class="btn btn-sm btn-outline-primary" type="submit">Restore order</button>
                                </form>
                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                    <button class="btn btn-sm btn-outline-danger" type="button" data-permanent-delete data-order-id="<?= (int) $order['id'] ?>" data-order-label="Order #<?= (int) $order['id'] ?> for <?= h($order['customer_name']) ?>">Delete permanently</button>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php $availableStatuses = $nextStatuses[$order['status']] ?? []; ?>
                            <?php if ($availableStatuses): ?>
                                <form method="post" class="border-top mt-3 pt-3" data-order-status-form>
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-5"><label class="form-label" for="status-<?= (int) $order['id'] ?>">Update status</label><select class="form-select" id="status-<?= (int) $order['id'] ?>" name="status" required>
                                            <?php foreach ($availableStatuses as $status): ?><option value="<?= h($status) ?>"><?= h($statusLabels[$status]) ?></option><?php endforeach; ?>
                                        </select></div>
                                        <div class="col-md-5"><label class="form-label" for="staff-note-<?= (int) $order['id'] ?>">Staff note (optional)</label><input class="form-control" id="staff-note-<?= (int) $order['id'] ?>" name="staff_notes" maxlength="500" value="<?= h($order['staff_notes'] ?? '') ?>"></div>
                                        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Save</button></div>
                                    </div>
                                    <?php if (in_array('completed', $availableStatuses, true)): ?>
                                        <div class="form-check mt-2"><input class="form-check-input" id="pwd-discount-<?= (int) $order['id'] ?>" name="pwd_discount" type="checkbox" value="1"><label class="form-check-label" for="pwd-discount-<?= (int) $order['id'] ?>">Apply PWD discount (<?= number_format($pwdDiscountRate, 2) ?>%; verify ID)</label></div>
                                        <small class="text-secondary d-block mt-2">Completing the order saves it as a sale for the daily sales report and creates its receipt.</small>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                            <form method="post" class="mt-3">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="trash">
                                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete order</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<?php if ($isTrash && $_SESSION['role'] === 'admin'): ?>
<dialog class="order-confirm-dialog" id="permanent-delete-dialog" aria-labelledby="permanent-delete-title">
    <form method="post" id="permanent-delete-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="permanent-delete">
        <input type="hidden" name="order_id" id="permanent-delete-order-id" value="">
        <h2 class="h4" id="permanent-delete-title">Permanently delete this order?</h2>
        <p><strong id="permanent-delete-order-label"></strong></p>
        <p class="text-secondary">This cannot be undone. The order and its item details will be removed. Any related sale record will be kept.</p>
        <div class="d-flex justify-content-end gap-2">
            <button class="btn btn-outline-secondary" type="button" data-close-dialog>Keep order</button>
            <button class="btn btn-danger" type="submit">Permanently delete</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<dialog class="order-confirm-dialog" id="cancel-order-dialog" aria-labelledby="cancel-order-title">
    <div>
        <h2 class="h4" id="cancel-order-title">Cancel this customer order?</h2>
        <p class="text-secondary">The order will be marked as cancelled. You can still find it in Active orders.</p>
        <div class="d-flex justify-content-end gap-2">
            <button class="btn btn-outline-secondary" type="button" data-close-dialog>Keep order</button>
            <button class="btn btn-danger" id="confirm-cancel-order" type="button">Cancel order</button>
        </div>
    </div>
</dialog>
<script>
(() => {
    const permanentDialog = document.getElementById('permanent-delete-dialog');
    const cancelDialog = document.getElementById('cancel-order-dialog');
    const closeButtons = document.querySelectorAll('[data-close-dialog]');

    closeButtons.forEach((button) => {
        button.addEventListener('click', () => button.closest('dialog').close());
    });

    if (permanentDialog) {
        const orderIdInput = document.getElementById('permanent-delete-order-id');
        const orderLabel = document.getElementById('permanent-delete-order-label');
        document.querySelectorAll('[data-permanent-delete]').forEach((button) => {
            button.addEventListener('click', () => {
                orderIdInput.value = button.dataset.orderId;
                orderLabel.textContent = button.dataset.orderLabel;
                permanentDialog.showModal();
            });
        });
    }

    let pendingCancellationForm = null;
    document.querySelectorAll('[data-order-status-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const status = form.querySelector('select[name="status"]');
            if (status.value === 'cancelled' && form.dataset.cancellationConfirmed !== 'true') {
                event.preventDefault();
                pendingCancellationForm = form;
                cancelDialog.showModal();
            }
        });
    });

    document.getElementById('confirm-cancel-order').addEventListener('click', () => {
        if (!pendingCancellationForm) return;
        const form = pendingCancellationForm;
        pendingCancellationForm = null;
        form.dataset.cancellationConfirmed = 'true';
        cancelDialog.close();
        form.requestSubmit();
    });
})();
</script>
</body>
</html>
