<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_role('admin');
require_once __DIR__ . '/config.php';

$saleId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$saleId || $saleId < 1) {
    http_response_code(404);
    exit('Receipt not found.');
}

$sql = 'SELECT s.id, s.cashier_id, s.total, s.pwd_discount_amount, s.pwd_discount_rate, s.created_at, u.full_name AS cashier_name,
               o.service_type, o.event_at, o.party_size, o.customer_notes
        FROM sales s
        JOIN users u ON u.id = s.cashier_id
        LEFT JOIN customer_orders o ON o.sale_id = s.id
        WHERE s.id = ?';
$parameters = [$saleId];
$statement = $pdo->prepare($sql);
$statement->execute($parameters);
$sale = $statement->fetch();
if (!$sale) {
    http_response_code(404);
    exit('Receipt not found.');
}

$statement = $pdo->prepare('SELECT item_name, quantity, unit_price FROM sale_items WHERE sale_id = ? ORDER BY id');
$statement->execute([$saleId]);
$items = $statement->fetchAll();
$serviceLabels = [
    'dine_in' => 'Dine in',
    'takeout' => 'Take out',
    'advance_booking' => 'Advance booking',
    'catering' => 'Catering',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt #<?= (int) $sale['id'] ?> | La Guerta Kuzina</title>
    <link href="app.css" rel="stylesheet">
</head>
<body class="receipt-page">
<?php require __DIR__ . '/nav.php'; ?>
<main class="container py-4 receipt-main">
    <article class="receipt-card mx-auto">
        <header class="receipt-brand">
            <img class="receipt-logo" src="Image/laguerta.jpg" alt="La Guerta Kuzina">
            <p class="receipt-eyebrow">A taste of home</p>
            <h1>La Guerta <span>Kuzina</span></h1>
            <p class="receipt-tagline">Thank you for sharing a meal with us</p>
            <div class="receipt-status"><span aria-hidden="true">✦</span> PAYMENT RECEIPT <span aria-hidden="true">✦</span></div>
        </header>
        <section class="receipt-details" aria-label="Sale details">
            <div><span>Receipt</span><strong>#<?= (int) $sale['id'] ?></strong></div>
            <div><span>Date &amp; time</span><strong><?= h(date('M j, Y · g:i A', strtotime($sale['created_at']))) ?></strong></div>
            <div><span>Served by</span><strong><?= h($sale['cashier_name']) ?></strong></div>
            <?php if ($sale['service_type']): ?>
                <div><span>Order type</span><strong><?= h($serviceLabels[$sale['service_type']] ?? $sale['service_type']) ?></strong></div>
            <?php endif; ?>
            <?php if ($sale['event_at']): ?>
                <div><span>Booking</span><strong><?= h(date('M j, Y · g:i A', strtotime($sale['event_at']))) ?> · <?= (int) $sale['party_size'] ?> people</strong></div>
            <?php endif; ?>
        </section>
        <?php if ($sale['customer_notes']): ?>
            <aside class="receipt-customer-note"><strong>Customer request</strong><br><?= nl2br(h($sale['customer_notes'])) ?></aside>
        <?php endif; ?>
        <section class="receipt-items" aria-labelledby="receipt-items-title">
            <h2 id="receipt-items-title">Your order</h2>
            <div class="table-responsive">
                <table class="table receipt-table">
                    <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php $lineCents = (int) round((float) $item['unit_price'] * 100) * (int) $item['quantity']; ?>
                        <tr>
                            <td><?= h($item['item_name']) ?></td>
                            <td class="text-end"><?= (int) $item['quantity'] ?></td>
                            <td class="text-end">₱<?= number_format((float) $item['unit_price'], 2) ?></td>
                            <td class="text-end fw-semibold">₱<?= number_format($lineCents / 100, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <section class="receipt-totals" aria-label="Payment totals">
            <div><span>Subtotal</span><strong>₱<?= number_format((float) $sale['total'], 2) ?></strong></div>
            <?php if ((float) $sale['pwd_discount_amount'] > 0): ?>
                <div class="receipt-discount"><span>PWD discount (<?= number_format((float) $sale['pwd_discount_rate'], 2) ?>%)</span><strong>−₱<?= number_format((float) $sale['pwd_discount_amount'], 2) ?></strong></div>
            <?php endif; ?>
            <div class="receipt-amount-due"><span>Amount due</span><strong>₱<?= number_format((float) $sale['total'] - (float) $sale['pwd_discount_amount'], 2) ?></strong></div>
        </section>
        <footer class="receipt-thanks">
            <span class="receipt-heart" aria-hidden="true">♥</span>
            <p>Made with care, served with love.</p>
            <strong>We hope to see you again!</strong>
        </footer>
    </article>
    <div class="d-flex justify-content-center gap-2 mt-3 no-print">
        <button class="btn btn-primary" type="button" onclick="window.print()">Print receipt</button>
        <a class="btn btn-outline-secondary" href="orders.php">Back to customer orders</a>
    </div>
</main>
</body>
</html>
