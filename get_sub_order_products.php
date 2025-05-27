<?php
session_start();
require_once 'db.php';

// لگ کردن درخواست ورودی
error_log("Request received: " . json_encode($_POST, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$query = $_POST['query'] ?? '';
$work_details_id = $_POST['work_details_id'] ?? '';
$partner_id = $_POST['partner_id'] ?? null;
$current_user_id = $_SESSION['user_id'] ?? null;

if (empty($query) || !$current_user_id) {
    error_log("Error: Empty query or user not logged in.");
    exit;
}

if (strlen($query) < 3) {
    error_log("Error: Query length less than 3 characters.");
    exit;
}

// تعیین user_id برای موجودی
$user_id_for_inventory = $partner_id ?: $current_user_id;
error_log("Using user_id for inventory: $user_id_for_inventory");

$products = [];
if ($work_details_id) {
    // گرفتن تاریخ کاری
    $stmt_work = $pdo->prepare("SELECT work_date FROM Work_Details WHERE id = ?");
    $stmt_work->execute([$work_details_id]);
    $work_date = $stmt_work->fetchColumn();
    error_log("Debug: work_details_id = $work_details_id, work_date = $work_date");

    if ($work_date) {
        $stmt = $pdo->prepare("
            SELECT p.product_id, p.product_name, 
                   COALESCE(
                       (SELECT h.unit_price 
                        FROM Product_Price_History h 
                        WHERE h.product_id = p.product_id 
                        AND h.start_date <= ? 
                        AND (h.end_date IS NULL OR h.end_date >= ?) 
                        ORDER BY h.start_date DESC 
                        LIMIT 1),
                       p.unit_price
                   ) AS unit_price,
                   COALESCE(
                       (SELECT i.quantity 
                        FROM Inventory i 
                        WHERE i.product_id = p.product_id 
                        AND i.user_id = ?),
                       0
                   ) AS inventory
            FROM Products p 
            WHERE p.product_name LIKE ? 
            LIMIT 10
        ");
        $stmt->execute([$work_date, $work_date, $user_id_for_inventory, '%' . $query . '%']);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Debug: Products fetched with work_date = $work_date, user_id = $user_id_for_inventory, count = " . count($products));
    } else {
        error_log("Debug: No work_date found for work_details_id = $work_details_id");
        // بدون تاریخ کاری، آخرین قیمت و موجودی
        $stmt = $pdo->prepare("
            SELECT p.product_id, p.product_name, 
                   COALESCE(
                       (SELECT h.unit_price 
                        FROM Product_Price_History h 
                        WHERE h.product_id = p.product_id 
                        ORDER BY h.start_date DESC 
                        LIMIT 1),
                       p.unit_price
                   ) AS unit_price,
                   COALESCE(
                       (SELECT i.quantity 
                        FROM Inventory i 
                        WHERE i.product_id = p.product_id 
                        AND i.user_id = ?),
                       0
                   ) AS inventory
            FROM Products p 
            WHERE p.product_name LIKE ? 
            LIMIT 10
        ");
        $stmt->execute([$user_id_for_inventory, '%' . $query . '%']);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    // بدون work_details_id، آخرین قیمت و موجودی
    $stmt = $pdo->prepare("
        SELECT p.product_id, p.product_name, 
               COALESCE(
                   (SELECT h.unit_price 
                    FROM Product_Price_History h 
                    WHERE h.product_id = p.product_id 
                    ORDER BY h.start_date DESC 
                    LIMIT 1),
                   p.unit_price
               ) AS unit_price,
               COALESCE(
                   (SELECT i.quantity 
                    FROM Inventory i 
                    WHERE i.product_id = p.product_id 
                    AND i.user_id = ?),
                   0
               ) AS inventory
        FROM Products p 
        WHERE p.product_name LIKE ? 
        LIMIT 10
    ");
    $stmt->execute([$user_id_for_inventory, '%' . $query . '%']);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Debug: No work_details_id, using latest prices, user_id = $user_id_for_inventory, count = " . count($products));
}

if (empty($products)) {
    error_log("No products found for query: $query");
    echo "<div class='list-group-item'>محصولی یافت نشد</div>";
} else {
    foreach ($products as $product) {
        $product_json = json_encode($product, JSON_UNESCAPED_UNICODE);
        echo "<a href='#' class='list-group-item list-group-item-action product-suggestion' data-product='$product_json'>" 
             . htmlspecialchars($product['product_name']) . " - " 
             . number_format($product['unit_price'], 0) . " تومان (موجودی: " . $product['inventory'] . ")</a>";
    }
}
?>