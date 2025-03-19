<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json; charset=UTF-8');

$response = ['success' => false, 'message' => '', 'html' => ''];

$year = $_GET['year'] ?? '';
$user_id = $_GET['user_id'] ?? '';
$work_month_id = $_GET['work_month_id'] ?? 'all';
$work_date = $_GET['work_date'] ?? 'all';
$current_user_id = $_SESSION['user_id'] ?? null;

if (!$year || !$current_user_id) {
    $response['message'] = 'پارامترهای لازم ارائه نشده است.';
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// بررسی نقش کاربر
$stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user_role = $stmt->fetchColumn();

try {
    $query = "
        SELECT oi.product_name, oi.unit_price, SUM(oi.quantity) AS total_quantity, SUM(oi.total_price) AS total_price
        FROM Order_Items oi
        JOIN Orders o ON oi.order_id = o.order_id
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
        WHERE YEAR(wm.start_date) = ?
    ";
    $params = [$year];

    // فیلتر همکار
    if ($user_id !== 'all') {
        $query .= " AND p.user_id1 = ?";
        $params[] = $user_id;
    } elseif ($user_role !== 'admin') {
        $query .= " AND (p.user_id1 = ? OR wd.agency_owner_id = ?)";
        $params[] = $current_user_id;
        $params[] = $current_user_id;
    }

    // فیلتر ماه کاری
    if ($work_month_id !== 'all') {
        $query .= " AND wd.work_month_id = ?";
        $params[] = $work_month_id;
    }

    // فیلتر روز کاری
    if ($work_date !== 'all') {
        $query .= " AND wd.work_date = ?";
        $params[] = $work_date;
    }

    $query .= " GROUP BY oi.product_name, oi.unit_price ORDER BY oi.product_name COLLATE utf8mb4_persian_ci";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    $response['success'] = true;
    $response['html'] = $html;
} catch (Exception $e) {
    error_log("Error in get_sold_products: " . $e->getMessage());
    $response['message'] = 'خطایی در سرور رخ داد: ' . $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>