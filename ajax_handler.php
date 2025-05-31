<?php
ob_start();
session_start();
require_once 'db.php';

function respond($success, $message = '', $data = []) {
    header('Content-Type: application/json; charset=UTF-8');
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
            $customer_name = trim($_POST['customer_name'] ?? '');
            $product_id = $_POST['product_id'] ?? '';
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $unit_price = (float) ($_POST['unit_price'] ?? 0);
            $extra_sale = (float) ($_POST['extra_sale'] ?? 0);
            $discount = (float) ($_POST['discount'] ?? 0);
            $work_details_id = $_POST['work_details_id'] ?? '';
            $partner1_id = $_POST['partner1_id'] ?? '';

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || !$work_details_id || !$partner1_id || $extra_sale < 0) {
                throw new Exception('لطفاً تمام فیلدها را پر کنید و اضافه فروش منفی نباشد.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new Exception('محصول یافت نشد.');
            }

            $items = $_SESSION['order_items'] ?? [];
            if ($items && array_filter($items, fn($item) => $item['product_id'] === $product_id)) {
                throw new Exception('این محصول قبلاً در فاکتور ثبت شده است. برای ویرایش از دکمه ویرایش استفاده کنید.');
            }

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

        case 'edit_item':
            $customer_name = trim($_POST['customer_name'] ?? '');
            $product_id = $_POST['product_id'] ?? '';
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $unit_price = (float) ($_POST['unit_price'] ?? 0);
            $extra_sale = (float) ($_POST['extra_sale'] ?? 0);
            $discount = (float) ($_POST['discount'] ?? 0);
            $index = (int) ($_POST['index'] ?? -1);
            $work_details_id = $_POST['work_details_id'] ?? '';
            $partner1_id = $_POST['partner1_id'] ?? '';

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || $index < 0 || !$work_details_id || !$partner1_id) {
                throw new Exception('لطفاً تمام فیلدها را پر کنید.');
            }

            $items = $_SESSION['order_items'] ?? [];
            if (!isset($items[$index])) {
                throw new Exception('آیتم مورد نظر یافت نشد.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new Exception('محصول یافت نشد.');
            }

            $items[$index] = [
                'product_id' => $product_id,
                'product_name' => $product['product_name'],
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'extra_sale' => $extra_sale,
                'total_price' => $quantity * ($unit_price + $extra_sale)
            ];

            $_SESSION['order_items'] = $items;
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

        case 'delete_item':
            $index = (int) ($_POST['index'] ?? -1);
            $partner1_id = $_POST['partner1_id'] ?? '';

            if ($index < 0 || !isset($_SESSION['order_items'][$index]) || !$partner1_id) {
                throw new Exception('آیتم یافت نشد.');
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
            $postal_price = $_SESSION['postal_enabled'] ? ($_SESSION['postal_price'] ?? 50000) : 0;
            $final_amount = $total_amount - $discount + $postal_price;

            respond(true, 'آیتم با موفقیت حذف شد.', [
                'items' => $items,
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'postal_enabled' => $_SESSION['postal_enabled'] ?? false,
                'postal_price' => $postal_price
            ]);
            break;

        case 'update_discount':
            $discount = (float) ($_POST['discount'] ?? 0);
            if ($discount < 0) {
                throw new Exception('تخفیف نمی‌تواند منفی باشد.');
            }

            $_SESSION['discount'] = $discount;
            $items = $_SESSION['order_items'] ?? [];
            $total_amount = array_sum(array_column($items, 'total_price'));
            $postal_price = $_SESSION['postal_enabled'] ? ($_SESSION['postal_price'] ?? 50000) : 0;
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

        case 'finalize_order':
            $work_details_id = $_POST['work_details_id'] ?? '';
            $customer_name = trim($_POST['customer_name'] ?? '');
            $discount = (float) ($_POST['discount'] ?? 0);
            $partner1_id = $_POST['partner1_id'] ?? '';

            if (!$work_details_id || !$customer_name || !$partner1_id) {
                throw new Exception('لطفاً تمام فیلدها را پر کنید.');
            }

            $items = $_SESSION['order_items'] ?? [];
            if (empty($items)) {
                throw new Exception('هیچ محصولی برای ثبت سفارش وجود ندارد.');
            }

            $total_amount = array_sum(array_column($items, 'total_price'));
            $postal_price = $_SESSION['postal_enabled'] ? ($_SESSION['postal_price'] ?? 50000) : 0;
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

                    $stmt_inventory = $pdo->prepare("
                        SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE
                    ");
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
                throw new Exception('خطا در ثبت سفارش: ' . $e->getMessage());
            }
            break;

        case 'add_edit_item':
            $customer_name = trim($_POST['customer_name'] ?? '');
            $product_id = $_POST['product_id'] ?? '';
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $unit_price = (float) ($_POST['unit_price'] ?? 0);
            $extra_sale = (float) ($_POST['extra_sale'] ?? 0);
            $discount = (float) ($_POST['discount'] ?? 0);
            $order_id = $_POST['order_id'] ?? '';
            $partner1_id = $_POST['partner1_id'] ?? '';

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || !$order_id || !$partner1_id) {
                throw new Exception('لطفاً تمام فیلدها را پر کنید.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product_name = $stmt->fetchColumn();

            if (!$product_name) {
                throw new Exception('محصول یافت نشد.');
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
            $postal_price = $stmt_postal->fetchColumn() ?: ($_SESSION['postal_price'] ?? 0);
            $final_amount = $total_amount - $discount + $postal_price;

            respond(true, 'محصول با موفقیت اضافه شد.', [
                'items' => $_SESSION['edit_order_items'],
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'postal_enabled' => $_SESSION['postal_enabled'] ?? false,
                'postal_price' => $postal_price
            ]);
            break;

        case 'edit_edit_item':
            $customer_name = trim($_POST['customer_name'] ?? '');
            $product_id = $_POST['product_id'] ?? '';
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $unit_price = (float) ($_POST['unit_price'] ?? 0);
            $extra_sale = (float) ($_POST['extra_sale'] ?? 0);
            $discount = (float) ($_POST['discount'] ?? 0);
            $index = (int) ($_POST['index'] ?? -1);
            $order_id = $_POST['order_id'] ?? '';
            $partner1_id = $_POST['partner1_id'] ?? '';

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || $index < 0 || !$order_id || !$partner1_id) {
                throw new Exception('لطفاً تمام فیلدها را پر کنید.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product_name = $stmt->fetchColumn();

            if (!$product_name) {
                throw new Exception('محصول یافت نشد.');
            }

            if (!isset($_SESSION['edit_order_items'][$index])) {
                throw new Exception('آیتم مورد نظر یافت نشد.');
            }

            $_SESSION['edit_order_items'][$index] = [
                'product_id' => $product_id,
                'product_name' => $product_name,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'extra_sale' => $extra_sale,
                'total_price' => $quantity * ($unit_price + $extra_sale)
            ];

            $total_amount = array_sum(array_column($_SESSION['edit_order_items'], 'total_price'));
            $stmt_postal = $pdo->prepare("SELECT postal_price FROM Invoice_Prices WHERE order_id = ? AND is_postal = TRUE");
            $stmt_postal->execute([$order_id]);
            $postal_price = $stmt_postal->fetchColumn() ?: ($_SESSION['postal_price'] ?? 0);
            $final_amount = $total_amount - $discount + $postal_price;

            respond(true, 'محصول با موفقیت ویرایش شد.', [
                'items' => $_SESSION['edit_order_items'],
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'postal_enabled' => $_SESSION['postal_enabled'] ?? false,
                'postal_price' => $postal_price
            ]);
            break;

        case 'delete_edit_item':
            $index = (int) ($_POST['index'] ?? -1);
            $order_id = $_POST['order_id'] ?? '';
            $partner1_id = $_POST['partner1_id'] ?? '';

            if ($index < 0 || !$order_id || !$partner1_id) {
                throw new Exception('ایندکس یا شناسه سفارش نامعتبر است.');
            }

            if (!isset($_SESSION['edit_order_items'][$index])) {
                throw new Exception('آیتم مورد نظر یافت نشد.');
            }

            unset($_SESSION['edit_order_items'][$index]);
            $_SESSION['edit_order_items'] = array_values($_SESSION['edit_order_items']);

            $stmt = $pdo->prepare("DELETE FROM Invoice_Prices WHERE order_id = ? AND item_index = ? AND is_postal = FALSE");
            $stmt->execute([$order_id, $index]);

            $total_amount = array_sum(array_column($_SESSION['edit_order_items'], 'total_price'));
            $discount = $_SESSION['edit_order_discount'] ?? 0;
            $stmt_postal = $pdo->prepare("SELECT postal_price FROM Invoice_Prices WHERE order_id = ? AND is_postal = TRUE");
            $stmt_postal->execute([$order_id]);
            $postal_price = $stmt_postal->fetchColumn() ?: ($_SESSION['postal_price'] ?? 0);
            $final_amount = $total_amount - $discount + $postal_price;

            respond(true, 'محصول با موفقیت حذف شد.', [
                'items' => $_SESSION['edit_order_items'],
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'postal_enabled' => $_SESSION['postal_enabled'] ?? false,
                'postal_price' => $postal_price
            ]);
            break;

        case 'update_edit_discount':
            $discount = (float) ($_POST['discount'] ?? 0);
            $order_id = $_POST['order_id'] ?? '';

            if ($discount < 0 || !$order_id) {
                throw new Exception('مقدار تخفیف یا شناسه سفارش نامعتبر است.');
            }

            $_SESSION['edit_order_discount'] = $discount;
            $items = $_SESSION['edit_order_items'] ?? [];
            $total_amount = array_sum(array_column($items, 'total_price'));
            $stmt_postal = $pdo->prepare("SELECT postal_price FROM Invoice_Prices WHERE order_id = ? AND is_postal = TRUE");
            $stmt_postal->execute([$order_id]);
            $postal_price = $stmt_postal->fetchColumn() ?: ($_SESSION['postal_price'] ?? 0);
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

        case 'set_invoice_price':
            $index = $_POST['index'] ?? '';
            $invoice_price = (float) ($_POST['invoice_price'] ?? 0);
            $order_id = $_POST['order_id'] ?? '';

            if ($index === '' || $invoice_price < 0) {
                throw new Exception('مقدار نامعتبر برای ایندکس یا قیمت فاکتور.');
            }

            $_SESSION['invoice_prices'][$index] = $invoice_price;

            if ($order_id) {
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
                    $items = $_SESSION['edit_order_items'] ?? $_SESSION['edit_temp_order_items'] ?? [];
                    if (!isset($items[$index])) {
                        throw new Exception('آیتم مورد نظر یافت نشد.');
                    }
                    $stmt = $pdo->prepare("
                        INSERT INTO Invoice_Prices (order_id, item_index, invoice_price, is_postal)
                        VALUES (?, ?, ?, FALSE)
                        ON DUPLICATE KEY UPDATE invoice_price = ?
                    ");
                    $stmt->execute([$order_id, $index, $invoice_price, $invoice_price]);
                }
            } elseif ($index === 'postal') {
                $_SESSION['postal_price'] = $invoice_price;
                $_SESSION['postal_enabled'] = true;
            }

            respond(true, 'قیمت فاکتور با موفقیت تنظیم شد.');
            break;

        case 'set_postal_option':
            $order_id = $_POST['order_id'] ?? '';
            $enable_postal = filter_var($_POST['enable_postal'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $postal_price = (float) ($_SESSION['postal_price'] ?? 50000);

            $items = [];
            $discount = 0;
            if ($order_id) {
                $stmt = $pdo->prepare("SELECT 1 FROM Temp_Orders WHERE temp_order_id = ? LIMIT 1");
                $stmt->execute([$order_id]);
                $is_temp = $stmt->fetchColumn();

                if ($is_temp) {
                    $items = $_SESSION['edit_temp_order_items'] ?? [];
                    $discount = (float) ($_SESSION['edit_temp_order_discount'] ?? 0);
                } else {
                    $items = $_SESSION['edit_order_items'] ?? [];
                    $discount = (float) ($_SESSION['edit_order_discount'] ?? 0);
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
                $_SESSION['postal_enabled'] = $enable_postal;
                if ($enable_postal) {
                    $_SESSION['postal_price'] = $postal_price;
                    $_SESSION['invoice_prices']['postal'] = $postal_price;
                } else {
                    $_SESSION['postal_price'] = 0;
                    unset($_SESSION['invoice_prices']['postal']);
                }
                $items = $_SESSION['order_items'] ?? [];
                $discount = (float) ($_SESSION['discount'] ?? 0);
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
            $customer_name = trim($_POST['customer_name'] ?? '');
            $discount = (float) ($_POST['discount'] ?? 0);
            $partner1_id = $_POST['partner1_id'] ?? '';

            if (!$order_id || !$customer_name || !$partner1_id) {
                throw new Exception('لطفاً تمام فیلدها را پر کنید.');
            }

            $new_items = $_SESSION['edit_order_items'] ?? [];
            if (empty($new_items)) {
                throw new Exception('هیچ محصولی برای ویرایش سفارش وجود ندارد.');
            }

            $total_amount = array_sum(array_column($new_items, 'total_price'));
            $postal_price = $_SESSION['postal_enabled'] ? (float) ($_SESSION['postal_price'] ?? 50000) : 0;
            $final_amount = $total_amount - $discount + $postal_price;

            $pdo->beginTransaction();
            try {
                $stmt_items = $pdo->prepare("
                    SELECT product_name, quantity, unit_price, extra_sale, total_price 
                    FROM Order_Items WHERE order_id = ? ORDER BY item_id ASC
                ");
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
                        $stmt_inventory = $pdo->prepare("
                            SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE
                        ");
                        $stmt_inventory->execute([$partner1_id, $product_id]);
                        $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);

                        $current_quantity = $inventory ? $inventory['quantity'] : 0;
                        $new_quantity = $current_quantity + $old_item['quantity'];

                        $stmt_update = $pdo->prepare("
                            INSERT INTO Inventory (user_id, product_id, quantity) 
                            VALUES (?, ?, ?) 
                            ON DUPLICATE KEY UPDATE quantity = ?
                        ");
                        $stmt_update->execute([$partner1_id, $product_id, $new_quantity, $new_quantity]);
                    } elseif ($new_items_map[$product_id]['quantity'] != $old_item['quantity']) {
                        $quantity_diff = $old_item['quantity'] - $new_items_map[$product_id]['quantity'];
                        $stmt_inventory = $pdo->prepare("
                            SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE
                        ");
                        $stmt_inventory->execute([$partner1_id, $product_id]);
                        $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);

                        $current_quantity = $inventory ? $inventory['quantity'] : 0;
                        $new_quantity = $current_quantity + $quantity_diff;

                        $stmt_update = $pdo->prepare("
                            INSERT INTO Inventory (user_id, product_id, quantity) 
                            VALUES (?, ?, ?) 
                            ON DUPLICATE KEY UPDATE quantity = ?
                        ");
                        $stmt_update->execute([$partner1_id, $product_id, $new_quantity, $new_quantity]);
                    }
                }

                foreach ($new_items_map as $product_id => $new_item) {
                    if (!isset($old_items_map[$product_id])) {
                        $stmt_inventory = $pdo->prepare("
                            SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE
                        ");
                        $stmt_inventory->execute([$partner1_id, $product_id]);
                        $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);

                        $current_quantity = $inventory ? $inventory['quantity'] : 0;
                        $new_quantity = $current_quantity - $new_item['quantity'];

                        $stmt_update = $pdo->prepare("
                            INSERT INTO Inventory (user_id, product_id, quantity) 
                            VALUES (?, ?, ?) 
                            ON DUPLICATE KEY UPDATE quantity = ?
                        ");
                        $stmt_update->execute([$partner1_id, $product_id, $new_quantity, $new_quantity]);
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

                $stmt_delete = $pdo->prepare("DELETE FROM Invoice_Prices WHERE order_id = ?");
                $stmt_delete->execute([$order_id]);

                $stmt_invoice = $pdo->prepare("
                    INSERT INTO Invoice_Prices (order_id, item_index, invoice_price, is_postal, postal_price)
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($_SESSION['invoice_prices'] ?? [] as $index => $price) {
                    if ($index === 'postal' && $_SESSION['postal_enabled']) {
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
                unset($_SESSION['postal_enabled']);
                unset($_SESSION['postal_price']);

                respond(true, 'سفارش با موفقیت ویرایش شد.', [
                    'redirect' => "print_invoice.php?order_id=$order_id"
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw new Exception('خطا در ویرایش سفارش: ' . $e->getMessage());
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

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || !$temp_order_id || !$partner1_id) {
                throw new Exception('لطفاً تمام فیلدها را پر کنید.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product_name = $stmt->fetchColumn();

            if (!$product_name) {
                throw new Exception('محصول یافت نشد.');
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

            if (!isset($_SESSION['edit_temp_order_items'])) {
                $_SESSION['edit_temp_order_items'] = [];
            }
            $_SESSION['edit_temp_order_items'][] = $new_item;

            $total_amount = array_sum(array_column($_SESSION['edit_temp_order_items'], 'total_price'));
            $stmt_postal = $pdo->prepare("SELECT postal_price FROM Invoice_Prices WHERE order_id = ? AND is_postal = TRUE");
            $stmt_postal->execute([$temp_order_id]);
            $postal_price = $stmt_postal->fetchColumn() ?: ($_SESSION['postal_price'] ?? 0);
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

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || $index < 0 || !$temp_order_id || !$partner1_id) {
                throw new Exception('لطفاً تمام فیلدها را پر کنید.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product_name = $stmt->fetchColumn();

            if (!$product_name) {
                throw new Exception('محصول یافت نشد.');
            }

            if (!isset($_SESSION['edit_temp_order_items'][$index])) {
                throw new Exception('آیتم مورد نظر یافت نشد.');
            }

            $_SESSION['edit_temp_order_items'][$index] = [
                'product_id' => $product_id,
                'product_name' => $product_name,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'extra_sale' => $extra_sale,
                'total_price' => $quantity * ($unit_price + $extra_sale)
            ];

            $total_amount = array_sum(array_column($_SESSION['edit_temp_order_items'], 'total_price'));
            $stmt_postal = $pdo->prepare("SELECT postal_price FROM Invoice_Prices WHERE order_id = ? AND is_postal = TRUE");
            $stmt_postal->execute([$temp_order_id]);
            $postal_price = $stmt_postal->fetchColumn() ?: ($_SESSION['postal_price'] ?? 0);
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

            if ($index < 0 || !$temp_order_id || !$partner1_id) {
                throw new Exception('ایندکس یا شناسه سفارش نامعتبر است.');
            }

            if (!isset($_SESSION['edit_temp_order_items'][$index])) {
                throw new Exception('آیتم مورد نظر یافت نشد.');
            }

            unset($_SESSION['edit_temp_order_items'][$index]);
            $_SESSION['edit_temp_order_items'] = array_values($_SESSION['edit_temp_order_items']);

            $stmt = $pdo->prepare("DELETE FROM Invoice_Prices WHERE order_id = ? AND item_index = ? AND is_postal = FALSE");
            $stmt->execute([$temp_order_id, $index]);

            $total_amount = array_sum(array_column($_SESSION['edit_temp_order_items'], 'total_price'));
            $discount = $_SESSION['edit_temp_order_discount'] ?? 0;
            $stmt_postal = $pdo->prepare("SELECT postal_price FROM Invoice_Prices WHERE order_id = ? AND is_postal = TRUE");
            $stmt_postal->execute([$temp_order_id]);
            $postal_price = $stmt_postal->fetchColumn() ?: ($_SESSION['postal_price'] ?? 0);
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
                throw new Exception('مقدار تخفیف یا شناسه سفارش نامعتبر است.');
            }

            $_SESSION['edit_temp_order_discount'] = $discount;
            $items = $_SESSION['edit_temp_order_items'] ?? [];
            $total_amount = array_sum(array_column($items, 'total_price'));
            $stmt_postal = $pdo->prepare("SELECT postal_price FROM Invoice_Prices WHERE order_id = ? AND is_postal = TRUE");
            $stmt_postal->execute([$temp_order_id]);
            $postal_price = $stmt_postal->fetchColumn() ?: ($_SESSION['postal_price'] ?? 0);
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

        case 'save_edit_temp_order':
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            $customer_name = trim($_POST['customer_name'] ?? '');
            $discount = (float) ($_POST['discount'] ?? 0);
            $partner1_id = $_POST['partner1_id'] ?? '';

            if (!$temp_order_id || !$customer_name || !$partner1_id) {
                throw new Exception('لطفاً تمام فیلدها را پر کنید.');
            }

            $new_items = $_SESSION['edit_temp_order_items'] ?? [];
            if (empty($new_items)) {
                throw new Exception('هیچ محصولی برای ویرایش سفارش وجود ندارد.');
            }

            $total_amount = array_sum(array_column($new_items, 'total_price'));
            $postal_price = $_SESSION['postal_enabled'] ? (float) ($_SESSION['postal_price'] ?? 50000) : 0;
            $final_amount = $total_amount - $discount + $postal_price;

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    UPDATE Temp_Orders 
                    SET customer_name = ?, total_amount = ?, discount = ?, final_amount = ?
                    WHERE temp_order_id = ? AND user_id = ?
                ");
                $stmt->execute([$customer_name, $total_amount, $discount, $final_amount, $temp_order_id, $partner1_id]);

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
                        if ($index === 'postal' && $_SESSION['postal_enabled']) {
                            $stmt_invoice->execute([$temp_order_id, -1, 0, TRUE, $price]);
                        } else {
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

                $stmt = $pdo->prepare("
                    SELECT wd.work_month_id 
                    FROM Temp_Orders to
                    JOIN Work_Details wd ON to.work_details_id = wd.work_details_id
                    WHERE to.temp_order_id = ?
                ");
                $stmt->execute([$temp_order_id]);
                $work_month_id = $stmt->fetchColumn() ?: '';

                respond(true, 'سفارش با موفقیت ویرایش شد.', [
                    'redirect' => "orders.php?work_month_id=" . ($work_month_id ?: '')
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw new Exception('خطا در ویرایش سفارش: ' . $e->getMessage());
            }
            break;

        case 'clear_invoice_prices':
            unset($_SESSION['invoice_prices']);
            unset($_SESSION['postal_enabled']);
            unset($_SESSION['postal_price']);
            respond(true, 'قیمت‌های فاکتور با موفقیت پاک شدند.');
            break;

        case 'add_temp_item':
            $customer_name = trim($_POST['customer_name'] ?? '');
            $product_id = $_POST['product_id'] ?? '';
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $unit_price = (float) ($_POST['unit_price'] ?? 0);
            $extra_sale = (float) ($_POST['extra_sale'] ?? 0);
            $discount = (float) ($_POST['discount'] ?? 0);
            $partner1_id = $_POST['partner1_id'] ?? '';

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || !$partner1_id || $extra_sale < 0) {
                throw new Exception('لطفاً تمام فیلدها را پر کنید و اضافه فروش منفی نباشد.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new Exception('محصول یافت نشد.');
            }

            $items = $_SESSION['temp_order_items'] ?? [];
            if ($items && array_filter($items, fn($item) => $item['product_id'] === $product_id)) {
                throw new Exception('این محصول قبلاً در فاکتور ثبت شده است.');
            }

            $adjusted_price = $unit_price + $extra_sale;
            $items[] = [
                'product_id' => $product_id,
                'product_name' => $product['product_name'],
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'extra_sale' => $extra_sale,
                'total_price' => $quantity * $adjusted_price
            ];

            $_SESSION['temp_order_items'] = $items;
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

            if ($index < 0 || !isset($_SESSION['temp_order_items'][$index]) || !$partner1_id) {
                throw new Exception('آیتم یافت نشد.');
            }

            $items = $_SESSION['temp_order_items'];
            unset($items[$index]);
            $items = array_values($items);
            $_SESSION['temp_order_items'] = $items;

            if (isset($_SESSION['invoice_prices'][$index])) {
                unset($_SESSION['invoice_prices'][$index]);
                $_SESSION['invoice_prices'] = array_values($_SESSION['invoice_prices']);
            }

            $total_amount = array_sum(array_column($items, 'total_price'));
            $discount = $_SESSION['discount'] ?? 0;
            $postal_price = $_SESSION['postal_enabled'] ? ($_SESSION['postal_price'] ?? 50000) : 0;
            $final_amount = $total_amount - $discount + $postal_price;

            respond(true, 'آیتم با موفقیت حذف شد.', [
                'items' => $items,
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'postal_enabled' => $_SESSION['postal_enabled'] ?? false,
                'postal_price' => $postal_price
            ]);
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

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || $index < 0 || !$partner1_id) {
                throw new Exception('لطفاً تمام فیلدها را پر کنید.');
            }

            $items = $_SESSION['temp_order_items'] ?? [];
            if (!isset($items[$index])) {
                throw new Exception('آیتم مورد نظر یافت نشد.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new Exception('محصول یافت نشد.');
            }

            $items[$index] = [
                'product_id' => $product_id,
                'product_name' => $product['product_name'],
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'extra_sale' => $extra_sale,
                'total_price' => $quantity * ($unit_price + $extra_sale)
            ];

            $_SESSION['temp_order_items'] = $items;
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

        case 'finalize_temp_order':
            $customer_name = trim($_POST['customer_name'] ?? '');
            $discount = (float) ($_POST['discount'] ?? 0);
            $partner1_id = $_POST['partner1_id'] ?? '';
            $work_month_id = $_POST['work_month_id'] ?? '';

            if (!$customer_name || !$partner1_id || !$work_month_id) {
                throw new Exception('لطفاً تمام فیلدها را پر کنید.');
            }

            $items = $_SESSION['temp_order_items'] ?? [];
            if (empty($items)) {
                throw new Exception('هیچ محصولی برای ثبت سفارش وجود ندارد.');
            }

            $total_amount = array_sum(array_column($items, 'total_price'));
            $postal_price = $_SESSION['postal_enabled'] ? ($_SESSION['postal_price'] ?? 50000) : 0;
            $final_amount = $total_amount - $discount + $postal_price;

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO Temp_Orders (customer_name, total_amount, discount, final_amount, user_id, work_details_id)
                    SELECT ?, ?, ?, ?, ?, id FROM Work_Details WHERE work_month_id = ?
                ");
                $stmt->execute([$customer_name, $total_amount, $discount, $final_amount, $partner1_id, $work_month_id]);
                $temp_order_id = $pdo->lastInsertId();

                foreach ($items as $item) {
                    $stmt = $pdo->prepare("
                        INSERT INTO Temp_Order_Items (temp_order_id, product_id, product_name, quantity, unit_price, extra_sale, total_price)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $temp_order_id,
                        $item['product_id'],
                        $item['product_name'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['extra_sale'],
                        $item['total_price']
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
                throw new Exception('خطا در ثبت سفارش: ' . $e->getMessage());
            }
            break;

        case 'convert_temp_order':
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            $work_details_id = $_POST['work_details_id'] ?? '';
            $partner1_id = $_POST['partner1_id'] ?? '';

            if (!$temp_order_id || !$work_details_id || !$partner1_id) {
                throw new Exception('اطلاعات ناقص است.');
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT * FROM Temp_Orders WHERE temp_order_id = ? AND user_id = ?");
                $stmt->execute([$temp_order_id, $partner1_id]);
                $temp_order = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$temp_order) {
                    $pdo->rollBack();
                    throw new Exception('سفارش موقت یافت نشد یا دسترسی ندارید.');
                }

                $stmt = $pdo->prepare("SELECT * FROM Temp_Order_Items WHERE temp_order_id = ?");
                $stmt->execute([$temp_order_id]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    
                        $stmt_inventory = $pdo->prepare("
                            SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE
                        ");
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
                    throw new Exception('خطا در تبدیل سفارش: ' . $e->getMessage());
                }
                break;

        case 'get_temp_order_items':
            $temp_order_id = $_POST['temp_order_id'] ?? '';
            if (!$temp_order_id || !is_numeric($temp_order_id)) {
                throw new Exception('شناسه سفارش نامعتبر است.');
            }

            $items = $_SESSION['edit_temp_order_items'] ?? [];
            respond(true, 'آیتم‌ها با موفقیت دریافت شدند.', ['items' => $items]);
            break;

        default:
            throw new Exception('اکشن نامعتبر است.');
    }
} catch (Exception $e) {
    error_log("Error in ajax_handler.php: " . $e->getMessage());
    respond(false, 'خطای سرور: ' . $e->getMessage());
}
?>