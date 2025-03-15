<?php
session_start();
require_once 'db.php';

// فعال کردن نمایش خطاها برای دیباگ (فقط برای تست)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// لگ کردن درخواست ورودی
error_log("Request received: " . json_encode($_GET, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$action = $_GET['action'] ?? ''; // پارامتر جدید برای انتخاب عملکرد
$query = $_POST['query'] ?? '';
$work_details_id = $_POST['work_details_id'] ?? ''; // برای منطق فعلی
$work_month_id = $_GET['work_month_id'] ?? ''; // برای گزارش فروش
$current_user_id = $_SESSION['user_id'] ?? null; // برای فیلتر همکار اول

if ($action === 'get_sales_report' && $work_month_id && $current_user_id) {
    // لگ کردن شروع منطق گزارش فروش
    error_log("Starting get_sales_report: work_month_id = $work_month_id, user_id = $current_user_id");

    // تنظیم هدر برای JSON
    header('Content-Type: application/json; charset=UTF-8');

    // متغیرهای جمع کل
    $total_sales = 0;
    $total_discount = 0;
    $total_sessions = 0;

    try {
        // جمع کل فروش و تخفیف
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(o.total_amount), 0) AS total_sales,
                   COALESCE(SUM(o.discount), 0) AS total_discount
            FROM Orders o
            JOIN Work_Details wd ON o.work_details_id = wd.id
            JOIN Partners p ON wd.partner_id = p.partner_id
            WHERE wd.work_month_id = ? AND p.user_id1 = ?
        ");
        $stmt->execute([$work_month_id, $current_user_id]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_sales = $summary['total_sales'] ?? 0;
        $total_discount = $summary['total_discount'] ?? 0;
        error_log("Total sales: $total_sales, Total discount: $total_discount");

        // تعداد جلسات (روزهای کاری)
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT wd.work_date) AS total_sessions
            FROM Work_Details wd
            WHERE wd.work_month_id = ?
        ");
        $stmt->execute([$work_month_id]);
        $sessions = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_sessions = $sessions['total_sessions'] ?? 0;
        error_log("Total sessions: $total_sessions");

        // لیست محصولات
        $products = [];
        $stmt = $pdo->prepare("
            SELECT oi.product_name, oi.unit_price, SUM(oi.quantity) AS total_quantity, SUM(oi.total_price) AS total_price
            FROM Order_Items oi
            JOIN Orders o ON oi.order_id = o.order_id
            JOIN Work_Details wd ON o.work_details_id = wd.id
            JOIN Partners p ON wd.partner_id = p.partner_id
            WHERE wd.work_month_id = ? AND p.user_id1 = ?
            GROUP BY oi.product_name, oi.unit_price
            ORDER BY oi.product_name COLLATE utf8mb4_persian_ci
        ");
        $stmt->execute([$work_month_id, $current_user_id]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Products fetched: " . count($products));

        // تولید HTML جدول
        $html = '<table class="table table-light"><thead><tr><th>ردیف</th><th>اقلام</th><th>قیمت واحد</th><th>تعداد</th><th>قیمت کل</th><th>سود</th><th>مشاهده</th></tr></thead><tbody>';
        if (empty($products)) {
            $html .= '<tr><td colspan="7" class="text-center">محصولی یافت نشد.</td></tr>';
        } else {
            $row_number = 1;
            foreach ($products as $product) {
                $html .= '<tr>';
                $html .= '<td>' . $row_number++ . '</td>';
                $html .= '<td>' . htmlspecialchars($product['product_name']) . '</td>';
                $html .= '<td>' . number_format($product['unit_price'], 0) . ' تومان</td>';
                $html .= '<td>' . $product['total_quantity'] . '</td>';
                $html .= '<td>' . number_format($product['total_price'], 0) . ' تومان</td>';
                $html .= '<td></td>';
                $html .= '<td><a href="print-report-sell.php?work_month_id=' . $work_month_id . '" class="btn btn-info btn-sm"><i class="fas fa-eye"></i> مشاهده</a></td>';
                $html .= '</tr>';
            }
        }
        $html .= '</tbody></table>';

        // لگ کردن خروجی
        error_log("HTML generated: " . substr($html, 0, 100) . "...");

        echo json_encode([
            'success' => true,
            'html' => $html,
            'total_sales' => $total_sales,
            'total_discount' => $total_discount,
            'total_sessions' => $total_sessions
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        // لگ کردن خطا
        error_log("Error in get_sales_report: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'خطایی در سرور رخ داد: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// منطق فعلی (پیشنهاددهنده محصولات)
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