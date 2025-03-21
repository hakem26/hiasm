<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';
require_once 'persian_year.php';

$jalali_year = $_GET['year'] ?? null;
$work_month_id = $_GET['work_month_id'] ?? 'all';
$partner_id = $_GET['partner_id'] ?? 'all';
$current_user_id = $_SESSION['user_id'] ?? null;

if (!$jalali_year || !$current_user_id) {
    echo json_encode(['success' => false, 'message' => 'پارامترهای لازم ارائه نشده‌اند']);
    exit;
}

// پیدا کردن work_month_idهایی که توی سال شمسی انتخاب‌شده هستن
$selected_work_month_ids = [];
$stmt = $pdo->query("SELECT work_month_id, start_date FROM Work_Months");
$all_work_months = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($all_work_months as $month) {
    $jalali_year_from_date = get_persian_year($month['start_date']);
    if ($jalali_year_from_date == $jalali_year) {
        $selected_work_month_ids[] = $month['work_month_id'];
    }
}

// لاگ برای دیباگ
error_log("Selected work_month_ids in get_sold_products: " . print_r($selected_work_month_ids, true));

if (empty($selected_work_month_ids)) {
    echo json_encode(['success' => false, 'message' => 'سال انتخاب‌شده معتبر نیست']);
    exit;
}

$stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user_role = $stmt->fetchColumn();

// جمع کل فروش و تعداد کل محصولات
$total_sales = 0;
$total_quantity = 0;
$query = "
    SELECT COALESCE(SUM(o.total_amount), 0) AS total_sales,
           COALESCE(SUM(oi.quantity), 0) AS total_quantity
    FROM Orders o
    JOIN Order_Items oi ON o.order_id = oi.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
    WHERE wd.work_month_id IN (" . implode(',', array_fill(0, count($selected_work_month_ids), '?')) . ")
";
$params = $selected_work_month_ids;

if ($user_role !== 'admin') {
    $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
    $params[] = $current_user_id;
    $params[] = $current_user_id;
}

if ($work_month_id !== 'all') {
    $query .= " AND wd.work_month_id = ?";
    $params[] = $work_month_id;
}

if ($partner_id !== 'all') {
    $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
    $params[] = $partner_id;
    $params[] = $partner_id;
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);
$total_sales = $summary['total_sales'] ?? 0;
$total_quantity = $summary['total_quantity'] ?? 0;

// لاگ برای دیباگ
error_log("Total sales and quantity in get_sold_products: " . print_r($summary, true));

// لیست محصولات فروخته‌شده
$products = [];
$query = "
    SELECT oi.product_name, oi.unit_price, SUM(oi.quantity) AS total_quantity, SUM(oi.total_price) AS total_price
    FROM Order_Items oi
    JOIN Orders o ON oi.order_id = o.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
    WHERE wd.work_month_id IN (" . implode(',', array_fill(0, count($selected_work_month_ids), '?')) . ")
";
$params = $selected_work_month_ids;

if ($user_role !== 'admin') {
    $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
    $params[] = $current_user_id;
    $params[] = $current_user_id;
}

if ($work_month_id !== 'all') {
    $query .= " AND wd.work_month_id = ?";
    $params[] = $work_month_id;
}

if ($partner_id !== 'all') {
    $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
    $params[] = $partner_id;
    $params[] = $partner_id;
}

$query .= " GROUP BY oi.product_name, oi.unit_price ORDER BY oi.product_name COLLATE utf8mb4_persian_ci";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// لاگ برای دیباگ
error_log("Products in get_sold_products: " . print_r($products, true));

// تولید HTML جدول محصولات
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

echo json_encode([
    'success' => true,
    'total_quantity' => $total_quantity,
    'total_sales' => $total_sales,
    'html' => $html
]);
?>