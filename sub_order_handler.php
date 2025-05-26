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
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_product_name($pdo, $product_id)
{
    $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    return $product ? $product['product_name'] : 'نامشخص';
}

$action = $_POST['action'] ?? '';
if (!$action) {
    respond(false, 'اکشن مشخص نشده است.');
}

if (!isset($_SESSION['user_id'])) {
    respond(false, 'لطفاً ابتدا وارد شوید.');
}

$current_user_id = $_SESSION['user_id'];

switch ($action) {
    case 'get_items':
        $items = $_SESSION['sub_order_items'] ?? [];
        $total_amount = array_sum(array_column($items, 'total_price'));
        $discount = $_SESSION['sub_discount'] ?? 0;
        $postal_price = $_SESSION['sub_postal_enabled'] ? ($_SESSION['sub_invoice_prices']['postal'] ?? 50000) : 0;
        $final_amount = $total_amount - $discount + $postal_price;

        respond(true, 'آیتم‌ها دریافت شدند.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount,
            'sub_postal_enabled' => $_SESSION['sub_postal_enabled'] ?? false,
            'sub_postal_price' => $postal_price,
            'invoice_prices' => $_SESSION['sub_invoice_prices'] ?? []
        ]);
        break;

    case 'add_sub_item':
        $customer_name = trim($_POST['customer_name'] ?? '');
        $product_id = $_POST['product_id'] ?? '';
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $unit_price = (float) ($_POST['unit_price'] ?? 0);
        $extra_sale = (float) ($_POST['extra_sale'] ?? 0);
        $discount = (float) ($_POST['discount'] ?? 0);
        $work_details_id = $_POST['work_details_id'] ?? '';
        $partner_id = $_POST['partner_id'] ?? $current_user_id;

        if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0 || !$work_details_id || !$partner_id || $extra_sale < 0) {
            respond(false, 'لطفاً تمام فیلدها را پر کنید و اضافه فروش منفی نباشد.');
        }

        $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            respond(false, 'محصول یافت نشد.');
        }

        $items = $_SESSION['sub_order_items'] ?? [];
        if (array_filter($items, fn($item) => $item['product_id'] === $product_id)) {
            respond(false, 'این محصول قبلاً در پیش‌فاکتور ثبت شده است.');
        }

        $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ?");
        $stmt_inventory->execute([$partner_id, $product_id]);
        $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);
        $current_quantity = $inventory ? (int) $inventory['quantity'] : 0;

        $total_price = $quantity * ($unit_price + $extra_sale);
        $items[] = [
            'product_id' => $product_id,
            'product_name' => $product['product_name'],
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'extra_sale' => $extra_sale,
            'total_price' => $total_price
        ];

        $_SESSION['sub_order_items'] = $items;
        $_SESSION['sub_discount'] = $discount;

        $total_amount = array_sum(array_column($items, 'total_price'));
        $postal_price = $_SESSION['sub_postal_enabled'] ? ($_SESSION['sub_invoice_prices']['postal'] ?? 50000) : 0;
        $final_amount = $total_amount - $discount + $postal_price;

        respond(true, 'محصول با موفقیت به پیش‌فاکتور اضافه شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount,
            'sub_postal_enabled' => $_SESSION['sub_postal_enabled'] ?? false,
            'sub_postal_price' => $postal_price,
            'invoice_prices' => $_SESSION['sub_invoice_prices'] ?? []
        ]);
        break;

    case 'edit_sub_item':
        $order_id = $_POST['order_id'] ?? '';
        $index = $_POST['index'] ?? '';
        $customer_name = $_POST['customer_name'] ?? '';
        $product_id = $_POST['product_id'] ?? '';
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $unit_price = (float) ($_POST['unit_price'] ?? 0);
        $extra_sale = (float) ($_POST['extra_sale'] ?? 0);
        $discount = (float) ($_POST['discount'] ?? 0);
        $partner_id = $_POST['partner_id'] ?? $current_user_id;

        if (!$order_id || $index === '' || !$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0) {
            respond(false, 'اطلاعات ناقص است.');
        }

        $stmt = $pdo->prepare("SELECT 1 FROM Orders WHERE order_id = ? AND user_id = ? AND is_main = 0");
        $stmt->execute([$order_id, $current_user_id]);
        if (!$stmt->fetchColumn()) {
            respond(false, 'دسترسی غیرمجاز یا پیش‌فاکتور یافت نشد.');
        }

        if (isset($_SESSION['sub_order_items'][$index])) {
            $_SESSION['sub_order_items'][$index] = [
                'product_id' => $product_id,
                'product_name' => get_product_name($pdo, $product_id),
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'extra_sale' => $extra_sale,
                'total_price' => $quantity * ($unit_price + $extra_sale),
                'original_index' => $index
            ];
        }

        $_SESSION['sub_discount'] = $discount;

        $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
        $postal_price = $_SESSION['sub_postal_enabled'] ? ($_SESSION['sub_invoice_prices']['postal'] ?? 50000) : 0;
        $final_amount = $total_amount - $discount + $postal_price;

        respond(true, 'آیتم با موفقیت ویرایش شد.', [
            'items' => array_values($_SESSION['sub_order_items']),
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount,
            'sub_postal_enabled' => $_SESSION['sub_postal_enabled'] ?? false,
            'sub_postal_price' => $postal_price,
            'invoice_prices' => $_SESSION['sub_invoice_prices'] ?? []
        ]);
        break;

    case 'delete_sub_item':
        $index = (int) ($_POST['index'] ?? -1);
        $partner_id = $_POST['partner_id'] ?? $current_user_id;

        if ($index < 0 || !isset($_SESSION['sub_order_items'][$index]) || !$partner_id) {
            respond(false, 'آیتم یافت نشد.');
        }

        $items = $_SESSION['sub_order_items'];
        unset($items[$index]);
        $items = array_values($items);
        $_SESSION['sub_order_items'] = $items;

        if (isset($_SESSION['sub_invoice_prices'][$index])) {
            unset($_SESSION['sub_invoice_prices'][$index]);
        }

        $total_amount = array_sum(array_column($items, 'total_price'));
        $discount = $_SESSION['sub_discount'] ?? 0;
        $postal_price = $_SESSION['sub_postal_enabled'] ? ($_SESSION['sub_invoice_prices']['postal'] ?? 50000) : 0;
        $final_amount = $total_amount - $discount + $postal_price;

        respond(true, 'آیتم با موفقیت از پیش‌فاکتور حذف شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount,
            'sub_postal_enabled' => $_SESSION['sub_postal_enabled'] ?? false,
            'sub_postal_price' => $postal_price,
            'invoice_prices' => $_SESSION['sub_invoice_prices'] ?? []
        ]);
        break;

    case 'set_sub_invoice_price':
        $index = $_POST['index'] ?? '';
        $invoice_price = (float) ($_POST['invoice_price'] ?? 0);

        if ($index === '' || $invoice_price < 0) {
            respond(false, 'مقدار نامعتبر برای ایندکس یا قیمت فاکتور.');
        }

        $_SESSION['sub_invoice_prices'][$index] = $invoice_price;

        if ($index === 'postal') {
            $_SESSION['sub_postal_price'] = $invoice_price;
            $_SESSION['sub_postal_enabled'] = true;
        }

        $items = $_SESSION['sub_order_items'] ?? [];
        $total_amount = array_sum(array_column($items, 'total_price'));
        $discount = $_SESSION['sub_discount'] ?? 0;
        $postal_price = $_SESSION['sub_postal_enabled'] ? ($_SESSION['sub_invoice_prices']['postal'] ?? 50000) : 0;
        $final_amount = $total_amount - $discount + $postal_price;

        respond(true, 'قیمت فاکتور با موفقیت تنظیم شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount,
            'sub_postal_enabled' => $_SESSION['sub_postal_enabled'] ?? false,
            'sub_postal_price' => $postal_price,
            'invoice_prices' => $_SESSION['sub_invoice_prices'] ?? []
        ]);
        break;

    case 'set_sub_postal_option':
        $enable_postal = filter_var($_POST['enable_postal'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $postal_price = $_SESSION['sub_postal_price'] ?? 50000;

        $_SESSION['sub_postal_enabled'] = $enable_postal;
        if ($enable_postal) {
            $_SESSION['sub_postal_price'] = $postal_price;
            $_SESSION['sub_invoice_prices']['postal'] = $postal_price;
        } else {
            $_SESSION['sub_postal_price'] = 0;
            unset($_SESSION['sub_invoice_prices']['postal']);
        }

        $items = $_SESSION['sub_order_items'] ?? [];
        $total_amount = array_sum(array_column($items, 'total_price'));
        $discount = $_SESSION['sub_discount'] ?? 0;
        $final_amount = $total_amount - $discount + ($enable_postal ? $postal_price : 0);

        respond(true, 'گزینه ارسال پستی با موفقیت به‌روزرسانی شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount,
            'sub_postal_enabled' => $enable_postal,
            'sub_postal_price' => $enable_postal ? $postal_price : 0,
            'invoice_prices' => $_SESSION['sub_invoice_prices'] ?? []
        ]);
        break;

    case 'update_sub_discount':
        $discount = (float) ($_POST['discount'] ?? 0);

        if ($discount < 0) {
            respond(false, 'مقدار تخفیف نامعتبر است.');
        }

        $_SESSION['sub_discount'] = $discount;
        $items = $_SESSION['sub_order_items'] ?? [];
        $total_amount = array_sum(array_column($items, 'total_price'));
        $postal_price = $_SESSION['sub_postal_enabled'] ? ($_SESSION['sub_invoice_prices']['postal'] ?? 50000) : 0;
        $final_amount = $total_amount - $discount + $postal_price;

        respond(true, 'تخفیف با موفقیت به‌روزرسانی شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'discount' => $discount,
            'final_amount' => $final_amount,
            'sub_postal_enabled' => $_SESSION['sub_postal_enabled'] ?? false,
            'sub_postal_price' => $postal_price,
            'invoice_prices' => $_SESSION['sub_invoice_prices'] ?? []
        ]);
        break;

    case 'finalize_sub_order':
        $work_details_id = $_POST['work_details_id'] ?? '';
        $customer_name = trim($_POST['customer_name'] ?? '');
        $discount = (float) ($_POST['discount'] ?? 0);
        $partner_id = $_POST['partner_id'] ?? $current_user_id;
        $convert_to_main = filter_var($_POST['convert_to_main'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$work_details_id || !$customer_name || !$partner_id) {
            respond(false, 'لطفاً تمام فیلدها را پر کنید.');
        }

        $items = $_SESSION['sub_order_items'] ?? [];
        if (empty($items)) {
            respond(false, 'هیچ محصولی برای ثبت پیش‌فاکتور وجود ندارد.');
        }

        $pdo->beginTransaction();
        try {
            $total_amount = array_sum(array_column($items, 'total_price'));
            $postal_price = $_SESSION['sub_postal_enabled'] ? ($_SESSION['sub_invoice_prices']['postal'] ?? 50000) : 0;
            $final_amount = $total_amount - $discount + $postal_price;

            // اعتبارسنجی work_details_id
            if ($convert_to_main) {
                $stmt = $pdo->prepare("
                    SELECT wd.id
                    FROM Work_Details wd
                    JOIN Partners p ON wd.partner_id = p.partner_id
                    WHERE wd.work_date = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
                ");
                $stmt->execute([$work_details_id, $partner_id, $partner_id]);
                $work_details = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$work_details) {
                    $pdo->rollBack();
                    respond(false, 'تاریخ کاری یا همکار معتبر نیست.');
                }
                $work_details_id_final = $work_details['id'];
            } else {
                $stmt = $pdo->prepare("SELECT id FROM Work_Details WHERE work_month_id = ? LIMIT 1");
                $stmt->execute([$work_details_id]);
                $work_details = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($work_details) {
                    $work_details_id_final = $work_details['id'];
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO Work_Details (work_month_id, work_date, partner_id)
                        SELECT ?, ?, p.partner_id
                        FROM Partners p
                        WHERE p.user_id1 = ? OR p.user_id2 = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$work_details_id, date('Y-m-d'), $partner_id, $partner_id]);
                    $work_details_id_final = $pdo->lastInsertId();
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO Orders (work_details_id, customer_name, total_amount, discount, final_amount, is_main, user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $work_details_id_final,
                $customer_name,
                $total_amount,
                $discount,
                $final_amount,
                $convert_to_main ? 1 : 0,
                $partner_id
            ]);
            $order_id = $pdo->lastInsertId();

            foreach ($items as $index => $item) {
                $stmt = $pdo->prepare("
                    INSERT INTO Order_Items (order_id, product_id, product_name, quantity, unit_price, extra_sale, total_price)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $order_id,
                    $item['product_id'],
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['extra_sale'],
                    $item['total_price']
                ]);

                if ($convert_to_main) {
                    $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
                    $stmt_inventory->execute([$partner_id, $item['product_id']]);
                    $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);

                    $current_quantity = $inventory ? $inventory['quantity'] : 0;
                    $new_quantity = $current_quantity - $item['quantity'];

                    $stmt_update = $pdo->prepare("
                        INSERT INTO Inventory (user_id, product_id, quantity)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE quantity = ?
                    ");
                    $stmt_update->execute([$partner_id, $item['product_id'], $new_quantity, $new_quantity]);
                }
            }

            $invoice_prices = $_SESSION['sub_invoice_prices'] ?? [];
            if (!empty($invoice_prices)) {
                $stmt_invoice = $pdo->prepare("
                    INSERT INTO Invoice_Prices (order_id, item_index, invoice_price, is_postal, postal_price)
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($invoice_prices as $index => $price) {
                    if ($index === 'postal' && $_SESSION['sub_postal_enabled']) {
                        $stmt_invoice->execute([$order_id, -1, 0, true, $price]);
                    } elseif ($index !== 'postal') {
                        $stmt_invoice->execute([$order_id, $index, $price, false, 0]);
                    }
                }
            }

            $pdo->commit();

            unset($_SESSION['sub_order_items']);
            unset($_SESSION['sub_discount']);
            unset($_SESSION['sub_invoice_prices']);
            unset($_SESSION['sub_postal_enabled']);
            unset($_SESSION['sub_postal_price']);
            unset($_SESSION['is_sub_order_in_progress']);

            $redirect = $convert_to_main ? "print_invoice.php?order_id=$order_id" : 'orders.php';
            respond(true, $convert_to_main ? 'پیش‌فاکتور به فاکتور اصلی تبدیل شد.' : 'پیش‌فاکتور با موفقیت ثبت شد.', [
                'redirect' => $redirect
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(false, 'خطا در ثبت: ' . $e->getMessage());
        }
        break;

    case 'get_partners':
        $work_month_id = $_POST['work_month_id'] ?? '';
        if (!$work_month_id) {
            respond(false, 'ماه کاری مشخص نشده است.');
        }

        $stmt = $pdo->prepare("
            SELECT DISTINCT u.user_id, u.full_name
            FROM Work_Details wd
            JOIN Partners p ON wd.partner_id = p.partner_id
            JOIN Users u ON u.user_id IN (p.user_id1, p.user_id2)
            WHERE wd.work_month_id = ? AND u.role = 'seller' AND u.user_id != ?
            ORDER BY u.full_name
        ");
        $stmt->execute([$work_month_id, $current_user_id]);
        $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

        respond(true, 'همکارها با موفقیت دریافت شدند.', ['partners' => $partners]);
        break;

    case 'get_work_days':
        $partner_id = $_POST['partner_id'] ?? '';
        $work_month_id = $_POST['work_details_id'] ?? '';

        if (!$partner_id || !$work_month_id) {
            respond(false, 'همکار یا ماه کاری مشخص نشده.');
        }

        $stmt = $pdo->prepare("
            SELECT DISTINCT wd.work_date
            FROM Work_Details wd
            JOIN Partners p ON wd.partner_id = p.partner_id
            WHERE wd.work_month_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
            ORDER BY wd.work_date
        ");
        $stmt->execute([$work_month_id, $partner_id, $partner_id]);
        $work_days = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($work_days)) {
            respond(false, 'هیچ روز کاری برای این همکار در ماه انتخاب‌شده یافت نشد.');
        }

        respond(true, 'روزهای کاری دریافت شدند.', ['work_days' => $work_days]);
        break;

    default:
        respond(false, 'اکشن ناشناخته است.');
}
?>