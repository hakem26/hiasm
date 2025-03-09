<?php
session_start();
require_once 'db.php';

// تابع برای محاسبه مقادیر
function calculate_totals($items, $discount) {
    $total_amount = array_sum(array_column($items, 'total_price'));
    $final_amount = $total_amount - $discount;
    return [
        'total_amount' => $total_amount,
        'final_amount' => $final_amount,
        'discount' => $discount,
        'items' => $items
    ];
}

$action = $_POST['action'] ?? '';

if ($action === 'add_item') {
    // منطق اضافه کردن محصول (که قبلاً داشتید)
    $customer_name = $_POST['customer_name'] ?? '';
    $product_id = $_POST['product_id'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 1);
    $unit_price = (int)($_POST['unit_price'] ?? 0);
    $discount = (int)($_POST['discount'] ?? 0);

    if (!$customer_name || !$product_id || $quantity < 1 || $unit_price < 0) {
        echo json_encode(['success' => false, 'message' => 'فیلدهای الزامی را پر کنید.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'محصول یافت نشد.']);
        exit;
    }

    $total_price = $quantity * $unit_price;
    $item = [
        'product_id' => $product_id,
        'product_name' => $product['product_name'],
        'quantity' => $quantity,
        'unit_price' => $unit_price,
        'total_price' => $total_price
    ];

    if (!isset($_SESSION['order_items'])) {
        $_SESSION['order_items'] = [];
    }
    $_SESSION['order_items'][] = $item;

    $totals = calculate_totals($_SESSION['order_items'], $discount);
    echo json_encode(['success' => true, 'data' => $totals]);
    exit;

} elseif ($action === 'delete_item') {
    // منطق حذف محصول
    $index = (int)($_POST['index'] ?? -1);
    $discount = (int)($_SESSION['discount'] ?? 0);

    if ($index < 0 || !isset($_SESSION['order_items'][$index])) {
        echo json_encode(['success' => false, 'message' => 'آیتم موردنظر یافت نشد.']);
        exit;
    }

    // حذف آیتم از سشن
    array_splice($_SESSION['order_items'], $index, 1);

    $totals = calculate_totals($_SESSION['order_items'], $discount);
    echo json_encode(['success' => true, 'data' => $totals]);
    exit;

} elseif ($action === 'update_discount') {
    // منطق به‌روزرسانی تخفیف (که قبلاً داشتید)
    $discount = (int)($_POST['discount'] ?? 0);
    $_SESSION['discount'] = $discount;

    $items = $_SESSION['order_items'] ?? [];
    $totals = calculate_totals($items, $discount);
    echo json_encode(['success' => true, 'data' => $totals]);
    exit;

} elseif ($action === 'finalize_order') {
    // منطق بستن فاکتور (که قبلاً داشتید)
    $work_details_id = $_POST['work_details_id'] ?? '';
    $customer_name = $_POST['customer_name'] ?? '';
    $discount = (int)($_POST['discount'] ?? 0);

    if (!$work_details_id || !$customer_name || !isset($_SESSION['order_items']) || empty($_SESSION['order_items'])) {
        echo json_encode(['success' => false, 'message' => 'فیلدهای الزامی را پر کنید یا محصولی اضافه کنید.']);
        exit;
    }

    $items = $_SESSION['order_items'];
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
                INSERT INTO Order_Items (order_id, product_id, product_name, quantity, unit_price, total_price)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $order_id,
                $item['product_id'],
                $item['product_name'],
                $item['quantity'],
                $item['unit_price'],
                $item['total_price']
            ]);
        }

        $pdo->commit();
        unset($_SESSION['order_items']);
        unset($_SESSION['discount']);
        $_SESSION['is_order_in_progress'] = false;

        echo json_encode([
            'success' => true,
            'message' => 'فاکتور با موفقیت ثبت شد.',
            'data' => ['redirect' => 'orders.php']
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'خطا در ثبت فاکتور: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'درخواست نامعتبر']);
exit;