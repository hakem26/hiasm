<?php
ob_start(); // شروع بافر خروجی در ابتدای فایل
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
$work_month_id = $_GET['work_month_id'] ?? '';
$display_filter = $_GET['display_filter'] ?? 'all'; // پیش‌فرض "همه"
$partner_role = $_GET['partner_role'] ?? 'all'; // پیش‌فرض "همه"
$current_user_id = $_SESSION['user_id'] ?? null;

if ($action === 'get_bill_report' && $work_month_id && $current_user_id) {
    error_log("Starting get_bill_report: work_month_id = $work_month_id, user_id = $current_user_id, display_filter = $display_filter, partner_role = $partner_role");
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
        error_log("Executing total_invoices query...");
        // جمع کل فاکتورها (فروش - تخفیف)
        $query = "
            SELECT COALESCE(SUM(o.total_amount - o.discount), 0) AS total_invoices
            FROM Orders o
            JOIN Work_Details wd ON o.work_details_id = wd.id
            JOIN Partners p ON wd.partner_id = p.partner_id
            WHERE wd.work_month_id = ?
        ";
        $params = [$work_month_id];

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

        error_log("Executing total_payments query...");
        // مجموع پرداختی‌ها (از جدول Order_Payments)
        $query = "
            SELECT COALESCE(SUM(op.amount), 0) AS total_payments
            FROM Order_Payments op
            JOIN Orders o ON op.order_id = o.order_id
            JOIN Work_Details wd ON o.work_details_id = wd.id
            JOIN Partners p ON wd.partner_id = p.partner_id
            WHERE wd.work_month_id = ?
        ";
        $params = [$work_month_id];

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

        error_log("Executing bills query...");
        // لیست فاکتورها برای جدول (با فیلتر بدهکاران)
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
            WHERE wd.work_month_id = ?
        ";
        $params = [$work_month_id];

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
        error_log("Bills fetched: " . count($bills) . ", Sample: " . json_encode($bills[0] ?? 'No data', JSON_UNESCAPED_UNICODE));

        // تولید HTML جدول
        $html = '<table class="table table-light"><thead><tr><th>تاریخ</th><th>نام مشتری</th>';
        if ($user_role === 'seller' || $user_role === 'admin') {
            $html .= '<th>همکاران</th>';
        }
        $html .= '<th>مبلغ فاکتور</th><th>مانده بدهی</th></tr></thead><tbody>';
        if (empty($bills)) {
            $html .= '<tr><td colspan="' . ($user_role === 'seller' || $user_role === 'admin' ? 5 : 4) . '" class="text-center">فاکتوری یافت نشد.</td></tr>';
        } else {
            foreach ($bills as $bill) {
                $html .= '<tr>';
                $html .= '<td>' . gregorian_to_jalali_format($bill['order_date']) . '</td>';
                $html .= '<td>' . htmlspecialchars($bill['customer_name']) . '</td>';
                if ($user_role === 'seller' || $user_role === 'admin') {
                    $html .= '<td>' . htmlspecialchars($bill['partner1_name']) . ' - ' . htmlspecialchars($bill['partner2_name']) . '</td>';
                }
                $html .= '<td>' . number_format($bill['invoice_amount'], 0) . ' تومان</td>';
                $html .= '<td>' . number_format($bill['remaining_debt'], 0) . ' تومان</td>';
                $html .= '</tr>';
            }
        }
        $html .= '</tbody></table>';

        error_log("HTML generated: " . substr($html, 0, 100) . "...");

        // تولید پاسخ JSON
        $response = [
            'success' => true,
            'html' => $html,
            'total_invoices' => $total_invoices,
            'total_payments' => $total_payments,
            'total_debt' => $total_debt
        ];
        error_log("Response before echo: " . json_encode($response, JSON_UNESCAPED_UNICODE));
        
        // پاک کردن بافر و ارسال خروجی
        ob_end_clean();
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log("Error in get_bill_report: " . $e->getMessage());
        $error_response = [
            'success' => false,
            'message' => 'خطایی در سرور رخ داد: ' . $e->getMessage()
        ];
        
        // پاک کردن بافر و ارسال خطا
        ob_end_clean();
        echo json_encode($error_response, JSON_UNESCAPED_UNICODE);
    }
    exit;
} else {
    error_log("Invalid request: action=$action, work_month_id=$work_month_id, user_id=$current_user_id");
    $error_response = [
        'success' => false,
        'message' => 'درخواست نامعتبر است.'
    ];
    
    // پاک کردن بافر و ارسال خطا
    ob_end_clean();
    echo json_encode($error_response, JSON_UNESCAPED_UNICODE);
    exit;
}
?>