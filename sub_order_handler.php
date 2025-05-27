<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}

require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date)
{
    if (!$gregorian_date)
        return 'نامشخص';
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    if (!is_numeric($gy) || !is_numeric($gm) || !is_numeric($gd))
        return 'نامشخص';
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

function sendResponse($success, $message, $data = [])
{
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

$action = $_POST['action'] ?? '';
$current_user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

if ($is_admin) {
    sendResponse(false, 'دسترسی غیرمجاز.');
}

$_SESSION['sub_order_items'] = $_SESSION['sub_order_items'] ?? [];
$_SESSION['sub_discount'] = $_SESSION['sub_discount'] ?? 0;
$_SESSION['sub_invoice_prices'] = $_SESSION['sub_invoice_prices'] ?? ['postal' => 50000];
$_SESSION['sub_postal_enabled'] = $_SESSION['sub_postal_enabled'] ?? false;
$_SESSION['sub_postal_price'] = $_SESSION['sub_postal_price'] ?? 50000;

try {
    switch ($action) {
        case 'get_partners':
            $work_month_id = $_POST['work_month_id'] ?? '';
            if (!$work_month_id) {
                sendResponse(false, 'ماه کاری مشخص نیست.');
            }

            $stmt = $pdo->prepare("
                SELECT DISTINCT u.user_id, u.full_name
                FROM Work_Details wd
                JOIN Partners p ON wd.partner_id = p.partner_id
                JOIN Users u ON (p.user_id1 = u.user_id OR p.user_id2 = u.user_id)
                WHERE wd.work_month_id = ? AND u.user_id != ? AND u.role = 'seller'
                ORDER BY u.full_name
            ");
            $stmt->execute([$work_month_id, $current_user_id]);
            $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($partners)) {
                sendResponse(false, 'هیچ همکاری برای این ماه کاری یافت نشد.');
            }

            sendResponse(true, 'موفق', ['partners' => $partners]);

        case 'get_work_days':
            $partner_id = $_POST['partner_id'] ?? '';
            $work_month_id = $_POST['work_month_id'] ?? '';
            if (!$partner_id || !$work_month_id) {
                sendResponse(false, 'همکار یا ماه کاری مشخص نیست.');
            }

            $stmt = $pdo->prepare("
                SELECT wd.id, wd.work_date
                FROM Work_Details wd
                JOIN Partners p ON wd.partner_id = p.partner_id
                WHERE wd.work_month_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
                ORDER BY wd.work_date
            ");
            $stmt->execute([$work_month_id, $partner_id, $partner_id]);
            $work_days = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formatted_days = array_map(function ($day) {
                return [
                    'id' => $day['id'],
                    'jalali_date' => gregorian_to_jalali_format($day['work_date'])
                ];
            }, $work_days);

            if (empty($formatted_days)) {
                sendResponse(false, 'هیچ روز کاری برای این همکار یافت نشد.');
            }

            sendResponse(true, 'موفق', ['work_days' => $formatted_days]);

        case 'add_sub_item':
            $customer_name = trim($_POST['customer_name'] ?? '');
            $product_id = $_POST['product_id'] ?? '';
            $quantity = floatval($_POST['quantity'] ?? 0);
            $unit_price = floatval($_POST['unit_price'] ?? 0);
            $extra_sale = floatval($_POST['extra_sale'] ?? 0);
            $discount = floatval($_POST['discount'] ?? 0);
            $work_details_id = $_POST['work_details_id'] ?? '';
            $partner_id = $_POST['partner_id'] ?: $current_user_id;
            $product_name = trim($_POST['product_name'] ?? '');

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || !$product_name) {
                sendResponse(false, 'لطفاً همه فیلدها را پر کنید.');
            }

            $total_price = $quantity * ($unit_price + $extra_sale);
            $item = [
                'product_id' => $product_id,
                'product_name' => $product_name,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'extra_sale' => $extra_sale,
                'total_price' => $total_price
            ];

            $_SESSION['sub_order_items'][] = $item;
            $_SESSION['sub_discount'] = $discount;

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            sendResponse(true, 'محصول اضافه شد.', [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ]);

        case 'delete_sub_item':
            $index = $_POST['index'] ?? '';
            if (!isset($_SESSION['sub_order_items'][$index])) {
                sendResponse(false, 'آیتم یافت نشد.');
            }

            unset($_SESSION['sub_order_items'][$index]);
            $_SESSION['sub_order_items'] = array_values($_SESSION['sub_order_items']);
            unset($_SESSION['sub_invoice_prices'][$index]);

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $_SESSION['sub_discount'] + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            sendResponse(true, 'محصول حذف شد.', [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $_SESSION['sub_discount'],
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ]);

        case 'set_sub_invoice_price':
            $index = $_POST['index'] ?? '';
            $invoice_price = floatval($_POST['invoice_price'] ?? 0);

            if ($invoice_price < 0) {
                sendResponse(false, 'قیمت فاکتور نمی‌تواند منفی باشد.');
            }

            $_SESSION['sub_invoice_prices'][$index] = $invoice_price;

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $_SESSION['sub_discount'] + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            sendResponse(true, 'قیمت فاکتور تنظیم شد.', [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $_SESSION['sub_discount'],
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ]);

        case 'set_sub_postal_option':
            $enable_postal = filter_var($_POST['enable_postal'], FILTER_VALIDATE_BOOLEAN);
            $_SESSION['sub_postal_enabled'] = $enable_postal;

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $_SESSION['sub_discount'] + ($enable_postal ? $_SESSION['sub_postal_price'] : 0);

            sendResponse(true, 'موفق', [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $_SESSION['sub_discount'],
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ]);

        case 'update_sub_discount':
            $discount = floatval($_POST['discount'] ?? 0);
            if ($discount < 0) {
                sendResponse(false, 'تخفیف نمی‌تواند منفی باشد.');
            }

            $_SESSION['sub_discount'] = $discount;

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            sendResponse(true, 'تخفیف به‌روز شد.', [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ]);

        case 'get_items':
            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $_SESSION['sub_discount'] + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            sendResponse(true, 'موفقیت', [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $_SESSION['sub_discount'],
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ]);

        case 'finalize_sub_order':
            $customer_name = trim($_POST['customer_name'] ?? '');
            $work_details_id = $_POST['work_details_id'] ?? '';
            $partner_id = $_POST['partner_id'] ?? $current_user_id;
            $discount = floatval($_POST['discount'] ?? 0);
            $convert_to_main = isset($_POST['convert_to_main']) ? (int) $_POST['convert_to_main'] : 0;
            $work_month_id = $_POST['work_month_id'] ?? '';

            if (!$customer_name || !$work_month_id) {
                sendResponse(false, 'نام مشتری یا ماه کاری مشخص نیست.');
            }

            if ($convert_to_main && (!$partner_id || !$work_details_id)) {
                sendResponse(false, 'همکار یا تاریخ کاری انتخاب نشده است.');
            }

            if (empty($_SESSION['sub_order_items'])) {
                sendResponse(false, 'هیچ محصولی در پیش‌فاکتور نیست.');
            }

            // برای پیش‌فاکتور، work_details_id را از Work_Details می‌گیریم
            if (!$convert_to_main) {
                $stmt = $pdo->prepare("
            SELECT wd.id
            FROM Work_Details wd
            JOIN Partners p ON wd.partner_id = p.partner_id
            WHERE wd.work_month_id = ? AND p.user_id1 = ?
            LIMIT 1
        ");
                $stmt->execute([$work_month_id, $current_user_id]);
                $work_details_id = $stmt->fetchColumn();
                if (!$work_details_id) {
                    sendResponse(false, 'جزئیات کاری برای این ماه یافت نشد.');
                }
            }

            $pdo->beginTransaction();

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            $stmt = $pdo->prepare("
        INSERT INTO Orders (customer_name, total_amount, discount, final_amount, work_details_id, is_main_order, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
            $stmt->execute([$customer_name, $total_amount, $discount, $final_amount, $work_details_id, $convert_to_main]);

            $order_id = $pdo->lastInsertId();

            foreach ($_SESSION['sub_order_items'] as $index => $item) {
                $invoice_price = $_SESSION['sub_invoice_prices'][$index] ?? $item['total_price'];
                $stmt = $pdo->prepare("
            INSERT INTO Order_Items (order_id, product_name, unit_price, extra_sale, quantity, total_price)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
                $stmt->execute([$order_id, $item['product_name'], $item['unit_price'], $item['extra_sale'], $item['quantity'], $invoice_price]);
            }

            if ($_SESSION['sub_postal_enabled']) {
                $postal_price = $_SESSION['sub_invoice_prices']['postal'] ?? $_SESSION['sub_postal_price'];
                $stmt = $pdo->prepare("
            INSERT INTO Order_Items (order_id, product_name, unit_price, quantity, total_price)
            VALUES (?, ?, ?, ?, ?)
        ");
                $stmt->execute([$order_id, 'ارسال پستی', $postal_price, 1, $postal_price]);
            }

            // پاک کردن سشن‌ها
            unset($_SESSION['sub_order_items']);
            unset($_SESSION['sub_discount']);
            unset($_SESSION['sub_invoice_prices']);
            unset($_SESSION['sub_postal_enabled']);
            unset($_SESSION['sub_postal_price']);
            unset($_SESSION['is_sub_order_in_progress']);

            $pdo->commit();

            sendResponse(true, 'پیش‌فاکتور با موفقیت ثبت شد.', ['redirect' => 'orders.php?work_month_id=' . $work_month_id]);

        case 'load_sub_order':
            $order_id = $_POST['order_id'] ?? '';
            if (!$order_id) {
                sendResponse(false, 'شناسه سفارش مشخص نیست.');
            }

            $stmt = $pdo->prepare("
        SELECT order_id, customer_name, total_amount, discount, final_amount, work_details_id, is_main_order
        FROM Orders WHERE order_id = ? AND is_main_order = 0
    ");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                sendResponse(false, 'پیش‌فاکتور یافت نشد یا قابل ویرایش نیست.');
            }

            $stmt = $pdo->prepare("
        SELECT item_id, product_name, unit_price, extra_sale, quantity, total_price
        FROM Order_Items WHERE order_id = ?
    ");
            $stmt->execute([$order_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $sub_order_items = [];
            $sub_invoice_prices = [];
            $sub_postal_enabled = false;
            $sub_postal_price = 50000;

            foreach ($items as $index => $item) {
                if ($item['product_name'] === 'ارسال پستی') {
                    $sub_postal_enabled = true;
                    $sub_postal_price = $item['total_price'];
                    $sub_invoice_prices['postal'] = $item['total_price'];
                } else {
                    $sub_order_items[] = [
                        'product_id' => '', // product_id در Order_Items نیست، فقط برای سازگاری با add_sub_order
                        'product_name' => $item['product_name'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'extra_sale' => $item['extra_sale'],
                        'total_price' => $item['total_price']
                    ];
                    $sub_invoice_prices[$index] = $item['total_price'];
                }
            }

            $_SESSION['sub_order_items'] = $sub_order_items;
            $_SESSION['sub_discount'] = $order['discount'];
            $_SESSION['sub_invoice_prices'] = $sub_invoice_prices;
            $_SESSION['sub_postal_enabled'] = $sub_postal_enabled;
            $_SESSION['sub_postal_price'] = $sub_postal_price;
            $_SESSION['is_sub_order_in_progress'] = true;

            sendResponse(true, 'موفق', [
                'order' => $order,
                'items' => $sub_order_items,
                'total_amount' => $order['total_amount'],
                'discount' => $order['discount'],
                'final_amount' => $order['final_amount'],
                'invoice_prices' => $sub_invoice_prices,
                'sub_postal_enabled' => $sub_postal_enabled,
                'sub_postal_price' => $sub_postal_price
            ]);

        case 'update_sub_order':
            $order_id = $_POST['order_id'] ?? '';
            $customer_name = trim($_POST['customer_name'] ?? '');
            $discount = floatval($_POST['discount'] ?? 0);
            $work_month_id = $_POST['work_month_id'] ?? '';

            if (!$order_id || !$customer_name || !$work_month_id) {
                sendResponse(false, 'نام مشتری، شناسه سفارش یا ماه کاری مشخص نیست.');
            }

            if (empty($_SESSION['sub_order_items'])) {
                sendResponse(false, 'هیچ محصولی در پیش‌فاکتور نیست.');
            }

            $stmt = $pdo->prepare("SELECT order_id FROM Orders WHERE order_id = ? AND is_main_order = 0");
            $stmt->execute([$order_id]);
            if (!$stmt->fetch()) {
                sendResponse(false, 'پیش‌فاکتور یافت نشد یا قابل ویرایش نیست.');
            }

            $pdo->beginTransaction();

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            $stmt = $pdo->prepare("
        UPDATE Orders
        SET customer_name = ?, total_amount = ?, discount = ?, final_amount = ?
        WHERE order_id = ? AND is_main_order = 0
    ");
            $stmt->execute([$customer_name, $total_amount, $discount, $final_amount, $order_id]);

            $stmt = $pdo->prepare("DELETE FROM Order_Items WHERE order_id = ?");
            $stmt->execute([$order_id]);

            foreach ($_SESSION['sub_order_items'] as $index => $item) {
                $invoice_price = $_SESSION['sub_invoice_prices'][$index] ?? $item['total_price'];
                $stmt = $pdo->prepare("
            INSERT INTO Order_Items (order_id, product_name, unit_price, extra_sale, quantity, total_price)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
                $stmt->execute([$order_id, $item['product_name'], $item['unit_price'], $item['extra_sale'], $item['quantity'], $invoice_price]);
            }

            if ($_SESSION['sub_postal_enabled']) {
                $postal_price = $_SESSION['sub_invoice_prices']['postal'] ?? $_SESSION['sub_postal_price'];
                $stmt = $pdo->prepare("
            INSERT INTO Order_Items (order_id, product_name, unit_price, quantity, total_price)
            VALUES (?, ?, ?, ?, ?)
        ");
                $stmt->execute([$order_id, 'ارسال پستی', $postal_price, 1, $postal_price]);
            }

            $pdo->commit();

            unset($_SESSION['sub_order_items']);
            unset($_SESSION['sub_discount']);
            unset($_SESSION['sub_invoice_prices']);
            unset($_SESSION['sub_postal_enabled']);
            unset($_SESSION['sub_postal_price']);
            unset($_SESSION['is_sub_order_in_progress']);

            sendResponse(true, 'پیش‌فاکتور با موفقیت به‌روزرسانی شد.', ['redirect' => 'orders.php?work_month_id=' . $work_month_id]);

        case 'convert_to_main_order':
            $order_id = $_POST['order_id'] ?? '';
            $work_details_id = $_POST['work_details_id'] ?? '';
            $partner_id = $_POST['partner_id'] ?? $current_user_id;
            $work_month_id = $_POST['work_month_id'] ?? '';

            if (!$order_id || !$work_details_id || !$partner_id || !$work_month_id) {
                sendResponse(false, 'شناسه سفارش، همکار، تاریخ کاری یا ماه کاری مشخص نیست.');
            }

            $stmt = $pdo->prepare("SELECT order_id FROM Orders WHERE order_id = ? AND is_main_order = 0");
            $stmt->execute([$order_id]);
            if (!$stmt->fetch()) {
                sendResponse(false, 'پیش‌فاکتور یافت نشد یا قبلاً به فاکتور اصلی تبدیل شده است.');
            }

            $stmt = $pdo->prepare("SELECT id FROM Work_Details WHERE id = ? AND partner_id IN (
        SELECT partner_id FROM Partners WHERE user_id1 = ? OR user_id2 = ?
    )");
            $stmt->execute([$work_details_id, $partner_id, $partner_id]);
            if (!$stmt->fetch()) {
                sendResponse(false, 'تاریخ کاری برای این همکار معتبر نیست.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
        UPDATE Orders
        SET work_details_id = ?, is_main_order = 1
        WHERE order_id = ?
    ");
            $stmt->execute([$work_details_id, $order_id]);

            $pdo->commit();

            unset($_SESSION['sub_order_items']);
            unset($_SESSION['sub_discount']);
            unset($_SESSION['sub_invoice_prices']);
            unset($_SESSION['sub_postal_enabled']);
            unset($_SESSION['sub_postal_price']);
            unset($_SESSION['is_sub_order_in_progress']);

            sendResponse(true, 'پیش‌فاکتور با موفقیت به فاکتور اصلی تبدیل شد.', ['redirect' => 'orders.php?work_month_id=' . $work_month_id]);

        default:
            sendResponse(false, 'عملیات نامعتبر.');
    }
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error in sub_order_handler.php: " . $e->getMessage());
    sendResponse(false, 'خطای سرور: ' . $e->getMessage());
}
?>