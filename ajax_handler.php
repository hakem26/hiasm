<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

function respond($success, $message = '', $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

$action = $_POST['action'] ?? '';

if (!$action) {
    respond(false, 'Action not specified.');
}

switch ($action) {
    case 'add_item':
        $customer_name = $_POST['customer_name'] ?? '';
        $product_id = $_POST['product_id'] ?? '';
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $unit_price = (float) ($_POST['unit_price'] ?? 0);
        $discount = (float) ($_POST['discount'] ?? 0);
        $work_details_id = $_POST['work_details_id'] ?? '';

        if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || !$work_details_id) {
            respond(false, 'لطفاً تمام فیلدها را پر کنید.');
        }

        // دریافت نام محصول
        $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            respond(false, 'محصول یافت نشد.');
        }

        $items = $_SESSION['order_items'] ?? [];
        $items[] = [
            'product_id' => $product_id,
            'product_name' => $product['product_name'],
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'total_price' => $quantity * $unit_price
        ];

        $_SESSION['order_items'] = $items;
        $total_amount = array_sum(array_column($items, 'total_price'));
        $final_amount = $total_amount - $discount;

        respond(true, 'محصول با موفقیت اضافه شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount
        ]);
        break;

    case 'delete_item':
        $index = (int) ($_POST['index'] ?? -1);

        if ($index < 0 || !isset($_SESSION['order_items'][$index])) {
            respond(false, 'آیتم یافت نشد.');
        }

        $items = $_SESSION['order_items'];
        unset($items[$index]);
        $items = array_values($items); // بازچینی اندیس‌ها
        $_SESSION['order_items'] = $items;

        $total_amount = array_sum(array_column($items, 'total_price'));
        $discount = $_SESSION['discount'] ?? 0;
        $final_amount = $total_amount - $discount;

        respond(true, 'آیتم با موفقیت حذف شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount
        ]);
        break;

    case 'update_discount':
        $discount = (float) ($_POST['discount'] ?? 0);

        $items = $_SESSION['order_items'] ?? [];
        $total_amount = array_sum(array_column($items, 'total_price'));
        $final_amount = $total_amount - $discount;

        $_SESSION['discount'] = $discount;

        respond(true, 'تخفیف با موفقیت به‌روزرسانی شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount
        ]);
        break;

    case 'finalize_order':
        $work_details_id = $_POST['work_details_id'] ?? '';
        $customer_name = $_POST['customer_name'] ?? '';
        $discount = (float) ($_POST['discount'] ?? 0);

        if (!$work_details_id || !$customer_name) {
            respond(false, 'لطفاً تمام فیلدها را پر کنید.');
        }

        $items = $_SESSION['order_items'] ?? [];
        if (empty($items)) {
            respond(false, 'هیچ محصولی برای ثبت سفارش وجود ندارد.');
        }

        $total_amount = array_sum(array_column($items, 'total_price'));
        $final_amount = $total_amount - $discount;

        $pdo->beginTransaction();
        try {
            // ثبت سفارش در جدول Orders
            $stmt = $pdo->prepare("
                INSERT INTO Orders (work_details_id, customer_name, total_amount, discount, final_amount)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$work_details_id, $customer_name, $total_amount, $discount, $final_amount]);
            $order_id = $pdo->lastInsertId();

            // ثبت اقلام در Order_Items
            foreach ($items as $item) {
                $stmt = $pdo->prepare("
                    INSERT INTO Order_Items (order_id, product_name, quantity, unit_price, total_price)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $order_id,
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['total_price']
                ]);
            }

            // چک کردن اینکه کاربر همکار ۱ هست یا نه
            $current_user_id = $_SESSION['user_id'];
            $stmt_work = $pdo->prepare("SELECT partner_id FROM Work_Details WHERE id = ?");
            $stmt_work->execute([$work_details_id]);
            $work_info = $stmt_work->fetch(PDO::FETCH_ASSOC);

            $stmt_check_partner1 = $pdo->prepare("SELECT COUNT(*) FROM Partners WHERE user_id1 = ? AND partner_id = ?");
            $stmt_check_partner1->execute([$current_user_id, $work_info['partner_id']]);
            $is_partner1 = $stmt_check_partner1->fetchColumn() > 0;

            // کسر موجودی برای همکار ۱
            if ($is_partner1) {
                foreach ($items as $item) {
                    $product_id = $item['product_id'];
                    $quantity = $item['quantity'];

                    $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
                    $stmt_inventory->execute([$current_user_id, $product_id]);
                    $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);

                    $current_quantity = $inventory ? $inventory['quantity'] : 0;
                    if ($current_quantity < $quantity) {
                        throw new Exception("موجودی کافی برای محصول '{$item['product_name']}' نیست. موجودی: $current_quantity، درخواست: $quantity");
                    }

                    $new_quantity = $current_quantity - $quantity;
                    $stmt_update = $pdo->prepare("INSERT INTO Inventory (user_id, product_id, quantity) VALUES (?, ?, ?) 
                                               ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
                    $stmt_update->execute([$current_user_id, $product_id, $new_quantity]);
                }
            }

            $pdo->commit();

            // ریست کردن سشن
            unset($_SESSION['order_items']);
            unset($_SESSION['discount']);
            $_SESSION['is_order_in_progress'] = false;

            respond(true, 'سفارش با موفقیت ثبت شد.', [
                'redirect' => "invoice.php?order_id=$order_id"
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(false, 'خطا در ثبت سفارش: ' . $e->getMessage());
        }
        break;

    default:
        respond(false, 'Action not recognized.');
}