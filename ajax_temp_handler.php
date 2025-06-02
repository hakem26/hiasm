<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

function sendResponse($success, $message = '', $data = []) {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    sendResponse(false, 'لطفاً ابتدا وارد شوید.');
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if (!$action) {
    sendResponse(false, 'اکشن مشخص نشده است.');
}

if (!isset($_SESSION['temp_order_items'])) {
    $_SESSION['temp_order_items'] = [];
}
if (!isset($_SESSION['discount'])) {
    $_SESSION['discount'] = 0;
}
if (!isset($_SESSION['invoice_prices'])) {
    $_SESSION['invoice_prices'] = ['postal' => 50000];
}
if (!isset($_SESSION['postal_enabled'])) {
    $_SESSION['postal_enabled'] = false;
}
if (!isset($_SESSION['postal_price'])) {
    $_SESSION['postal_price'] = 50000;
}

try {
    switch ($action) {
        case 'add_temp_item':
            $customer_name = $_POST['customer_name'] ?? '';
            $product_id = $_POST['product_id'] ?? '';
            $quantity = (int)($_POST['quantity'] ?? 0);
            $unit_price = (float)($_POST['unit_price'] ?? 0);
            $extra_sale = (float)($_POST['extra_sale'] ?? 0);
            $discount = (float)($_POST['discount'] ?? 0);

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0) {
                sendResponse(false, 'لطفاً همه فیلدها را به درستی پر کنید.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                sendResponse(false, 'محصول یافت نشد.');
            }

            // چک موجودی حذف شده (مشابه ajax_handler.php)
            $items = $_SESSION['temp_order_items'];
            if (array_filter($items, fn($item) => $item['product_id'] === $product_id)) {
                sendResponse(false, 'این محصول قبلاً در فاکتور ثبت شده است.');
            }

            $total_price = $quantity * ($unit_price + $extra_sale);
            $item = [
                'product_id' => $product_id,
                'product_name' => $product['product_name'],
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'extra_sale' => $extra_sale,
                'total_price' => $total_price
            ];

            $_SESSION['temp_order_items'][] = $item;
            $_SESSION['discount'] = $discount;

            $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0);

            sendResponse(true, 'محصول با موفقیت اضافه شد.', [
                'items' => $_SESSION['temp_order_items'],
                'total_amount' => $total_amount,
                'final_amount' => $final_amount,
                'discount' => $discount,
                'invoice_prices' => $_SESSION['invoice_prices'],
                'postal_enabled' => $_SESSION['postal_enabled'],
                'postal_price' => $_SESSION['postal_price']
            ]);

        case 'delete_temp_item':
            $index = (int)($_POST['index'] ?? -1);
            if ($index < 0 || !isset($_SESSION['temp_order_items'][$index])) {
                sendResponse(false, 'آیتم یافت نشد.');
            }

            unset($_SESSION['temp_order_items'][$index]);
            $_SESSION['temp_order_items'] = array_values($_SESSION['temp_order_items']);
            unset($_SESSION['invoice_prices'][$index]);

            $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
            $final_amount = $total_amount - $_SESSION['discount'] + ($_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0);

            sendResponse(true, 'آیتم با موفقیت حذف شد.', [
                'items' => $_SESSION['temp_order_items'],
                'total_amount' => $total_amount,
                'final_amount' => $final_amount,
                'discount' => $_SESSION['discount'],
                'invoice_prices' => $_SESSION['invoice_prices'],
                'postal_enabled' => $_SESSION['postal_enabled'],
                'postal_price' => $_SESSION['postal_price']
            ]);

        case 'set_temp_invoice_price':
            $index = $_POST['index'] ?? '';
            $invoice_price = (float)($_POST['invoice_price'] ?? 0);

            if ($index === '' || $invoice_price < 0) {
                sendResponse(false, 'قیمت فاکتور معتبر نیست.');
            }

            $_SESSION['invoice_prices'][$index] = $invoice_price;
            sendResponse(true, 'قیمت فاکتور با موفقیت تنظیم شد.', [
                'invoice_prices' => $_SESSION['invoice_prices']
            ]);

        case 'set_temp_postal_option':
            $enable_postal = filter_var($_POST['enable_postal'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $_SESSION['postal_enabled'] = $enable_postal;

            $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
            $final_amount = $total_amount - $_SESSION['discount'] + ($enable_postal ? $_SESSION['postal_price'] : 0);

            sendResponse(true, 'گزینه پستی با موفقیت به‌روزرسانی شد.', [
                'items' => $_SESSION['temp_order_items'],
                'total_amount' => $total_amount,
                'final_amount' => $final_amount,
                'discount' => $_SESSION['discount'],
                'invoice_prices' => $_SESSION['invoice_prices'],
                'postal_enabled' => $_SESSION['postal_enabled'],
                'postal_price' => $_SESSION['postal_price']
            ]);

        case 'update_temp_discount':
            $discount = (float)($_POST['discount'] ?? 0);
            if ($discount < 0) {
                sendResponse(false, 'تخفیف معتبر نیست.');
            }

            $_SESSION['discount'] = $discount;
            $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0);

            sendResponse(true, 'تخفیف با موفقیت به‌روزرسانی شد.', [
                'items' => $_SESSION['temp_order_items'],
                'total_amount' => $total_amount,
                'final_amount' => $final_amount,
                'discount' => $discount,
                'invoice_prices' => $_SESSION['invoice_prices'],
                'postal_enabled' => $_SESSION['postal_enabled'],
                'postal_price' => $_SESSION['postal_price']
            ]);

        case 'finalize_temp_order':
            $customer_name = $_POST['customer_name'] ?? '';
            $discount = (float)($_POST['discount'] ?? 0);

            if (!$customer_name || empty($_SESSION['temp_order_items'])) {
                sendResponse(false, 'نام مشتری یا اقلام سفارش معتبر نیست.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO Temp_Orders (user_id, customer_name, total_amount, discount, final_amount, postal_enabled, postal_price, order_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0);
            $stmt->execute([
                $user_id,
                $customer_name,
                $total_amount,
                $discount,
                $final_amount,
                $_SESSION['postal_enabled'] ? 1 : 0,
                $_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0
            ]);
            $order_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO Temp_Order_Items (temp_order_id, product_id, quantity, unit_price, extra_sale, total_price, invoice_price)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($_SESSION['temp_order_items'] as $index => $item) {
                $invoice_price = $_SESSION['invoice_prices'][$index] ?? $item['total_price'];
                $stmt->execute([
                    $order_id,
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['extra_sale'],
                    $item['total_price'],
                    $invoice_price
                ]);

                // آپدیت موجودی (اجازه به موجودی منفی)
                $stmt_inventory = $pdo->prepare("
                    INSERT INTO Inventory (user_id, product_id, quantity)
                    VALUES (?, ?, -?)
                    ON DUPLICATE KEY UPDATE quantity = quantity - ?
                ");
                $stmt_inventory->execute([$user_id, $item['product_id'], $item['quantity'], $item['quantity']]);
            }

            if ($_SESSION['postal_enabled']) {
                $stmt = $pdo->prepare("
                    INSERT INTO Temp_Order_Items (temp_order_id, product_id, quantity, unit_price, extra_sale, total_price, invoice_price)
                    VALUES (?, 0, 1, ?, 0, ?, ?)
                ");
                $postal_price = $_SESSION['invoice_prices']['postal'] ?? $_SESSION['postal_price'];
                $stmt->execute([$order_id, $postal_price, $postal_price, $postal_price]);
            }

            $pdo->commit();

            unset($_SESSION['temp_order_items']);
            unset($_SESSION['discount']);
            unset($_SESSION['invoice_prices']);
            unset($_SESSION['postal_enabled']);
            unset($_SESSION['postal_price']);
            unset($_SESSION['is_temp_order_in_progress']);

            sendResponse(true, 'سفارش با موفقیت ثبت شد.', ['redirect' => 'temp_orders.php']);

        default:
            sendResponse(false, 'اکشن نامعتبر است.');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Error in ajax_temp_handler.php: ' . $e->getMessage());
    sendResponse(false, 'خطا در پردازش درخواست: ' . $e->getMessage());
}
?>