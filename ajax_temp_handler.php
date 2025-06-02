<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

function sendResponse($success, $message = '', $data = []) {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

$action = $_POST['action'] ?? '';
$current_user_id = $_SESSION['user_id'] ?? null;

if (!$current_user_id) {
    sendResponse(false, 'لطفاً ابتدا وارد شوید.');
}

switch ($action) {
    case 'add_temp_item':
        $customer_name = $_POST['customer_name'] ?? '';
        $product_id = $_POST['product_id'] ?? '';
        $quantity = (int)($_POST['quantity'] ?? 0);
        $unit_price = (float)($_POST['unit_price'] ?? 0);
        $extra_sale = (float)($_POST['extra_sale'] ?? 0);
        $discount = (float)($_POST['discount'] ?? 0);

        if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0) {
            sendResponse(false, 'لطفاً همه فیلدها را پر کنید.');
        }

        $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            sendResponse(false, 'محصول یافت نشد.');
        }

        $stmt = $pdo->prepare("SELECT quantity FROM Inventory WHERE product_id = ? AND user_id = ?");
        $stmt->execute([$product_id, $current_user_id]);
        $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inventory || $inventory['quantity'] < $quantity) {
            sendResponse(false, 'موجودی کافی نیست.');
        }

        if (!isset($_SESSION['temp_order_items'])) {
            $_SESSION['temp_order_items'] = [];
        }

        if (array_filter($_SESSION['temp_order_items'], fn($item) => $item['product_id'] === $product_id)) {
            sendResponse(false, 'این محصول قبلاً اضافه شده است.');
        }

        $total_price = $quantity * ($unit_price + $extra_sale);
        $_SESSION['temp_order_items'][] = [
            'product_id' => $product_id,
            'product_name' => $product['product_name'],
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'extra_sale' => $extra_sale,
            'total_price' => $total_price
        ];

        $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
        $final_amount = $total_amount - $discount + ($_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0);

        sendResponse(true, 'محصول اضافه شد.', [
            'items' => $_SESSION['temp_order_items'],
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount,
            'invoice_prices' => $_SESSION['invoice_prices'],
            'postal_enabled' => $_SESSION['postal_enabled'],
            'postal_price' => $_SESSION['postal_price']
        ]);

    case 'delete_temp_item':
        $index = $_POST['index'] ?? '';
        if (!isset($_SESSION['temp_order_items'][$index])) {
            sendResponse(false, 'آیتم یافت نشد.');
        }

        unset($_SESSION['temp_order_items'][$index]);
        $_SESSION['temp_order_items'] = array_values($_SESSION['temp_order_items']);
        unset($_SESSION['invoice_prices'][$index]);

        $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
        $discount = $_SESSION['discount'];
        $final_amount = $total_amount - $discount + ($_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0);

        sendResponse(true, 'آیتم حذف شد.', [
            'items' => $_SESSION['temp_order_items'],
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount,
            'invoice_prices' => $_SESSION['invoice_prices'],
            'postal_enabled' => $_SESSION['postal_enabled'],
            'postal_price' => $_SESSION['postal_price']
        ]);

    case 'set_temp_invoice_price':
        $index = $_POST['index'] ?? '';
        $invoice_price = (float)($_POST['invoice_price'] ?? 0);
        if ($invoice_price < 0) {
            sendResponse(false, 'قیمت نامعتبر است.');
        }

        $_SESSION['invoice_prices'][$index] = $invoice_price;
        sendResponse(true, 'قیمت فاکتور ثبت شد.');

    case 'set_temp_postal_option':
        $enable_postal = filter_var($_POST['enable_postal'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $_SESSION['postal_enabled'] = $enable_postal;

        $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
        $discount = $_SESSION['discount'];
        $final_amount = $total_amount - $discount + ($enable_postal ? $_SESSION['postal_price'] : 0);

        sendResponse(true, 'گزینه پست به‌روزرسانی شد.', [
            'items' => $_SESSION['temp_order_items'],
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount,
            'invoice_prices' => $_SESSION['invoice_prices'],
            'postal_enabled' => $enable_postal,
            'postal_price' => $_SESSION['postal_price']
        ]);

    case 'update_temp_discount':
        $discount = (float)($_POST['discount'] ?? 0);
        if ($discount < 0) {
            sendResponse(false, 'تخفیف نامعتبر است.');
        }

        $_SESSION['discount'] = $discount;
        $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
        $final_amount = $total_amount - $discount + ($_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0);

        sendResponse(true, 'تخفیف به‌روزرسانی شد.', [
            'items' => $_SESSION['temp_order_items'],
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount,
            'invoice_prices' => $_SESSION['invoice_prices'],
            'postal_enabled' => $_SESSION['postal_enabled'],
            'postal_price' => $_SESSION['postal_price']
        ]);

    case 'finalize_temp_order':
        $customer_name = $_POST['customer_name'] ?? '';
        $discount = (float)($_POST['discount'] ?? 0);
        $user_id = $_POST['user_id'] ?? $current_user_id;

        if (!$customer_name || empty($_SESSION['temp_order_items'])) {
            sendResponse(false, 'نام مشتری یا اقلام سفارش وارد نشده است.');
        }

        $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
        $final_amount = $total_amount - $discount + ($_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO Temp_Orders (user_id, customer_name, total_amount, discount, final_amount)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$user_id, $customer_name, $total_amount, $discount, $final_amount]);
            $temp_order_id = $pdo->lastInsertId();

            foreach ($_SESSION['temp_order_items'] as $item) {
                $stmt = $pdo->prepare("
                    INSERT INTO Temp_Order_Items (temp_order_id, product_name, quantity, unit_price, extra_sale, total_price)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $temp_order_id,
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['extra_sale'],
                    $item['total_price']
                ]);

                $stmt = $pdo->prepare("
                    UPDATE Inventory SET quantity = quantity - ? WHERE product_id = ? AND user_id = ?
                ");
                $stmt->execute([$item['quantity'], $item['product_id'], $user_id]);

                $stmt = $pdo->prepare("
                    INSERT INTO Inventory_Transactions (product_id, user_id, quantity, work_month_id)
                    VALUES (?, ?, ?, (SELECT work_month_id FROM Work_Months WHERE start_date = '0000-00-00'))
                ");
                $stmt->execute([$item['product_id'], $user_id, -$item['quantity']]);
            }

            if ($_SESSION['postal_enabled']) {
                $stmt = $pdo->prepare("
                    INSERT INTO Temp_Order_Items (temp_order_id, product_name, quantity, unit_price, extra_sale, total_price)
                    VALUES (?, 'ارسال پستی', 1, ?, 0, ?)
                ");
                $stmt->execute([$temp_order_id, $_SESSION['postal_price'], $_SESSION['postal_price']]);
            }

            $pdo->commit();

            unset($_SESSION['temp_order_items']);
            unset($_SESSION['discount']);
            unset($_SESSION['invoice_prices']);
            unset($_SESSION['is_temp_order_in_progress']);
            unset($_SESSION['postal_enabled']);
            unset($_SESSION['postal_price']);

            sendResponse(true, 'سفارش بدون تاریخ با موفقیت ثبت شد.', ['redirect' => 'temp_orders.php']);
        } catch (Exception $e) {
            $pdo->rollBack();
            sendResponse(false, 'خطا در ثبت سفارش: ' . $e->getMessage());
        }

    case 'convert_temp_order':
        $temp_order_id = $_POST['temp_order_id'] ?? '';
        $work_details_id = $_POST['work_details_id'] ?? '';

        if (!$temp_order_id || !$work_details_id) {
            sendResponse(false, 'اطلاعات ناقص است.');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT user_id, customer_name, total_amount, discount, final_amount
                FROM Temp_Orders WHERE temp_order_id = ? AND user_id = ?
            ");
            $stmt->execute([$temp_order_id, $current_user_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                sendResponse(false, 'سفارش یافت نشد یا شما دسترسی ندارید.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO Orders (work_details_id, customer_name, total_amount, discount, final_amount, is_main_order)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $work_details_id,
                $order['customer_name'],
                $order['total_amount'],
                $order['discount'],
                $order['final_amount']
            ]);
            $new_order_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                SELECT product_name, quantity, unit_price, extra_sale, total_price
                FROM Temp_Order_Items WHERE temp_order_id = ?
            ");
            $stmt->execute([$temp_order_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                $stmt = $pdo->prepare("
                    INSERT INTO Order_Items (order_id, product_name, quantity, unit_price, extra_sale, total_price)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $new_order_id,
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['extra_sale'],
                    $item['total_price']
                ]);
            }

            $stmt = $pdo->prepare("
                UPDATE Order_Payments SET order_id = ? WHERE order_id = ?
            ");
            $stmt->execute([$new_order_id, $temp_order_id]);

            $stmt = $pdo->prepare("
                UPDATE Inventory_Transactions 
                SET work_month_id = (SELECT work_month_id FROM Work_Details WHERE id = ?)
                WHERE work_month_id = (SELECT work_month_id FROM Work_Months WHERE start_date = '0000-00-00')
                AND user_id = ?
            ");
            $stmt->execute([$work_details_id, $current_user_id]);

            $stmt = $pdo->prepare("DELETE FROM Temp_Order_Items WHERE temp_order_id = ?");
            $stmt->execute([$temp_order_id]);

            $stmt = $pdo->prepare("DELETE FROM Temp_Orders WHERE temp_order_id = ?");
            $stmt->execute([$temp_order_id]);

            $pdo->commit();
            sendResponse(true, 'سفارش با موفقیت به سفارش اصلی تبدیل شد.', ['redirect' => 'temp_orders.php']);
        } catch (Exception $e) {
            $pdo->rollBack();
            sendResponse(false, 'خطا در تبدیل سفارش: ' . $e->getMessage());
        }

    default:
        sendResponse(false, 'اکشن نامعتبر است.');
}
?>