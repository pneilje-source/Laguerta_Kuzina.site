<?php
$currentRole = $_SESSION['role'] ?? '';
$roleLabels = ['admin' => 'Administrator', 'cashier' => 'Cashier', 'customer' => 'Customer'];
$homeHref = $currentRole === 'admin'
    ? 'admin_dashboard.php'
    : ($currentRole === 'cashier' ? 'staff_login.php' : 'menu.php');
$navContainerClass = $currentRole === 'customer' ? 'container-fluid px-2 px-md-3' : 'container';
$navLinksClass = $currentRole === 'customer'
    ? 'd-flex align-items-center gap-2 text-white flex-nowrap'
    : 'd-flex align-items-center gap-3 text-white';
$navNameClass = $currentRole === 'customer' ? 'small text-truncate' : '';
$roleLabelClass = $currentRole === 'customer' ? 'text-white-50 d-none d-md-inline' : 'text-white-50';
$orderNotificationCursor = $currentRole === 'admin'
    ? (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM customer_orders')->fetchColumn()
    : 0;
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark storefront-navbar<?= $currentRole === 'customer' ? ' flex-shrink-0' : '' ?>">
    <div class="<?= $navContainerClass ?>">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= h($homeHref) ?>">
            <img class="storefront-brand-mark" src="Image/laguerta.jpg" alt="">
            <span>La Guerta <span class="storefront-brand-light">Kuzina</span></span>
        </a>
        <div class="<?= $navLinksClass ?>">
            <a class="btn btn-sm btn-outline-light" href="<?= h($homeHref) ?>">Home</a>
            <?php if ($currentRole !== ''): ?>
                <span class="<?= $navNameClass ?>"><?= h($_SESSION['full_name'] ?? '') ?> <span class="<?= $roleLabelClass ?>">(<?= h($roleLabels[$currentRole]) ?>)</span></span>
            <?php endif; ?>
            <?php if ($currentRole === 'admin'): ?>
                <a class="btn btn-sm btn-outline-light" href="orders.php" id="customer-orders-link">Customer orders <span class="badge text-bg-warning ms-1" id="customer-orders-count" hidden></span></a>
            <?php endif; ?>
            <?php if ($currentRole === 'customer'): ?>
                <a class="btn btn-sm btn-outline-light" href="feedback.php">Ratings &amp; reviews</a>
                <a class="btn btn-sm btn-outline-light" href="staff_login.php">Staff sign in</a>
            <?php elseif ($currentRole === ''): ?>
                <a class="btn btn-sm btn-outline-light" href="feedback.php">Ratings &amp; reviews</a>
                <a class="btn btn-sm btn-outline-light" href="staff_login.php">Staff sign in</a>
            <?php else: ?>
                <a class="btn btn-sm btn-outline-light" href="feedback.php">Ratings &amp; reviews</a>
            <?php endif; ?>
            <?php if (isset($_SESSION['user_id'])): ?>
                <form method="post" action="logout.php" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <button class="btn btn-sm btn-warning" type="submit">Sign out</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</nav>
<?php if ($currentRole === 'admin'): ?>
<div class="container order-notification-container" aria-live="polite">
    <div id="order-notification" class="alert alert-info shadow-sm order-notification" role="status" hidden>
        <div>
            <strong>New customer order</strong>
            <p class="mb-2 mt-1" id="order-notification-message"></p>
            <a class="alert-link" href="orders.php">Review customer orders</a>
        </div>
        <button type="button" class="btn-close" id="dismiss-order-notification" aria-label="Dismiss notification"></button>
    </div>
</div>
<script>
(() => {
    const countBadge = document.getElementById('customer-orders-count');
    const notification = document.getElementById('order-notification');
    const notificationMessage = document.getElementById('order-notification-message');
    const dismissButton = document.getElementById('dismiss-order-notification');
    let orderCursor = <?= $orderNotificationCursor ?>;
    let polling = false;

    if (window.location.pathname.endsWith('/orders.php')) {
        window.addEventListener('customer-order-received', () => {
            window.setTimeout(() => window.location.reload(), 1200);
        });
    }

    dismissButton.addEventListener('click', () => {
        notification.hidden = true;
    });

    const checkOrders = async () => {
        if (polling) return;
        polling = true;
        try {
            const url = new URL('order_notifications.php', window.location.href);
            url.searchParams.set('after_id', String(orderCursor));
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) throw new Error(`Order notifications returned HTTP ${response.status}`);
            const data = await response.json();
            if (!Number.isInteger(data.pending_count) || !Number.isInteger(data.latest_id) || !Array.isArray(data.new_orders)) {
                throw new Error('Invalid order notification response.');
            }

            countBadge.textContent = data.pending_count > 99 ? '99+' : String(data.pending_count);
            countBadge.hidden = data.pending_count === 0;
            if (data.new_orders.length > 0) {
                const summaries = data.new_orders.map(order =>
                    `#${order.id} from ${order.customer_name} (₱${Number(order.total).toFixed(2)})`
                );
                notificationMessage.textContent = summaries.join(', ');
                notification.hidden = false;
                window.dispatchEvent(new CustomEvent('customer-order-received', { detail: data.new_orders }));
            }
            orderCursor = data.latest_id;
        } catch (error) {
            console.error('Unable to check for customer orders.', error);
        } finally {
            polling = false;
        }
    };

    checkOrders();
    window.setInterval(checkOrders, 10000);
})();
</script>
<?php endif; ?>