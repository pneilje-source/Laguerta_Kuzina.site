<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

$databaseHost = getenv('POS_DB_HOST') ?: '127.0.0.1';
$databaseName = getenv('POS_DB_NAME') ?: 'laguerta_pos';
$databaseUser = getenv('POS_DB_USER') ?: 'root';
$databasePassword = getenv('POS_DB_PASSWORD') ?: '';

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $databaseHost, $databaseName);

$pdo = new PDO($dsn, $databaseUser, $databasePassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone = '+08:00'");
