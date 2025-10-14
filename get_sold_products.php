<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';
require_once 'persian_year.php';

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

$jalali_year = $_GET['year'] ?? null;
$work_month_id = $_GET['work_month_id'] ?? 'all';
$partner_id = $_GET['partner_id'] ?? 'all';
$partner_type = $_GET['partner_type'] ?? 'all';
$current_user_id = $_SESSION['user_id'] ?? null;

if (!$jalali_year || !$current_user_id) {
    header('Content-Type: application/json');
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

if (empty($selected_work_month_ids)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'سال انتخاب‌شده معتبر نیست']);
    exit;
}

$stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user_role = $stmt->fetchColumn();

// جمع کل فروش و تعداد کل محصولات
$total_sales = 0;
$total_quantity = 0;
$sales_query = "
    SELECT COALESCE(SUM(o.total_amount), 0) AS total_sales
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
    WHERE wd.work_month_id IN (" . implode(',', array_fill(0, count($selected_work_month_ids), '?')) . ")
";
$quantity_query = "
    SELECT COALESCE(SUM(oi.quantity), 0) AS total_quantity
    FROM Order_Items oi
    JOIN Orders o ON oi.order_id = o.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
    WHERE wd.work_month_id IN (" . implode(',', array_fill(0, count($selected_work_month_ids), '?')) . ")
";
$params = $selected_work_month_ids;
$params_quantity = $selected_work_month_ids;

if ($user_role !== 'admin') {
    $sales_query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
    $quantity_query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
    $params[] = $current_user_id;
    $params[] = $current_user_id;
    $params_quantity[] = $current_user_id;
    $params_quantity[] = $current_user_id;
}

if ($work_month_id !== 'all') {
    $sales_query .= " AND wd.work_month_id = ?";
    $quantity_query .= " AND wd.work_month_id = ?";
    $params[] = $work_month_id;
    $params_quantity[] = $work_month_id;
}

if ($partner_type !== 'all') {
    $sales_query .= " AND (";
    $quantity_query .= " AND (";
    if ($partner_type === 'leader') {
        $sales_query .= "p.user_id1 = ?";
        $quantity_query .= "p.user_id1 = ?";
        $params[] = $current_user_id;
        $params_quantity[] = $current_user_id;
    } elseif ($partner_type === 'sub') {
        $sales_query .= "p.user_id2 = ?";
        $quantity_query .= "p.user_id2 = ?";
        $params[] = $current_user_id;
        $params_quantity[] = $current_user_id;
    }
    $sales_query .= ")";
    $quantity_query .= ")";
}

if ($partner_id !== 'all') {
    $sales_query .= " AND (";
    $quantity_query .= " AND (";
    if ($partner_type === 'leader') {
        $sales_query .= "p.user_id1 = ?";
        $quantity_query .= "p.user_id1 = ?";
        $params[] = $partner_id;
        $params_quantity[] = $partner_id;
    } elseif ($partner_type === 'sub') {
        $sales_query .= "p.user_id2 = ?";
        $quantity_query .= "p.user_id2 = ?";
        $params[] = $partner_id;
        $params_quantity[] = $partner_id;
    } else {
        $sales_query .= "p.user_id1 = ? OR p.user_id2 = ?";
        $quantity_query .= "p.user_id1 = ? OR p.user_id2 = ?";
        $params[] = $partner_id;
        $params[] = $partner_id;
        $params_quantity[] = $partner_id;
        $params_quantity[] = $partner_id;
    }
    $sales_query .= ")";
    $quantity_query .= ")";
}

try {
    $stmt = $pdo->prepare($sales_query);
    $stmt->execute($params);
    $total_sales = $stmt->fetchColumn() ?? 0;

    $stmt = $pdo->prepare($quantity_query);
    $stmt->execute($params_quantity);
    $total_quantity = $stmt->fetchColumn() ?? 0;
} catch (Exception $e) {
    error_log("Error in get_sold_products queries: " . $e->getMessage() . " Query: $sales_query, Params: " . print_r($params, true));
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'خطا در پایگاه داده']);
    exit;
}

// محاسبه total_leader_sales (فقط اگر کاربر سرگروه باشه)
$total_leader_sales = 0;
if ($user_role !== 'admin') {
    $leader_query = "
        SELECT COALESCE(SUM(o.total_amount), 0) AS total_leader_sales
        FROM Orders o
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
        WHERE wd.work_month_id IN (" . implode(',', array_fill(0, count($selected_work_month_ids), '?')) . ")
        AND p.user_id1 = ?
    ";
    $leader_params = array_merge($selected_work_month_ids, [$current_user_id]);

    if ($work_month_id !== 'all') {
        $leader_query .= " AND wd.work_month_id = ?";
        $leader_params[] = $work_month_id;
    }

    if ($partner_id !== 'all') {
        $leader_query .= " AND p.user_id2 = ?";
        $leader_params[] = $partner_id;
    }

    try {
        $stmt_leader = $pdo->prepare($leader_query);
        $stmt_leader->execute($leader_params);
        $total_leader_sales = $stmt_leader->fetchColumn() ?? 0;
    } catch (Exception $e) {
        error_log("Error in leader sales query: " . $e->getMessage() . " Query: $leader_query, Params: " . print_r($leader_params, true));
    }
}

// لیست محصولات فروخته‌شده
$products_query = "
    SELECT oi.product_name, oi.unit_price, SUM(oi.quantity) AS total_quantity, SUM(oi.total_price) AS total_price
    FROM Order_Items oi
    JOIN Orders o ON oi.order_id = o.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
    WHERE wd.work_month_id IN (" . implode(',', array_fill(0, count($selected_work_month_ids), '?')) . ")
";
$params_products = $selected_work_month_ids;

if ($user_role !== 'admin') {
    $products_query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
    $params_products[] = $current_user_id;
    $params_products[] = $current_user_id;
}

if ($work_month_id !== 'all') {
    $products_query .= " AND wd.work_month_id = ?";
    $params_products[] = $work_month_id;
}

if ($partner_type !== 'all') {
    $products_query .= " AND (";
    if ($partner_type === 'leader') {
        $products_query .= "p.user_id1 = ?";
        $params_products[] = $current_user_id;
    } elseif ($partner_type === 'sub') {
        $products_query .= "p.user_id2 = ?";
        $params_products[] = $current_user_id;
    }
    $products_query .= ")";
    error_log("Partner Type Applied: $partner_type");
}

if ($partner_id !== 'all') {
    $products_query .= " AND (";
    if ($partner_type === 'leader') {
        $products_query .= "p.user_id1 = ?";
        $params_products[] = $partner_id;
    } elseif ($partner_type === 'sub') {
        $products_query .= "p.user_id2 = ?";
        $params_products[] = $partner_id;
    } else {
        $products_query .= "p.user_id1 = ? OR p.user_id2 = ?";
        $params_products[] = $partner_id;
        $params_products[] = $partner_id;
    }
    $products_query .= ")";
}

$products_query .= " GROUP BY oi.product_name, oi.unit_price ORDER BY oi.product_name COLLATE utf8mb4_persian_ci";

try {
    $stmt = $pdo->prepare($products_query);
    $stmt->execute($params_products);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Products fetched: " . print_r($products, true));
} catch (Exception $e) {
    error_log("Error fetching products in get_sold_products: " . $e->getMessage() . " Query: $products_query, Params: " . print_r($params_products, true));
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'خطا در دریافت محصولات']);
    exit;
}

// تولید HTML جدول محصولات
$html = '<table class="table table-light"><thead><tr><th>ردیف</th><th>اقلام</th><th>قیمت واحد</th><th>تعداد</th><th>قیمت کل</th><th>سفارشات</th></tr></thead><tbody>';
if (empty($products)) {
    $html .= '<tr><td colspan="6" class="text-center">محصولی یافت نشد.</td></tr>';
} else {
    $row_number = 1;
    foreach ($products as $product) {
        $html .= '<tr data-product-name="' . htmlspecialchars($product['product_name']) . '">';
        $html .= '<td>' . $row_number++ . '</td>';
        $html .= '<td>' . htmlspecialchars($product['product_name']) . '</td>';
        $html .= '<td>' . number_format($product['unit_price'], 0) . ' تومان</td>';
        $html .= '<td>' . $product['total_quantity'] . '</td>';
        $html .= '<td>' . number_format($product['total_price'], 0) . ' تومان</td>';
        $html .= '<td><button type="button" class="btn btn-info btn-sm view-orders" data-product="' . htmlspecialchars($product['product_name']) . '">مشاهده سفارشات</button></td>';
        $html .= '</tr>';
    }
}
$html .= '</tbody></table>';

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'total_quantity' => $total_quantity,
    'total_sales' => $total_sales,
    'total_leader_sales' => $total_leader_sales,
    'html' => $html
]);
exit;
?>