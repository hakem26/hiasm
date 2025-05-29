<?php
// Starting output buffer to prevent unwanted output
ob_start();
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}

// Include dependencies
require_once 'db.php';
require_once 'jdf.php';

// Convert Gregorian date to Jalali format
function gregorian_to_jalali_format($gregorian_date)
{
    if (!$gregorian_date || !preg_match('/^\d{4}-\d{2}-\d{2}/', $gregorian_date)) {
        return 'نامشخص';
    }
    try {
        list($gy, $gm, $gd) = explode('-', $gregorian_date);
        $gy = (int) $gy;
        $gm = (int) $gm;
        $gd = (int) $gd;
        if ($gy < 1000 || $gm < 1 || $gm > 12 || $gd < 1 || $gd > 31) {
            return 'نامشخص';
        }
        list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
        return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
    } catch (Exception $e) {
        error_log("Error in gregorian_to_jalali_format: " . $e->getMessage());
        return 'نامشخص';
    }
}

// Send JSON response and clean buffer
function sendResponse($success, $message, $data = [])
{
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['success' => $success, 'message' => $message, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    exit;
}

$action = $_POST['action'] ?? '';
$current_user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

// Restrict admin access
if ($is_admin) {
    error_log("Admin access attempt by user_id=$current_user_id");
    sendResponse(false, 'دسترسی غیرمجاز.');
}

// Initialize session variables
$_SESSION['sub_order_items'] = $_SESSION['sub_order_items'] ?? [];
$_SESSION['sub_discount'] = $_SESSION['sub_discount'] ?? 0;
$_SESSION['sub_invoice_prices'] = $_SESSION['sub_invoice_prices'] ?? ['postal' => 50000];
$_SESSION['sub_postal_enabled'] = $_SESSION['sub_postal_enabled'] ?? false;
$_SESSION['sub_postal_price'] = $_SESSION['sub_postal_price'] ?? 50000;

try {
    switch ($action) {
        case 'get_related_partners':
            $work_month_id = $_POST['work_month_id'] ?? '';
            if (!$work_month_id) {
                error_log("get_related_partners: Missing work_month_id");
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

            error_log("get_related_partners: Found " . count($partners) . " partners for work_month_id=$work_month_id");
            if (empty($partners)) {
                sendResponse(false, 'هیچ همکاری برای این ماه کاری یافت نشد.');
            }

            sendResponse(true, 'موفق', ['partners' => $partners]);
            break;

        case 'get_partner_work_days':
            $partner_id = $_POST['partner_id'] ?? '';
            $work_month_id = $_POST['work_month_id'] ?? '';
            if (!$partner_id || !$work_month_id) {
                error_log("get_partner_work_days: Missing parameters - partner_id=$partner_id, work_month_id=$work_month_id");
                sendResponse(false, 'همکار یا ماه کاری مشخص نیست.');
            }

            $stmt = $pdo->prepare("
                SELECT wd.id, wd.work_date
                FROM Work_Details wd
                JOIN Partners p ON wd.partner_id = p.partner_id
                WHERE wd.work_month_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
                ORDER BY wd.work_date DESC
            ");
            $stmt->execute([$work_month_id, $partner_id, $partner_id]);
            $work_days = $stmt->fetchAll(PDO::FETCH_ASSOC);

            error_log("get_partner_work_days: Found " . count($work_days) . " work days for partner_id=$partner_id, work_month_id=$work_month_id");
            $formatted_days = array_map(function ($day) {
                return [
                    'id' => $day['id'],
                    'jalali_date' => gregorian_to_jalali_format($day['work_date'])
                ];
            }, $work_days);

            if (empty($work_days)) {
                sendResponse(false, 'هیچ روز کاری برای این همکار یافت نشد.');
            }

            sendResponse(true, 'موفق', ['work_days' => $formatted_days]);
            break;

        case 'add_sub_item':
            $customer_name = trim($_POST['customer_name'] ?? '');
            $product_id = trim($_POST['product_id'] ?? '');
            $quantity = floatval($_POST['quantity'] ?? 0);
            $unit_price = floatval($_POST['unit_price'] ?? 0);
            $extra_sale = floatval($_POST['extra_sale'] ?? 0);
            $discount = floatval($_POST['discount'] ?? 0);
            $work_details_id = $_POST['work_details_id'] ?? '';
            $partner_id = $_POST['partner_id'] ?: $current_user_id;
            $product_name = htmlspecialchars(trim($_POST['product_name'] ?? ''));

            error_log("add_sub_item: customer_name=$customer_name, product_id=$product_id, quantity=$quantity, unit_price=$unit_price, partner_id=$partner_id");

            if (!$customer_name || !$product_id || !$product_name || $quantity <= 0 || $unit_price <= 0) {
                sendResponse(false, 'لطفاً تمام فیلدها را پر کنید.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                sendResponse(false, 'محصول پیدا نشد.');
            }

            $pdo->beginTransaction();
            try {
                $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
                $stmt_inventory->execute([$current_user_id, $product_id]);
                $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);
                $current_quantity = $inventory ? (int) $inventory['quantity'] : 0;

                // Allow negative inventory as per original logic
                $new_quantity = $current_quantity - $quantity;

                $stmt_update = $pdo->prepare("
                    INSERT INTO Inventory (user_id, product_id, quantity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = ?
                ");
                $stmt_update->execute([$current_user_id, $product_id, $new_quantity, $new_quantity]);

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

                $pdo->commit();

                sendResponse(true, 'محصول اضافه شد.', [
                    'items' => $_SESSION['sub_order_items'],
                    'total_amount' => $total_amount,
                    'discount' => $discount,
                    'final_amount' => $final_amount,
                    'invoice_prices' => $_SESSION['sub_invoice_prices'],
                    'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                    'sub_postal_price' => $_SESSION['sub_postal_price']
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error in add_sub_item: " . $e->getMessage());
                sendResponse(false, 'خطا در اضافه کردن محصول.');
            }
            break;

        case 'delete_sub_item':
            $index = $_POST['index'] ?? '';
            if (!isset($_SESSION['sub_order_items'][$index])) {
                error_log("delete_sub_item: Invalid index=$index");
                sendResponse(false, 'آیتم یافت نشد.');
            }

            $item = $_SESSION['sub_order_items'][$index];
            $product_id = $item['product_id'];
            $quantity = $item['quantity'];

            $pdo->beginTransaction();
            try {
                $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
                $stmt_inventory->execute([$current_user_id, $product_id]);
                $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);
                $current_quantity = $inventory ? (int) $inventory['quantity'] : 0;
                $new_quantity = $current_quantity + $quantity;

                $stmt_update = $pdo->prepare("
                    INSERT INTO Inventory (user_id, product_id, quantity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = ?
                ");
                $stmt_update->execute([$current_user_id, $product_id, $new_quantity, $new_quantity]);

                unset($_SESSION['sub_order_items'][$index]);
                $_SESSION['sub_order_items'] = array_values($_SESSION['sub_order_items']);
                unset($_SESSION['sub_invoice_prices'][$index]);

                $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
                $final_amount = $total_amount - $_SESSION['sub_discount'] + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

                $pdo->commit();

                sendResponse(true, 'محصول حذف شد.', [
                    'items' => $_SESSION['sub_order_items'],
                    'total_amount' => $total_amount,
                    'discount' => $_SESSION['sub_discount'],
                    'final_amount' => $final_amount,
                    'invoice_prices' => $_SESSION['sub_invoice_prices'],
                    'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                    'sub_postal_price' => $_SESSION['sub_postal_price']
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error in delete_sub_item: " . $e->getMessage());
                sendResponse(false, 'خطا در حذف محصول.');
            }
            break;

        case 'set_sub_invoice_price':
            $index = $_POST['index'] ?? '';
            $invoice_price = floatval($_POST['invoice_price'] ?? 0);

            if ($invoice_price < 0) {
                error_log("set_sub_invoice_price: Negative invoice_price=$invoice_price");
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
            break;

        case 'set_sub_postal_option':
            $enable_postal = filter_var($_POST['enable_postal'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $postal_price = floatval($_POST['postal_price'] ?? 50000);

            error_log("set_sub_postal_option: enable_postal=$enable_postal, postal_price=$postal_price");

            $_SESSION['sub_postal_enabled'] = $enable_postal;
            $_SESSION['sub_postal_price'] = $postal_price;

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $_SESSION['sub_discount'] + ($enable_postal ? $postal_price : 0);

            sendResponse(true, 'گزینه پستی به‌روزرسانی شد.', [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $_SESSION['sub_discount'],
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $enable_postal,
                'sub_postal_price' => $postal_price
            ]);
            break;

        case 'update_sub_discount':
            $discount = floatval($_POST['discount'] ?? 0);
            if ($discount < 0) {
                error_log("update_sub_discount: Negative discount=$discount");
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
            break;

        case 'get_items':
            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $_SESSION['sub_discount'] + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            sendResponse(true, 'موفق', [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $_SESSION['sub_discount'],
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ]);
            break;

        case 'finalize_sub_order':
            $customer_name = trim($_POST['customer_name'] ?? '');
            $work_details_id = $_POST['work_details_id'] ?? '';
            $partner_id = $_POST['partner_id'] ?? $current_user_id;
            $discount = floatval($_POST['discount'] ?? 0);
            $work_month_id = $_POST['work_month_id'] ?? '';

            error_log("finalize_sub_order: customer_name=$customer_name, work_details_id=$work_details_id, partner_id=$partner_id, discount=$discount, work_month_id=$work_month_id");

            if (!$customer_name || !$work_details_id || !$partner_id || !$work_month_id) {
                error_log("finalize_sub_order: Missing required fields");
                sendResponse(false, 'نام مشتری، تاریخ کاری، همکار یا ماه کاری مشخص نیست.');
            }

            if (empty($_SESSION['sub_order_items'])) {
                error_log("finalize_sub_order: No items in session");
                sendResponse(false, 'هیچ محصولی برای ثبت سفارش انتخاب نشده است.');
            }

            $stmt = $pdo->prepare("
                SELECT id
                FROM Work_Details
                WHERE id = ? AND partner_id IN (
                    SELECT partner_id FROM Partners WHERE user_id1 = ? OR user_id2 = ?
                )
            ");
            $stmt->execute([$work_details_id, $partner_id, $partner_id]);
            if (!$stmt->fetch()) {
                error_log("finalize_sub_order: Invalid work_details_id=$work_details_id for partner_id=$partner_id");
                sendResponse(false, 'تاریخ کاری برای این همکار معتبر نیست.');
            }

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            if ($_SESSION['sub_postal_enabled']) {
                $total_amount += $_SESSION['sub_postal_price'];
            }
            $final_amount = $total_amount - $discount;

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO Orders (work_details_id, customer_name, total_amount, discount, final_amount, is_main_order)
                    VALUES (?, ?, ?, ?, ?, 0)
                ");
                $stmt->execute([$work_details_id, $customer_name, $total_amount, $discount, $final_amount]);
                $order_id = $pdo->lastInsertId();

                foreach ($_SESSION['sub_order_items'] as $index => $item) {
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

                    if (isset($_SESSION['sub_invoice_prices'][$index])) {
                        $stmt = $pdo->prepare("
                            INSERT INTO Invoice_Prices (order_id, item_index, invoice_price, is_postal, postal_price)
                            VALUES (?, ?, ?, 0, 0)
                        ");
                        $stmt->execute([$order_id, $index, $_SESSION['sub_invoice_prices'][$index]]);
                    }
                }

                if ($_SESSION['sub_postal_enabled'] && $_SESSION['sub_postal_price'] > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO Order_Items (order_id, product_name, quantity, unit_price, extra_sale, total_price)
                        VALUES (?, 'ارسال پستی', 1, ?, 0, ?)
                    ");
                    $stmt->execute([$order_id, $_SESSION['sub_postal_price'], $_SESSION['sub_postal_price']]);

                    $stmt = $pdo->prepare("
                        INSERT INTO Invoice_Prices (order_id, item_index, invoice_price, is_postal, postal_price)
                        VALUES (?, 0, ?, 1, ?)
                    ");
                    $stmt->execute([$order_id, $_SESSION['sub_postal_price'], $_SESSION['sub_postal_price']]);
                }

                $pdo->commit();

                // Clear session only after successful commit
                unset($_SESSION['sub_order_items']);
                unset($_SESSION['sub_discount']);
                unset($_SESSION['sub_invoice_prices']);
                unset($_SESSION['sub_postal_enabled']);
                unset($_SESSION['sub_postal_price']);
                unset($_SESSION['is_sub_order_in_progress']);

                sendResponse(true, 'پیش‌فاکتور با موفقیت ثبت شد.', ['redirect' => "print_invoice.php?order_id=$order_id"]);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error in finalize_sub_order: " . $e->getMessage());
                sendResponse(false, 'خطا در ثبت پیش‌فاکتور: ' . $e->getMessage());
            }
            break;

        case 'load_sub_order':
            $order_id = $_POST['order_id'] ?? '';
            if (!$order_id) {
                error_log("load_sub_order: Missing order_id");
                sendResponse(false, 'شناسه سفارش مشخص نیست.');
            }

            $stmt = $pdo->prepare("
                SELECT order_id, customer_name, total_amount, discount, final_amount, work_details_id
                FROM Orders WHERE order_id = ? AND is_main_order = 0
            ");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                error_log("load_sub_order: Order not found or not editable, order_id=$order_id");
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
                    $stmt_product = $pdo->prepare("SELECT product_id FROM Products WHERE product_name = ?");
                    $stmt_product->execute([$item['product_name']]);
                    $product = $stmt_product->fetch(PDO::FETCH_ASSOC);
                    $product_id = $product ? $product['product_id'] : '';

                    $sub_order_items[] = [
                        'product_id' => $product_id,
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
            break;

        case 'update_sub_order':
            $order_id = $_POST['order_id'] ?? '';
            $customer_name = trim($_POST['customer_name'] ?? '');
            $discount = floatval($_POST['discount'] ?? 0);
            $work_month_id = $_POST['work_month_id'] ?? '';

            error_log("update_sub_order: order_id=$order_id, customer_name=$customer_name, discount=$discount, work_month_id=$work_month_id");

            if (!$order_id || !$customer_name || !$work_month_id) {
                sendResponse(false, 'نام مشتری، شناسه سفارش یا ماه کاری مشخص نیست.');
            }

            if (empty($_SESSION['sub_order_items'])) {
                sendResponse(false, 'هیچ محصولی در پیش‌فاکتور نیست.');
            }

            $stmt = $pdo->prepare("SELECT order_id FROM Orders WHERE order_id = ? AND is_main_order = 0");
            $stmt->execute([$order_id]);
            if (!$stmt->fetch()) {
                error_log("update_sub_order: Order not found or not editable, order_id=$order_id");
                sendResponse(false, 'پیش‌فاکتور یافت نشد یا قابل ویرایش نیست.');
            }

            $pdo->beginTransaction();
            try {
                // Restore inventory for old items
                $stmt_items = $pdo->prepare("SELECT product_name, quantity FROM Order_Items WHERE order_id = ? AND product_name != 'ارسال پستی'");
                $stmt_items->execute([$order_id]);
                $old_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

                foreach ($old_items as $item) {
                    $stmt_product = $pdo->prepare("SELECT product_id FROM Products WHERE product_name = ?");
                    $stmt_product->execute([$item['product_name']]);
                    $product = $stmt_product->fetch(PDO::FETCH_ASSOC);
                    $product_id = $product ? $product['product_id'] : null;

                    if ($product_id) {
                        $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
                        $stmt_inventory->execute([$current_user_id, $product_id]);
                        $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);
                        $current_quantity = $inventory ? (int) $inventory['quantity'] : 0;
                        $new_quantity = $current_quantity + $item['quantity'];

                        $stmt_update = $pdo->prepare("
                            INSERT INTO Inventory (user_id, product_id, quantity)
                            VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE quantity = ?
                        ");
                        $stmt_update->execute([$current_user_id, $product_id, $new_quantity, $new_quantity]);
                    }
                }

                // Update inventory for new items
                foreach ($_SESSION['sub_order_items'] as $item) {
                    $product_id = $item['product_id'];
                    $quantity = $item['quantity'];

                    $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
                    $stmt_inventory->execute([$current_user_id, $product_id]);
                    $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);
                    $current_quantity = $inventory ? (int) $inventory['quantity'] : 0;
                    $new_quantity = $current_quantity - $quantity;

                    $stmt_update = $pdo->prepare("
                        INSERT INTO Inventory (user_id, product_id, quantity)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE quantity = ?
                    ");
                    $stmt_update->execute([$current_user_id, $product_id, $new_quantity, $new_quantity]);
                }

                $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
                $final_amount = $total_amount - $discount + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

                // Update order
                $stmt = $pdo->prepare("
                    UPDATE Orders
                    SET customer_name = ?, total_amount = ?, discount = ?, final_amount = ?
                    WHERE order_id = ? AND is_main_order = 0
                ");
                $stmt->execute([$customer_name, $total_amount, $discount, $final_amount, $order_id]);

                // Clear old items and invoice prices
                $stmt = $pdo->prepare("DELETE FROM Order_Items WHERE order_id = ?");
                $stmt->execute([$order_id]);

                $stmt = $pdo->prepare("DELETE FROM Invoice_Prices WHERE order_id = ?");
                $stmt->execute([$order_id]);

                // Insert new items
                foreach ($_SESSION['sub_order_items'] as $index => $item) {
                    $invoice_price = $_SESSION['sub_invoice_prices'][$index] ?? $item['total_price'];
                    $stmt = $pdo->prepare("
                        INSERT INTO Order_Items (order_id, product_name, unit_price, extra_sale, quantity, total_price)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$order_id, $item['product_name'], $item['unit_price'], $item['extra_sale'], $item['quantity'], $invoice_price]);
                }

                // Insert postal item if enabled
                if ($_SESSION['sub_postal_enabled']) {
                    $postal_price = $_SESSION['sub_invoice_prices']['postal'] ?? $_SESSION['sub_postal_price'];
                    $stmt = $pdo->prepare("
                        INSERT INTO Order_Items (order_id, product_name, unit_price, quantity, total_price)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$order_id, 'ارسال پستی', $postal_price, 1, $postal_price]);
                }

                // Insert invoice prices
                foreach ($_SESSION['sub_invoice_prices'] as $index => $price) {
                    $stmt = $pdo->prepare("
                        INSERT INTO Invoice_Prices (order_id, item_index, invoice_price, is_postal, postal_price)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    if ($index === 'postal') {
                        $stmt->execute([$order_id, -1, 0, true, $price]);
                    } else {
                        $stmt->execute([$order_id, $index, $price, false, 0]);
                    }
                }

                $pdo->commit();

                // Clear session only after successful commit
                unset($_SESSION['sub_order_items']);
                unset($_SESSION['sub_discount']);
                unset($_SESSION['sub_invoice_prices']);
                unset($_SESSION['sub_postal_enabled']);
                unset($_SESSION['sub_postal_price']);
                unset($_SESSION['is_sub_order_in_progress']);

                sendResponse(true, 'پیش‌فاکتور با موفقیت به‌روزرسانی شد.', ['redirect' => 'orders.php?work_month_id=' . $work_month_id]);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error in update_sub_order: " . $e->getMessage());
                sendResponse(false, 'خطا در به‌روزرسانی پیش‌فاکتور.');
            }
            break;

        case 'convert_to_main_order':
            $order_id = $_POST['order_id'] ?? '';
            $work_details_id = $_POST['work_details_id'] ?? '';
            $partner_id = $_POST['partner_id'] ?: $current_user_id;
            $work_month_id = $_POST['work_month_id'] ?? '';

            error_log("convert_to_main_order: order_id=$order_id, work_details_id=$work_details_id, partner_id=$partner_id, work_month_id=$work_month_id");

            if (!$order_id || !$work_details_id || !$partner_id || !$work_month_id) {
                sendResponse(false, 'شناسه سفارش، همکار، تاریخ کاری یا ماه کاری مشخص نیست.');
            }

            $stmt = $pdo->prepare("SELECT order_id FROM Orders WHERE order_id = ? AND is_main_order = 0");
            $stmt->execute([$order_id]);
            if (!$stmt->fetch()) {
                error_log("convert_to_main_order: Order not found or already main, order_id=$order_id");
                sendResponse(false, 'پیش‌فاکتور یافت نشد یا قبلاً به فاکتور اصلی تبدیل شده است.');
            }

            $stmt = $pdo->prepare("
                SELECT id
                FROM Work_Details
                WHERE id = ? AND partner_id IN (
                    SELECT partner_id FROM Partners WHERE user_id1 = ? OR user_id2 = ?
                )
            ");
            $stmt->execute([$work_details_id, $partner_id, $partner_id]);
            if (!$stmt->fetch()) {
                error_log("convert_to_main_order: Invalid work_details_id=$work_details_id for partner_id=$partner_id");
                sendResponse(false, 'تاریخ کاری برای این همکار معتبر نیست.');
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    UPDATE Orders
                    SET work_details_id = ?, is_main_order = 1
                    WHERE order_id = ?
                ");
                $stmt->execute([$work_details_id, $order_id]);

                $pdo->commit();

                // Clear session only after successful commit
                unset($_SESSION['sub_order_items']);
                unset($_SESSION['sub_discount']);
                unset($_SESSION['sub_invoice_prices']);
                unset($_SESSION['sub_postal_enabled']);
                unset($_SESSION['sub_postal_price']);
                unset($_SESSION['is_sub_order_in_progress']);

                sendResponse(true, 'پیش‌فاکتور با موفقیت به فاکتور اصلی تبدیل شد.', ['redirect' => 'orders.php?work_month_id=' . $work_month_id]);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error in convert_to_main_order: " . $e->getMessage());
                sendResponse(false, 'خطا در تبدیل به فاکتور اصلی.');
            }
            break;

        default:
            error_log("Invalid action: $action");
            sendResponse(false, 'عملیات نامعتبر.');
            break;
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error in sub_order_handler.php: " . $e->getMessage());
    sendResponse(false, 'خطای سرور: ' . $e->getMessage());
}

// Clean up output buffer
ob_end_clean();
?>