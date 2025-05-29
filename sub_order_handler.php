<?php
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date) {
    if (!$gregorian_date || !preg_match('/^\d{4}-\d{2}-\d{2}/', $gregorian_date)) {
        return 'نامشخص';
    }
    try {
        list($gy, $gm, $gd) = explode('-', $gregorian_date);
        $gy = (int)$gy;
        $gm = (int)$gm;
        $gd = (int)$gd;
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

function sendResponse($success, $message, $data = []) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
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
        case 'get_related_partners':
            $work_month_id = filter_input(INPUT_POST, 'work_month_id', FILTER_VALIDATE_INT);
            if (!$work_month_id) {
                error_log("Invalid work_month_id in get_related_partners: " . print_r($_POST, true));
                sendResponse(false, 'ماه کاری مشخص نیست.');
            }
            $stmt = $pdo->prepare("
                SELECT DISTINCT u.user_id, u.full_name
                FROM Partners p
                JOIN Users u ON (p.user_id1 = u.user_id OR p.user_id2 = u.user_id)
                WHERE (p.user_id1 = ? OR p.user_id2 = ?) AND u.user_id != ? AND u.role = 'seller'
            ");
            $stmt->execute([$current_user_id, $current_user_id, $current_user_id]);
            $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($partners)) {
                error_log("No partners found for user_id=$current_user_id, work_month_id=$work_month_id");
                sendResponse(false, 'هیچ همکاری برای این ماه کاری یافت نشد.');
            }
            sendResponse(true, 'موفق', ['partners' => $partners]);
            break;

        case 'get_partner_work_days':
            $partner_id = filter_input(INPUT_POST, 'partner_id', FILTER_VALIDATE_INT);
            $work_month_id = filter_input(INPUT_POST, 'work_month_id', FILTER_VALIDATE_INT);
            if (!$partner_id || !$work_month_id) {
                error_log("Invalid partner_id or work_month_id in get_partner_work_days: " . print_r($_POST, true));
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
            $formatted_days = array_map(function($day) {
                return [
                    'id' => $day['id'],
                    'jalali_date' => gregorian_to_jalali_format($day['work_date'])
                ];
            }, $work_days);
            if (empty($work_days)) {
                error_log("No work days found for partner_id=$partner_id, work_month_id=$work_month_id");
                sendResponse(false, 'هیچ روز کاری برای این همکار یافت نشد.');
            }
            sendResponse(true, 'موفق', ['work_days' => $formatted_days]);
            break;

        case 'add_sub_item':
            $customer_name = $_POST['customer_name'] ?? '';
            $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
            $product_name = $_POST['product_name'] ?? '';
            $quantity = (int)($_POST['quantity'] ?? 0);
            $unit_price = (float)($_POST['unit_price'] ?? 0);
            $extra_sale = (float)($_POST['extra_sale'] ?? 0);
            $work_details_id = filter_input(INPUT_POST, 'work_details_id', FILTER_VALIDATE_INT);
            $partner_id = filter_input(INPUT_POST, 'partner_id', FILTER_VALIDATE_INT);

            if (!$product_id || !$product_name || $quantity <= 0 || $unit_price <= 0 || !$work_details_id || !$partner_id) {
                error_log("Invalid input in add_sub_item: " . print_r($_POST, true));
                sendResponse(false, 'اطلاعات ناقص یا نامعتبر است.');
            }

            $total_price = $quantity * ($unit_price + $extra_sale);
            $item = [
                'product_id' => $product_id,
                'product_name' => $product_name,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'extra_sale' => $extra_sale,
                'total_price' => $total_price
            ];

            if (isset($_SESSION['sub_order_items'][$editingIndex])) {
                $_SESSION['sub_order_items'][$editingIndex] = $item;
            } else {
                $_SESSION['sub_order_items'][] = $item;
            }

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $_SESSION['sub_discount'] + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            sendResponse(true, 'محصول با موفقیت اضافه شد.', [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $_SESSION['sub_discount'],
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ]);
            break;

        case 'delete_sub_item':
            $index = (int)($_POST['index'] ?? -1);
            if ($index < 0 || !isset($_SESSION['sub_order_items'][$index])) {
                error_log("Invalid index in delete_sub_item: index=$index");
                sendResponse(false, 'آیتم یافت نشد.');
            }
            array_splice($_SESSION['sub_order_items'], $index, 1);
            unset($_SESSION['sub_invoice_prices'][$index]);

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $_SESSION['sub_discount'] + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            sendResponse(true, 'آیتم حذف شد.', [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $_SESSION['sub_discount'],
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ]);
            break;

        case 'set_sub_invoice_price':
            $index = $_POST['index'] ?? '';
            $invoice_price = (float)($_POST['invoice_price'] ?? 0);
            if ($index === '' || $invoice_price < 0) {
                error_log("Invalid input in set_sub_invoice_price: index=$index, invoice_price=$invoice_price");
                sendResponse(false, 'اطلاعات نامعتبر.');
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
            $enable_postal = filter_var($_POST['enable_postal'], FILTER_VALIDATE_BOOLEAN);
            $postal_price = (float)($_POST['postal_price'] ?? 50000);
            if ($postal_price < 0) {
                $postal_price = 50000;
            }
            $_SESSION['sub_postal_enabled'] = $enable_postal;
            $_SESSION['sub_postal_price'] = $postal_price;
            if (!$enable_postal) {
                unset($_SESSION['sub_invoice_prices']['postal']);
            } else {
                $_SESSION['sub_invoice_prices']['postal'] = $postal_price;
            }

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $_SESSION['sub_discount'] + ($enable_postal ? $postal_price : 0);

            sendResponse(true, 'گزینه پستی به‌روزرسانی شد.', [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $_SESSION['sub_discount'],
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ]);
            break;

        case 'update_sub_discount':
            $discount = (float)($_POST['discount'] ?? 0);
            if ($discount < 0) {
                error_log("Negative discount in update_sub_discount: discount=$discount");
                sendResponse(false, 'تخفیف نمی‌تواند منفی باشد.');
            }
            $_SESSION['sub_discount'] = $discount;

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            sendResponse(true, 'تخفیف به‌روزرسانی شد.', [
                'items' => $_SESSION['sub_order_items'],
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'invoice_prices' => $_SESSION['sub_invoice_prices'],
                'sub_postal_enabled' => $_SESSION['sub_postal_enabled'],
                'sub_postal_price' => $_SESSION['sub_postal_price']
            ]);
            break;

        case 'finalize_sub_order':
            $customer_name = $_POST['customer_name'] ?? '';
            $work_details_id = filter_input(INPUT_POST, 'work_details_id', FILTER_VALIDATE_INT);
            $partner_id = filter_input(INPUT_POST, 'partner_id', FILTER_VALIDATE_INT);
            $discount = (float)($_POST['discount'] ?? 0);
            $work_month_id = filter_input(INPUT_POST, 'work_month_id', FILTER_VALIDATE_INT);

            if (!$customer_name || !$work_details_id || !$partner_id || !$work_month_id || empty($_SESSION['sub_order_items'])) {
                error_log("Invalid input in finalize_sub_order: " . print_r($_POST, true));
                sendResponse(false, 'اطلاعات ناقص است یا محصولی انتخاب نشده.');
            }

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO Sub_Orders (work_details_id, customer_name, partner_id, total_amount, discount, final_amount, created_at, sub_postal_enabled, sub_postal_price)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)
            ");
            $stmt->execute([$work_details_id, $customer_name, $partner_id, $total_amount, $discount, $final_amount, $_SESSION['sub_postal_enabled'] ? 1 : 0, $_SESSION['sub_postal_price']]);
            $sub_order_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO Sub_Order_Items (sub_order_id, product_id, product_name, quantity, unit_price, extra_sale, total_price)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($_SESSION['sub_order_items'] as $item) {
                $stmt->execute([
                    $sub_order_id,
                    $item['product_id'],
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['extra_sale'],
                    $item['total_price']
                ]);
            }

            if (!empty($_SESSION['sub_invoice_prices'])) {
                $stmt = $pdo->prepare("
                    INSERT INTO Sub_Invoice_Prices (sub_order_id, item_index, invoice_price, is_postal, postal_price)
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($_SESSION['sub_invoice_prices'] as $index => $price) {
                    $is_postal = ($index === 'postal') ? 1 : 0;
                    $postal_price = $is_postal ? $price : 0;
                    $invoice_price = $is_postal ? 0 : $price;
                    $stmt->execute([$sub_order_id, $index, $invoice_price, $is_postal, $postal_price]);
                }
            }

            $pdo->commit();

            // Clear session data
            unset($_SESSION['sub_order_items']);
            unset($_SESSION['sub_discount']);
            unset($_SESSION['sub_invoice_prices']);
            unset($_SESSION['sub_postal_enabled']);
            unset($_SESSION['sub_postal_price']);
            unset($_SESSION['is_sub_order_in_progress']);

            sendResponse(true, 'پیش‌فاکتور با موفقیت ثبت شد.', ['redirect' => "orders.php?work_month_id=$work_month_id"]);
            break;

        case 'update_sub_order':
            $sub_order_id = filter_input(INPUT_POST, 'sub_order_id', FILTER_VALIDATE_INT);
            $customer_name = $_POST['customer_name'] ?? '';
            $work_details_id = filter_input(INPUT_POST, 'work_details_id', FILTER_VALIDATE_INT);
            $discount = (float)($_POST['discount'] ?? 0);
            $work_month_id = filter_input(INPUT_POST, 'work_month_id', FILTER_VALIDATE_INT);

            if (!$sub_order_id || !$customer_name || !$work_details_id || !$work_month_id) {
                error_log("Invalid input in update_sub_order: " . print_r($_POST, true));
                sendResponse(false, 'اطلاعات ناقص یا نامعتبر است.');
            }

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE Sub_Orders 
                SET customer_name = ?, work_details_id = ?, total_amount = ?, discount = ?, final_amount = ?, 
                    sub_postal_enabled = ?, sub_postal_price = ?
                WHERE sub_order_id = ?
            ");
            $stmt->execute([
                $customer_name, $work_details_id, $total_amount, $discount, $final_amount,
                $_SESSION['sub_postal_enabled'] ? 1 : 0, $_SESSION['sub_postal_price'], $sub_order_id
            ]);

            $stmt = $pdo->prepare("DELETE FROM Sub_Order_Items WHERE sub_order_id = ?");
            $stmt->execute([$sub_order_id]);

            $stmt = $pdo->prepare("
                INSERT INTO Sub_Order_Items (sub_order_id, product_id, product_name, quantity, unit_price, extra_sale, total_price)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($_SESSION['sub_order_items'] as $item) {
                $stmt->execute([
                    $sub_order_id, $item['product_id'], $item['product_name'], 
                    $item['quantity'], $item['unit_price'], $item['extra_sale'], $item['total_price']
                ]);
            }

            $stmt = $pdo->prepare("DELETE FROM Sub_Invoice_Prices WHERE sub_order_id = ?");
            $stmt->execute([$sub_order_id]);

            if (!empty($_SESSION['sub_invoice_prices'])) {
                $stmt = $pdo->prepare("
                    INSERT INTO Sub_Invoice_Prices (sub_order_id, item_index, invoice_price, is_postal, postal_price)
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($_SESSION['sub_invoice_prices'] as $index => $price) {
                    $is_postal = ($index === 'postal') ? 1 : 0;
                    $postal_price = $is_postal ? $price : 0;
                    $invoice_price = $is_postal ? 0 : $price;
                    $stmt->execute([$sub_order_id, $index, $invoice_price, $is_postal, $postal_price]);
                }
            }

            $pdo->commit();

            sendResponse(true, 'پیش‌فاکتور با موفقیت به‌روزرسانی شد.', ['redirect' => "orders.php?work_month_id=$work_month_id"]);
            break;

        case 'convert_to_main_order':
            $sub_order_id = filter_input(INPUT_POST, 'sub_order_id', FILTER_VALIDATE_INT);
            $customer_name = $_POST['customer_name'] ?? '';
            $main_work_details_id = filter_input(INPUT_POST, 'main_work_details_id', FILTER_VALIDATE_INT);
            $main_partner_id = filter_input(INPUT_POST, 'main_partner_id', FILTER_VALIDATE_INT);
            $discount = (float)($_POST['discount'] ?? 0);
            $work_month_id = filter_input(INPUT_POST, 'work_month_id', FILTER_VALIDATE_INT);

            if (!$sub_order_id || !$customer_name || !$main_work_details_id || !$main_partner_id || !$work_month_id) {
                error_log("Invalid input in convert_to_main_order: " . print_r($_POST, true));
                sendResponse(false, 'اطلاعات ناقص یا نامعتبر است.');
            }

            $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO Orders (work_details_id, customer_name, partner_id, total_amount, discount, final_amount, created_at, is_main_order)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), 1)
            ");
            $stmt->execute([$main_work_details_id, $customer_name, $main_partner_id, $total_amount, $discount, $final_amount]);
            $order_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO Order_Items (order_id, product_id, product_name, quantity, unit_price, extra_sale, total_price)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($_SESSION['sub_order_items'] as $item) {
                $stmt->execute([
                    $order_id, $item['product_id'], $item['product_name'], 
                    $item['quantity'], $item['unit_price'], $item['extra_sale'], $item['total_price']
                ]);
            }

            if (!empty($_SESSION['sub_invoice_prices'])) {
                $stmt = $pdo->prepare("
                    INSERT INTO Order_Invoice_Prices (order_id, item_index, invoice_price, is_postal, postal_price)
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($_SESSION['sub_invoice_prices'] as $index => $price) {
                    $is_postal = ($index === 'postal') ? 1 : 0;
                    $postal_price = $is_postal ? $price : 0;
                    $invoice_price = $is_postal ? 0 : $price;
                    $stmt->execute([$order_id, $index, $invoice_price, $is_postal, $postal_price]);
                }
            }

            $stmt = $pdo->prepare("DELETE FROM Sub_Orders WHERE sub_order_id = ?");
            $stmt->execute([$sub_order_id]);
            $stmt = $pdo->prepare("DELETE FROM Sub_Order_Items WHERE sub_order_id = ?");
            $stmt->execute([$sub_order_id]);
            $stmt = $pdo->prepare("DELETE FROM Sub_Invoice_Prices WHERE sub_order_id = ?");
            $stmt->execute([$sub_order_id]);

            $pdo->commit();

            // Clear session data
            unset($_SESSION['sub_order_items']);
            unset($_SESSION['sub_discount']);
            unset($_SESSION['sub_invoice_prices']);
            unset($_SESSION['sub_postal_enabled']);
            unset($_SESSION['sub_postal_price']);
            unset($_SESSION['is_sub_order_in_progress']);

            sendResponse(true, 'فاکتور اصلی با موفقیت ثبت شد.', ['redirect' => "orders.php?work_month_id=$work_month_id"]);
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

        default:
            error_log("Invalid action in sub_order_handler: action=$action");
            sendResponse(false, 'عملیات نامعتبر.');
    }
} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    error_log("Error in sub_order_handler.php: " . $e->getMessage() . " | Action: $action | POST: " . print_r($_POST, true));
    sendResponse(false, 'خطای سرور: ' . $e->getMessage());
}