<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

// تابع برای دریافت سال شمسی از تاریخ میلادی
function get_jalali_year($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    $gy = (int)$gy;
    $gm = (int)$gm;
    $gd = (int)$gd;
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return $jy;
}

// بررسی نقش کاربر
$current_user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user_role = $stmt->fetchColumn();

// دریافت پارامترها
$action = $_GET['action'] ?? '';
$year = $_GET['year'] ?? '';
$work_month_id = $_GET['work_month_id'] ?? '';
$display_filter = $_GET['display_filter'] ?? 'all';
$partner_role_filter = $_GET['partner_role'] ?? 'all';

$response = ['success' => false, 'message' => '', 'data' => [], 'total_invoices' => 0, 'total_payments' => 0, 'total_debt' => 0];

if ($action === 'get_bill_report' && $work_month_id && $year) {
    // محاسبه بازه تاریخ برای سال انتخاب‌شده
    $gregorian_year = (int)($year - 621); // تبدیل سال شمسی به میلادی (تقریبی)
    $start_date = "$gregorian_year-03-21";
    $end_date = ($gregorian_year + 1) . "-03-21";
    if ($year == 1404) {
        $start_date = "2025-03-21";
        $end_date = "2026-03-21";
    } elseif ($year == 1403) {
        $start_date = "2024-03-20";
        $end_date = "2025-03-21";
    }

    // جمع کل فاکتورها (فروش - تخفیف)
    $query = "
        SELECT COALESCE(SUM(o.total_amount - o.discount), 0) AS total_invoices
        FROM Orders o
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
        WHERE wd.work_month_id = ?
        AND wm.start_date >= ? AND wm.start_date < ?
    ";
    $params = [$work_month_id, $start_date, $end_date];

    if ($user_role !== 'admin') {
        if ($partner_role_filter === 'all') {
            $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
            $params[] = $current_user_id;
            $params[] = $current_user_id;
        } elseif ($partner_role_filter === 'partner1') {
            $query .= " AND p.user_id1 = ?";
            $params[] = $current_user_id;
        } elseif ($partner_role_filter === 'partner2') {
            $query .= " AND p.user_id2 = ?";
            $params[] = $current_user_id;
        }
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $total_invoices = $stmt->fetchColumn() ?? 0;

    // مجموع پرداختی‌ها (از جدول Order_Payments)
    $query = "
        SELECT COALESCE(SUM(op.amount), 0) AS total_payments
        FROM Order_Payments op
        JOIN Orders o ON op.order_id = o.order_id
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
        WHERE wd.work_month_id = ?
        AND wm.start_date >= ? AND wm.start_date < ?
    ";
    $params = [$work_month_id, $start_date, $end_date];

    if ($user_role !== 'admin') {
        if ($partner_role_filter === 'all') {
            $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
            $params[] = $current_user_id;
            $params[] = $current_user_id;
        } elseif ($partner_role_filter === 'partner1') {
            $query .= " AND p.user_id1 = ?";
            $params[] = $current_user_id;
        } elseif ($partner_role_filter === 'partner2') {
            $query .= " AND p.user_id2 = ?";
            $params[] = $current_user_id;
        }
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $total_payments = $stmt->fetchColumn() ?? 0;

    // مانده بدهی
    $total_debt = $total_invoices - $total_payments;

    // لیست فاکتورها برای دیتاتیبل
    $query = "
        SELECT o.created_at AS order_date, o.customer_name, 
               (o.total_amount - o.discount) AS invoice_amount,
               (o.total_amount - o.discount - COALESCE((
                   SELECT SUM(op.amount) 
                   FROM Order_Payments op 
                   WHERE op.order_id = o.order_id
               ), 0)) AS remaining_debt,
               u1.full_name AS partner1_name,
               u2.full_name AS partner2_name
        FROM Orders o
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        JOIN Users u1 ON p.user_id1 = u1.user_id
        JOIN Users u2 ON p.user_id2 = u2.user_id
        JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
        WHERE wd.work_month_id = ?
        AND wm.start_date >= ? AND wm.start_date < ?
    ";
    $params = [$work_month_id, $start_date, $end_date];

    if ($user_role !== 'admin') {
        if ($partner_role_filter === 'all') {
            $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
            $params[] = $current_user_id;
            $params[] = $current_user_id;
        } elseif ($partner_role_filter === 'partner1') {
            $query .= " AND p.user_id1 = ?";
            $params[] = $current_user_id;
        } elseif ($partner_role_filter === 'partner2') {
            $query .= " AND p.user_id2 = ?";
            $params[] = $current_user_id;
        }
    }

    if ($display_filter === 'debtors') {
        $query .= " AND (o.total_amount - o.discount - COALESCE((
                   SELECT SUM(op.amount) 
                   FROM Order_Payments op 
                   WHERE op.order_id = o.order_id
               ), 0)) > 0";
    }
    $query .= " ORDER BY o.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // فرمت داده‌ها برای دیتاتیبل
    $data = [];
    foreach ($bills as $bill) {
        $data[] = [
            'order_date' => gregorian_to_jalali_format($bill['order_date']),
            'customer_name' => htmlspecialchars($bill['customer_name']),
            'partners' => $user_role === 'seller' || $user_role === 'admin' ? htmlspecialchars($bill['partner1_name']) . ' - ' . htmlspecialchars($bill['partner2_name']) : '',
            'invoice_amount' => number_format($bill['invoice_amount'], 0) . ' تومان',
            'remaining_debt' => number_format($bill['remaining_debt'], 0) . ' تومان'
        ];
    }

    $response['success'] = true;
    $response['data'] = $data;
    $response['total_invoices'] = $total_invoices;
    $response['total_payments'] = $total_payments;
    $response['total_debt'] = $total_debt;
} else {
    $response['message'] = 'لطفاً ماه کاری را انتخاب کنید.';
}

header('Content-Type: application/json');
echo json_encode($response);
exit;