<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

function respond($success, $message = '', $data = [])
{
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
        $extra_sale = (float) ($_POST['extra_sale'] ?? 0);
        $discount = (float) ($_POST['discount'] ?? 0);
        $work_details_id = $_POST['work_details_id'] ?? '';
        $partner1_id = $_POST['partner1_id'] ?? '';

        if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || !$work_details_id || !$partner1_id || $extra_sale < 0) {
            respond(false, 'لطفاً تمام فیلدها را پر کنید و اضافه فروش منفی نباشد.');
        }

        $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            respond(false, 'محصول یافت نشد.');
        }

        $items = $_SESSION['order_items'] ?? [];
        if ($items && array_filter($items, fn($item) => $item['product_id'] === $product_id)) {
            respond(false, 'این محصول قبلاً در فاکتور ثبت شده است. برای ویرایش از دکمه ویرایش استفاده کنید.');
        }

        $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ?");
        $stmt_inventory->execute([$partner1_id, $product_id]);
        $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);
        $current_quantity = $inventory ? (int) $inventory['quantity'] : 0;

        if ($current_quantity < $quantity) {
            respond(false, "موجودی کافی برای محصول '{$product['product_name']}' نیست. موجودی: $current_quantity، درخواست: $quantity");
        }

        $adjusted_price = $unit_price + $extra_sale;
        $items[] = [
            'product_id' => $product_id,
            'product_name' => $product['product_name'],
            'quantity' => $quantity,
            'unit_price' => $adjusted_price,
            'extra_sale' => $extra_sale,
            'total_price' => $quantity * $adjusted_price
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

    case 'edit_item':
        $customer_name = $_POST['customer_name'] ?? '';
        $product_id = $_POST['product_id'] ?? '';
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $unit_price = (float) ($_POST['unit_price'] ?? 0);
        $discount = (float) ($_POST['discount'] ?? 0);
        $index = (int) ($_POST['index'] ?? -1);
        $work_details_id = $_POST['work_details_id'] ?? '';
        $partner1_id = $_POST['partner1_id'] ?? '';

        if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || $index < 0 || !$work_details_id || !$partner1_id) {
            respond(false, 'لطفاً تمام فیلدها را پر کنید.');
        }

        $items = $_SESSION['order_items'] ?? [];
        if (!isset($items[$index])) {
            respond(false, 'آیتم مورد نظر یافت نشد.');
        }

        $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ?");
        $stmt_inventory->execute([$partner1_id, $product_id]);
        $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);
        $current_quantity = $inventory ? (int) $inventory['quantity'] : 0;

        $old_quantity = $items[$index]['quantity'];
        $quantity_diff = $old_quantity - $quantity;
        if ($current_quantity + $quantity_diff < 0) {
            respond(false, "موجودی کافی برای محصول '{$items[$index]['product_name']}' نیست. موجودی: $current_quantity");
        }

        $items[$index] = [
            'product_id' => $product_id,
            'product_name' => $items[$index]['product_name'],
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'total_price' => $quantity * $unit_price
        ];

        $_SESSION['order_items'] = $items;
        $total_amount = array_sum(array_column($items, 'total_price'));
        $final_amount = $total_amount - $discount;

        respond(true, 'آیتم با موفقیت ویرایش شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount
        ]);
        break;

    case 'delete_item':
        $index = (int) ($_POST['index'] ?? -1);
        $partner1_id = $_POST['partner1_id'] ?? '';

        if ($index < 0 || !isset($_SESSION['order_items'][$index]) || !$partner1_id) {
            respond(false, 'آیتم یافت نشد.');
        }

        $items = $_SESSION['order_items'];
        unset($items[$index]);
        $items = array_values($items);
        $_SESSION['order_items'] = $items;

        // حذف قیمت فاکتور مرتبط با این ایندکس
        if (isset($_SESSION['invoice_prices'][$index])) {
            unset($_SESSION['invoice_prices'][$index]);
            $_SESSION['invoice_prices'] = array_values($_SESSION['invoice_prices']);
        }

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
        $partner1_id = $_POST['partner1_id'] ?? '';

        if (!$work_details_id || !$customer_name || !$partner1_id) {
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
            $stmt = $pdo->prepare("
                INSERT INTO Orders (work_details_id, customer_name, total_amount, discount, final_amount)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$work_details_id, $customer_name, $total_amount, $discount, $final_amount]);
            $order_id = $pdo->lastInsertId();

            foreach ($items as $item) {
                $stmt = $pdo->prepare("
                    INSERT INTO Order_Items (order_id, product_name, quantity, unit_price, extra_sale, total_price)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $order_id,
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['extra_sale'],
                    $item['total_price']
                ]);

                $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
                $stmt_inventory->execute([$partner1_id, $item['product_id']]);
                $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);

                $current_quantity = $inventory ? $inventory['quantity'] : 0;
                if ($current_quantity < $item['quantity']) {
                    throw new Exception("موجودی کافی برای محصول '{$item['product_name']}' نیست. موجودی: $current_quantity");
                }

                $new_quantity = $current_quantity - $item['quantity'];
                $stmt_update = $pdo->prepare("INSERT INTO Inventory (user_id, product_id, quantity) VALUES (?, ?, ?) 
                                           ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
                $stmt_update->execute([$partner1_id, $item['product_id'], $new_quantity]);
            }

            $pdo->commit();

            // نگه داشتن invoice_prices برای پرینت و پاک کردن بقیه
            $invoice_prices = $_SESSION['invoice_prices'] ?? [];
            unset($_SESSION['order_items']);
            unset($_SESSION['discount']);
            $_SESSION['is_order_in_progress'] = false;
            $_SESSION['invoice_prices'] = $invoice_prices;

            respond(true, 'سفارش با موفقیت ثبت شد.', [
                'redirect' => "print_invoice.php?order_id=$order_id"
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(false, 'خطا در ثبت سفارش: ' . $e->getMessage());
        }
        break;

    case 'add_edit_item':
        $customer_name = $_POST['customer_name'] ?? '';
        $product_id = $_POST['product_id'] ?? '';
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $unit_price = (float) ($_POST['unit_price'] ?? 0);
        $discount = (float) ($_POST['discount'] ?? 0);
        $order_id = $_POST['order_id'] ?? '';
        $partner1_id = $_POST['partner1_id'] ?? '';

        if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || !$order_id || !$partner1_id) {
            respond(false, 'لطفاً تمام فیلدها را پر کنید.');
        }

        $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            respond(false, 'محصول یافت نشد.');
        }

        $items = $_SESSION['edit_order_items'] ?? [];
        if ($items && array_filter($items, fn($item) => $item['product_id'] === $product_id)) {
            respond(false, 'این محصول قبلاً در فاکتور ثبت شده است. برای ویرایش از دکمه ویرایش استفاده کنید.');
        }

        $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ?");
        $stmt_inventory->execute([$partner1_id, $product_id]);
        $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);
        $current_quantity = $inventory ? (int) $inventory['quantity'] : 0;

        if ($current_quantity < $quantity) {
            respond(false, "موجودی کافی برای محصول '{$product['product_name']}' نیست. موجودی: $current_quantity، درخواست: $quantity");
        }

        $items[] = [
            'product_id' => $product_id,
            'product_name' => $product['product_name'],
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'total_price' => $quantity * $unit_price
        ];

        $_SESSION['edit_order_items'] = $items;
        $total_amount = array_sum(array_column($items, 'total_price'));
        $final_amount = $total_amount - $discount;

        respond(true, 'محصول با موفقیت اضافه شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount
        ]);
        break;

    case 'edit_edit_item':
        $customer_name = $_POST['customer_name'] ?? '';
        $product_id = $_POST['product_id'] ?? '';
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $unit_price = (float) ($_POST['unit_price'] ?? 0);
        $discount = (float) ($_POST['discount'] ?? 0);
        $index = (int) ($_POST['index'] ?? -1);
        $order_id = $_POST['order_id'] ?? '';
        $partner1_id = $_POST['partner1_id'] ?? '';

        if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || $index < 0 || !$order_id || !$partner1_id) {
            respond(false, 'لطفاً تمام فیلدها را پر کنید.');
        }

        $items = $_SESSION['edit_order_items'] ?? [];
        if (!isset($items[$index])) {
            respond(false, 'آیتم مورد نظر یافت نشد.');
        }

        $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ?");
        $stmt_inventory->execute([$partner1_id, $product_id]);
        $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);
        $current_quantity = $inventory ? (int) $inventory['quantity'] : 0;

        $old_quantity = $items[$index]['quantity'];
        $quantity_diff = $old_quantity - $quantity;
        if ($current_quantity + $quantity_diff < 0) {
            respond(false, "موجودی کافی برای محصول '{$items[$index]['product_name']}' نیست. موجودی: $current_quantity");
        }

        $items[$index] = [
            'product_id' => $product_id,
            'product_name' => $items[$index]['product_name'],
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'total_price' => $quantity * $unit_price
        ];

        $_SESSION['edit_order_items'] = $items;
        $total_amount = array_sum(array_column($items, 'total_price'));
        $final_amount = $total_amount - $discount;

        respond(true, 'آیتم با موفقیت ویرایش شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount
        ]);
        break;

    case 'delete_edit_item':
        $index = (int) ($_POST['index'] ?? -1);
        $order_id = $_POST['order_id'] ?? '';
        $partner1_id = $_POST['partner1_id'] ?? '';

        if ($index < 0 || !isset($_SESSION['edit_order_items'][$index]) || !$order_id || !$partner1_id) {
            respond(false, 'آیتم یافت نشد.');
        }

        $items = $_SESSION['edit_order_items'];
        unset($items[$index]);
        $items = array_values($items);
        $_SESSION['edit_order_items'] = $items;

        $total_amount = array_sum(array_column($items, 'total_price'));
        $discount = $_SESSION['edit_order_discount'] ?? 0;
        $final_amount = $total_amount - $discount;

        respond(true, 'آیتم با موفقیت حذف شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount
        ]);
        break;

    case 'update_edit_discount':
        $discount = (float) ($_POST['discount'] ?? 0);
        $order_id = $_POST['order_id'] ?? '';

        if (!$order_id) {
            respond(false, 'شناسه سفارش مشخص نشده است.');
        }

        $items = $_SESSION['edit_order_items'] ?? [];
        $total_amount = array_sum(array_column($items, 'total_price'));
        $final_amount = $total_amount - $discount;

        $_SESSION['edit_order_discount'] = $discount;

        respond(true, 'تخفیف با موفقیت به‌روزرسانی شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount
        ]);
        break;

    case 'save_edit_order':
        $order_id = $_POST['order_id'] ?? '';
        $customer_name = $_POST['customer_name'] ?? '';
        $discount = (float) ($_POST['discount'] ?? 0);
        $partner1_id = $_POST['partner1_id'] ?? '';

        if (!$order_id || !$customer_name || !$partner1_id) {
            respond(false, 'لطفاً تمام فیلدها را پر کنید.');
        }

        $new_items = $_SESSION['edit_order_items'] ?? [];
        if (empty($new_items)) {
            respond(false, 'هیچ محصولی برای ویرایش سفارش وجود ندارد.');
        }

        $total_amount = array_sum(array_column($new_items, 'total_price'));
        $final_amount = $total_amount - $discount;

        $pdo->beginTransaction();
        try {
            $stmt_items = $pdo->prepare("SELECT product_name, quantity, unit_price, total_price FROM Order_Items WHERE order_id = ?");
            $stmt_items->execute([$order_id]);
            $old_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

            $old_items_map = [];
            foreach ($old_items as $item) {
                $stmt_product = $pdo->prepare("SELECT product_id FROM Products WHERE product_name = ? LIMIT 1");
                $stmt_product->execute([$item['product_name']]);
                $product = $stmt_product->fetch(PDO::FETCH_ASSOC);
                $product_id = $product ? $product['product_id'] : null;

                if ($product_id) {
                    $old_items_map[$product_id] = [
                        'quantity' => $item['quantity'],
                        'product_name' => $item['product_name'],
                        'unit_price' => $item['unit_price'],
                        'total_price' => $item['total_price']
                    ];
                }
            }

            $new_items_map = [];
            foreach ($new_items as $item) {
                if ($item['product_id']) {
                    $new_items_map[$item['product_id']] = [
                        'quantity' => $item['quantity'],
                        'product_name' => $item['product_name'],
                        'unit_price' => $item['unit_price'],
                        'total_price' => $item['total_price']
                    ];
                }
            }

            foreach ($old_items_map as $product_id => $old_item) {
                if (!isset($new_items_map[$product_id])) {
                    $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
                    $stmt_inventory->execute([$partner1_id, $product_id]);
                    $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);

                    $current_quantity = $inventory ? $inventory['quantity'] : 0;
                    $new_quantity = $current_quantity + $old_item['quantity'];

                    $stmt_update = $pdo->prepare("INSERT INTO Inventory (user_id, product_id, quantity) VALUES (?, ?, ?) 
                                               ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
                    $stmt_update->execute([$partner1_id, $product_id, $new_quantity]);
                } elseif ($new_items_map[$product_id]['quantity'] != $old_item['quantity']) {
                    $quantity_diff = $old_item['quantity'] - $new_items_map[$product_id]['quantity'];
                    $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
                    $stmt_inventory->execute([$partner1_id, $product_id]);
                    $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);

                    $current_quantity = $inventory ? $inventory['quantity'] : 0;
                    $new_quantity = $current_quantity + $quantity_diff;

                    if ($new_quantity < 0) {
                        throw new Exception("موجودی کافی برای محصول '{$old_item['product_name']}' نیست. موجودی: $current_quantity");
                    }

                    $stmt_update = $pdo->prepare("INSERT INTO Inventory (user_id, product_id, quantity) VALUES (?, ?, ?) 
                                               ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
                    $stmt_update->execute([$partner1_id, $product_id, $new_quantity]);
                }
            }

            foreach ($new_items_map as $product_id => $new_item) {
                if (!isset($old_items_map[$product_id])) {
                    $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
                    $stmt_inventory->execute([$partner1_id, $product_id]);
                    $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);

                    $current_quantity = $inventory ? $inventory['quantity'] : 0;
                    if ($current_quantity < $new_item['quantity']) {
                        throw new Exception("موجودی کافی برای محصول '{$new_item['product_name']}' نیست. موجودی: $current_quantity، درخواست: {$new_item['quantity']}");
                    }

                    $new_quantity = $current_quantity - $new_item['quantity'];
                    $stmt_update = $pdo->prepare("INSERT INTO Inventory (user_id, product_id, quantity) VALUES (?, ?, ?) 
                                               ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
                    $stmt_update->execute([$partner1_id, $product_id, $new_quantity]);
                }
            }

            $stmt = $pdo->prepare("
                UPDATE Orders 
                SET customer_name = ?, total_amount = ?, discount = ?, final_amount = ?
                WHERE order_id = ?
            ");
            $stmt->execute([$customer_name, $total_amount, $discount, $final_amount, $order_id]);

            $stmt = $pdo->prepare("DELETE FROM Order_Items WHERE order_id = ?");
            $stmt->execute([$order_id]);

            foreach ($new_items as $item) {
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

            $pdo->commit();

            unset($_SESSION['edit_order_items']);
            unset($_SESSION['edit_order_id']);
            unset($_SESSION['edit_order_discount']);

            respond(true, 'سفارش با موفقیت ویرایش شد.', [
                'redirect' => "orders.php"
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(false, 'خطا در ویرایش سفارش: ' . $e->getMessage());
        }
        break;

    case 'set_invoice_price':
        $index = (int) ($_POST['index'] ?? -1);
        $invoice_price = (float) ($_POST['invoice_price'] ?? 0);
        $order_id = $_POST['order_id'] ?? ''; // اضافه کردن order_id برای تشخیص ویرایش

        if ($index < 0 || $invoice_price < 0) {
            respond(false, 'مقدار نامعتبر برای ایندکس یا قیمت فاکتور.');
        }

        // انتخاب آرایه مناسب بر اساس وجود order_id
        $items = $order_id ? ($_SESSION['edit_order_items'] ?? []) : ($_SESSION['order_items'] ?? []);
        if (!isset($items[$index])) {
            respond(false, 'آیتم مورد نظر یافت نشد.');
        }

        $_SESSION['invoice_prices'][$index] = $invoice_price;
        respond(true, 'قیمت فاکتور با موفقیت تنظیم شد.');
        break;

    case 'clear_invoice_prices':
        unset($_SESSION['invoice_prices']);
        respond(true, 'قیمت‌های فاکتور با موفقیت پاک شدند.');
        break;

    default:
        respond(false, 'Action not recognized.');
}
?>