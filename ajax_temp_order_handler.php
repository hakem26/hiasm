<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید.']);
    exit;
}

$action = $_POST['action'] ?? '';

function gregorian_to_jalali_short($gregorian_date) {
    if (!$gregorian_date || $gregorian_date == '0000-00-00') return 'نامشخص';
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%02d/%02d", $jd, $jm);
}

if ($action === 'get_work_days') {
    $work_month_id = (int)($_POST['work_month_id'] ?? 0);
    $user_id = (int)($_POST['user_id'] ?? 0);

    if ($work_month_id <= 0 || $user_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ورودی نامعتبر.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT wd.id, wd.work_date, u2.full_name
            FROM Work_Details wd
            JOIN Partners p ON wd.partner_id = p.partner_id
            LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
            WHERE wd.work_month_id = ? AND p.user_id1 = ?
            ORDER BY wd.work_date ASC
        ");
        $stmt->execute([$work_month_id, $user_id]);
        $work_days = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($work_days as $day) {
            $display = gregorian_to_jalali_short($day['work_date']) . ' - ' . ($day['full_name'] ?: 'بدون همکار');
            $result[] = ['id' => $day['id'], 'display' => $display];
        }

        echo json_encode(['success' => true, 'work_days' => $result]);
    } catch (Exception $e) {
        error_log("Error in get_work_days: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'خطا در دریافت تاریخ‌ها.']);
    }
    exit;
}

if ($action === 'convert_temp_order') {
    $temp_order_id = (int)($_POST['temp_order_id'] ?? 0);
    $work_details_id = (int)($_POST['work_details_id'] ?? 0);

    if ($temp_order_id <= 0 || $work_details_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ورودی نامعتبر.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // چک کردن سفارش و دسترسی
        $stmt_check = $pdo->prepare("
            SELECT tord.*, wm.start_date, wm.end_date
            FROM Temp_Orders tord
            JOIN Work_Months wm ON tord.work_month_id = wm.work_month_id
            WHERE tord.temp_order_id = ? AND tord.user_id = ?
        ");
        $stmt_check->execute([$temp_order_id, $_SESSION['user_id']]);
        $order = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'سفارش یافت نشد یا دسترسی ندارید.']);
            exit;
        }

        // چک کردن Work_Details
        $stmt_wd = $pdo->prepare("
            SELECT wd.id
            FROM Work_Details wd
            WHERE wd.id = ? AND wd.work_month_id = ?
        ");
        $stmt_wd->execute([$work_details_id, $order['work_month_id']]);
        if (!$stmt_wd->fetch()) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'تاریخ کاری نامعتبر است.']);
            exit;
        }

        // ایجاد سفارش جدید در Orders
        $stmt_order = $pdo->prepare("
            INSERT INTO Orders (work_details_id, customer_name, total_amount, discount, final_amount, is_main_order, created_at)
            VALUES (?, ?, ?, ?, ?, 0, ?)
        ");
        $stmt_order->execute([
            $work_details_id,
            $order['customer_name'],
            $order['total_amount'],
            $order['discount'],
            $order['final_amount'],
            $order['created_at']
        ]);
        $new_order_id = $pdo->lastInsertId();

        // کپی آیتم‌ها به Order_Items
        $stmt_items = $pdo->prepare("
            SELECT product_name, quantity, unit_price, extra_sale, total_price
            FROM Temp_Order_Items
            WHERE temp_order_id = ?
        ");
        $stmt_items->execute([$temp_order_id]);
        $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

        $stmt_insert_items = $pdo->prepare("
            INSERT INTO Order_Items (order_id, product_name, quantity, unit_price, extra_sale, total_price)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($items as $item) {
            $stmt_insert_items->execute([
                $new_order_id,
                $item['product_name'],
                $item['quantity'],
                $item['unit_price'],
                $item['extra_sale'],
                $item['total_price']
            ]);
        }

        // انتقال پرداخت‌ها
        $stmt_payments = $pdo->prepare("
            UPDATE Order_Payments
            SET order_id = ?
            WHERE order_id = ?
        ");
        $stmt_payments->execute([$new_order_id, $temp_order_id]);

        // حذف داده‌های قدیمی
        $stmt_delete_items = $pdo->prepare("DELETE FROM Temp_Order_Items WHERE temp_order_id = ?");
        $stmt_delete_items->execute([$temp_order_id]);

        $stmt_delete_order = $pdo->prepare("DELETE FROM Temp_Orders WHERE temp_order_id = ?");
        $stmt_delete_order->execute([$temp_order_id]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'سفارش با موفقیت به سفارش تاریخ‌دار تبدیل شد.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error in convert_temp_order: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'خطا در تبدیل سفارش: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'اقدام نامعتبر است.']);
?>