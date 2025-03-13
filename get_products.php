<?php
require_once 'db.php';

$query = $_POST['query'] ?? '';
$work_details_id = $_POST['work_details_id'] ?? ''; // مطمئن مي‌شويم دريافت مي‌شه

if (empty($query)) {
    exit;
}

$products = [];
if ($work_details_id) {
    // گرفتن تاريخ كارى با ديباگ
    $stmt_work = $pdo->prepare("SELECT work_date FROM Work_Details WHERE id = ?");
    $stmt_work->execute([$work_details_id]);
    $work_date = $stmt_work->fetchColumn();
    error_log("Debug: work_details_id = $work_details_id, work_date = $work_date"); // لگ براي ديباگ

    if ($work_date) {
        // گرفتن آخرين قيمت تا تاريخ كارى با شرايط دقيق‌تر
        $stmt = $pdo->prepare("
            SELECT p.product_id, p.product_name, COALESCE(
                (SELECT unit_price 
                 FROM Product_Price_History h 
                 WHERE h.product_id = p.product_id 
                 AND h.start_date <= ? 
                 AND (h.end_date IS NULL OR h.end_date >= ?) 
                 ORDER BY h.start_date DESC LIMIT 1), p.unit_price
            ) AS unit_price
            FROM Products p 
            WHERE p.product_name LIKE ? 
            LIMIT 10
        ");
        $stmt->execute([$work_date, $work_date, '%' . $query . '%']);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Debug: Products fetched with work_date = $work_date, count = " . count($products)); // لگ
    } else {
        error_log("Debug: No work_date found for work_details_id = $work_details_id");
    }
} else {
    // اگر تاريخ كارى مشخص نباشه، فقط قيمت پايه رو نشون بده
    $stmt = $pdo->prepare("SELECT product_id, product_name, unit_price FROM Products WHERE product_name LIKE ? LIMIT 10");
    $stmt->execute(['%' . $query . '%']);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Debug: No work_details_id, using base prices, count = " . count($products));
}

foreach ($products as $product) {
    echo "<a href='#' class='list-group-item list-group-item-action product-suggestion' data-product='" . json_encode($product) . "'>" . htmlspecialchars($product['product_name']) . " - " . number_format($product['unit_price'], 0) . " تومان</a>";
}
?>