<?php
// Start session and include database
session_start();
require_once 'db.php';

// Log incoming request
error_log("get_sub_order_products.php - Request: " . json_encode($_POST, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Validate input
$query = trim($_POST['query'] ?? '');
$work_details_id = trim($_POST['work_details_id'] ?? '');
$partner_id = trim($_POST['partner_id'] ?? '');
$current_user_id = $_SESSION['user_id'] ?? null;

if (empty($query) || !$current_user_id) {
    error_log("get_sub_order_products.php - Error: Empty query or user not logged in.");
    echo "<div class='list-group-item'>ورودی نامعتبر</div>";
    exit;
}

if (strlen($query) < 3) {
    error_log("get_sub_order_products.php - Error: Query length less than 3 characters.");
    echo "<div class='list-group-item'>حداقل ۳ حرف وارد کنید</div>";
    exit;
}

// Determine user_id for inventory
$user_id_for_inventory = $partner_id ?: $current_user_id;
error_log("get_sub_order_products.php - Using user_id for inventory: $user_id_for_inventory");

$products = [];
try {
    if ($work_details_id) {
        // Get work date
        $stmt_work = $pdo->prepare("SELECT work_date FROM Work_Details WHERE id = ?");
        $stmt_work->execute([$work_details_id]);
        $work_date = $stmt_work->fetchColumn();
        error_log("get_sub_order_products.php - work_details_id = $work_details_id, work_date = " . ($work_date ?: 'null'));

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
                ORDER BY p.product_name
                LIMIT 10
            ");
            $stmt->execute([$work_date, $work_date, $user_id_for_inventory, '%' . $query . '%']);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("get_sub_order_products.php - Products fetched with work_date = $work_date, count = " . count($products));
        } else {
            error_log("get_sub_order_products.php - No work_date found for work_details_id = $work_details_id");
            // Fallback to latest price and inventory
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
                ORDER BY p.product_name
                LIMIT 10
            ");
            $stmt->execute([$user_id_for_inventory, '%' . $query . '%']);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        // No work_details_id, use latest price and inventory
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
            ORDER BY p.product_name
            LIMIT 10
        ");
        $stmt->execute([$user_id_for_inventory, '%' . $query . '%']);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("get_sub_order_products.php - No work_details_id, using latest prices, count = " . count($products));
    }

    // Output products
    if (empty($products)) {
        error_log("get_sub_order_products.php - No products found for query: $query");
        echo "<div class='list-group-item'>محصولی یافت نشد</div>";
    } else {
        foreach ($products as $product) {
            $product_json = json_encode($product, JSON_UNESCAPED_UNICODE);
            echo "<a href='#' class='list-group-item list-group-item-action product-suggestion' data-product='" . htmlspecialchars($product_json, ENT_QUOTES, 'UTF-8') . "'>" 
                 . htmlspecialchars($product['product_name']) . " - " 
                 . number_format($product['unit_price'], 0) . " تومان (موجودی: " . $product['inventory'] . ")</a>";
        }
    }
} catch (Exception $e) {
    error_log("get_sub_order_products.php - Error: " . $e->getMessage());
    echo "<div class='list-group-item'>خطا در دریافت محصولات</div>";
}
?>