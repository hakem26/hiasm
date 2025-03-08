<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

// بررسی نقش کاربر
$is_admin = ($_SESSION['role'] === 'admin');
if ($is_admin) {
    header("Location: orders.php");
    exit;
}
$current_user_id = $_SESSION['user_id'];

// دریافت اطلاعات روز کاری از work_details_id (از GET)
$work_details_id = $_GET['work_details_id'] ?? '';
$work_info = [];
if ($work_details_id) {
    $stmt_work = $pdo->prepare("SELECT wd.work_date, wd.partner_id, wd.agency_owner_id FROM Work_Details wd WHERE wd.id = ?");
    $stmt_work->execute([$work_details_id]);
    $work_info = $stmt_work->fetch(PDO::FETCH_ASSOC);

    if ($work_info) {
        $stmt_partner = $pdo->prepare("
            SELECT p.partner_id
            FROM Partners p
            WHERE (p.user_id1 = ? OR p.user_id2 = ?) 
            AND p.partner_id = (SELECT partner_id FROM Work_Details WHERE id = ?)
        ");
        $stmt_partner->execute([$current_user_id, $current_user_id, $work_details_id]);
        $partner_access = $stmt_partner->fetch(PDO::FETCH_ASSOC);

        if (!$partner_access) {
            if ($work_info['agency_owner_id'] != $current_user_id && $work_info['partner_id'] != $current_user_id) {
                $work_info = [];
            }
        }
    }
}

if (empty($work_info)) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>لطفاً ابتدا یک روز کاری انتخاب کنید یا روز کاری معتبر نیست.</div></div>";
    require_once 'footer.php';
    exit;
}

// دریافت نام‌های همکار و آژانس (بدون نمایش نام خود کاربر)
$work_info['partner_name'] = $work_info['partner_id'] == $current_user_id ? 
    $pdo->query("SELECT full_name FROM Users WHERE user_id = " . $work_info['agency_owner_id'])->fetchColumn() : 
    $pdo->query("SELECT full_name FROM Users WHERE user_id = " . $work_info['partner_id'])->fetchColumn();

$work_info['agency_owner_name'] = $work_info['agency_owner_id'] == $current_user_id ? 
    $pdo->query("SELECT full_name FROM Users WHERE user_id = " . $work_info['partner_id'])->fetchColumn() : 
    $pdo->query("SELECT full_name FROM Users WHERE user_id = " . $work_info['agency_owner_id'])->fetchColumn();

// پردازش اولیه سشن و مقادیر
$items = isset($_SESSION['order_items']) ? $_SESSION['order_items'] : [];
$customer_name = isset($_POST['customer_name']) ? $_POST['customer_name'] : '';
$total_amount = 0;
$discount = isset($_POST['discount']) ? (float)$_POST['discount'] : 0;

// پردازش افزودن محصول با AJAX (بدون رفرش)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item') {
    $customer_name = $_POST['customer_name'];
    $product_id = $_POST['product_id'];
    $quantity = (int)$_POST['quantity'];
    $unit_price = (float)$_POST['unit_price'];
    $total_price = $quantity * $unit_price;

    $stmt_product = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
    $stmt_product->execute([$product_id]);
    $product = $stmt_product->fetch(PDO::FETCH_ASSOC);

    $items[] = [
        'product_id' => $product_id,
        'product_name' => $product['product_name'],
        'quantity' => $quantity,
        'unit_price' => $unit_price,
        'total_price' => $total_price
    ];
    $_SESSION['order_items'] = $items;

    // محاسبه کل
    $total_amount = array_sum(array_column($items, 'total_price'));
    $final_amount = $total_amount - $discount;

    // پاسخ JSON برای آپدیت جدول
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'items' => $items,
        'total_amount' => $total_amount,
        'final_amount' => $final_amount
    ]);
    exit;
}

// پردازش تغییر تخفیف با AJAX (بدون رفرش)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_discount') {
    $discount = (float)$_POST['discount'];
    $total_amount = array_sum(array_column($items, 'total_price'));
    $final_amount = $total_amount - $discount;

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'total_amount' => $total_amount,
        'final_amount' => $final_amount
    ]);
    exit;
}

// پردازش بستن فاکتور
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'finalize_order') {
    $customer_name = $_POST['customer_name'];
    $discount = (float)$_POST['discount'];
    $total_amount = array_sum(array_column($items, 'total_price'));
    $final_amount = $total_amount - $discount;

    if (empty($items)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'لطفاً حداقل یک محصول به فاکتور اضافه کنید.']);
        exit;
    }

    $stmt_order = $pdo->prepare("INSERT INTO Orders (work_details_id, customer_name, total_amount, discount, final_amount, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt_order->execute([$work_details_id, $customer_name, $total_amount, $discount, $final_amount]);

    $order_id = $pdo->lastInsertId();

    foreach ($items as $item) {
        $stmt_item = $pdo->prepare("INSERT INTO Order_Items (order_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
        $stmt_item->execute([$order_id, $item['product_name'], $item['quantity'], $item['unit_price'], $item['total_price']]);
    }

    unset($_SESSION['order_items']);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'فاکتور با موفقیت ثبت گردید', 'redirect' => 'orders.php']);
    exit;
}

// محاسبه اولیه
$total_amount = array_sum(array_column($items, 'total_price'));
$final_amount = $total_amount - $discount;
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ثبت فاکتور</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        table {
            min-width: 400px;
            table-layout: auto;
        }
        th, td {
            white-space: nowrap;
            padding: 8px 6px;
        }
        .product-input {
            width: 250px;
        }
        @media (max-width: 768px) {
            table {
                min-width: 300px;
            }
            th, td {
                padding: 4px;
                font-size: 14px;
            }
            .table-wrapper {
                overflow-x: scroll;
            }
            .total-row td {
                border-top: 2px solid #dee2e6;
                font-weight: bold;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-5">
        <h5 class="card-title mb-4">ثبت فاکتور</h5>

        <!-- اطلاعات روز کاری (فقط نمایش) -->
        <div class="card mb-4">
            <div class="card-body">
                <p><strong>تاریخ:</strong> <?= gregorian_to_jalali_format($work_info['work_date']) ?></p>
                <p><strong>همکار:</strong> <?= htmlspecialchars($work_info['partner_name']) ?> - <?= htmlspecialchars($work_info['agency_owner_name']) ?></p>
            </div>
        </div>

        <!-- فرم ثبت فاکتور -->
        <form id="order-form">
            <div class="mb-3">
                <label for="customer_name" class="form-label">نام مشتری</label>
                <input type="text" class="form-control" id="customer_name" name="customer_name" value="<?= htmlspecialchars($customer_name) ?>" required autocomplete="off">
            </div>

            <!-- انتخاب محصول -->
            <div class="mb-3">
                <label for="product_name" class="form-label">نام محصول</label>
                <input type="text" class="form-control product-input" id="product_name" name="product_name" placeholder="3 حرف تایپ کنید..." required autocomplete="off">
                <div id="product_suggestions" class="list-group position-absolute" style="display: none; z-index: 1000; width: 250px;"></div>
                <input type="hidden" id="product_id" name="product_id">
                <input type="hidden" id="unit_price" name="unit_price">
            </div>
            <div class="mb-3">
                <label for="quantity" class="form-label">تعداد</label>
                <input type="number" class="form-control" id="quantity" name="quantity" value="1" min="1" required autocomplete="off">
            </div>
            <div class="mb-3">
                <label for="total_price" class="form-label">قیمت کل</label>
                <input type="text" class="form-control" id="total_price" name="total_price" readonly>
            </div>
            <button type="button" id="add_item_btn" class="btn btn-primary mb-3">افزودن محصول</button>

            <!-- جدول فاکتور -->
            <div class="table-wrapper" id="items_table">
                <?php if (!empty($items)): ?>
                    <table class="table table-light">
                        <thead>
                            <tr>
                                <th>نام محصول</th>
                                <th>تعداد</th>
                                <th>قیمت واحد</th>
                                <th>قیمت کل</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $index => $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                                    <td><?= $item['quantity'] ?></td>
                                    <td><?= number_format($item['unit_price'], 0) ?> تومان</td>
                                    <td><?= number_format($item['total_price'], 0) ?> تومان</td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td colspan="3"><strong>جمع کل</strong></td>
                                <td><strong id="total_amount"><?= number_format($total_amount, 0) ?> تومان</strong></td>
                            </tr>
                            <tr class="total-row">
                                <td colspan="2"><label for="discount" class="form-label">تخفیف</label></td>
                                <td><input type="number" class="form-control" id="discount" name="discount" value="<?= $discount ?>" min="0"></td>
                                <td><strong id="final_amount"><?= number_format($final_amount, 0) ?> تومان</strong></td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <button type="button" id="finalize_order_btn" class="btn btn-success mt-3">بستن فاکتور</button>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // ساجستشن محصولات
            $('#product_name').on('input', function() {
                let query = $(this).val();
                if (query.length >= 3) {
                    $.ajax({
                        url: 'get_products.php',
                        type: 'POST',
                        data: { query: query },
                        success: function(response) {
                            $('#product_suggestions').html(response).show();
                        }
                    });
                } else {
                    $('#product_suggestions').hide();
                }
            });

            $(document).on('click', '.product-suggestion', function() {
                let product = $(this).data('product');
                $('#product_name').val(product.product_name);
                $('#product_id').val(product.product_id);
                $('#unit_price').val(product.unit_price);
                $('#total_price').val((1 * product.unit_price).toLocaleString('fa') + ' تومان');
                $('#product_suggestions').hide();
                $('#quantity').focus();
            });

            $('#quantity').on('input', function() {
                let quantity = $(this).val();
                let unit_price = $('#unit_price').val();
                let total = quantity * unit_price;
                $('#total_price').val(total.toLocaleString('fa') + ' تومان');
            });

            // افزودن محصول با AJAX
            $('#add_item_btn').on('click', function() {
                let customer_name = $('#customer_name').val();
                let product_id = $('#product_id').val();
                let quantity = $('#quantity').val();
                let unit_price = $('#unit_price').val();

                if (!customer_name || !product_id || !quantity || !unit_price) {
                    alert('لطفاً همه فیلدها را پر کنید.');
                    return;
                }

                $.ajax({
                    url: 'add_order.php',
                    type: 'POST',
                    data: {
                        action: 'add_item',
                        customer_name: customer_name,
                        product_id: product_id,
                        quantity: quantity,
                        unit_price: unit_price
                    },
                    success: function(response) {
                        let data = JSON.parse(response);
                        if (data.success) {
                            $('#items_table').html(`
                                <table class="table table-light">
                                    <thead><tr><th>نام محصول</th><th>تعداد</th><th>قیمت واحد</th><th>قیمت کل</th></tr></thead>
                                    <tbody>
                                        ${data.items.map(item => `
                                            <tr>
                                                <td>${item.product_name}</td>
                                                <td>${item.quantity}</td>
                                                <td>${Number(item.unit_price).toLocaleString('fa')} تومان</td>
                                                <td>${Number(item.total_price).toLocaleString('fa')} تومان</td>
                                            </tr>
                                        `).join('')}
                                        <tr class="total-row">
                                            <td colspan="3"><strong>جمع کل</strong></td>
                                            <td><strong>${Number(data.total_amount).toLocaleString('fa')} تومان</strong></td>
                                        </tr>
                                        <tr class="total-row">
                                            <td colspan="2"><label for="discount" class="form-label">تخفیف</label></td>
                                            <td><input type="number" class="form-control" id="discount" name="discount" value="${$('#discount').val() || 0}" min="0"></td>
                                            <td><strong>${Number(data.final_amount).toLocaleString('fa')} تومان</strong></td>
                                        </tr>
                                    </tbody>
                                </table>
                            `);
                            $('#product_name').val('');
                            $('#quantity').val('1');
                            $('#total_price').val('');
                            $('#product_id').val('');
                            $('#unit_price').val('');
                        }
                    }
                });
            });

            // به‌روزرسانی تخفیف با AJAX
            $('#discount').on('input', function() {
                let discount = $(this).val();
                $.ajax({
                    url: 'add_order.php',
                    type: 'POST',
                    data: {
                        action: 'update_discount',
                        discount: discount
                    },
                    success: function(response) {
                        let data = JSON.parse(response);
                        if (data.success) {
                            $('#final_amount').text(Number(data.final_amount).toLocaleString('fa') + ' تومان');
                        }
                    }
                });
            });

            // بستن فاکتور با AJAX
            $('#finalize_order_btn').on('click', function() {
                let customer_name = $('#customer_name').val();
                let discount = $('#discount').val() || 0;

                if (!customer_name) {
                    alert('لطفاً نام مشتری را وارد کنید.');
                    return;
                }

                $.ajax({
                    url: 'add_order.php',
                    type: 'POST',
                    data: {
                        action: 'finalize_order',
                        customer_name: customer_name,
                        discount: discount
                    },
                    success: function(response) {
                        let data = JSON.parse(response);
                        if (data.success) {
                            alert(data.message);
                            window.location.href = data.redirect;
                        } else {
                            alert(data.message);
                        }
                    }
                });
            });
        });
    </script>

<?php require_once 'footer.php'; ?>