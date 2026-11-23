<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_role('admin');
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
session_write_close();

$afterIdInput = $_GET['after_id'] ?? null;
$afterId = null;
if ($afterIdInput !== null) {
    $afterId = filter_var($afterIdInput, FILTER_VALIDATE_INT);
    if ($afterId === false || $afterId < 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid order cursor.']);
        exit;
    }
}

$latestOrderId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM customer_orders')->fetchColumn();
$pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE status = 'pending' AND deleted_at IS NULL")->fetchColumn();
$newOrders = [];
$nextCursor = $latestOrderId;

if ($afterId !== null) {
    $statement = $pdo->prepare(
        "SELECT o.id, o.total, u.full_name AS customer_name
         FROM customer_orders o
         JOIN users u ON u.id = o.customer_id
         WHERE o.id > ? AND o.status = 'pending' AND o.deleted_at IS NULL
         ORDER BY o.id
         LIMIT 20"
    );
    $statement->execute([$afterId]);
    $newOrders = $statement->fetchAll();
    if (count($newOrders) === 20) {
        $nextCursor = (int) $newOrders[array_key_last($newOrders)]['id'];
    }
}

echo json_encode([
    'pending_count' => $pendingCount,
    'latest_id' => $nextCursor,
    'new_orders' => array_map(static fn(array $order): array => [
        'id' => (int) $order['id'],
        'customer_name' => $order['customer_name'],
        'total' => number_format((float) $order['total'], 2, '.', ''),
    ], $newOrders),
], JSON_THROW_ON_ERROR);
