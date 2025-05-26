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
    case 'add_sub_item':
        $customer_name = $_POST['customer_name'] ?? '';
        $product_id = $_POST['product_id'] ?? '';
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $unit_price = (float) ($_POST['unit_price'] ?? 0);
        $extra_sale = (float) ($_POST['extra_sale'] ?? 0);
        $discount = (float) ($_POST['discount'] ?? 0);
        $work_details_id = $_POST['work_details_id'] ?? '';
        $partner_id = $_POST['partner_id'] ?? '';

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
        if ($items && array_filter($items, fn($item) => $item['product_id'] === $product_id)) {
            respond(false, 'این محصول قبلاً در پیش‌فاکتور ثبت شده است.');
        }

        $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ?");
        $stmt_inventory->execute([$partner_id, $product_id]);
        $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);
        $current_quantity = $inventory ? (int) $inventory['quantity'] : 0;

        $adjusted_price = $unit_price + $extra_sale;
        $items[] = [
            'product_id' => $product_id,
            'product_name' => $product['product_name'],
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'extra_sale' => $extra_sale,
            'total_price' => $quantity * $adjusted_price
        ];

        $_SESSION['sub_order_items'] = $items;
        $_SESSION['sub_discount'] = $discount;
        $total_amount = array_sum(array_column($items, 'total_price'));
        $postal_price = $_SESSION['sub_postal_enabled'] ? ($_SESSION['sub_invoice_prices']['postal'] ?? 50000) : 0;
        $final_amount = $total_amount - $discount + $postal_price;

        respond(true, 'محصول با موفقیت به پیش‌فاکتور اضافه شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'sub_discount' => $discount,
            'final_amount' => $final_amount,
            'sub_postal_enabled' => $_SESSION['sub_postal_enabled'] ?? false,
            'sub_postal_price' => $postal_price
        ]);
        break;

    case 'delete_sub_item':
        $index = (int) ($_POST['index'] ?? -1);
        $partner_id = $_POST['partner_id'] ?? '';

        if ($index < 0 || !isset($_SESSION['sub_order_items'][$index]) || !$partner_id) {
            respond(false, 'آیتم یافت نشد.');
        }

        $items = $_SESSION['sub_order_items'];
        unset($items[$index]);
        $items = array_values($items);
        $_SESSION['sub_order_items'] = $items;

        if (isset($_SESSION['sub_invoice_prices'][$index])) {
            unset($_SESSION['sub_invoice_prices'][$index]);
            $_SESSION['sub_invoice_prices'] = array_values($_SESSION['sub_invoice_prices']);
        }

        $total_amount = array_sum(array_column($items, 'total_price'));
        $discount = $_SESSION['sub_discount'] ?? 0;
        $postal_price = $_SESSION['sub_postal_enabled'] ? ($_SESSION['sub_invoice_prices']['postal'] ?? 50000) : 0;
        $final_amount = $total_amount - $discount + $postal_price;

        respond(true, 'آیتم با موفقیت از پیش‌فاکتور حذف شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'sub_discount' => $discount,
            'final_amount' => $final_amount,
            'sub_postal_enabled' => $_SESSION['sub_postal_enabled'] ?? false,
            'sub_postal_price' => $postal_price
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

        respond(true, 'قیمت فاکتور پیش‌فاکتور با موفقیت تنظیم شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'sub_discount' => $discount,
            'final_amount' => $final_amount,
            'sub_postal_enabled' => $_SESSION['sub_postal_enabled'] ?? false,
            'sub_postal_price' => $postal_price
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

        respond(true, 'گزینه ارسال پستی پیش‌فاکتور با موفقیت به‌روزرسانی شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'sub_discount' => $discount,
            'final_amount' => $final_amount,
            'sub_postal_enabled' => $enable_postal,
            'sub_postal_price' => $enable_postal ? $postal_price : 0
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

        respond(true, 'تخفیف پیش‌فاکتور با موفقیت به‌روزرسانی شد.', [
            'items' => $items,
            'total_amount' => $total_amount,
            'sub_discount' => $discount,
            'final_amount' => $final_amount,
            'sub_postal_enabled' => $_SESSION['sub_postal_enabled'] ?? false,
            'sub_postal_price' => $postal_price
        ]);
        break;

    case 'finalize_sub_order':
        $work_details_id = $_POST['work_details_id'] ?? '';
        $customer_name = $_POST['customer_name'] ?? '';
        $discount = (float) ($_POST['discount'] ?? 0);
        $partner_id = $_POST['partner_id'] ?? '';
        $convert_to_main = filter_var($_POST['convert_to_main'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $sub_order_id = $_POST['sub_order_id'] ?? null;

        if (!$work_details_id || !$customer_name || !$partner_id) {
            respond(false, 'لطفاً تمام فیلدها را پر کنید.');
        }

        $items = $_SESSION['sub_order_items'] ?? [];
        if (empty($items)) {
            respond(false, 'هیچ محصولی برای ثبت پیش‌فاکتور وجود ندارد.');
        }

        $total_amount = array_sum(array_column($items, 'total_price'));
        $postal_price = $_SESSION['sub_postal_enabled'] ? ($_SESSION['sub_invoice_prices']['postal'] ?? 50000) : 0;
        $final_amount = $total_amount - $discount + $postal_price;

        $pdo->beginTransaction();
        try {
            if (!$convert_to_main) {
                // ذخیره پیش‌فاکتور در جدول Sub_Orders
                $stmt = $pdo->prepare("
                    INSERT INTO Sub_Orders (work_details_id, customer_name, partner_id, total_amount, discount, final_amount, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$work_details_id, $customer_name, $partner_id, $total_amount, $discount, $final_amount]);
                $sub_order_id = $pdo->lastInsertId();

                foreach ($items as $index => $item) {
                    $stmt = $pdo->prepare("
                        INSERT INTO Sub_Order_Items (sub_order_id, product_name, quantity, unit_price, extra_sale, total_price)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $sub_order_id,
                        $item['product_name'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['extra_sale'],
                        $item['total_price']
                    ]);
                }

                $invoice_prices = $_SESSION['sub_invoice_prices'] ?? [];
                if (!empty($invoice_prices)) {
                    $stmt_invoice = $pdo->prepare("
                        INSERT INTO Sub_Invoice_Prices (sub_order_id, item_index, invoice_price, is_postal, postal_price)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    foreach ($invoice_prices as $index => $price) {
                        if ($index === 'postal' && $_SESSION['sub_postal_enabled']) {
                            $stmt_invoice->execute([$sub_order_id, -1, 0, TRUE, $price]);
                        } elseif ($index !== 'postal') {
                            $stmt_invoice->execute([$sub_order_id, $index, $price, FALSE, 0]);
                        }
                    }
                }
            } else {
                // تبدیل به فاکتور اصلی
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

                $invoice_prices = $_SESSION['sub_invoice_prices'] ?? [];
                if (!empty($invoice_prices)) {
                    $stmt_invoice = $pdo->prepare("
                        INSERT INTO Invoice_Prices (order_id, item_index, invoice_price, is_postal, postal_price)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    foreach ($invoice_prices as $index => $price) {
                        if ($index === 'postal' && $_SESSION['sub_postal_enabled']) {
                            $stmt_invoice->execute([$order_id, -1, 0, TRUE, $price]);
                        } elseif ($index !== 'postal') {
                            $stmt_invoice->execute([$order_id, $index, $price, FALSE, 0]);
                        }
                    }
                }

                // حذف پیش‌فاکتور از جدول Sub_Orders اگر وجود داشته باشد
                if ($sub_order_id) {
                    $stmt = $pdo->prepare("DELETE FROM Sub_Orders WHERE sub_order_id = ?");
                    $stmt->execute([$sub_order_id]);
                    $stmt = $pdo->prepare("DELETE FROM Sub_Order_Items WHERE sub_order_id = ?");
                    $stmt->execute([$sub_order_id]);
                    $stmt = $pdo->prepare("DELETE FROM Sub_Invoice_Prices WHERE sub_order_id = ?");
                    $stmt->execute([$sub_order_id]);
                }
            }

            $pdo->commit();

            unset($_SESSION['sub_order_items']);
            unset($_SESSION['sub_discount']);
            unset($_SESSION['sub_invoice_prices']);
            unset($_SESSION['sub_postal_enabled']);
            unset($_SESSION['sub_postal_price']);
            $_SESSION['is_sub_order_in_progress'] = false;

            $redirect = $convert_to_main ? "print_invoice.php?order_id=$order_id" : 'orders.php';
            respond(true, $convert_to_main ? 'پیش‌فاکتور به فاکتور اصلی تبدیل شد.' : 'پیش‌فاکتور ثبت شد.', [
                'redirect' => $redirect
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(false, 'خطا در ثبت: ' . $e->getMessage());
        }
        break;

    case 'get_partners':
        $user_id = $_SESSION['user_id'] ?? 0; // فرض بر اینکه user_id توی سشن ذخیره شده
        if (!$user_id) {
            respond(false, 'کاربر شناسایی نشد.');
        }

        $stmt = $pdo->prepare("
            SELECT u.user_id, u.username
            FROM Users u
            JOIN Partners p ON u.user_id = p.partner_id
            WHERE p.user_id = ?
        ");
        $stmt->execute([$user_id]);
        $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

        respond(true, 'همکارها با موفقیت دریافت شدند.', ['partners' => $partners]);
        break;

    case 'get_work_days':
        $partner_id = $_POST['partner_id'] ?? '';
        $work_details_id = $_POST['work_details_id'] ?? '';

        if (!$partner_id || !$work_details_id) {
            respond(false, 'همکار یا ماه کاری مشخص نشده.');
        }

        $stmt = $pdo->prepare("
            SELECT work_date
            FROM Work_Days
            WHERE partner_id = ? AND work_details_id = ?
            ORDER BY work_date
        ");
        $stmt->execute([$partner_id, $work_details_id]);
        $work_days = $stmt->fetchAll(PDO::FETCH_COLUMN);

        respond(true, 'روزهای کاری دریافت شدند.', ['work_days' => $work_days]);
        break;

    default:
        respond(false, 'Action not recognized.');
}
?>