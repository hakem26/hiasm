<?php
ob_start(); // شروع بافر خروجی
session_start();
require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

// لگ کردن درخواست ورودی
error_log("Bill Request received: " . json_encode($_GET, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$action = $_GET['action'] ?? '';
$year_jalali = isset($_GET['year']) ? (int) $_GET['year'] : null;
$work_month_id = $_GET['work_month_id'] ?? '';
$display_filter = $_GET['display_filter'] ?? 'all'; // پیش‌فرض "همه"
$partner_role = $_GET['partner_role'] ?? 'all'; // پیش‌فرض "همه"
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

if ($action === 'get_bill_report' && $work_month_id && $current_user_id) {
    error_log("Starting get_bill_report: work_month_id = $work_month_id, user_id = $current_user_id, year_jalali = $year_jalali, display_filter = $display_filter, partner_role = $partner_role");
    header('Content-Type: application/json; charset=UTF-8');

    // بررسی نقش کاربر
    $stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
    $stmt->execute([$current_user_id]);
    $user_role = $stmt->fetchColumn();
    error_log("User role: $user_role");

    $total_invoices = 0;
    $total_payments = 0;
    $total_debt = 0;
    $bills = [];

    try {
        // جمع کل فاکتورها (final_amount = total_amount - discount)
        $query = "
            SELECT COALESCE(SUM(o.total_amount - COALESCE(o.discount, 0)), 0) AS total_invoices
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

        if ($user_role !== 'admin') {
            if ($partner_role === 'all') {
                $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
                $params[] = $current_user_id;
                $params[] = $current_user_id;
            } elseif ($partner_role === 'partner1') {
                $query .= " AND p.user_id1 = ?";
                $params[] = $current_user_id;
            } elseif ($partner_role === 'partner2') {
                $query .= " AND p.user_id2 = ?";
                $params[] = $current_user_id;
            }
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $total_invoices = $stmt->fetchColumn() ?? 0;
        error_log("Total invoices result: $total_invoices");

        // مجموع پرداختی‌ها
        $query = "
            SELECT COALESCE(SUM(op.amount), 0) AS total_payments
            FROM Order_Payments op
            JOIN Orders o ON op.order_id = o.order_id
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

        if ($user_role !== 'admin') {
            if ($partner_role === 'all') {
                $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
                $params[] = $current_user_id;
                $params[] = $current_user_id;
            } elseif ($partner_role === 'partner1') {
                $query .= " AND p.user_id1 = ?";
                $params[] = $current_user_id;
            } elseif ($partner_role === 'partner2') {
                $query .= " AND p.user_id2 = ?";
                $params[] = $current_user_id;
            }
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $total_payments = $stmt->fetchColumn() ?? 0;
        error_log("Total payments result: $total_payments");

        // مانده بدهی
        $total_debt = $total_invoices - $total_payments;
        error_log("Total debt calculated: $total_debt");

        // لیست فاکتورها برای دیتاتیبل
        $query = "
            SELECT o.order_id, o.created_at AS order_date, o.customer_name, 
                   (o.total_amount - COALESCE(o.discount, 0)) AS invoice_amount,
                   COALESCE(SUM(op.amount), 0) AS paid_amount,
                   u1.full_name AS partner1_name,
                   u2.full_name AS partner2_name
            FROM Orders o
            JOIN Work_Details wd ON o.work_details_id = wd.id
            JOIN Partners p ON wd.partner_id = p.partner_id
            LEFT JOIN Order_Payments op ON o.order_id = op.order_id
            LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
            LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
            JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
            WHERE wd.work_month_id = ?
            " . ($year_jalali ? "AND wm.start_date >= ? AND wm.start_date < ?" : "") . "
        ";
        $params = [$work_month_id];
        if ($year_jalali) {
            $params[] = $start_date;
            $params[] = $end_date;
        }

        if ($user_role !== 'admin') {
            if ($partner_role === 'all') {
                $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
                $params[] = $current_user_id;
                $params[] = $current_user_id;
            } elseif ($partner_role === 'partner1') {
                $query .= " AND p.user_id1 = ?";
                $params[] = $current_user_id;
            } elseif ($partner_role === 'partner2') {
                $query .= " AND p.user_id2 = ?";
                $params[] = $current_user_id;
            }
        }

        $query .= " GROUP BY o.order_id, o.created_at, o.customer_name, o.total_amount, o.discount";
        if ($display_filter === 'debtors') {
            $query .= " HAVING (o.total_amount - COALESCE(o.discount, 0) - COALESCE(SUM(op.amount), 0)) > 0";
        }
        $query .= " ORDER BY o.created_at DESC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Bills fetched: " . count($bills) . ", Sample: " . json_encode($bills[0] ?? 'No data', JSON_UNESCAPED_UNICODE));

        // فرمت داده‌ها برای دیتاتیبل
        $data = [];
        if (empty($bills)) {
            error_log("No bills found.");
        } else {
            foreach ($bills as $bill) {
                $remaining_debt = $bill['invoice_amount'] - $bill['paid_amount'];
                $data[] = [
                    'order_date' => gregorian_to_jalali_format($bill['order_date']),
                    'customer_name' => htmlspecialchars($bill['customer_name']),
                    'partners' => ($user_role === 'seller' || $user_role === 'admin') ? htmlspecialchars($bill['partner1_name']) . ' - ' . htmlspecialchars($bill['partner2_name']) : '',
                    'invoice_amount' => number_format($bill['invoice_amount'], 0) . ' تومان',
                    'remaining_debt' => number_format($remaining_debt, 0) . ' تومان'
                ];
            }
        }
        error_log("Data prepared for DataTables: " . json_encode($data, JSON_UNESCAPED_UNICODE));

        // تولید پاسخ JSON
        $response = [
            'success' => true,
            'data' => $data,
            'total_invoices' => $total_invoices,
            'total_payments' => $total_payments,
            'total_debt' => $total_debt
        ];
        error_log("Response before echo: " . json_encode($response, JSON_UNESCAPED_UNICODE));
        
        ob_end_clean();
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log("Error in get_bill_report: " . $e->getMessage());
        $error_response = [
            'success' => false,
            'message' => 'خطایی در سرور رخ داد: ' . $e->getMessage(),
            'data' => []
        ];
        ob_end_clean();
        echo json_encode($error_response, JSON_UNESCAPED_UNICODE);
    }
    exit;
} else {
    error_log("Invalid request: action=$action, work_month_id=$work_month_id, user_id=$current_user_id");
    $error_response = [
        'success' => false,
        'message' => 'درخواست نامعتبر است.',
        'data' => []
    ];
    ob_end_clean();
    echo json_encode($error_response, JSON_UNESCAPED_UNICODE);
    exit;
}
?>