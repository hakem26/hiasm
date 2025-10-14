<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

$current_user_id = $_SESSION['user_id'] ?? 0;
$product_name = $_GET['product_name'] ?? '';
$selected_year = $_GET['year'] ?? null;
$selected_month = $_GET['work_month_id'] ?? 'all';
$selected_partner_id = $_GET['partner_id'] ?? 'all';
$selected_partner_type = $_GET['partner_type'] ?? 'all';

if (empty($product_name)) {
    echo json_encode(['success' => false, 'message' => 'نام محصول مشخص نشده است.']);
    exit;
}

$selected_work_month_ids = [];
if ($selected_year) {
    $stmt = $pdo->query("SELECT work_month_id FROM Work_Months WHERE YEAR(start_date) = ?");
    $stmt->execute([$selected_year]);
    $selected_work_month_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$query = "
    SELECT DISTINCT o.order_id, o.created_at, o.customer_name, oi.quantity
    FROM Orders o
    JOIN Order_Items oi ON o.order_id = oi.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE oi.product_name = ? AND wd.work_month_id IN (" . (empty($selected_work_month_ids) ? '0' : implode(',', array_fill(0, count($selected_work_month_ids), '?'))) . ")
";
$params = [$product_name];
if (!empty($selected_work_month_ids)) {
    $params = array_merge($params, $selected_work_month_ids);
}

if ($current_user_id && $_SESSION['role'] !== 'admin') {
    $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
    $params[] = $current_user_id;
    $params[] = $current_user_id;
}

if ($selected_month !== 'all') {
    $query .= " AND wd.work_month_id = ?";
    $params[] = $selected_month;
}

if ($selected_partner_id !== 'all') {
    $query .= " AND (";
    if ($selected_partner_type === 'leader') {
        $query .= "p.user_id1 = ?";
        $params[] = $selected_partner_id;
    } elseif ($selected_partner_type === 'sub') {
        $query .= "p.user_id2 = ?";
        $params[] = $selected_partner_id;
    } else {
        $query .= "p.user_id1 = ? OR p.user_id2 = ?";
        $params[] = $selected_partner_id;
        $params[] = $selected_partner_id;
    }
    $query .= ")";
}

$query .= " ORDER BY o.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($orders)) {
    echo json_encode(['success' => false, 'message' => 'سفارشی یافت نشد.']);
    exit;
}

$html = '<table class="table table-light">
    <thead>
        <tr>
            <th>کد سفارش</th>
            <th>تاریخ</th>
            <th>نام مشتری</th>
            <th>تعداد</th>
            <th>فاکتور</th>
        </tr>
    </thead>
    <tbody>';
foreach ($orders as $order) {
    $date = gregorian_to_jalali_format($order['created_at']);
    $html .= "<tr>
        <td>{$order['order_id']}</td>
        <td>{$date}</td>
        <td>" . htmlspecialchars($order['customer_name']) . "</td>
        <td>{$order['quantity']}</td>
        <td><a href='print_invoice.php?order_id={$order['order_id']}' class='btn btn-primary btn-sm' target='_blank'>مشاهده</a></td>
    </tr>";
}
$html .= '</tbody></table>';

echo json_encode(['success' => true, 'html' => $html]);
?>