<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

$isCustomer = ($_SESSION['role'] ?? '') === 'customer' && isset($_SESSION['user_id']);
$customerNameInput = is_string($_POST['customer_name'] ?? null)
    ? trim($_POST['customer_name'])
    : ($isCustomer ? (string) ($_SESSION['full_name'] ?? '') : '');
$customerPhoneInput = is_string($_POST['phone_number'] ?? null)
    ? trim($_POST['phone_number'])
    : ($isCustomer ? (string) ($_SESSION['phone_number'] ?? '') : '');
if (!isset($_SESSION['customer_cart']) || !is_array($_SESSION['customer_cart'])) {
    $_SESSION['customer_cart'] = [];
}
$cart = $_SESSION['customer_cart'];
$error = '';
$isAjaxAddRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
$notice = (string) ($_SESSION['customer_order_notice'] ?? '');
$noticeExpiresAt = (int) ($_SESSION['customer_order_notice_expires_at'] ?? 0);
if ($noticeExpiresAt <= time()) {
    unset($_SESSION['customer_order_notice'], $_SESSION['customer_order_notice_expires_at']);
    $notice = '';
}
$dashboardTabValue = $_POST['dashboard_tab'] ?? $_GET['tab'] ?? 'menu';
$dashboardTabOptions = $isCustomer ? ['menu', 'order', 'orders'] : ['menu', 'order'];
$dashboardTab = is_string($dashboardTabValue) && in_array($dashboardTabValue, $dashboardTabOptions, true)
    ? $dashboardTabValue
    : 'menu';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add-item') {
        $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        if (!$itemId || $itemId < 1 || !$quantity || $quantity < 1 || $quantity > 99) {
            $error = 'Choose a menu item and a quantity from 1 to 99.';
        } else {
            $statement = $pdo->prepare('SELECT id FROM menu_items WHERE id = ? AND is_active = 1');
            $statement->execute([$itemId]);
            if (!$statement->fetch()) {
                $error = 'That item is not currently available.';
            } elseif (($cart[$itemId] ?? 0) + $quantity > 99) {
                $error = 'A maximum of 99 of each item can be ordered.';
            } else {
                $cart[$itemId] = ($cart[$itemId] ?? 0) + $quantity;
                $_SESSION['customer_cart'] = $cart;
                $_SESSION['customer_order_notice'] = 'Added to your order.';
                $_SESSION['customer_order_notice_expires_at'] = time() + 8;
                if ($isAjaxAddRequest) {
                    $notice = $_SESSION['customer_order_notice'];
                    $noticeExpiresAt = $_SESSION['customer_order_notice_expires_at'];
                } else {
                    header('Location: menu.php?tab=menu');
                    exit;
                }
            }
        }
    } elseif ($action === 'update-cart') {
        $quantities = $_POST['quantity'] ?? null;
        if (!is_array($quantities)) {
            $error = 'Invalid order quantities.';
        } else {
            $updatedCart = [];
            foreach ($cart as $itemId => $oldQuantity) {
                if (!array_key_exists($itemId, $quantities)) {
                    continue;
                }
                $quantity = filter_var($quantities[$itemId], FILTER_VALIDATE_INT);
                if ($quantity === false || $quantity < 0 || $quantity > 99) {
                    $error = 'Each quantity must be between 0 and 99.';
                    break;
                }
                if ($quantity > 0) {
                    $updatedCart[(int) $itemId] = $quantity;
                }
            }
            if ($error === '') {
                $_SESSION['customer_cart'] = $updatedCart;
                $_SESSION['customer_order_notice'] = 'Your order was updated.';
                $_SESSION['customer_order_notice_expires_at'] = time() + 8;
                header('Location: menu.php?tab=order');
                exit;
            }
        }
    } elseif ($action === 'cancel-cart') {
        $_SESSION['customer_cart'] = [];
        $_SESSION['customer_order_notice'] = 'Your order was cancelled and the cart was cleared.';
        $_SESSION['customer_order_notice_expires_at'] = time() + 8;
        header('Location: menu.php?tab=order');
        exit;
    } elseif ($action === 'place-order') {
        $submittedQuantities = $_POST['quantity'] ?? null;
        if ($submittedQuantities !== null) {
            if (!is_array($submittedQuantities)) {
                $error = 'Invalid order quantities.';
            } else {
                $updatedCart = [];
                foreach ($cart as $itemId => $oldQuantity) {
                    if (!array_key_exists($itemId, $submittedQuantities)) {
                        continue;
                    }
                    $quantity = filter_var($submittedQuantities[$itemId], FILTER_VALIDATE_INT);
                    if ($quantity === false || $quantity < 0 || $quantity > 99) {
                        $error = 'Each quantity must be between 0 and 99.';
                        break;
                    }
                    if ($quantity > 0) {
                        $updatedCart[(int) $itemId] = $quantity;
                    }
                }
                if ($error === '') {
                    $cart = $updatedCart;
                    $_SESSION['customer_cart'] = $cart;
                }
            }
        }

        $serviceType = $_POST['service_type'] ?? '';
        $allowedServiceTypes = ['dine_in', 'takeout', 'advance_booking', 'catering'];
        $notes = trim((string) ($_POST['customer_notes'] ?? ''));
        $eventAtInput = trim((string) ($_POST['event_at'] ?? ''));
        $partySizeInput = trim((string) ($_POST['party_size'] ?? ''));
        if ($error === '' && !in_array($serviceType, $allowedServiceTypes, true)) {
            $error = 'Choose dine-in, takeout, advance booking, or catering.';
        } elseif ($error === '' && ($customerNameInput === '' || strlen($customerNameInput) > 120)) {
            $error = 'Enter your name (up to 120 characters) to submit your order.';
        } elseif ($error === '' && (!preg_match('/^[0-9+().\-\s]{7,30}$/', $customerPhoneInput) || strlen(preg_replace('/\D/', '', $customerPhoneInput) ?? '') < 7)) {
            $error = 'Enter a valid contact number with at least 7 digits.';
        } elseif ($error === '' && strlen($notes) > 2000) {
            $error = 'Order messages must be 2,000 characters or fewer.';
        } elseif ($error === '' && !$cart) {
            $error = 'Add at least one item before submitting your order.';
        }

        $eventAt = null;
        $partySize = null;
        if ($error === '' && in_array($serviceType, ['advance_booking', 'catering'], true)) {
            $eventDate = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $eventAtInput);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!$eventDate || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $eventDate <= new DateTimeImmutable()) {
                $error = 'Choose a future date and time for your booking or catering order.';
            } else {
                $eventAt = $eventDate->format('Y-m-d H:i:s');
            }

            $validatedPartySize = filter_var($partySizeInput, FILTER_VALIDATE_INT);
            if ($error === '' && ($validatedPartySize === false || $validatedPartySize < 1 || $validatedPartySize > 500)) {
                $error = 'Enter a number of people between 1 and 500.';
            } elseif ($error === '') {
                $partySize = $validatedPartySize;
            }
        }

        if ($error === '') {
            $itemIds = array_map('intval', array_keys($cart));
            $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare("SELECT id, name, price FROM menu_items WHERE is_active = 1 AND id IN ($placeholders) FOR UPDATE");
                $statement->execute($itemIds);
                $itemsById = [];
                foreach ($statement->fetchAll() as $item) {
                    $itemsById[(int) $item['id']] = $item;
                }

                if (count($itemsById) !== count($cart)) {
                    $pdo->rollBack();
                    $error = 'One or more items became unavailable. Update your order and try again.';
                } else {
                    if ($isCustomer) {
                        $customerId = (int) $_SESSION['user_id'];
                        $statement = $pdo->prepare(
                            "UPDATE users SET full_name = ?, phone_number = ? WHERE id = ? AND role = 'customer'"
                        );
                        $statement->execute([$customerNameInput, $customerPhoneInput, $customerId]);
                        if ($statement->rowCount() === 0) {
                            $statement = $pdo->prepare(
                                "SELECT id FROM users WHERE id = ? AND role = 'customer' AND full_name = ? AND phone_number = ?"
                            );
                            $statement->execute([$customerId, $customerNameInput, $customerPhoneInput]);
                            if (!$statement->fetch()) {
                                throw new RuntimeException('The customer account could not be updated.');
                            }
                        }
                        $customerUsername = (string) $_SESSION['username'];
                    } else {
                        $customerUsername = 'guest_' . bin2hex(random_bytes(16));
                        $customerPassword = bin2hex(random_bytes(32));
                        $statement = $pdo->prepare(
                            "INSERT INTO users (username, full_name, phone_number, password_hash, role) VALUES (?, ?, ?, ?, 'customer')"
                        );
                        $statement->execute([
                            $customerUsername,
                            $customerNameInput,
                            $customerPhoneInput,
                            password_hash($customerPassword, PASSWORD_DEFAULT),
                        ]);
                        $customerId = (int) $pdo->lastInsertId();
                    }

                    $totalCents = 0;
                    foreach ($cart as $itemId => $quantity) {
                        $totalCents += (int) round((float) $itemsById[(int) $itemId]['price'] * 100) * (int) $quantity;
                    }
                    $total = number_format($totalCents / 100, 2, '.', '');
                    $statement = $pdo->prepare(
                        'INSERT INTO customer_orders (customer_id, service_type, event_at, party_size, customer_notes, total) VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $statement->execute([
                        $customerId,
                        $serviceType,
                        $eventAt,
                        $partySize,
                        $notes === '' ? null : $notes,
                        $total,
                    ]);
                    $orderId = (int) $pdo->lastInsertId();
                    $insertItem = $pdo->prepare(
                        'INSERT INTO customer_order_items (order_id, menu_item_id, item_name, quantity, unit_price) VALUES (?, ?, ?, ?, ?)'
                    );
                    foreach ($cart as $itemId => $quantity) {
                        $item = $itemsById[(int) $itemId];
                        $insertItem->execute([$orderId, (int) $itemId, $item['name'], (int) $quantity, $item['price']]);
                    }
                    $pdo->commit();
                    if (!$isCustomer) {
                        session_regenerate_id(true);
                    }
                    $_SESSION['user_id'] = $customerId;
                    $_SESSION['username'] = $customerUsername;
                    $_SESSION['full_name'] = $customerNameInput;
                    $_SESSION['phone_number'] = $customerPhoneInput;
                    $_SESSION['role'] = 'customer';
                    $isCustomer = true;
                    $_SESSION['customer_cart'] = [];
                    $_SESSION['customer_order_notice'] = 'Order #' . $orderId . ' submitted. The cashier will confirm it.';
                    $_SESSION['customer_order_notice_expires_at'] = time() + 8;
                    header('Location: menu.php?tab=orders#my-orders');
                    exit;
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    } elseif ($action === 'cancel-order') {
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$orderId || $orderId < 1) {
            $error = 'Invalid order.';
        } else {
            $statement = $pdo->prepare(
                "UPDATE customer_orders SET status = 'cancelled'
                 WHERE id = ? AND customer_id = ? AND status = 'pending' AND deleted_at IS NULL"
            );
            $statement->execute([$orderId, (int) $_SESSION['user_id']]);
            if ($statement->rowCount() > 0) {
                $_SESSION['customer_order_notice'] = 'Your pending order was cancelled.';
                $_SESSION['customer_order_notice_expires_at'] = time() + 8;
                header('Location: menu.php?tab=orders#my-orders');
                exit;
            }
            $error = 'Only pending orders can be cancelled. Please contact the cashier about confirmed orders.';
        }
    } else {
        $error = 'Unknown order action.';
    }
}

$menuItems = $pdo->query(
    'SELECT id, name, category, item_type, price FROM menu_items
     WHERE is_active = 1 ORDER BY item_type, category, name'
)->fetchAll();
$menuCategoryCounts = [];
foreach ($menuItems as $menuItem) {
    $itemType = (string) $menuItem['item_type'];
    $category = (string) $menuItem['category'];
    $menuCategoryCounts[$itemType][$category] = ($menuCategoryCounts[$itemType][$category] ?? 0) + 1;
}
$menuCategoryCounts['food'] = $menuCategoryCounts['food'] ?? [];
$menuCategoryCounts['drink'] = $menuCategoryCounts['drink'] ?? [];
$foodCount = count(array_filter($menuItems, static fn(array $item): bool => $item['item_type'] === 'food'));
$drinkCount = count(array_filter($menuItems, static fn(array $item): bool => $item['item_type'] === 'drink'));
$menuItemImages = [
    'Beef Kansi' => 'Image/menu-items/beef-kansi.jpg',
    'Beef Caldereta' => 'Image/menu-items/beef-caldereta.jpg',
    'Buttered Chicken' => 'Image/menu-items/buttered-chicken.jpg',
    'Buttered Shrimp' => 'Image/menu-items/buttered-shrimp.jpg',
    'Native Fried Chicken (Whole)' => 'Image/menu-items/native-fried-chicken.jpg',
    'Chicken BBQ with Rice' => 'Image/menu-items/chicken-bbq-rice.jpg',
    'Chicken Biryani' => 'Image/menu-items/chicken-biryani.jpg',
    'Chicken Curry' => 'Image/menu-items/chicken-curry.jpg',
    'Bam-E' => 'Image/menu-items/bam-e.jpg',
    'Sotanghon Guisado' => 'Image/menu-items/sotanghon.jpg',
    'Grilled Pork with Rice' => 'Image/menu-items/grilled-pork-rice.jpg',
    'Crispy Pata' => 'Image/menu-items/crispy-pata.jpg',
    'Pork Lumpia' => 'Image/menu-items/pork-lumpia.jpg',
    'Sisig' => 'Image/menu-items/sisig.jpg',
    'Garlic Rice Platter' => 'Image/menu-items/garlic-rice-platter.jpg',
    'Plain Cup Rice' => 'Image/menu-items/plain-cup-rice.jpg',
    'Plain Rice Platter' => 'Image/menu-items/plain-rice-platter.jpg',
    'Calamares' => 'Image/menu-items/calamares.jpg',
    'Camaron' => 'Image/menu-items/camaron.jpg',
    'Gambas' => 'Image/menu-items/gambas.jpg',
    'Grilled Fish with Rice' => 'Image/menu-items/grilled-fish.jpg',
    'Kinilaw' => 'Image/menu-items/kinilaw.jpg',
    'Squid Adobo' => 'Image/menu-items/squid-adobo.jpg',
];
$menuCategoryImages = [
    'Beef' => 'Image/menu-items/beef-caldereta.jpg',
    'Chicken' => 'Image/menu-items/chicken-curry.jpg',
    'Noodles' => 'Image/menu-items/sotanghon.jpg',
    'Pork' => 'Image/menu-items/pork-lumpia.jpg',
    'Rice' => 'Image/menu-items/plain-rice-platter.jpg',
    'Seafood' => 'Image/menu-items/shrimp.jpg',
];
$cart = $_SESSION['customer_cart'] ?? [];
$cartItems = [];
$cartTotalCents = 0;
if ($cart) {
    $ids = array_map('intval', array_keys($cart));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = $pdo->prepare("SELECT id, name, item_type, price FROM menu_items WHERE id IN ($placeholders)");
    $statement->execute($ids);
    foreach ($statement->fetchAll() as $item) {
        $id = (int) $item['id'];
        $item['quantity'] = (int) $cart[$id];
        $item['line_cents'] = (int) round((float) $item['price'] * 100) * $item['quantity'];
        $cartTotalCents += $item['line_cents'];
        $cartItems[$id] = $item;
    }
}
$customerOrders = [];
if ($isCustomer) {
    $statement = $pdo->prepare(
        'SELECT id, service_type, event_at, party_size, customer_notes, total, status, staff_notes, created_at
         FROM customer_orders WHERE customer_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 20'
    );
    $statement->execute([(int) $_SESSION['user_id']]);
    $customerOrders = $statement->fetchAll();
}
$customerOrderItems = [];
if ($customerOrders) {
    $orderIds = array_map(static fn(array $order): int => (int) $order['id'], $customerOrders);
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $statement = $pdo->prepare(
        "SELECT order_id, item_name, quantity, unit_price FROM customer_order_items WHERE order_id IN ($placeholders) ORDER BY id"
    );
    $statement->execute($orderIds);
    foreach ($statement->fetchAll() as $item) {
        $customerOrderItems[(int) $item['order_id']][] = $item;
    }
}
$serviceLabels = [
    'dine_in' => 'Dine in',
    'takeout' => 'Take out',
    'advance_booking' => 'Advance booking',
    'catering' => 'Catering',
];
$statusLabels = [
    'pending' => 'Pending confirmation',
    'confirmed' => 'Confirmed',
    'preparing' => 'Preparing',
    'ready' => 'Ready',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];
$orderProgressSteps = ['Received', 'Confirmed', 'Preparing', 'Ready', 'Completed'];
$orderProgressByStatus = [
    'pending' => 0,
    'confirmed' => 1,
    'preparing' => 2,
    'ready' => 3,
    'completed' => 4,
];
$orderStatusDescriptions = [
    'pending' => 'Your order has been received and is waiting for the restaurant to confirm it.',
    'confirmed' => 'Your order has been accepted and will be prepared soon.',
    'preparing' => 'The kitchen is preparing your order now.',
    'ready' => 'Your order is ready for pickup or service.',
    'completed' => 'Your order is complete. Thank you for choosing us!',
    'cancelled' => 'This order was cancelled and will not be prepared.',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Explore the menu | La Guerta Kuzina</title>
    <link href="app.css?v=<?= (int) filemtime(__DIR__ . '/app.css') ?>" rel="stylesheet">
</head>
<body class="customer-storefront-page">
<?php require __DIR__ . '/nav.php'; ?>
<main class="storefront-main">
    <nav id="customer-dashboard-tabs" class="nav storefront-tabs" aria-label="Customer navigation">
        <button class="nav-link<?= $dashboardTab === 'menu' ? ' active' : '' ?>" type="button" data-dashboard-tab="menu" aria-pressed="<?= $dashboardTab === 'menu' ? 'true' : 'false' ?>"><span aria-hidden="true">⌂</span> Menu</button>
        <a class="nav-link" href="#customer-categories" data-dashboard-category-link><span aria-hidden="true">☷</span> Categories</a>
        <button class="nav-link<?= $dashboardTab === 'order' ? ' active' : '' ?>" type="button" data-dashboard-tab="order" aria-pressed="<?= $dashboardTab === 'order' ? 'true' : 'false' ?>"><span aria-hidden="true">▤</span> Your order <span class="badge"><?= count($cartItems) ?></span></button>
        <?php if ($isCustomer): ?><button class="nav-link<?= $dashboardTab === 'orders' ? ' active' : '' ?>" type="button" data-dashboard-tab="orders" aria-pressed="<?= $dashboardTab === 'orders' ? 'true' : 'false' ?>"><span aria-hidden="true">◷</span> My orders <span class="badge"><?= count($customerOrders) ?></span></button><?php endif; ?>
        <a class="nav-link" href="feedback.php"><span aria-hidden="true">★</span> Reviews</a>
    </nav>
    <header class="storefront-hero">
        <div class="storefront-hero-copy">
            <span class="storefront-eyebrow">FRESH FROM OUR KITCHEN</span>
            <h1>Good food.<br><span>Good company.</span></h1>
            <p>Discover the flavors of La Guerta Kuzina, made fresh and served with love.</p>
            <a class="btn storefront-hero-button" href="#customer-menu-grid">Explore the menu <span aria-hidden="true">↓</span></a>
        </div>
    </header>
    <div class="storefront-toolbar">
        <div>
            <span class="storefront-eyebrow">MADE FOR YOU</span>
            <h2 class="storefront-section-title">Explore our menu</h2>
        </div>
        <p>Choose your favorites · Sign in automatically when you order</p>
    </div>
    <?php if ($error !== ''): ?><div class="alert alert-danger storefront-alert" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="alert alert-success storefront-alert" role="status" data-success-notice data-expires-at="<?= max(0, ($noticeExpiresAt - time()) * 1000) ?>"><span><?= h($notice) ?></span><button type="button" class="btn-close ms-2" data-dismiss-success-notice aria-label="Dismiss message"></button></div><?php endif; ?>

    <section class="storefront-panel<?= $dashboardTab === 'menu' ? ' d-flex' : ' d-none' ?>" data-dashboard-panel="menu" aria-hidden="<?= $dashboardTab === 'menu' ? 'false' : 'true' ?>">
        <div class="storefront-menu-toolbar">
            <div class="nav nav-pills storefront-category-pills" aria-label="Menu type filters">
                <button class="nav-link active" type="button" data-menu-type="food" aria-pressed="true">Food <span class="badge"><?= $foodCount ?></span></button>
                <button class="nav-link" type="button" data-menu-type="drink" aria-pressed="false">Drinks <span class="badge"><?= $drinkCount ?></span></button>
            </div>
            <span class="storefront-price-note">Prices in Philippine pesos</span>
        </div>
        <nav class="storefront-dish-categories" id="customer-categories" aria-label="Filter dishes by category">
            <span class="storefront-category-label">Browse by category</span>
            <div class="nav nav-pills storefront-dish-category-pills">
                <button class="nav-link active" type="button" data-category-filter="all" aria-pressed="true">All dishes <span class="badge"><?= $foodCount ?></span></button>
                <?php foreach ($menuCategoryCounts['food'] as $category => $count): ?>
                    <button class="nav-link" type="button" data-category-filter="<?= h($category) ?>" data-category-type="food" aria-pressed="false"><?= h($category) ?> <span class="badge"><?= $count ?></span></button>
                <?php endforeach; ?>
                <?php foreach ($menuCategoryCounts['drink'] as $category => $count): ?>
                    <button class="nav-link d-none" type="button" data-category-filter="<?= h($category) ?>" data-category-type="drink" aria-pressed="false"><?= h($category) ?> <span class="badge"><?= $count ?></span></button>
                <?php endforeach; ?>
            </div>
        </nav>
        <div class="row row-cols-2 row-cols-lg-3 g-3 align-content-start storefront-menu-grid" id="customer-menu-grid" role="region" aria-label="Available menu items" tabindex="0">
            <?php foreach ($menuItems as $item): ?>
                <?php $menuImage = $menuItemImages[$item['name']] ?? $menuCategoryImages[$item['category']] ?? 'Image/menu-items/buttered-chicken.jpg'; ?>
                <div class="col" data-menu-item data-menu-type="<?= h($item['item_type']) ?>" data-menu-category="<?= h($item['category']) ?>">
                    <article class="card storefront-product-card h-100">
                        <img class="storefront-product-image" src="<?= h($menuImage) ?>" alt="<?= h($item['name']) ?>" width="228" loading="lazy" decoding="async">
                        <div class="card-body d-flex flex-column justify-content-between gap-3">
                            <div class="d-flex justify-content-between gap-2"><div class="overflow-hidden"><span class="storefront-product-category"><?= h($item['category']) ?></span><h3 class="storefront-product-name"><?= h($item['name']) ?></h3></div><strong class="storefront-product-price">₱<?= number_format((float) $item['price'], 2) ?> <small>each</small></strong></div>
                            <form method="post" class="storefront-add-form">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="add-item"><input type="hidden" name="dashboard_tab" value="menu"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                <div class="storefront-quantity-control">
                                    <button class="storefront-quantity-step" type="button" data-quantity-step="-1" aria-label="Decrease quantity for <?= h($item['name']) ?>">−</button>
                                    <label class="visually-hidden" for="menu-qty-<?= (int) $item['id'] ?>">Quantity for <?= h($item['name']) ?></label>
                                    <input class="form-control" id="menu-qty-<?= (int) $item['id'] ?>" name="quantity" type="number" min="1" max="99" value="1" required>
                                    <button class="storefront-quantity-step" type="button" data-quantity-step="1" aria-label="Increase quantity for <?= h($item['name']) ?>">+</button>
                                </div>
                                <button class="btn btn-primary" type="submit">Add to order</button>
                            </form>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="text-secondary<?= $menuItems ? ' d-none' : '' ?>" id="empty-menu">No menu items are available right now.</p>
    </section>

    <section class="storefront-panel<?= $dashboardTab === 'order' ? ' d-flex' : ' d-none' ?>" data-dashboard-panel="order" aria-hidden="<?= $dashboardTab === 'order' ? 'false' : 'true' ?>">
        <div class="storefront-panel-heading"><h2>Your order</h2><strong>Estimated total <span id="cart-estimated-total">₱<?= number_format($cartTotalCents / 100, 2) ?></span></strong></div>
        <?php if (!$cartItems): ?>
            <div class="d-flex flex-fill justify-content-center align-items-center flex-column text-secondary"><p>Your order is empty.</p><button class="btn btn-primary" type="button" data-dashboard-tab="menu">Browse menu</button></div>
        <?php else: ?>
            <form method="post" class="d-flex flex-column flex-fill storefront-cart-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="update-cart"><input type="hidden" name="dashboard_tab" value="order">
                <div class="storefront-cart-grid" id="customer-cart-list">
                    <?php foreach ($cartItems as $item): ?>
                        <article class="card storefront-cart-card" data-cart-item data-unit-cents="<?= (int) round((float) $item['price'] * 100) ?>">
                            <div class="card-body">
                                <div class="storefront-cart-heading"><div><strong><?= h($item['name']) ?></strong><span class="storefront-cart-unit-price">₱<?= number_format((float) $item['price'], 2) ?> each</span></div><strong class="storefront-cart-line-total" data-line-total>₱<?= number_format($item['line_cents'] / 100, 2) ?></strong></div>
                                <div class="storefront-quantity-control storefront-cart-quantity">
                                    <button class="storefront-quantity-step" type="button" data-quantity-step="-1" aria-label="Decrease quantity for <?= h($item['name']) ?> (0 removes)">−</button>
                                    <label class="visually-hidden" for="cart-qty-<?= (int) $item['id'] ?>">Quantity for <?= h($item['name']) ?> (0 removes)</label>
                                    <input class="form-control form-control-sm" id="cart-qty-<?= (int) $item['id'] ?>" name="quantity[<?= (int) $item['id'] ?>]" type="number" min="0" max="99" value="<?= (int) $item['quantity'] ?>">
                                    <button class="storefront-quantity-step" type="button" data-quantity-step="1" aria-label="Increase quantity for <?= h($item['name']) ?>">+</button>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex justify-content-between align-items-center flex-shrink-0 py-2 d-none" id="cart-controls"><span class="small text-secondary" data-page-status></span><div class="btn-group btn-group-sm"><button class="btn btn-outline-secondary" type="button" data-page-prev>Previous</button><button class="btn btn-outline-secondary" type="button" data-page-next>Next</button></div></div>
            </form>
            <form method="post" id="place-order-form" class="card card-body storefront-checkout-form mt-2">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="dashboard_tab" value="order">
                <?php foreach ($cartItems as $item): ?>
                    <input type="hidden" name="quantity[<?= (int) $item['id'] ?>]" value="<?= (int) $item['quantity'] ?>" data-checkout-quantity="<?= (int) $item['id'] ?>">
                <?php endforeach; ?>
                <div class="storefront-checkout-grid">
                    <div class="storefront-checkout-field"><label class="form-label small mb-1" for="customer_name">Your name</label><input class="form-control form-control-sm" id="customer_name" name="customer_name" maxlength="120" autocomplete="name" value="<?= h($customerNameInput) ?>" required></div>
                    <div class="storefront-checkout-field"><label class="form-label small mb-1" for="phone_number">Contact number</label><input class="form-control form-control-sm" id="phone_number" name="phone_number" type="tel" maxlength="30" autocomplete="tel" value="<?= h($customerPhoneInput) ?>" required></div>
                    <div class="storefront-checkout-field"><label class="form-label small mb-1" for="service_type">Order type</label><select class="form-select form-select-sm" id="service_type" name="service_type" required><option value="dine_in">Dine in</option><option value="takeout">Take out</option><option value="advance_booking">Advance booking</option><option value="catering">Catering</option></select></div>
                    <div id="booking-details" class="storefront-booking-details d-none">
                        <div class="col"><label class="form-label small mb-1" for="event_at">Booking date and time</label><input class="form-control form-control-sm" id="event_at" name="event_at" type="datetime-local"></div>
                        <div class="col">
                            <label class="form-label small mb-1" for="party_size">Number of people</label>
                            <div class="storefront-quantity-control storefront-party-size">
                                <button class="storefront-quantity-step" type="button" data-quantity-step="-1" aria-label="Decrease number of people">−</button>
                                <input class="form-control form-control-sm" id="party_size" name="party_size" type="number" min="1" max="500" value="1" required>
                                <button class="storefront-quantity-step" type="button" data-quantity-step="1" aria-label="Increase number of people">+</button>
                            </div>
                        </div>
                    </div>
                    <div class="storefront-checkout-field"><label class="form-label small mb-1" for="customer_notes">Allergies or requests</label><textarea class="form-control form-control-sm" id="customer_notes" name="customer_notes" rows="1" maxlength="2000" placeholder="Optional message"></textarea></div>
                    <div class="storefront-checkout-field storefront-checkout-submit"><div class="storefront-checkout-actions"><button class="btn btn-outline-danger" type="submit" name="action" value="cancel-cart" formnovalidate data-cart-cancel>Cancel order</button><button class="btn btn-success" id="place-order-button" type="submit" name="action" value="place-order">Submit order · ₱<?= number_format($cartTotalCents / 100, 2) ?></button></div></div>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="storefront-panel<?= $dashboardTab === 'orders' ? ' d-flex' : ' d-none' ?>" data-dashboard-panel="orders" id="my-orders" aria-hidden="<?= $dashboardTab === 'orders' ? 'false' : 'true' ?>">
        <h2 class="storefront-section-title">My orders</h2>
        <?php if (!$isCustomer): ?>
            <div class="d-flex flex-fill justify-content-center align-items-center flex-column text-secondary"><p>Submit your first order with your name and contact number to sign in automatically and track your orders.</p><button class="btn btn-primary" type="button" data-dashboard-tab="order">View your order</button></div>
        <?php elseif (!$customerOrders): ?>
            <div class="d-flex flex-fill justify-content-center align-items-center flex-column text-secondary"><p>You don’t have any submitted orders yet.</p><button class="btn btn-primary" type="button" data-dashboard-tab="menu">Browse menu</button></div>
        <?php else: ?>
            <div class="storefront-order-history" id="customer-order-history">
                <?php foreach ($customerOrders as $order): ?>
                    <?php
                    $isOrderCancelled = $order['status'] === 'cancelled';
                    $orderProgressIndex = $orderProgressByStatus[$order['status']] ?? 0;
                    $orderProgressPercent = (int) round($orderProgressIndex / (count($orderProgressSteps) - 1) * 100);
                    ?>
                    <article class="card storefront-order-card" data-order-card>
                        <div class="card-body d-flex flex-column gap-2">
                            <div class="storefront-order-heading"><h3 class="h6 mb-1">Order #<?= (int) $order['id'] ?></h3><strong class="text-success">₱<?= number_format((float) $order['total'], 2) ?></strong></div>
                            <p class="small text-secondary mb-0"><?= h($serviceLabels[$order['service_type']] ?? $order['service_type']) ?> · <?= h($order['created_at']) ?><?php if ($order['event_at']): ?><br>Booking: <?= h($order['event_at']) ?> · <?= (int) $order['party_size'] ?> people<?php endif; ?></p>
                            <section class="storefront-order-status<?= $isOrderCancelled ? ' is-cancelled' : '' ?>" aria-label="Order status: <?= h($statusLabels[$order['status']] ?? ucfirst($order['status'])) ?>">
                                <div class="storefront-order-status-heading">
                                    <div>
                                        <span class="storefront-order-status-kicker">Current status</span>
                                        <strong><?= h($statusLabels[$order['status']] ?? ucfirst($order['status'])) ?></strong>
                                    </div>
                                    <span class="storefront-order-status-icon" aria-hidden="true"><?= $isOrderCancelled ? '&times;' : '•' ?></span>
                                </div>
                                <?php if ($isOrderCancelled): ?>
                                    <p class="storefront-order-status-description"><?= h($orderStatusDescriptions[$order['status']]) ?></p>
                                <?php else: ?>
                                    <ol class="storefront-order-progress" style="--progress: <?= $orderProgressPercent ?>%" aria-label="Order progress">
                                        <?php foreach ($orderProgressSteps as $stepIndex => $stepLabel): ?>
                                            <?php $stepComplete = $stepIndex < $orderProgressIndex; $stepCurrent = $stepIndex === $orderProgressIndex; ?>
                                            <li class="<?= $stepComplete ? 'is-complete' : ($stepCurrent ? 'is-current' : 'is-upcoming') ?>"<?= $stepCurrent ? ' aria-current="step"' : '' ?>>
                                                <span class="storefront-order-progress-dot" aria-hidden="true"><?= $stepComplete ? '✓' : $stepIndex + 1 ?></span>
                                                <span class="storefront-order-progress-label"><?= h($stepLabel) ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ol>
                                    <p class="storefront-order-status-description"><?= h($orderStatusDescriptions[$order['status']] ?? 'Your order status has been updated.') ?></p>
                                <?php endif; ?>
                            </section>
                            <?php $orderItems = $customerOrderItems[(int) $order['id']] ?? []; ?>
                            <ul class="storefront-order-items list-group list-group-flush small mb-0" id="order-items-<?= (int) $order['id'] ?>"><?php foreach ($orderItems as $item): ?><li class="list-group-item" data-order-line><?= (int) $item['quantity'] ?> × <?= h($item['item_name']) ?> — ₱<?= number_format((float) $item['unit_price'] * (int) $item['quantity'], 2) ?></li><?php endforeach; ?></ul>
                            <div class="d-flex justify-content-between align-items-center d-none" data-order-item-controls><span class="small text-secondary" data-page-status></span><div class="btn-group btn-group-sm"><button class="btn btn-outline-secondary" type="button" data-page-prev>Previous</button><button class="btn btn-outline-secondary" type="button" data-page-next>Next</button></div></div>
                            <?php if ($order['customer_notes']): ?><details class="small"><summary>Your allergy/request note</summary><p class="mb-0 mt-1"><?= nl2br(h($order['customer_notes'])) ?></p></details><?php endif; ?>
                            <?php if ($order['staff_notes'] && $isOrderCancelled): ?><p class="storefront-order-cancellation-note"><strong>Note from the restaurant:</strong> <?= nl2br(h($order['staff_notes'])) ?></p>
                            <?php elseif ($order['staff_notes']): ?><details class="small"><summary>Staff note</summary><p class="mb-0 mt-1"><?= nl2br(h($order['staff_notes'])) ?></p></details><?php endif; ?>
                            <?php if ($order['status'] === 'pending'): ?>
                                <form method="post" class="mt-auto" data-confirm-cancel data-cancel-message="Cancel order #<?= (int) $order['id'] ?>? This cannot be undone."><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="cancel-order"><input type="hidden" name="dashboard_tab" value="orders"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger">Cancel order</button><span class="small text-secondary ms-2">Available until the cashier confirms it.</span></form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="d-flex justify-content-between align-items-center flex-shrink-0 pt-2 d-none" id="orders-controls"><span class="small text-secondary" data-page-status></span><div class="btn-group btn-group-sm"><button class="btn btn-outline-secondary" type="button" data-page-prev>Previous</button><button class="btn btn-outline-secondary" type="button" data-page-next>Next</button></div></div>
        <?php endif; ?>
    </section>
</main>
<dialog class="order-confirm-dialog" id="customer-cancel-dialog" aria-labelledby="customer-cancel-title">
    <div>
        <h2 class="h4" id="customer-cancel-title">Cancel this order?</h2>
        <p class="text-secondary" id="customer-cancel-message">Please confirm that you want to cancel.</p>
        <div class="d-flex justify-content-end gap-2">
            <button class="btn btn-outline-secondary" type="button" data-close-dialog>Keep order</button>
            <button class="btn btn-danger" id="confirm-customer-cancel" type="button">Confirm cancellation</button>
        </div>
    </div>
</dialog>
<script>
    const dashboardTabs = document.querySelectorAll('[data-dashboard-tab]');
    const dashboardPanels = document.querySelectorAll('[data-dashboard-panel]');
    function initializeSuccessNotices(root = document) {
        root.querySelectorAll('[data-success-notice]').forEach((notice) => {
            const timeout = Number(notice.dataset.expiresAt);
            const dismissButton = notice.querySelector('[data-dismiss-success-notice]');
            const dismiss = () => {
                notice.remove();
            };
            dismissButton.addEventListener('click', dismiss);
            if (timeout > 0) {
                window.setTimeout(dismiss, timeout);
            }
        });
    }
    initializeSuccessNotices();
    function showDashboardTab(tabName) {
        dashboardPanels.forEach((panel) => {
            const active = panel.dataset.dashboardPanel === tabName;
            panel.classList.toggle('d-none', !active);
            panel.classList.toggle('d-flex', active);
            panel.setAttribute('aria-hidden', active ? 'false' : 'true');
        });
        document.querySelectorAll('#customer-dashboard-tabs [data-dashboard-tab]').forEach((tab) => {
            const active = tab.dataset.dashboardTab === tabName;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        history.replaceState(null, '', `?tab=${encodeURIComponent(tabName)}`);
    }
    dashboardTabs.forEach((tab) => {
        tab.addEventListener('click', () => showDashboardTab(tab.dataset.dashboardTab));
    });
    document.querySelector('[data-dashboard-category-link]')?.addEventListener('click', (event) => {
        event.preventDefault();
        if (document.querySelector('[data-dashboard-panel="menu"]').classList.contains('d-none')) {
            showDashboardTab('menu');
        }
        document.getElementById('customer-categories').scrollIntoView({ behavior: 'smooth', block: 'center' });
        history.replaceState(null, '', '?tab=menu#customer-categories');
    });

    const initialHash = window.location.hash === '#my-orders' ? 'orders' : null;
    if (initialHash) {
        showDashboardTab(initialHash);
    }

    const foodTypeTabs = document.querySelectorAll('nav[aria-label="Menu type filters"] [data-menu-type]');
    document.addEventListener('click', (event) => {
        const stepButton = event.target.closest('[data-quantity-step]');
        if (!stepButton) {
            return;
        }
        const quantityInput = stepButton.closest('.storefront-quantity-control').querySelector('input[type="number"]');
        const current = Number.parseInt(quantityInput.value, 10);
        const minimum = quantityInput.min === '' ? 1 : Number(quantityInput.min);
        const maximum = quantityInput.max === '' ? 99 : Number(quantityInput.max);
        const step = Number(stepButton.dataset.quantityStep);
        quantityInput.value = String(Math.min(maximum, Math.max(minimum, (Number.isNaN(current) ? Math.max(minimum, 1) : current) + step)));
        quantityInput.dispatchEvent(new Event('input', { bubbles: true }));
        quantityInput.dispatchEvent(new Event('change', { bubbles: true }));
    });

    function updateCartEstimate() {
        const cartForm = document.querySelector('.storefront-cart-form');
        const placeOrderButton = document.getElementById('place-order-button');
        const estimatedCartTotal = document.getElementById('cart-estimated-total');
        if (!cartForm || !placeOrderButton || !estimatedCartTotal) {
            return;
        }
        const formatMoney = (cents) => `₱${(cents / 100).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`;
        let totalCents = 0;
        cartForm.querySelectorAll('[data-cart-item]').forEach((card) => {
            const quantityInput = card.querySelector('input[type="number"]');
            const quantity = Math.min(99, Math.max(0, Number.parseInt(quantityInput.value, 10) || 0));
            const lineTotal = Number(card.dataset.unitCents) * quantity;
            totalCents += lineTotal;
            card.querySelector('[data-line-total]').textContent = formatMoney(lineTotal);
        });
        estimatedCartTotal.textContent = formatMoney(totalCents);
        placeOrderButton.textContent = `Submit order · ${formatMoney(totalCents)}`;
    }
    document.addEventListener('input', (event) => {
        if (event.target.closest('.storefront-cart-form') && event.target.matches('input[name^="quantity["]')) {
            const itemId = event.target.name.match(/^quantity\[(\d+)\]$/)?.[1];
            const checkoutQuantity = itemId && document.querySelector(`[data-checkout-quantity="${itemId}"]`);
            if (checkoutQuantity) {
                checkoutQuantity.value = event.target.value;
            }
            updateCartEstimate();
        }
    });

    const menuCards = Array.from(document.querySelectorAll('[data-menu-item]'));
    const menuGrid = document.getElementById('customer-menu-grid');
    const categoryTabs = Array.from(document.querySelectorAll('[data-category-filter]'));
    let activeMenuType = 'food';
    let activeMenuCategory = 'all';
    function renderCategoryFilters() {
        const categoryCounts = new Map();
        menuCards.forEach((card) => {
            if (card.dataset.menuType === activeMenuType) {
                categoryCounts.set(card.dataset.menuCategory, (categoryCounts.get(card.dataset.menuCategory) || 0) + 1);
            }
        });
        categoryTabs.forEach((tab) => {
            const category = tab.dataset.categoryFilter;
            const count = category === 'all' ? Array.from(categoryCounts.values()).reduce((total, value) => total + value, 0) : (categoryCounts.get(category) || 0);
            const isAvailable = category === 'all' || count > 0;
            tab.classList.toggle('d-none', !isAvailable);
            tab.querySelector('.badge').textContent = String(count);
            const active = category === activeMenuCategory;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }
    function renderMenu() {
        const visibleCards = menuCards.filter((card) => card.dataset.menuType === activeMenuType
            && (activeMenuCategory === 'all' || card.dataset.menuCategory === activeMenuCategory));
        menuCards.forEach((card) => {
            const visible = card.dataset.menuType === activeMenuType
                && (activeMenuCategory === 'all' || card.dataset.menuCategory === activeMenuCategory);
            card.classList.toggle('d-none', !visible);
        });
        menuGrid.classList.toggle('d-none', visibleCards.length === 0);
        document.getElementById('empty-menu').classList.toggle('d-none', visibleCards.length !== 0);
        renderCategoryFilters();
    }
    foodTypeTabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            activeMenuType = tab.dataset.menuType;
            activeMenuCategory = 'all';
            foodTypeTabs.forEach((item) => {
                const active = item === tab;
                item.classList.toggle('active', active);
                item.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            renderMenu();
        });
    });
    categoryTabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            activeMenuCategory = tab.dataset.categoryFilter;
            renderMenu();
            document.getElementById('customer-menu-grid').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
    renderMenu();

    const paginationCleanups = new Map();
    function setupPagination(listId, controlsId, pageSizeForViewport) {
        paginationCleanups.get(listId)?.();
        const list = document.getElementById(listId);
        const controls = document.getElementById(controlsId);
        if (!list || !controls) {
            paginationCleanups.delete(listId);
            return;
        }
        const items = Array.from(list.children);
        let page = 0;
        function render() {
            const pageSize = pageSizeForViewport();
            const pageCount = Math.max(1, Math.ceil(items.length / pageSize));
            page = Math.min(page, pageCount - 1);
            items.forEach((item, index) => {
                item.classList.toggle('d-none', index < page * pageSize || index >= (page + 1) * pageSize);
            });
            controls.classList.toggle('d-none', items.length <= pageSize);
            controls.querySelector('[data-page-status]').textContent = `Page ${page + 1} of ${pageCount}`;
            controls.querySelector('[data-page-prev]').disabled = page === 0;
            controls.querySelector('[data-page-next]').disabled = page >= pageCount - 1;
        }
        controls.querySelector('[data-page-prev]').addEventListener('click', () => {
            page--;
            render();
        });
        controls.querySelector('[data-page-next]').addEventListener('click', () => {
            page++;
            render();
        });
        window.addEventListener('resize', render);
        paginationCleanups.set(listId, () => window.removeEventListener('resize', render));
        render();
    }
    setupPagination('customer-cart-list', 'cart-controls', () => window.matchMedia('(max-width: 575.98px)').matches ? 2 : 5);
    setupPagination('customer-order-history', 'orders-controls', () => 1);
    document.querySelectorAll('[data-order-card]').forEach((card) => {
        const items = Array.from(card.querySelectorAll('[data-order-line]'));
        const controls = card.querySelector('[data-order-item-controls]');
        if (!controls) {
            return;
        }
        let page = 0;
        function renderOrderItems() {
            const pageSize = window.matchMedia('(max-width: 575.98px)').matches ? 4 : 6;
            const pageCount = Math.max(1, Math.ceil(items.length / pageSize));
            page = Math.min(page, pageCount - 1);
            items.forEach((item, index) => {
                item.classList.toggle('d-none', index < page * pageSize || index >= (page + 1) * pageSize);
            });
            controls.classList.toggle('d-none', items.length <= pageSize);
            controls.querySelector('[data-page-status]').textContent = `Items ${page * pageSize + 1}–${Math.min((page + 1) * pageSize, items.length)} of ${items.length}`;
            controls.querySelector('[data-page-prev]').disabled = page === 0;
            controls.querySelector('[data-page-next]').disabled = page >= pageCount - 1;
        }
        controls.querySelector('[data-page-prev]').addEventListener('click', () => {
            page--;
            renderOrderItems();
        });
        controls.querySelector('[data-page-next]').addEventListener('click', () => {
            page++;
            renderOrderItems();
        });
        window.addEventListener('resize', renderOrderItems);
        renderOrderItems();
    });

    const serviceType = document.getElementById('service_type');
    const bookingDetails = document.getElementById('booking-details');
    const eventAt = document.getElementById('event_at');
    const partySize = document.getElementById('party_size');
    function updateBookingFields() {
        const currentServiceType = document.getElementById('service_type');
        const currentBookingDetails = document.getElementById('booking-details');
        const currentEventAt = document.getElementById('event_at');
        const currentPartySize = document.getElementById('party_size');
        if (!currentServiceType || !currentBookingDetails || !currentEventAt || !currentPartySize) {
            return;
        }
        const needsBooking = currentServiceType.value === 'advance_booking' || currentServiceType.value === 'catering';
        currentBookingDetails.classList.toggle('d-none', !needsBooking);
        currentEventAt.required = needsBooking;
        currentPartySize.required = needsBooking;
    }
    document.addEventListener('change', (event) => {
        if (event.target.id === 'service_type') {
            updateBookingFields();
        }
    });
    updateBookingFields();

    const cancelDialog = document.getElementById('customer-cancel-dialog');
    const cancelMessage = document.getElementById('customer-cancel-message');
    let pendingCancelForm = null;
    let pendingCancelSubmitter = null;
    document.querySelectorAll('[data-close-dialog]').forEach((button) => {
        button.addEventListener('click', () => button.closest('dialog').close());
    });

    const showCancelDialog = (form, submitter, message) => {
        pendingCancelForm = form;
        pendingCancelSubmitter = submitter;
        cancelMessage.textContent = message;
        cancelDialog.showModal();
    };

    document.querySelectorAll('[data-confirm-cancel]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.dataset.cancelConfirmed === 'true') {
                form.dataset.cancelConfirmed = 'false';
                return;
            }
            event.preventDefault();
            showCancelDialog(form, event.submitter, form.dataset.cancelMessage);
        });
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!form.matches('#place-order-form')) {
            return;
        }
        if (!event.submitter || !event.submitter.matches('[data-cart-cancel]')) return;
        if (form.dataset.cancelConfirmed === 'true') {
            form.dataset.cancelConfirmed = 'false';
            return;
        }
        event.preventDefault();
        showCancelDialog(form, event.submitter, 'Cancel this order and clear your cart?');
    });

    document.getElementById('confirm-customer-cancel').addEventListener('click', () => {
        if (!pendingCancelForm) return;
        const form = pendingCancelForm;
        const submitter = pendingCancelSubmitter;
        pendingCancelForm = null;
        pendingCancelSubmitter = null;
        form.dataset.cancelConfirmed = 'true';
        cancelDialog.close();
        if (submitter) {
            form.requestSubmit(submitter);
        } else {
            form.requestSubmit();
        }
    });

    const addForms = document.querySelectorAll('.storefront-add-form');
    addForms.forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const submitButton = form.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            try {
                const response = await fetch(form.getAttribute('action') || window.location.href, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) {
                    throw new Error('The item could not be added. Please try again.');
                }
                const responseDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
                const errorNotice = responseDocument.querySelector('.storefront-alert.alert-danger');
                if (errorNotice) {
                    showAddNotice(errorNotice.textContent.trim());
                    return;
                }

                const currentOrderPanel = document.querySelector('[data-dashboard-panel="order"]');
                const updatedOrderPanel = responseDocument.querySelector('[data-dashboard-panel="order"]');
                if (!currentOrderPanel || !updatedOrderPanel) {
                    throw new Error('The order was added, but the cart could not be updated. Refresh the page to view it.');
                }
                const currentCheckoutForm = currentOrderPanel.querySelector('#place-order-form');
                const updatedCheckoutForm = updatedOrderPanel.querySelector('#place-order-form');
                if (currentCheckoutForm && updatedCheckoutForm) {
                    ['customer_name', 'phone_number', 'service_type', 'event_at', 'party_size', 'customer_notes'].forEach((fieldName) => {
                        const currentField = currentCheckoutForm.elements.namedItem(fieldName);
                        const updatedField = updatedCheckoutForm.elements.namedItem(fieldName);
                        if (currentField && updatedField) {
                            updatedField.value = currentField.value;
                        }
                    });
                }
                paginationCleanups.get('customer-cart-list')?.();
                currentOrderPanel.innerHTML = updatedOrderPanel.innerHTML;
                setupPagination('customer-cart-list', 'cart-controls', () => window.matchMedia('(max-width: 575.98px)').matches ? 2 : 5);
                const updatedCartBadge = responseDocument.querySelector('#customer-dashboard-tabs [data-dashboard-tab="order"] .badge');
                const currentCartBadge = document.querySelector('#customer-dashboard-tabs [data-dashboard-tab="order"] .badge');
                if (updatedCartBadge && currentCartBadge) {
                    currentCartBadge.textContent = updatedCartBadge.textContent;
                }
                updateBookingFields();
                const updatedNotice = responseDocument.querySelector('[data-success-notice]');
                const currentNotice = document.querySelector('[data-success-notice]');
                if (updatedNotice) {
                    if (currentNotice) {
                        currentNotice.replaceWith(updatedNotice);
                    } else {
                        document.querySelector('.storefront-toolbar').after(updatedNotice);
                    }
                    initializeSuccessNotices(document);
                }
                document.querySelector('.storefront-alert.alert-danger')?.remove();
            } catch (error) {
                showAddNotice(error instanceof Error ? error.message : 'The item could not be added. Please try again.');
            } finally {
                submitButton.disabled = false;
            }
        });
    });

    function showAddNotice(message) {
        document.querySelectorAll('.storefront-alert').forEach((notice) => notice.remove());
        const notice = document.createElement('div');
        notice.className = 'alert alert-danger storefront-alert';
        notice.setAttribute('role', 'alert');
        notice.textContent = message;
        document.querySelector('.storefront-toolbar').after(notice);
    }
</script>
</body>
</html>
