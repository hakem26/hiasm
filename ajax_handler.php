<?php
ob_start();
session_start();
require_once 'db.php';

header('Content-Type: application/json; charset=UTF-8');

function respond($success, $message = '', $data = [])
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

try {
    $action = $_POST['action'] ?? '';
    if (!$action) {
        throw new Exception('اکشن مشخص نشده است.');
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

            // حذف چک موجودی یا اجازه دادن به موجودی منفی
            $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ?");
            $stmt_inventory->execute([$partner1_id, $product_id]);
            $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);
            $current_quantity = $inventory ? (int) $inventory['quantity'] : 0;

            // بدون چک موجودی، مستقیم اضافه می‌کنیم
            $adjusted_price = $unit_price + $extra_sale;
            $items[] = [
                'product_id' => $product_id,
                'product_name' => $product['product_name'],
                'quantity' => $quantity,
                'unit_price' => $unit_price,
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
                'extra_sale' => $items[$index]['extra_sale'],
                'total_price' => $quantity * ($unit_price + $items[$index]['extra_sale'])
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
            if ($discount < 0) {
                respond(false, 'تخفیف نمی‌تواند منفی باشد.');
            }

            $items = $_SESSION['temp_order_items'] ?? [];
            $total_amount = array_sum(array_column($items, 'total_price'));
            $postal_price = $_SESSION['postal_enabled'] ? ($_SESSION['postal_price'] ?? 50000) : 0;
            $final_amount = $total_amount - $discount + $postal_price;

            $_SESSION['discount'] = $discount;

            respond(true, 'تخفیف با موفقیت به‌روزرسانی شد.', [
                'items' => $items,
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'postal_enabled' => $_SESSION['postal_enabled'] ?? false,
                'postal_price' => $postal_price
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
            $postal_price = $_SESSION['postal_enabled'] ? ($_SESSION['invoice_prices']['postal'] ?? 50000) : 0;
            $final_amount = $total_amount - $discount + $postal_price;

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO Orders (work_details_id, customer_name, total_amount, discount, final_amount)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$work_details_id, $customer_name, $total_amount, $discount, $final_amount]);
                $order_id = $pdo->lastInsertId();

                foreach ($items as $index => $item) {
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

                    // آپدیت موجودی (اجازه دادن به منفی)
                    $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
                    $stmt_inventory->execute([$partner1_id, $item['product_id']]);
                    $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);

                    $current_quantity = $inventory ? $inventory['quantity'] : 0;
                    $new_quantity = $current_quantity - $item['quantity'];

                    $stmt_update = $pdo->prepare("
                        INSERT INTO Inventory (user_id, product_id, quantity)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE quantity = ?
                    ");
                    $stmt_update->execute([$partner1_id, $item['product_id'], $new_quantity, $new_quantity]);
                }

                $invoice_prices = $_SESSION['invoice_prices'] ?? [];
                if (!empty($invoice_prices)) {
                    $stmt_invoice = $pdo->prepare("
                        INSERT INTO Invoice_Prices (order_id, item_index, invoice_price, is_postal, postal_price)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    foreach ($invoice_prices as $index => $price) {
                        if ($index === 'postal' && $_SESSION['postal_enabled']) {
                            $stmt_invoice->execute([$order_id, -1, 0, TRUE, $price]);
                        } elseif ($index !== 'postal') {
                            $stmt_invoice->execute([$order_id, $index, $price, FALSE, 0]);
                        }
                    }
                }

                $pdo->commit();

                unset($_SESSION['order_items']);
                unset($_SESSION['discount']);
                unset($_SESSION['invoice_prices']);
                unset($_SESSION['postal_enabled']);
                unset($_SESSION['postal_price']);
                $_SESSION['is_order_in_progress'] = false;

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
            $extra_sale = (float) ($_POST['extra_sale'] ?? 0);
            $discount = (float) ($_POST['discount'] ?? 0);
            $order_id = $_POST['order_id'] ?? '';
            $partner1_id = $_POST['partner1_id'] ?? '';

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || !$order_id || !$partner1_id) {
                respond(false, 'لطفاً تمام فیلدها را پر کنید.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product_name = $stmt->fetchColumn();

            if (!$product_name) {
                respond(false, 'محصول یافت نشد.');
            }

            $total_price = $quantity * ($unit_price + $extra_sale);

            $new_item = [
                'product_id' => $product_id,
                'product_name' => $product_name,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'extra_sale' => $extra_sale,
                'total_price' => $total_price
            ];

            if (!isset($_SESSION['edit_order_items'])) {
                $_SESSION['edit_order_items'] = [];
            }
            $_SESSION['edit_order_items'][] = $new_item;

            $total_amount = array_sum(array_column($_SESSION['edit_order_items'], 'total_price'));
            $stmt_postal = $pdo->prepare("SELECT postal_price FROM Invoice_Prices WHERE order_id = ? AND is_postal = TRUE");
            $stmt_postal->execute([$order_id]);
            $postal_price = $stmt_postal->fetchColumn() ?: 0;
            $final_amount = $total_amount - $discount + $postal_price;

            respond(true, 'محصول با موفقیت اضافه شد.', [
                'items' => $_SESSION['edit_order_items'],
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
            $extra_sale = (float) ($_POST['extra_sale'] ?? 0);
            $discount = (float) ($_POST['discount'] ?? 0);
            $index = (int) ($_POST['index'] ?? -1);
            $order_id = $_POST['order_id'] ?? '';
            $partner1_id = $_POST['partner1_id'] ?? '';

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || $index < 0 || !$order_id || !$partner1_id) {
                respond(false, 'لطفاً تمام فیلدها را پر کنید.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product_name = $stmt->fetchColumn();

            if (!$product_name) {
                respond(false, 'محصول یافت نشد.');
            }

            $total_price = $quantity * ($unit_price + $extra_sale);

            if (!isset($_SESSION['edit_order_items'][$index])) {
                respond(false, 'آیتم مورد نظر یافت نشد.');
            }

            $_SESSION['edit_order_items'][$index] = [
                'product_id' => $product_id,
                'product_name' => $product_name,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'extra_sale' => $extra_sale,
                'total_price' => $total_price
            ];

            $total_amount = array_sum(array_column($_SESSION['edit_order_items'], 'total_price'));
            $stmt_postal = $pdo->prepare("SELECT postal_price FROM Invoice_Prices WHERE order_id = ? AND is_postal = TRUE");
            $stmt_postal->execute([$order_id]);
            $postal_price = $stmt_postal->fetchColumn() ?: 0;
            $final_amount = $total_amount - $discount + $postal_price;

            respond(true, 'محصول با موفقیت ویرایش شد.', [
                'items' => $_SESSION['edit_order_items'],
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount
            ]);
            break;

        case 'delete_edit_item':
            $index = (int) ($_POST['index'] ?? -1);
            $order_id = $_POST['order_id'] ?? '';
            $partner1_id = $_POST['partner1_id'] ?? '';

            if ($index < 0 || !$order_id || !$partner1_id) {
                respond(false, 'ایندکس یا شناسه سفارش نامعتبر است.');
            }

            if (!isset($_SESSION['edit_order_items'][$index])) {
                respond(false, 'آیتم مورد نظر یافت نشد.');
            }

            unset($_SESSION['edit_order_items'][$index]);
            $_SESSION['edit_order_items'] = array_values($_SESSION['edit_order_items']);

            $stmt = $pdo->prepare("DELETE FROM Invoice_Prices WHERE order_id = ? AND item_index = ? AND is_postal = FALSE");
            $stmt->execute([$order_id, $index]);

            $total_amount = array_sum(array_column($_SESSION['edit_order_items'], 'total_price'));
            $discount = $_SESSION['edit_order_discount'] ?? 0;
            $stmt_postal = $pdo->prepare("SELECT postal_price FROM Invoice_Prices WHERE order_id = ? AND is_postal = TRUE");
            $stmt_postal->execute([$order_id]);
            $postal_price = $stmt_postal->fetchColumn() ?: 0;
            $final_amount = $total_amount - $discount + $postal_price;

            respond(true, 'محصول با موفقیت حذف شد.', [
                'items' => $_SESSION['edit_order_items'],
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount
            ]);
            break;

        case 'update_edit_discount':
            $discount = (float) ($_POST['discount'] ?? 0);
            $order_id = $_POST['order_id'] ?? '';

            if ($discount < 0 || !$order_id) {
                respond(false, 'مقدار تخفیف یا شناسه سفارش نامعتبر است.');
            }

            $_SESSION['edit_order_discount'] = $discount;
            $items = $_SESSION['edit_order_items'] ?? [];
            $total_amount = array_sum(array_column($items, 'total_price'));
            $stmt_postal = $pdo->prepare("SELECT postal_price FROM Invoice_Prices WHERE order_id = ? AND is_postal = TRUE");
            $stmt_postal->execute([$order_id]);
            $postal_price = $stmt_postal->fetchColumn() ?: 0;
            $final_amount = $total_amount - $discount + $postal_price;

            respond(true, 'تخفیف با موفقیت به‌روزرسانی شد.', [
                'items' => $items,
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount
            ]);
            break;

        case 'set_invoice_price':
            $index = $_POST['index'] ?? '';
            $invoice_price = (float) ($_POST['invoice_price'] ?? 0);
            $order_id = $_POST['order_id'] ?? '';
            $work_month_id = $_POST['work_month_id'] ?? $_SESSION['work_month_id'] ?? '';
            $is_edit = isset($_SESSION['edit_temp_order_id']) && $_SESSION['edit_temp_order_id'] == $order_id;

            if ($index === '' || !$work_month_id) {
                respond(false, 'پارامترهای نامعتبر.');
            }

            $session_key = $is_edit ? 'edit_temp_order_items' : 'temp_order_items';
            $items = $_SESSION[$session_key] ?? [];
            if ($index !== 'postal' && empty($items) && $order_id) {
                try {
                    $stmt = $pdo->prepare("SELECT * FROM Temp_Order_Items WHERE temp_order_id = ? ORDER BY item_index ASC");
                    $stmt->execute([$order_id]);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $_SESSION[$session_key] = $items;
                } catch (PDOException $e) {
                    error_log("Error fetching items: " . $e->getMessage());
                    respond(false, 'خطا در دریافت آیتم‌ها.');
                }
            }

            if ($index !== 'postal' && !isset($items[$index])) {
                respond(false, 'آیتم مورد نظر یافت نشد.');
            }

            if ($index !== 'postal' && $invoice_price === 0 && isset($items[$index])) {
                $invoice_price = (float) ($items[$index]['unit_price'] + $items[$index]['extra_sale']);
            }

            $_SESSION['invoice_prices'] = $_SESSION['invoice_prices'] ?? [];
            $_SESSION['invoice_prices'][$index] = $invoice_price;

            if ($order_id) {
                try {
                    if ($index === 'postal') {
                        $stmt = $pdo->prepare("
                    INSERT INTO Invoice_Prices (order_id, item_index, invoice_price, is_postal, postal_price)
                    VALUES (?, -1, 0, TRUE, ?)
                    ON DUPLICATE KEY UPDATE postal_price = ?
                ");
                        $stmt->execute([$order_id, $invoice_price, $invoice_price]);
                        $_SESSION['postal_price'] = $invoice_price;
                        $_SESSION['postal_enabled'] = true;
                    } else {
                        $stmt = $pdo->prepare("
                    INSERT INTO Invoice_Prices (order_id, item_index, invoice_price, is_postal)
                    VALUES (?, ?, ?, FALSE)
                    ON DUPLICATE KEY UPDATE invoice_price = ?
                ");
                        $stmt->execute([$order_id, $index, $invoice_price, $invoice_price]);
                    }
                } catch (PDOException $e) {
                    error_log("Error updating invoice prices: " . $e->getMessage());
                    respond(false, 'خطا در ذخیره قیمت فاکتور.');
                }
            } elseif ($index === 'postal') {
                $_SESSION['postal_price'] = $invoice_price;
                $_SESSION['postal_enabled'] = true;
            }

            $total_amount = array_sum(array_column($items, 'total_price'));
            $discount = $_SESSION[$is_edit ? 'edit_temp_order_discount' : 'discount'] ?? 0;

        case 'set_postal_option':
            $order_id = $_POST['order_id'] ?? '';
            $enable_postal = filter_var($_POST['enable_postal'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $postal_price = (float) ($_POST['postal_price'] ?? 50000);

            $items = [];
            $discount = 0;
            $is_temp = false;

            if ($order_id) {
                $stmt = $pdo->prepare("SELECT 1 FROM Temp_Orders WHERE temp_order_id = ? LIMIT 1");
                $stmt->execute([$order_id]);
                $is_temp = $stmt->fetchColumn();

                if ($is_temp) {
                    $items = $_SESSION['edit_temp_order_items'] ?? [];
                    $discount = $_SESSION['edit_temp_order_discount'] ?? 0;
                } else {
                    $items = $_SESSION['edit_order_items'] ?? [];
                    $discount = $_SESSION['edit_order_discount'] ?? 0;
                }

                if ($enable_postal) {
                    $stmt = $pdo->prepare("
                INSERT INTO Invoice_Prices (order_id, item_index, invoice_price, is_postal, postal_price)
                VALUES (?, -1, 0, TRUE, ?)
                ON DUPLICATE KEY UPDATE postal_price = ?
            ");
                    $stmt->execute([$order_id, $postal_price, $postal_price]);
                    $_SESSION['invoice_prices']['postal'] = $postal_price;
                    $_SESSION['postal_enabled'] = true;
                    $_SESSION['postal_price'] = $postal_price;
                } else {
                    $stmt = $pdo->prepare("DELETE FROM Invoice_Prices WHERE order_id = ? AND is_postal = TRUE");
                    $stmt->execute([$order_id]);
                    unset($_SESSION['invoice_prices']['postal']);
                    $_SESSION['postal_price'] = 0;
                    $_SESSION['postal_enabled'] = false;
                }
            } else {
                $items = $_SESSION['temp_order_items'] ?? $_SESSION['order_items'] ?? [];
                $discount = $_SESSION['discount'] ?? 0;

                $_SESSION['postal_enabled'] = $enable_postal;
                if ($enable_postal) {
                    $_SESSION['postal_price'] = $postal_price;
                    $_SESSION['invoice_prices']['postal'] = $postal_price;
                } else {
                    $_SESSION['postal_price'] = 0;
                    unset($_SESSION['invoice_prices']['postal']);
                }
            }

            $total_amount = array_sum(array_column($items, 'total_price'));
            $final_amount = $total_amount - $discount + ($enable_postal ? $postal_price : 0);

            respond(true, 'گزینه ارسال پستی با موفقیت به‌روزرسانی شد.', [
                'items' => $items,
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'postal_enabled' => $enable_postal,
                'postal_price' => $enable_postal ? $postal_price : 0
            ]);
            break;

        case 'save_edit_order':
            $order_id = $_POST['order_id'] ?? '';
            $customer_name = $_POST['customer_name'] ?? '';
            $discount = (float) ($_POST['discount'] ?? 0);
            $partner1_id = $_POST['partner1_id'] ?? '';
            $postal_enabled = isset($_POST['postal_option']) && $_POST['postal_option'] === 'on';

            if (!$order_id || !$customer_name || !$partner1_id) {
                respond(false, 'لطفاً تمام فیلدها را پر کنید.');
            }

            $new_items = $_SESSION['edit_order_items'] ?? [];
            if (empty($new_items)) {
                respond(false, 'هیچ محصولی برای ویرایش سفارش وجود ندارد.');
            }

            $total_amount = array_sum(array_column($new_items, 'total_price'));
            $postal_price = $postal_enabled && isset($_SESSION['invoice_prices']['postal']) ? (float) $_SESSION['invoice_prices']['postal'] : 0;
            $final_amount = $total_amount - $discount + $postal_price;

            $pdo->beginTransaction();
            try {
                $stmt_items = $pdo->prepare("SELECT product_name, quantity, unit_price, extra_sale, total_price FROM Order_Items WHERE order_id = ? ORDER BY item_id ASC");
                $stmt_items->execute([$order_id]);
                $old_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

                $old_items_map = [];
                foreach ($old_items as $index => $item) {
                    $stmt_product = $pdo->prepare("SELECT product_id FROM Products WHERE product_name = ? LIMIT 1");
                    $stmt_product->execute([$item['product_name']]);
                    $product = $stmt_product->fetch(PDO::FETCH_ASSOC);
                    $product_id = $product ? $product['product_id'] : null;

                    if ($product_id) {
                        $old_items_map[$product_id] = [
                            'index' => $index,
                            'quantity' => $item['quantity'],
                            'product_name' => $item['product_name'],
                            'unit_price' => $item['unit_price'],
                            'extra_sale' => $item['extra_sale'],
                            'total_price' => $item['total_price']
                        ];
                    }
                }

                $new_items_map = [];
                foreach ($new_items as $index => $item) {
                    if ($item['product_id']) {
                        $new_items_map[$item['product_id']] = [
                            'index' => $item['original_index'] ?? $index,
                            'quantity' => $item['quantity'],
                            'product_name' => $item['product_name'],
                            'unit_price' => $item['unit_price'],
                            'extra_sale' => $item['extra_sale'],
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

                $stmt_insert = $pdo->prepare("
                    INSERT INTO Order_Items (order_id, product_name, quantity, unit_price, extra_sale, total_price)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                foreach ($new_items as $index => $item) {
                    $stmt_insert->execute([
                        $order_id,
                        $item['product_name'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['extra_sale'],
                        $item['total_price']
                    ]);
                }

                // حذف همه ردیف‌های قبلی Invoice_Prices برای این سفارش
                $stmt_delete = $pdo->prepare("DELETE FROM Invoice_Prices WHERE order_id = ?");
                $stmt_delete->execute([$order_id]);

                // درج یا به‌روزرسانی قیمت‌های فاکتور (شامل ارسال پستی)
                $stmt_invoice = $pdo->prepare("
                    INSERT INTO Invoice_Prices (order_id, item_index, invoice_price, is_postal, postal_price)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE invoice_price = VALUES(invoice_price), postal_price = VALUES(postal_price)
                ");
                foreach ($_SESSION['invoice_prices'] ?? [] as $index => $price) {
                    if ($index === 'postal') {
                        $stmt_invoice->execute([$order_id, -1, 0, TRUE, $price]);
                    } else {
                        $stmt_invoice->execute([$order_id, $index, $price, FALSE, 0]);
                    }
                }

                $pdo->commit();

                unset($_SESSION['edit_order_items']);
                unset($_SESSION['edit_order_id']);
                unset($_SESSION['edit_order_discount']);
                unset($_SESSION['invoice_prices']);

                respond(true, 'سفارش با موفقیت ویرایش شد.', [
                    'redirect' => "print_invoice.php?order_id=$order_id"
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                respond(false, 'خطا در ویرایش سفارش: ' . $e->getMessage());
            }
            break;

        case 'add_edit_temp_item':
            $customer_name = trim($_POST['customer_name'] ?? '');
            $product_id = $_POST['product_id'] ?? '';
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $unit_price = (float) ($_POST['unit_price'] ?? 0);
            $extra_sale = (float) ($_POST['extra_sale'] ?? 0);
            $discount = (float) ($_POST['discount'] ?? 0);
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            $partner1_id = $_POST['partner1_id'] ?? '';
            $work_month_id = $_POST['work_month_id'] ?? '';

            if (!$customer_name || !$product_id || $quantity <= 0 || !$temp_order_id || !$partner1_id || !$work_month_id) {
                respond(false, 'لطفاً تمام فیلدها را پر کنید.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product_name = $stmt->fetchColumn();

            if (!$product_name) {
                respond(false, 'محصول یافت نشد.');
            }

            $total_price = $quantity * ($unit_price + $extra_sale);

            if (!isset($_SESSION['edit_temp_order_items'])) {
                $_SESSION['edit_temp_order_items'] = [];
            }
            $item_index = count($_SESSION['edit_temp_order_items']);
            $new_item = [
                'product_id' => $product_id,
                'product_name' => $product_name,
                'quantity' => $quantity,
                'total_price' => $total_price,
                'item_index' => $item_index
            ];
            $_SESSION['edit_temp_order_items'][] = $new_item;

            $stmt = $pdo->prepare("
        INSERT INTO Temp_Order_Items (temp_order_id, product_id, product_name, quantity, unit_price, extra_sale, total_price, item_index)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
            $stmt->execute([
                $temp_order_id,
                $product_id,
                $product_name,
                $quantity,
                $unit_price,
                $extra_sale,
                $total_price,
                $item_index
            ]);

            $total_amount = array_sum(array_column($_SESSION['edit_temp_order_items'], 'total_price'));
            $stmt_postal = $pdo->prepare("SELECT postal_price FROM Invoice_Prices WHERE order_id = ? AND is_postal = TRUE");
            $stmt_postal->execute([$temp_order_id]);
            $postal_price = $stmt_postal->fetchColumn() ?: 0;
            $final_amount = $total_amount - $discount + $postal_price;

            respond(true, 'محصول با موفقیت اضافه شد.', [
                'items' => $_SESSION['edit_temp_order_items'],
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'postal_enabled' => $_SESSION['postal_enabled'] ?? false,
                'postal_price' => $postal_price
            ]);
            break;

        case 'edit_edit_temp_item':
            $customer_name = trim($_POST['customer_name'] ?? '');
            $product_id = $_POST['product_id'] ?? '';
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $unit_price = (float) ($_POST['unit_price'] ?? 0);
            $extra_sale = (float) ($_POST['extra_sale'] ?? 0);
            $discount = (float) ($_POST['discount'] ?? 0);
            $index = (int) ($_POST['index'] ?? -1);
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            $partner1_id = $_POST['partner1_id'] ?? '';
            $work_month_id = $_POST['work_month_id'] ?? '';

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || $index < 0 || !$temp_order_id || !$partner1_id || !$work_month_id) {
                respond(false, 'لطفاً تمام فیلدها را پر کنید.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product_name = $stmt->fetchColumn();

            if (!$product_name) {
                respond(false, 'محصول یافت نشد.');
            }

            $total_price = $quantity * ($unit_price + $extra_sale);

            if (!isset($_SESSION['edit_temp_order_items'][$index])) {
                respond(false, 'آیتم مورد نظر یافت نشد.');
            }

            $_SESSION['edit_temp_order_items'][$index] = [
                'product_id' => $product_id,
                'product_name' => $product_name,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'extra_sale' => $extra_sale,
                'total_price' => $total_price,
                'item_index' => $index
            ];

            $stmt = $pdo->prepare("
        UPDATE Temp_Order_Items 
        SET product_id = ?, product_name = ?, quantity = ?, unit_price = ?, extra_sale = ?, total_price = ?
        WHERE temp_order_id = ? AND item_index = ?
    ");
            $stmt->execute([
                $product_id,
                $product_name,
                $quantity,
                $unit_price,
                $extra_sale,
                $total_price,
                $temp_order_id,
                $index
            ]);

            $total_amount = array_sum(array_column($_SESSION['edit_temp_order_items'], 'total_price'));
            $stmt_postal = $pdo->prepare("SELECT postal_price FROM Invoice_Prices WHERE order_id = ? AND is_postal = TRUE");
            $stmt_postal->execute([$temp_order_id]);
            $postal_price = $stmt_postal->fetchColumn() ?: 0;
            $final_amount = $total_amount - $discount + $postal_price;

            respond(true, 'محصول با موفقیت ویرایش شد.', [
                'items' => $_SESSION['edit_temp_order_items'],
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'postal_enabled' => $_SESSION['postal_enabled'] ?? false,
                'postal_price' => $postal_price
            ]);
            break;

        case 'delete_edit_temp_item':
            $index = (int) ($_POST['index'] ?? -1);
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            $partner1_id = $_POST['partner1_id'] ?? '';
            $work_month_id = $_POST['work_month_id'] ?? '';

            if ($index < 0 || !$temp_order_id || !$partner1_id || !$work_month_id) {
                respond(false, 'ایندکس یا شناسه سفارش نامعتبر است.');
            }

            if (!isset($_SESSION['edit_temp_order_items'][$index])) {
                respond(false, 'آیتم مورد نظر یافت نشد.');
            }

            unset($_SESSION['edit_temp_order_items'][$index]);
            $_SESSION['edit_temp_order_items'] = array_values($_SESSION['edit_temp_order_items']);
            foreach ($_SESSION['edit_temp_order_items'] as $i => &$item) {
                $item['item_index'] = $i;
            }
            unset($item);

            $stmt = $pdo->prepare("DELETE FROM Temp_Order_Items WHERE temp_order_id = ? AND item_index = ?");
            $stmt->execute([$temp_order_id, $index]);

            $stmt = $pdo->prepare("DELETE FROM Invoice_Prices WHERE order_id = ? AND item_index = ? AND is_postal = FALSE");
            $stmt->execute([$temp_order_id, $index]);

            $total_amount = array_sum(array_column($_SESSION['edit_temp_order_items'], 'total_price'));
            $discount = $_SESSION['edit_temp_order_discount'] ?? 0;
            $stmt_postal = $pdo->prepare("SELECT postal_price FROM Invoice_Prices WHERE order_id = ? AND is_postal = TRUE");
            $stmt_postal->execute([$temp_order_id]);
            $postal_price = $stmt_postal->fetchColumn() ?: 0;
            $final_amount = $total_amount - $discount + $postal_price;

            respond(true, 'محصول با موفقیت حذف شد.', [
                'items' => $_SESSION['edit_temp_order_items'],
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'postal_enabled' => $_SESSION['postal_enabled'] ?? false,
                'postal_price' => $postal_price
            ]);
            break;

        case 'update_edit_temp_discount':
            $discount = (float) ($_POST['discount'] ?? 0);
            $temp_order_id = $_POST['temp_order_id'] ?? '';

            if ($discount < 0 || !$temp_order_id) {
                respond(false, 'مقدار تخفیف یا شناسه سفارش نامعتبر است.');
            }

            $_SESSION['edit_temp_order_discount'] = $discount;
            $items = $_SESSION['edit_temp_order_items'] ?? [];
            $total_amount = array_sum(array_column($items, 'total_price'));
            $stmt_postal = $pdo->prepare("SELECT postal_price FROM Invoice_Prices WHERE order_id = ? AND is_postal = TRUE");
            $stmt_postal->execute([$temp_order_id]);
            $postal_price = $stmt_postal->fetchColumn() ?: 0;
            $final_amount = $total_amount - $discount + $postal_price;

            respond(true, 'تخفیف با موفقیت به‌روزرسانی شد.', [
                'items' => $items,
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'postal_enabled' => $_SESSION['postal_enabled'] ?? false,
                'postal_price' => $postal_price
            ]);
            break;

        case 'clear_invoice_prices':
            unset($_SESSION['invoice_prices']);
            respond(true, 'قیمت‌های فاکتور با موفقیت پاک شدند.');
            break;

        case 'edit_temp_item':
            $customer_name = trim($_POST['customer_name'] ?? '');
            $product_id = $_POST['product_id'] ?? '';
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $unit_price = (float) ($_POST['unit_price'] ?? 0);
            $extra_sale = (float) ($_POST['extra_sale'] ?? 0);
            $discount = (float) ($_POST['discount'] ?? 0);
            $index = (int) ($_POST['index'] ?? -1);
            $partner1_id = $_POST['partner1_id'] ?? '';
            $work_month_id = $_POST['work_month_id'] ?? $_SESSION['work_month_id'] ?? '';

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || $index < 0 || !$partner1_id || !$work_month_id) {
                respond(false, 'لطفاً تمام فیلدها را پر کنید.');
            }

            $items = $_SESSION['temp_order_items'] ?? [];
            if (!isset($items[$index])) {
                respond(false, 'آیتم مورد نظر یافت نشد.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                respond(false, 'محصول یافت نشد.');
            }

            $items[$index] = [
                'product_id' => $product_id,
                'product_name' => $product['product_name'],
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'extra_sale' => $extra_sale,
                'total_price' => $quantity * ($unit_price + $extra_sale),
                'item_index' => $index
            ];

            $_SESSION['temp_order_items'] = $items;
            $_SESSION['discount'] = $discount;
            $total_amount = array_sum(array_column($items, 'total_price'));
            $postal_price = $_SESSION['postal_enabled'] ? ($_SESSION['postal_price'] ?? 50000) : 0;
            $final_amount = $total_amount - $discount + $postal_price;

            respond(true, 'آیتم با موفقیت ویرایش شد.', [
                'items' => $items,
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'postal_enabled' => $_SESSION['postal_enabled'] ?? false,
                'postal_price' => $postal_price
            ]);
            break;

        case 'add_temp_item':
            $customer_name = trim($_POST['customer_name'] ?? '');
            $product_id = $_POST['product_id'] ?? '';
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $unit_price = (float) ($_POST['unit_price'] ?? 0);
            $extra_sale = (float) ($_POST['extra_sale'] ?? 0);
            $discount = (float) ($_POST['discount'] ?? 0);
            $partner1_id = $_POST['partner1_id'] ?? '';
            $work_month_id = $_POST['work_month_id'] ?? $_SESSION['work_month_id'] ?? '';

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || !$partner1_id || $extra_sale < 0 || !$work_month_id) {
                respond(false, 'لطفاً تمام فیلدها را پر کنید و اضافه فروش منفی نباشد.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                respond(false, 'محصول یافت نشد.');
            }

            $items = $_SESSION['temp_order_items'] ?? [];
            if ($items && array_filter($items, fn($item) => $item['product_id'] === $product_id)) {
                respond(false, 'این محصول قبلاً در فاکتور ثبت شده است. برای ویرایش از دکمه ویرایش استفاده کنید.');
            }

            $adjusted_price = $unit_price + $extra_sale;
            $item_index = count($items);
            $items[] = [
                'product_id' => $product_id,
                'product_name' => $product['product_name'],
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'extra_sale' => $extra_sale,
                'total_price' => $quantity * $adjusted_price,
                'item_index' => $item_index
            ];

            $_SESSION['temp_order_items'] = $items;
            $_SESSION['discount'] = $discount;
            $total_amount = array_sum(array_column($items, 'total_price'));
            $postal_price = $_SESSION['postal_enabled'] ? ($_SESSION['postal_price'] ?? 50000) : 0;
            $final_amount = $total_amount - $discount + $postal_price;

            respond(true, 'محصول با موفقیت اضافه شد.', [
                'items' => $items,
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'postal_enabled' => $_SESSION['postal_enabled'] ?? false,
                'postal_price' => $postal_price
            ]);
            break;



        case 'delete_temp_item':
            $index = (int) ($_POST['index'] ?? -1);
            $partner1_id = $_POST['partner1_id'] ?? '';
            $work_month_id = $_POST['work_month_id'] ?? $_SESSION['work_month_id'] ?? '';

            if ($index < 0 || !isset($_SESSION['temp_order_items'][$index]) || !$partner1_id || !$work_month_id) {
                respond(false, 'آیتم یافت نشد.');
            }

            $items = $_SESSION['temp_order_items'];
            unset($items[$index]);
            $items = array_values($items);
            foreach ($items as $i => &$item) {
                $item['item_index'] = $i;
            }
            unset($item);

            $_SESSION['temp_order_items'] = $items;

            if (isset($_SESSION['invoice_prices'][$index])) {
                unset($_SESSION['invoice_prices'][$index]);
                $_SESSION['invoice_prices'] = array_values($_SESSION['invoice_prices']);
            }

            $total_amount = array_sum(array_column($items, 'total_price'));
            $discount = $_SESSION['discount'] ?? 0;
            $final_amount = $total_amount - $discount + ($_SESSION['postal_enabled'] ? ($_SESSION['postal_price'] ?? 50000) : 0);

            respond(true, 'آیتم با موفقیت حذف شد.', [
                'items' => $items,
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'postal_enabled' => $_SESSION['postal_enabled'] ?? false,
                'postal_price' => $_SESSION['postal_price'] ?? 50000
            ]);
            break;

        case 'finalize_temp_order':
            $customer_name = trim($_POST['customer_name'] ?? '');
            $discount = (float) ($_POST['discount'] ?? 0);
            $partner1_id = $_POST['partner1_id'] ?? '';
            $work_month_id = $_POST['work_month_id'] ?? $_SESSION['work_month_id'] ?? '';

            if (!$customer_name || !$partner1_id || !$work_month_id) {
                respond(false, 'لطفاً تمام فیلدها را پر کنید.');
            }

            $items = $_SESSION['temp_order_items'] ?? [];
            if (empty($items)) {
                respond(false, 'هیچ محصولی برای ثبت سفارش وجود ندارد.');
            }

            $total_amount = array_sum(array_column($items, 'total_price'));
            $postal_price = $_SESSION['postal_enabled'] ? ($_SESSION['postal_price'] ?? 50000) : 0;
            $final_amount = $total_amount - $discount + $postal_price;

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
            INSERT INTO Temp_Orders (customer_name, total_amount, discount, final_amount, user_id)
            VALUES (?, ?, ?, ?, ?)
        ");
                $stmt->execute([$customer_name, $total_amount, $discount, $final_amount, $partner1_id]);
                $temp_order_id = $pdo->lastInsertId();

                foreach ($items as $item) {
                    $stmt = $pdo->prepare("
                INSERT INTO Temp_Order_Items (temp_order_id, product_id, product_name, quantity, unit_price, extra_sale, total_price, item_index)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
                    $stmt->execute([
                        $temp_order_id,
                        $item['product_id'],
                        $item['product_name'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['extra_sale'],
                        $item['total_price'],
                        $item['item_index']
                    ]);
                }

                $invoice_prices = $_SESSION['invoice_prices'] ?? [];
                if (!empty($invoice_prices)) {
                    $stmt_invoice = $pdo->prepare("
                INSERT INTO Invoice_Prices (order_id, item_index, invoice_price, is_postal, postal_price)
                VALUES (?, ?, ?, ?, ?)
            ");
                    foreach ($invoice_prices as $index => $price) {
                        if ($index === 'postal' && $_SESSION['postal_enabled']) {
                            $stmt_invoice->execute([$temp_order_id, -1, 0, TRUE, $price]);
                        } elseif ($index !== 'postal') {
                            $stmt_invoice->execute([$temp_order_id, $index, $price, FALSE, 0]);
                        }
                    }
                }

                $pdo->commit();

                unset($_SESSION['temp_order_items']);
                unset($_SESSION['discount']);
                unset($_SESSION['invoice_prices']);
                unset($_SESSION['postal_enabled']);
                unset($_SESSION['postal_price']);
                $_SESSION['is_temp_order_in_progress'] = false;

                respond(true, 'سفارش موقت با موفقیت ثبت شد.', [
                    'redirect' => "orders.php?work_month_id=$work_month_id"
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                respond(false, 'خطا در ثبت سفارش: ' . $e->getMessage());
            }
            break;

        case 'convert_temp_order':
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            $work_details_id = $_POST['work_details_id'] ?? '';
            $partner1_id = $_POST['partner1_id'] ?? '';

            if (!$temp_order_id || !$work_details_id || !$partner1_id) {
                respond(false, 'اطلاعات ناقص است.');
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT * FROM Temp_Orders WHERE temp_order_id = ? AND user_id = ?");
                $stmt->execute([$temp_order_id, $partner1_id]);
                $temp_order = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$temp_order) {
                    $pdo->rollBack();
                    respond(false, 'سفارش موقت یافت نشد یا دسترسی ندارید.');
                }

                $stmt = $pdo->prepare("SELECT * FROM Temp_Order_Items WHERE temp_order_id = ?");
                $stmt->execute([$temp_order_id]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // بدون چک موجودی (مشابه finalize_order)
                $stmt = $pdo->prepare("
            INSERT INTO Orders (work_details_id, customer_name, total_amount, discount, final_amount)
            VALUES (?, ?, ?, ?, ?)
        ");
                $stmt->execute([
                    $work_details_id,
                    $temp_order['customer_name'],
                    $temp_order['total_amount'],
                    $temp_order['discount'],
                    $temp_order['final_amount']
                ]);
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
                    $new_quantity = $current_quantity - $item['quantity'];

                    $stmt_update = $pdo->prepare("
                INSERT INTO Inventory (user_id, product_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = ?
            ");
                    $stmt_update->execute([$partner1_id, $item['product_id'], $new_quantity, $new_quantity]);
                }

                $stmt = $pdo->prepare("SELECT * FROM Invoice_Prices WHERE order_id = ?");
                $stmt->execute([$temp_order_id]);
                $invoice_prices = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($invoice_prices as $price) {
                    $stmt = $pdo->prepare("
                INSERT INTO Invoice_Prices (order_id, item_index, invoice_price, is_postal, postal_price)
                VALUES (?, ?, ?, ?, ?)
            ");
                    $stmt->execute([
                        $order_id,
                        $price['item_index'],
                        $price['invoice_price'],
                        $price['is_postal'],
                        $price['postal_price']
                    ]);
                }

                $stmt = $pdo->prepare("DELETE FROM Temp_Order_Items WHERE temp_order_id = ?");
                $stmt->execute([$temp_order_id]);
                $stmt = $pdo->prepare("DELETE FROM Temp_Orders WHERE temp_order_id = ?");
                $stmt->execute([$temp_order_id]);

                $pdo->commit();

                respond(true, 'سفارش با موفقیت به سفارش دائمی تبدیل شد.', [
                    'redirect' => "print_invoice.php?order_id=$order_id"
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                respond(false, 'خطا در تبدیل سفارش: ' . $e->getMessage());
            }
            break;


        case 'get_edit_temp_order_items':
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            $work_month_id = $_POST['work_month_id'] ?? '';

            if (!$temp_order_id || !$work_month_id) {
                respond(false, 'شناسه سفارش یا ماه کاری نامعتبر است.');
            }

            try {
                $stmt = $pdo->prepare("
            SELECT toi.*, p.product_name 
            FROM Temp_Order_Items toi 
            JOIN Products p ON toi.product_id = p.product_id 
            WHERE toi.temp_order_id = ? 
            ORDER BY toi.item_index ASC
        ");
                $stmt->execute([$temp_order_id]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $stmt = $pdo->prepare("SELECT item_index, invoice_price, is_postal, postal_price 
                              FROM Invoice_Prices 
                              WHERE order_id = ?");
                $stmt->execute([$temp_order_id]);
                $invoice_prices = [];
                $postal_enabled = false;
                $postal_price = 50000;
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($row['is_postal']) {
                        $postal_enabled = true;
                        $postal_price = (float) ($row['postal_price'] ?? 50000);
                        $invoice_prices['postal'] = $postal_price;
                    } else {
                        $invoice_prices[(int) $row['item_index']] = (float) $row['invoice_price'];
                    }
                }

                $stmt = $pdo->prepare("SELECT total_amount, discount, final_amount 
                              FROM Temp_Orders 
                              WHERE temp_order_id = ?");
                $stmt->execute([$temp_order_id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);

                $_SESSION['edit_temp_order_items'] = $items;
                $_SESSION['invoice_prices'] = $invoice_prices;
                $_SESSION['postal_enabled'] = $postal_enabled;
                $_SESSION['postal_price'] = $postal_price;

                respond(true, 'آیتم‌ها با موفقیت دریافت شدند.', [
                    'items' => $items,
                    'total_amount' => (float) ($order['total_amount'] ?? 0),
                    'discount' => (float) ($order['discount'] ?? 0),
                    'final_amount' => (float) ($order['final_amount'] ?? 0),
                    'postal_enabled' => $postal_enabled,
                    'postal_price' => $postal_price,
                    'invoice_prices' => $invoice_prices
                ]);
            } catch (PDOException $e) {
                error_log("Error fetching temp order items: " . $e->getMessage());
                respond(false, 'خطا در دریافت آیتم‌ها: ' . $e->getMessage());
            }
            break;

        case 'save_edit_temp_order':
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            $customer_name = trim($_POST['customer_name'] ?? '');
            $discount = (float) ($_POST['discount'] ?? 0);
            $partner1_id = $_POST['partner1_id'] ?? '';
            $postal_enabled = isset($_POST['postal_option']) && $_POST['postal_option'] === 'on';

            if (!$temp_order_id || !$customer_name || !$partner1_id) {
                respond(false, 'لطفاً تمام فیلدها را پر کنید.');
            }

            $new_items = $_SESSION['edit_temp_order_items'] ?? [];
            if (empty($new_items)) {
                respond(false, 'هیچ محصولی برای ویرایش سفارش وجود ندارد.');
            }

            $total_amount = array_sum(array_column($new_items, 'total_price'));
            $postal_price = $postal_enabled && isset($_SESSION['invoice_prices']['postal']) ? (float) $_SESSION['invoice_prices']['postal'] : 0;
            $final_amount = $total_amount - $discount + $postal_price;

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
            UPDATE Temp_Orders 
            SET customer_name = ?, total_amount = ?, discount = ?, final_amount = ?, user_id = ?
            WHERE temp_order_id = ?
        ");
                $stmt->execute([$customer_name, $total_amount, $discount, $final_amount, $partner1_id, $temp_order_id]);

                $stmt = $pdo->prepare("DELETE FROM Temp_Order_Items WHERE temp_order_id = ?");
                $stmt->execute([$temp_order_id]);

                if (!empty($new_items)) {
                    $stmt_insert = $pdo->prepare("
                INSERT INTO Temp_Order_Items (temp_order_id, product_id, product_name, quantity, unit_price, extra_sale, total_price)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
                    foreach ($new_items as $item) {
                        $stmt_insert->execute([
                            $temp_order_id,
                            $item['product_id'],
                            $item['product_name'],
                            $item['quantity'],
                            $item['unit_price'],
                            $item['extra_sale'],
                            $item['total_price']
                        ]);
                    }
                }

                $stmt_delete = $pdo->prepare("DELETE FROM Invoice_Prices WHERE order_id = ?");
                $stmt_delete->execute([$temp_order_id]);

                if (!empty($_SESSION['invoice_prices'])) {
                    $stmt_invoice = $pdo->prepare("
                INSERT INTO Invoice_Prices (order_id, item_index, invoice_price, is_postal, postal_price)
                VALUES (?, ?, ?, ?, ?)
            ");
                    foreach ($_SESSION['invoice_prices'] as $index => $price) {
                        if ($index === 'postal' && $postal_enabled) {
                            $stmt_invoice->execute([$temp_order_id, -1, 0, TRUE, $price]);
                        } elseif ($index !== 'postal') {
                            $stmt_invoice->execute([$temp_order_id, $index, $price, FALSE, 0]);
                        }
                    }
                }

                $pdo->commit();

                unset($_SESSION['edit_temp_order_items']);
                unset($_SESSION['edit_temp_order_id']);
                unset($_SESSION['edit_temp_order_discount']);
                unset($_SESSION['invoice_prices']);
                unset($_SESSION['postal_enabled']);
                unset($_SESSION['postal_price']);

                respond(true, 'سفارش موقت با موفقیت ویرایش شد.', [
                    'redirect' => "orders.php"
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                respond(false, 'خطا در ویرایش سفارش: ' . $e->getMessage());
            }
            break;

        case 'sync_temp_items': // temp
            $items = json_decode($_POST['items'] ?? '[]', true);
            $work_month_id = $_POST['work_month_id'] ?? $_SESSION['work_month_id'] ?? '';

            if (!$work_month_id) {
                respond(false, 'ماه کاری نامعتبر.');
            }

            $_SESSION['temp_order_items'] = $items;
            respond(true, 'آیتم‌ها با موفقیت سینک شدند.');
            break;

        case 'sync_edit_temp_items': // temp
            $items = json_decode($_POST['items'] ?? '[]', true);
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            $work_month_id = $_POST['work_month_id'] ?? $_SESSION['work_month_id'] ?? '';

            if (!$temp_order_id || !$work_month_id) {
                respond(false, 'شناسه سفارش یا ماه کاری نامعتبر.');
            }

            $_SESSION['edit_temp_order_items'] = $items;
            respond(true, 'آیتم‌ها با موفقیت سینک شدند.');
            break;

        case 'get_temp_order_items': // temp
            $work_month_id = $_POST['work_month_id'] ?? $_SESSION['work_month_id'] ?? '';
            if (!$work_month_id) {
                respond(false, 'ماه کاری نامعتبر.');
            }

            $items = $_SESSION['temp_order_items'] ?? [];
            $invoice_prices = $_SESSION['invoice_prices'] ?? [];
            $total_amount = array_sum(array_column($items, 'total_price'));
            $discount = $_SESSION['discount'] ?? 0;
            $postal_enabled = $_SESSION['postal_enabled'] ?? false;
            $postal_price = $_SESSION['postal_price'] ?? 50000;
            $final_amount = $total_amount - $discount + ($postal_enabled ? $postal_price : 0);

            respond(true, 'آیتم‌ها با موفقیت دریافت شدند.', [
                'items' => $items,
                'invoice_prices' => $invoice_prices,
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'postal_enabled' => $postal_enabled,
                'postal_price' => $postal_price
            ]);
            break;

        default:
            throw new Exception('اکشن ناشناخته است.');
    }
} catch (Exception $e) {
    respond(false, 'خطا: ' . $e->getMessage());
}