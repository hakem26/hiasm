<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

$current_user_id = $_SESSION['user_id'];
$selected_year = $_GET['year'] ?? 'all';
$selected_work_month_id = $_GET['work_month_id'] ?? 'all';
$selected_partner_id = $_GET['user_id'] ?? 'all';
$selected_work_day_id = $_GET['work_day_id'] ?? 'all';
$page = (int)($_GET['page'] ?? 1);
$per_page = 10;

$orders_query = "
    SELECT o.order_id, o.customer_name, o.total_amount, o.discount, o.final_amount,
           SUM(p.amount) AS paid_amount,
           (o.final_amount - COALESCE(SUM(p.amount), 0)) AS remaining_amount,
           wd.work_date, 
           COALESCE(
               (SELECT CASE 
                   WHEN p.user_id1 = ? THEN u2.full_name 
                   WHEN p.user_id2 = ? THEN u1.full_name 
                   ELSE 'نامشخص' 
               END
               FROM Partners p
               LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
               LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
               WHERE p.partner_id = wd.partner_id),
               'نامشخص'
           ) AS partner_name,
           wd.id AS work_details_id
    FROM Orders o
    LEFT JOIN Payments p ON o.order_id = p.order_id
    LEFT JOIN Work_Details wd ON o.work_details_id = wd.id";

$conditions = [];
$params = [];
$params[] = $current_user_id; // برای user_id1 توی partner_name
$params[] = $current_user_id; // برای user_id2 توی partner_name

if ($selected_year && $selected_year != 'all') {
    $conditions[] = "YEAR(wd.work_date) = ?";
    $params[] = $selected_year;
}

if ($selected_work_month_id && $selected_work_month_id != 'all') {
    $conditions[] = "wd.work_month_id = ?";
    $params[] = $selected_work_month_id;
}

if ($selected_partner_id && $selected_partner_id != 'all') {
    $conditions[] = "EXISTS (
        SELECT 1 FROM Partners p 
        WHERE p.partner_id = wd.partner_id 
        AND (p.user_id1 = ? OR p.user_id2 = ?)
    )";
    $params[] = $selected_partner_id;
    $params[] = $selected_partner_id;
}

if ($selected_work_day_id && $selected_work_day_id != 'all') {
    $conditions[] = "wd.id = ?";
    $params[] = $selected_work_day_id;
}

if (!empty($conditions)) {
    $orders_query .= " WHERE " . implode(" AND ", $conditions);
}

$orders_query .= "
    GROUP BY o.order_id, o.customer_name, o.total_amount, o.discount, o.final_amount, wd.work_date, partner_name";

// تعداد کل فاکتورها
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM ($orders_query) AS subquery");
$stmt_count->execute($params);
$total_orders = $stmt_count->fetchColumn();
$total_pages = ceil($total_orders / $per_page);
$offset = ($page - 1) * $per_page;

$orders_query .= " LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$stmt_orders = $pdo->prepare($orders_query);
$stmt_orders->execute($params);
$orders = $stmt_orders->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'data' => [
        'orders' => $orders,
        'total_pages' => $total_pages
    ]
]);