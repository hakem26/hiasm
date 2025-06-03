<?php
session_start();
ob_start();
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

function sendResponse($success, $message = '', $data = [])
{
    ob_clean();
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

if (!isset($_SESSION['user_id'])) {
    sendResponse(false, 'لطفاً ابتدا وارد شوید.');
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

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
        case 'load_temp_order':
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            if (!$temp_order_id) {
                sendResponse(false, 'شناسه فاکتور معتبر نیست.');
            }

            $stmt = $pdo->prepare("
                SELECT customer_name, total_amount, discount, final_amount, postal_enabled, postal_price
                FROM Temp_Orders WHERE temp_order_id = ? AND user_id = ?
            ");
            $stmt->execute([$temp_order_id, $user_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                sendResponse(false, 'فاکتور یافت نشد یا دسترسی ندارید.');
            }

            $stmt = $pdo->prepare("
                SELECT item_id, temp_order_id, product_name, quantity, unit_price, extra_sale, total_price
                FROM Temp_Order_Items
                WHERE temp_order_id = ? AND product_name != 'ارسال پستی'
                ORDER BY item_id
            ");
            $stmt->execute([$temp_order_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $_SESSION['temp_order_items'] = [];
            foreach ($items as $item) {
                $item['product_name'] = $item['product_name'] ?: 'محصول ناشناس #' . $item['item_id'];
                $item['product_id'] = 'unknown_' . $item['item_id'];
                $_SESSION['temp_order_items'][] = $item;
            }

            $_SESSION['discount'] = (float)$order['discount'];
            $_SESSION['postal_enabled'] = (bool)$order['postal_enabled'];
            $_SESSION['postal_price'] = (float)$order['postal_price'];
            $_SESSION['invoice_prices'] = ['postal' => $_SESSION['postal_price']];
            foreach ($_SESSION['temp_order_items'] as $index => $item) {
                $_SESSION['invoice_prices'][$index] = $item['unit_price'];
            }

            sendResponse(true, 'فاکتور با موفقیت بارگذاری شد.', [
                'customer_name' => $order['customer_name'],
                'items' => $_SESSION['temp_order_items'],
                'total_amount' => (float)$order['total_amount'],
                'final_amount' => (float)$order['final_amount'],
                'discount' => $_SESSION['discount'],
                'invoice_prices' => $_SESSION['invoice_prices'],
                'postal_enabled' => $_SESSION['postal_enabled'],
                'postal_price' => $_SESSION['postal_price']
            ]);

        case 'add_temp_item':
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            $customer_name = $_POST['customer_name'] ?? '';
            $product_id = $_POST['product_id'] ?? '';
            $quantity = (int)($_POST['quantity'] ?? 0);
            $unit_price = (float)($_POST['unit_price'] ?? 0);
            $extra_sale = (float)($_POST['extra_sale'] ?? 0);
            $discount = (float)($_POST['discount'] ?? 0);

            if (!$customer_name || !$temp_order_id || !$product_id || $quantity <= 0 || $unit_price <= 0) {
                sendResponse(false, 'لطفاً همه فیلدها را به درستی پر کنید.');
            }

            $stmt = $pdo->prepare("SELECT user_id FROM Temp_Orders WHERE temp_order_id = ?");
            $stmt->execute([$temp_order_id]);
            if ($stmt->fetch()['user_id'] != $user_id) {
                sendResponse(false, 'شما به این فاکتور دسترسی ندارید.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                sendResponse(false, 'محصول یافت نشد.');
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
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            $index = (int)($_POST['index'] ?? -1);

            $stmt = $pdo->prepare("SELECT user_id FROM Temp_Orders WHERE temp_order_id = ?");
            $stmt->execute([$temp_order_id]);
            if ($stmt->fetch()['user_id'] != $user_id) {
                sendResponse(false, 'شما به این فاکتور دسترسی ندارید.');
            }

            if (!isset($_SESSION['temp_order_items'][$index])) {
                sendResponse(false, 'آیتم یافت نشد.');
            }

            array_splice($_SESSION['temp_order_items'], $index, 1);
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
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            $index = $_POST['index'] ?? '';
            $invoice_price = (float)($_POST['invoice_price'] ?? 0);

            $stmt = $pdo->prepare("SELECT user_id FROM Temp_Orders WHERE temp_order_id = ?");
            $stmt->execute([$temp_order_id]);
            if ($stmt->fetch()['user_id'] != $user_id) {
                sendResponse(false, 'شما به این فاکتور دسترسی ندارید.');
            }

            if ($index === '' || $invoice_price < 0) {
                sendResponse(false, 'قیمت فاکتور معتبر نیست.');
            }

            if ($index === 'postal') {
                $_SESSION['postal_price'] = $invoice_price;
                $_SESSION['invoice_prices']['postal'] = $invoice_price;
            } else {
                if (!isset($_SESSION['temp_order_items'][$index])) {
                    sendResponse(false, 'آیتم یافت نشد.');
                }

                $item = &$_SESSION['temp_order_items'][$index];
                $item['unit_price'] = $invoice_price;
                $item['total_price'] = $item['quantity'] * ($invoice_price + $item['extra_sale']);
                $_SESSION['invoice_prices'][$index] = $invoice_price;
            }

            $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
            $final_amount = $total_amount - $_SESSION['discount'] + ($_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0);

            sendResponse(true, 'قیمت واحد با موفقیت تنظیم شد.', [
                'items' => $_SESSION['temp_order_items'],
                'total_amount' => $total_amount,
                'final_amount' => $final_amount,
                'discount' => $_SESSION['discount'],
                'invoice_prices' => $_SESSION['invoice_prices'],
                'postal_enabled' => $_SESSION['postal_enabled'],
                'postal_price' => $_SESSION['postal_price']
            ]);

        case 'set_temp_postal_option':
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            $enable_postal = filter_var($_POST['enable_postal'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $stmt = $pdo->prepare("SELECT user_id FROM Temp_Orders WHERE temp_order_id = ?");
            $stmt->execute([$temp_order_id]);
            if ($stmt->fetch()['user_id'] != $user_id) {
                sendResponse(false, 'شما به این فاکتور دسترسی ندارید.');
            }

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
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            $discount = (float)($_POST['discount'] ?? 0);

            $stmt = $pdo->prepare("SELECT user_id FROM Temp_Orders WHERE temp_order_id = ?");
            $stmt->execute([$temp_order_id]);
            if ($stmt->fetch()['user_id'] != $user_id) {
                sendResponse(false, 'شما به این فاکتور دسترسی ندارید.');
            }

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

        case 'save_temp_order':
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            $customer_name = $_POST['customer_name'] ?? '';
            $discount = (float)($_POST['discount'] ?? 0);

            if (!$customer_name || !$temp_order_id || empty($_SESSION['temp_order_items'])) {
                sendResponse(false, 'نام مشتری یا اقلام سفارش معتبر نیست.');
            }

            $stmt = $pdo->prepare("SELECT user_id FROM Temp_Orders WHERE temp_order_id = ?");
            $stmt->execute([$temp_order_id]);
            if ($stmt->fetch()['user_id'] != $user_id) {
                sendResponse(false, 'شما به این فاکتور دسترسی ندارید.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("DELETE FROM Temp_Order_Items WHERE temp_order_id = ?");
            $stmt->execute([$temp_order_id]);

            $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0);

            $stmt = $pdo->prepare("
                UPDATE Temp_Orders
                SET customer_name = ?, total_amount = ?, discount = ?, final_amount = ?, postal_enabled = ?, postal_price = ?
                WHERE temp_order_id = ? AND user_id = ?
            ");
            $stmt->execute([
                $customer_name,
                $total_amount,
                $discount,
                $final_amount,
                $_SESSION['postal_enabled'] ? 1 : 0,
                $_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0,
                $temp_order_id,
                $user_id
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO Temp_Order_Items (temp_order_id, product_name, quantity, unit_price, extra_sale, total_price)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($_SESSION['temp_order_items'] as $item) {
                $stmt->execute([
                    $temp_order_id,
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['extra_sale'],
                    $item['total_price']
                ]);

                if (strpos($item['product_id'], 'unknown_') !== 0) {
                    $stmt_inventory = $pdo->prepare("
                        INSERT INTO Inventory (user_id, product_id, quantity)
                        VALUES (?, ?, -?)
                        ON DUPLICATE KEY UPDATE quantity = quantity - ?
                    ");
                    $stmt_inventory->execute([$user_id, $item['product_id'], $item['quantity'], $item['quantity']]);
                }
            }

            if ($_SESSION['postal_enabled']) {
                $stmt = $pdo->prepare("
                    INSERT INTO Temp_Order_Items (temp_order_id, product_name, quantity, unit_price, extra_sale, total_price)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $postal_price = $_SESSION['invoice_prices']['postal'] ?? $_SESSION['postal_price'];
                $stmt->execute([
                    $temp_order_id,
                    'ارسال پستی',
                    1,
                    $postal_price,
                    0,
                    $postal_price
                ]);
            }

            $pdo->commit();

            unset($_SESSION['temp_order_items']);
            unset($_SESSION['discount']);
            unset($_SESSION['invoice_prices']);
            unset($_SESSION['postal_enabled']);
            unset($_SESSION['postal_price']);
            unset($_SESSION['is_temp_order_in_progress']);
            unset($_SESSION['editing_temp_order_id']);

            sendResponse(true, 'فاکتور با موفقیت به‌روزرسانی شد.', ['redirect' => 'temp_orders.php']);

        default:
            sendResponse(false, 'اقدام نامعتبر است.');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Error in ajax_edit_temp_handler.php: ' . $e->getMessage());
    sendResponse(false, 'خطا در پردازش درخواست: ' . $e->getMessage());
}
?>