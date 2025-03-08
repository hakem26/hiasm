<?php
require_once 'db.php';

$query = $_POST['query'];
$stmt = $pdo->prepare("SELECT id, name, price FROM Products WHERE name LIKE ? LIMIT 10");
$stmt->execute(['%' . $query . '%']);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($products as $product) {
    echo "<a href='#' class='list-group-item list-group-item-action product-suggestion' data-product='" . json_encode($product) . "'>" . htmlspecialchars($product['name']) . " - " . number_format($product['price'], 0) . " تومان</a>";
}
?>