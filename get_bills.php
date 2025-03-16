<?php
session_start();
require_once 'db.php';

// لگ کردن درخواست ورودی
error_log("Bill Request received: " . json_encode($_GET, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$action = $_GET['action'] ?? '';
$work_month_id = $_GET['work_month_id'] ?? '';
$user_id = $_GET['user_id'] ?? null; // دریافت user_id از درخواست
$current_user_id = $_SESSION['user_id'] ?? null;

// استفاده از user_id ارسالی، یا اگر نبود از سشن
$effective_user_id = $user_id !== null ? $user_id : $current_user_id;

if ($action === 'get_bill_report' && $work_month_id && $effective_user_id) {
    error_log("Starting get_bill_report: work_month_id = $work_month_id, user_id = $effective_user_id");
    header('Content-Type: application/json; charset=UTF-8');

    $total_invoices = 0;
    $total_payments = 0;
    $total_debt = 0;
    $bills = [];

    try {
        // جمع کل فاکتورها (فروش - تخفیف)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(o.total_amount - o.discount), 0) AS total_invoices
            FROM Orders o
            JOIN Work_Details wd ON o.work_details_id = wd.id
            JOIN Partners p ON wd.partner_id = p.partner_id
            WHERE wd.work_month_id = ? AND p.user_id1 = ?
        ");
        $stmt->execute([$work_month_id, $effective_user_id]);
        $total_invoices = $stmt->fetchColumn() ?? 0;
        error_log("Total invoices: $total_invoices");

        // مجموع پرداختی‌ها
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(o.paid_amount), 0) AS total_payments
            FROM Orders o
            JOIN Work_Details wd ON o.work_details_id = wd.id
            JOIN Partners p ON wd.partner_id = p.partner_id
            WHERE wd.work_month_id = ? AND p.user_id1 = ?
        ");
        $stmt->execute([$work_month_id, $effective_user_id]);
        $total_payments = $stmt->fetchColumn() ?? 0;
        error_log("Total payments: $total_payments");

        // مانده بدهی
        $total_debt = $total_invoices - $total_payments;

        // لیست فاکتورها برای جدول
        $stmt = $pdo->prepare("
            SELECT o.order_date, u.full_name AS customer_name, (o.total_amount - o.discount - o.paid_amount) AS remaining_debt
            FROM Orders o
            JOIN Work_Details wd ON o.work_details_id = wd.id
            JOIN Partners p ON wd.partner_id = p.partner_id
            JOIN Users u ON o.customer_id = u.user_id  -- فرض می‌کنیم customer_id به جدول Users ربط داره
            WHERE wd.work_month_id = ? AND p.user_id1 = ?
            ORDER BY o.order_date DESC
        ");
        $stmt->execute([$work_month_id, $effective_user_id]);
        $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Bills fetched: " . count($bills));

        // تولید HTML جدول
        $html = '<table class="table table-light"><thead><tr><th>تاریخ</th><th>نام مشتری</th><th>مانده بدهی</th></tr></thead><tbody>';
        if (empty($bills)) {
            $html .= '<tr><td colspan="3" class="text-center">فاکتوری یافت نشد.</td></tr>';
        } else {
            foreach ($bills as $bill) {
                $html .= '<tr>';
                $html .= '<td>' . gregorian_to_jalali_format($bill['order_date']) . '</td>';
                $html .= '<td>' . htmlspecialchars($bill['customer_name']) . '</td>';
                $html .= '<td>' . number_format($bill['remaining_debt'], 0) . ' تومان</td>';
                $html .= '</tr>';
            }
        }
        $html .= '</tbody></table>';

        error_log("HTML generated: " . substr($html, 0, 100) . "...");

        echo json_encode([
            'success' => true,
            'html' => $html,
            'total_invoices' => $total_invoices,
            'total_payments' => $total_payments,
            'total_debt' => $total_debt
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log("Error in get_bill_report: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'خطایی در سرور رخ داد: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?>
