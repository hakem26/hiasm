<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

// لگ کردن درخواست ورودی
error_log("Admin Request received: " . json_encode($_GET, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$action = $_GET['action'] ?? '';
$year_jalali = isset($_GET['year']) ? (int) $_GET['year'] : null;
$work_month_id = $_GET['work_month_id'] ?? '';
$user_id = $_GET['user_id'] ?? 'all'; // برای ادمین، این مقدار می‌تونه 'all' یا یه user_id خاص باشه
$current_user_id = $_SESSION['user_id'] ?? null;

// محاسبه بازه میلادی برای سال شمسی
$start_date = null;
$end_date = null;
if ($year_jalali) {
    $gregorian_start_year = $year_jalali - 579;
    $gregorian_end_year = $gregorian_start_year + 1;
    $start_date = "$gregorian_start_year-03-21";
    $end_date = "$gregorian_end_year-03-21";

    if ($year_jalali == 1404) {
        $start_date = "2025-03-21";
        $end_date = "2026-03-21";
    } elseif ($year_jalali == 1403) {
        $start_date = "2024-03-20";
        $end_date = "2025-03-21";
    }
}

if ($action === 'get_sales_report' && $work_month_id && $current_user_id) {
    error_log("Starting get_sales_report for admin: work_month_id = $work_month_id, user_id = $user_id");
    header('Content-Type: application/json; charset=UTF-8');

    $total_sales = 0;
    $total_discount = 0;
    $total_sessions = 0;

    try {
        // جمع کل فروش و تخفیف
        $query = "
            SELECT COALESCE(SUM(o.total_amount), 0) AS total_sales,
                   COALESCE(SUM(o.discount), 0) AS total_discount
            FROM Orders o
            JOIN Work_Details wd ON o.work_details_id = wd.id
            JOIN Partners p ON wd.partner_id = p.partner_id
            JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
            WHERE wd.work_month_id = ?
            " . ($year_jalali ? "AND wm.start_date >= ? AND wm.start_date < ?" : "") . "
        ";
        $params = [$work_month_id];
        if ($year_jalali) {
            $params[] = $start_date;
            $params[] = $end_date;
        }
        if ($user_id !== 'all') {
            $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
            $params[] = $user_id;
            $params[] = $user_id;
        }
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_sales = $summary['total_sales'] ?? 0;
        $total_discount = $summary['total_discount'] ?? 0;
        error_log("Admin Total sales: $total_sales, Total discount: $total_discount");

        // تعداد جلسات (روزهای کاری)
        $query = "
            SELECT COUNT(DISTINCT wd.work_date) AS total_sessions
            FROM Work_Details wd
            JOIN Partners p ON wd.partner_id = p.partner_id
            JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
            WHERE wd.work_month_id = ?
            " . ($year_jalali ? "AND wm.start_date >= ? AND wm.start_date < ?" : "") . "
        ";
        $params = [$work_month_id];
        if ($year_jalali) {
            $params[] = $start_date;
            $params[] = $end_date;
        }
        if ($user_id !== 'all') {
            $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
            $params[] = $user_id;
            $params[] = $user_id;
        }
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $sessions = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_sessions = $sessions['total_sessions'] ?? 0;
        error_log("Admin Total sessions: $total_sessions");

        // لیست محصولات
        $products = [];
        $query = "
            SELECT oi.product_name, oi.unit_price, SUM(oi.quantity) AS total_quantity, SUM(oi.total_price) AS total_price
            FROM Order_Items oi
            JOIN Orders o ON oi.order_id = o.order_id
            JOIN Work_Details wd ON o.work_details_id = wd.id
            JOIN Partners p ON wd.partner_id = p.partner_id
            JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
            WHERE wd.work_month_id = ?
            " . ($year_jalali ? "AND wm.start_date >= ? AND wm.start_date < ?" : "") . "
        ";
        $params = [$work_month_id];
        if ($year_jalali) {
            $params[] = $start_date;
            $params[] = $end_date;
        }
        if ($user_id !== 'all') {
            $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
            $params[] = $user_id;
            $params[] = $user_id;
        }
        $query .= " GROUP BY oi.product_name, oi.unit_price ORDER BY oi.product_name COLLATE utf8mb4_persian_ci";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Admin Products fetched: " . count($products));

        // تولید HTML جدول
        $html = '<table class="table table-light"><thead><tr><th>ردیف</th><th>اقلام</th><th>قیمت واحد</th><th>تعداد</th><th>قیمت کل</th></tr></thead><tbody>';
        if (empty($products)) {
            $html .= '<tr><td colspan="5" class="text-center">محصولی یافت نشد.</td></tr>';
        } else {
            $row_number = 1;
            foreach ($products as $product) {
                $html .= '<tr>';
                $html .= '<td>' . $row_number++ . '</td>';
                $html .= '<td>' . htmlspecialchars($product['product_name']) . '</td>';
                $html .= '<td>' . number_format($product['unit_price'], 0) . ' تومان</td>';
                $html .= '<td>' . $product['total_quantity'] . '</td>';
                $html .= '<td>' . number_format($product['total_price'], 0) . ' تومان</td>';
                $html .= '</tr>';
            }
        }
        $html .= '</tbody></table>';

        error_log("Admin HTML generated: " . substr($html, 0, 100) . "...");

        echo json_encode([
            'success' => true,
            'html' => $html,
            'total_sales' => $total_sales,
            'total_discount' => $total_discount,
            'total_sessions' => $total_sessions
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log("Error in get_admin_products: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'خطایی در سرور رخ داد: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// اگر درخواست مربوط به پیشنهاد محصولات بود (برای فرم‌ها)
$query = $_POST['query'] ?? '';
$work_details_id = $_POST['work_details_id'] ?? '';

if (empty($query)) {
    exit;
}

$products = [];
if ($work_details_id) {
    $stmt_work = $pdo->prepare("SELECT work_date FROM Work_Details WHERE id = ?");
    $stmt_work->execute([$work_details_id]);
    $work_date = $stmt_work->fetchColumn();
    error_log("Debug: work_details_id = $work_details_id, work_date = $work_date");

    if ($work_date) {
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
        error_log("Debug: Products fetched with work_date = $work_date, count = " . count($products));
    } else {
        error_log("Debug: No work_date found for work_details_id = $work_details_id");
    }
} else {
    $stmt = $pdo->prepare("SELECT product_id, product_name, unit_price FROM Products WHERE product_name LIKE ? LIMIT 10");
    $stmt->execute(['%' . $query . '%']);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Debug: No work_details_id, using base prices, count = " . count($products));
}

foreach ($products as $product) {
    echo "<a href='#' class='list-group-item list-group-item-action product-suggestion' data-product='" . json_encode($product) . "'>" . htmlspecialchars($product['product_name']) . " - " . number_format($product['unit_price'], 0) . " تومان</a>";
}
?>