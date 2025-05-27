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
    if (!$gregorian_date || !preg_match('/^\d{4}-\d{2}-\d{2}/', $gregorian_date)) {
        return 'نامشخص';
    }
    try {
        list($gy, $gm, $gd) = explode('-', $gregorian_date);
        if (!is_numeric($gy) || !is_numeric($gm) || !is_numeric($gd)) {
            return 'نامشخص';
        }
        list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
        return "$jy/$jm/$jd";
    } catch (Exception $e) {
        error_log("Error in gregorian_to_jalali_format: " . $e->getMessage());
        return 'نامشخص';
    }
}

function sendResponse($success, $message, $data = []) {
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
                sendResponse(false, 'ماه کاری مشخص نشده است.');
            }

            $stmt = $pdo->prepare("
                SELECT DISTINCT u.user_id, u.full_name
                FROM Work_Details wd
                JOIN Partners p ON wd.partner_id = p.partner_id
                JOIN Users u ON u.user_id IN (p.user_id1, p.user_id2)
                WHERE wd.work_month_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
                AND u.user_id != ? AND u.role = 'seller'
                ORDER BY u.full_name
            ");
            $stmt->execute([$work_month_id, $current_user_id, $current_user_id, $current_user_id]);
            $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

            sendResponse(true, 'موفق', ['partners' => $partners]);

        case 'get_work_days':
            $partner_id = $_POST['partner_id'] ?? '';
            $work_month_id = $_POST['work_month_id'] ?? '';
            if (!$partner_id || !$work_month_id) {
                sendResponse(false, 'همکار یا ماه کاری مشخص نشده است.');
            }

            $stmt = $pdo->prepare("
                SELECT wd.id, wd.work_date AS date
                FROM Work_Details wd
                JOIN Partners p ON wd.partner_id = p.partner_id
                WHERE wd.work_month_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
                ORDER BY wd.work_date
            ");
            $stmt->execute([$work_month_id, $partner_id, $partner_id]);
            $work_days = $stmt->fetchAll(PDO::FETCH_ASSOC);

            sendResponse(true, 'موفق', ['work_days' => $work_days]);

        case 'add_sub_item':
            $customer_name = trim($_POST['customer_name'] ?? '');
            $product_id = $_POST['product_id'] ?? '';
            $quantity = floatval($_POST['quantity'] ?? 0);
            $unit_price = floatval($_POST['unit_price'] ?? 0);
            $extra_sale = floatval($_POST['extra_sale'] ?? 0);
            $discount = floatval($_POST['discount'] ?? 0);
            $work_details_id = $_POST['work_details_id'] ?? '';
            $partner_id = $_POST['partner_id'] ?? '';
            $product_name = trim($_POST['product_name'] ?? '');

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || !$product_name) {
                sendResponse(false, 'لطفاً تمام فیلدها را پر کنید.');
            }

            $total_price = ($quantity * ($unit_price + $extra_sale));
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

            $data = [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ];

            sendResponse(true, 'محصول اضافه شد.', $data);

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

            $data = [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $_SESSION['sub_discount'],
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ];

            sendResponse(true, 'محصول حذف شد.', $data);

        case 'set_sub_invoice_price':
            $index = $_POST['index'] ?? '';
            $invoice_price = floatval($_POST['invoice_price'] ?? 0);

            if ($invoice_price < 0) {
                sendResponse(false, 'قیمت فاکتور نمی‌تواند منفی باشد.');
            }

            $_SESSION['sub_invoice_prices'][$index] = $invoice_price;

            $data = [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => array_sum(array_column($_SESSION['sub_order_items'], 'total_price')),
                'discount' => $_SESSION['sub_discount'],
                'final_amount' => $total_amount - $_SESSION['sub_discount'] + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0),
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ];

            sendResponse(true, 'قیمت فاکتور تنظیم شد.', $data);

        case 'set_sub_postal_option':
            $enable_postal = filter_var($_POST['enable_postal'], FILTER_VALIDATE_BOOLEAN);
            $_SESSION['sub_postal_enabled'] = $enable_postal;

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $_SESSION['sub_discount'] + ($enable_postal ? $_SESSION['sub_postal_price'] : 0);

            $data = [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $_SESSION['sub_discount'],
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ];

            sendResponse(true, 'Success', $data);

        case 'update_sub_discount':
            $discount = floatval($_POST['discount'] ?? 0);
            if ($discount < 0) {
                sendResponse(false, 'Discount cannot be negative.');
            }

            $_SESSION['sub_discount'] = $discount;

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            $data = [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ];

            sendResponse(true, 'تخفیف به‌روز شد.', $data);

        case 'get_items':
            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $_SESSION['sub_discount'] + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            $data = [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $_SESSION['sub_discount'],
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ];

            sendResponse(true, 'موفق', $data);

        case 'finalize_sub_order':
            $customer_name = trim($_POST['customer_name'] ?? '');
            $work_details_id = $_POST['work_details_id'] ?? '';
            $partner_id = $_POST['partner_id'] ?? '';
            $discount = floatval($_POST['discount'] ?? 0);
            $convert_to_main = isset($_POST['convert_to_main']) && $_POST['convert_to_main'] === '1' ? 1 : 0;

            if (!$customer_name || !$work_details_id) {
                sendResponse(false, 'نام مشتری یا جزئیات کاری مشخص نشده است.');
            }

            if ($convert_to_main && !$partner_id) {
                sendResponse(false, 'همکار انتخاب نشده است.');
            }

            if (empty($_SESSION['sub_order_items'])) {
                sendResponse(false, 'هیچ محصولی در پیش‌فاکتور وجود ندارد.');
            }

            $pdo->beginTransaction();

            $total_amount = array_sum(array_column($_SESSION['products_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            $stmt = $pdo->prepare("
                INSERT INTO Orders (customer_name, total_amount, discount, final_amount, work_details_id, is_main_order, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$customer_name, $total_amount, $discount, $final_amount, $work_details_id, $convert_to_main]);
            $order_id = $pdo->lastInsertId();

            foreach ($_SESSION['sub_order_items'] as $item) {
                $stmt = $pdo->prepare("
                    INSERT INTO Order_Items (order_id, product_id, unit_price, quantity, extra_sale, discount)
                    VALUES (?, ?, ?, ?, ?, 0)
                ");
                $stmt->execute([$order_id, $item['product_id'], $item['unit_price'], $item['quantity'], $item['extra_sale']]);
            }

            // پاک کردن سشن‌ها
            unset($_SESSION['sub_order_items']);
            unset($_SESSION['sub_discount']);
            unset($_SESSION['sub_invoice_prices']);
            unset($_SESSION['sub_postal_enabled']);
            unset($_SESSION['sub_postal_price']);
            unset($_SESSION['is_sub_order']);

            $pdo->commit();

            sendResponse(true, 'پیش‌فاکتور ثبت شد.', ['redirect' => 'orders.php']);

        default:
            sendResponse(false, 'عملکرد نامعتبر.');
    }
} catch (Exception $e) {
    $pdo->rollback();
    error_log("Error in sub_order_handler.php: " . $e->getMessage());
    sendResponse(false, 'خطای سرور.');
}
?>