<?php
session_start();
require_once 'db.php';

// تابع برای ارسال پاسخ JSON
function send_response($success, $data = [], $message = '') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ]);
    exit;
}

// بررسی دسترسی کاربر
if (!isset($_SESSION['user_id'])) {
    send_response(false, [], 'لطفاً ابتدا وارد شوید.');
}

$current_user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');
if ($is_admin) {
    send_response(false, [], 'دسترسی غیرمجاز.');
}

$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_response(false, [], 'درخواست نامعتبر.');
}

// پردازش درخواست‌های AJAX
switch ($action) {
    case 'add_item':
        try {
            $customer_name = $_POST['customer_name'] ?? '';
            $product_id = $_POST['product_id'] ?? '';
            $quantity = (int)($_POST['quantity'] ?? 0);
            $unit_price = (float)($_POST['unit_price'] ?? 0);

            if (empty($customer_name) || empty($product_id) || $quantity <= 0 || $unit_price <= 0) {
                send_response(false, [], 'لطفاً همه فیلدها را پر کنید.');
            }

            $stmt_product = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt_product->execute([$product_id]);
            $product = $stmt_product->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                send_response(false, [], 'محصول یافت نشد.');
            }

            $items = isset($_SESSION['order_items']) ? $_SESSION['order_items'] : [];
            $items[] = [
                'product_id' => $product_id,
                'product_name' => $product['product_name'],
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'total_price' => $quantity * $unit_price
            ];
            $_SESSION['order_items'] = $items;

            $total_amount = array_sum(array_column($items, 'total_price'));
            $discount = (float)($_POST['discount'] ?? 0);
            $final_amount = $total_amount - $discount;

            send_response(true, [
                'items' => $items,
                'total_amount' => $total_amount,
                'final_amount' => $final_amount
            ]);
        } catch (Exception $e) {
            send_response(false, [], 'خطا: ' . $e->getMessage());
        }
        break;

    case 'update_discount':
        try {
            $discount = (float)($_POST['discount'] ?? 0);
            $items = isset($_SESSION['order_items']) ? $_SESSION['order_items'] : [];
            $total_amount = array_sum(array_column($items, 'total_price'));
            $final_amount = $total_amount - $discount;

            send_response(true, [
                'total_amount' => $total_amount,
                'final_amount' => $final_amount
            ]);
        } catch (Exception $e) {
            send_response(false, [], 'خطا: ' . $e->getMessage());
        }
        break;

    case 'finalize_order':
        try {
            $work_details_id = $_POST['work_details_id'] ?? '';
            $customer_name = $_POST['customer_name'] ?? '';
            $discount = (float)($_POST['discount'] ?? 0);
            $items = isset($_SESSION['order_items']) ? $_SESSION['order_items'] : [];

            if (empty($customer_name)) {
                send_response(false, [], 'لطفاً نام مشتری را وارد کنید.');
            }

            if (empty($items)) {
                send_response(false, [], 'لطفاً حداقل یک محصول به فاکتور اضافه کنید.');
            }

            $total_amount = array_sum(array_column($items, 'total_price'));
            $final_amount = $total_amount - $discount;

            $stmt_order = $pdo->prepare("INSERT INTO Orders (work_details_id, customer_name, total_amount, discount, final_amount, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt_order->execute([$work_details_id, $customer_name, $total_amount, $discount, $final_amount]);

            $order_id = $pdo->lastInsertId();

            foreach ($items as $item) {
                $stmt_item = $pdo->prepare("INSERT INTO Order_Items (order_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
                $stmt_item->execute([$order_id, $item['product_name'], $item['quantity'], $item['unit_price'], $item['total_price']]);
            }

            unset($_SESSION['order_items']);
            $_SESSION['is_order_in_progress'] = false;

            send_response(true, ['redirect' => 'orders.php'], 'فاکتور با موفقیت ثبت گردید.');
        } catch (Exception $e) {
            send_response(false, [], 'خطا: ' . $e->getMessage());
        }
        break;

    default:
        send_response(false, [], 'درخواست نامعتبر.');
}