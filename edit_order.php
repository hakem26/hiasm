<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date)
{
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

// دریافت order_id از GET
$order_id = $_GET['order_id'] ?? '';
if (!$order_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>شناسه سفارش مشخص نشده است.</div></div>";
    require_once 'footer.php';
    exit;
}

// بررسی دسترسی کاربر به سفارش
$stmt = $pdo->prepare("
    SELECT o.order_id, o.work_details_id, o.customer_name, o.total_amount, o.discount, o.final_amount, wd.work_date, wd.partner_id
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE o.order_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
");
$stmt->execute([$order_id, $current_user_id, $current_user_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>سفارش یافت نشد یا شما دسترسی ویرایش آن را ندارید.</div></div>";
    require_once 'footer.php';
    exit;
}

// دریافت اقلام سفارش
$items_stmt = $pdo->prepare("SELECT * FROM Order_Items WHERE order_id = ?");
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// دریافت نام همکار
$partner_name = 'نامشخص';
if ($order['partner_id']) {
    $stmt_partner = $pdo->prepare("
        SELECT u1.user_id AS user1_id, u1.full_name AS user1_name, u2.user_id AS user2_id, u2.full_name AS user2_name
        FROM Partners p
        LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
        LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
        WHERE p.partner_id = ?
    ");
    $stmt_partner->execute([$order['partner_id']]);
    $partner_data = $stmt_partner->fetch(PDO::FETCH_ASSOC);

    if ($partner_data) {
        $user1_id = $partner_data['user1_id'];
        $user2_id = $partner_data['user2_id'];
        $user1_name = $partner_data['user1_name'] ?: 'نامشخص';
        $user2_name = $partner_data['user2_name'] ?: 'نامشخص';

        if ($user1_id == $current_user_id && $user2_name != 'نامشخص') {
            $partner_name = $user2_name;
        } elseif ($user2_id == $current_user_id && $user1_name != 'نامشخص') {
            $partner_name = $user1_name;
        }
    }
}

// مدیریت ارسال فرم (ویرایش سفارش)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_name = $_POST['customer_name'] ?? '';
    $discount = (int) ($_POST['discount'] ?? 0);
    $products = $_POST['products'] ?? [];

    if (empty($customer_name) || empty($products)) {
        echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>لطفاً تمام فیلدهای الزامی را پر کنید.</div></div>";
    } else {
        // محاسبه مجموع
        $total_amount = 0;
        foreach ($products as $product) {
            $total_amount += ($product['quantity'] * $product['unit_price']);
        }
        $final_amount = $total_amount - $discount;

        $pdo->beginTransaction();
        try {
            // به‌روزرسانی جدول Orders
            $stmt = $pdo->prepare("
                UPDATE Orders 
                SET customer_name = ?, total_amount = ?, discount = ?, final_amount = ?
                WHERE order_id = ?
            ");
            $stmt->execute([$customer_name, $total_amount, $discount, $final_amount, $order_id]);

            // حذف اقلام قبلی از Order_Items
            $stmt = $pdo->prepare("DELETE FROM Order_Items WHERE order_id = ?");
            $stmt->execute([$order_id]);

            // اضافه کردن اقلام جدید به Order_Items
            foreach ($products as $product) {
                $stmt = $pdo->prepare("
                    INSERT INTO Order_Items (order_id, product_name, quantity, unit_price, total_price)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $total_price = $product['quantity'] * $product['unit_price'];
                $stmt->execute([
                    $order_id,
                    $product['name'],
                    $product['quantity'],
                    $product['unit_price'],
                    $total_price
                ]);
            }

            $pdo->commit();
            echo "<div class='container-fluid mt-5'><div class='alert alert-success text-center'>سفارش با موفقیت ویرایش شد. <a href='orders.php'>بازگشت به لیست سفارشات</a></div></div>";
            require_once 'footer.php';
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>خطا در ویرایش سفارش: " . $e->getMessage() . "</div></div>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ویرایش سفارش</title>
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

        th,
        td {
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

            th,
            td {
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
        <h5 class="card-title mb-4">ویرایش سفارش</h5>

        <!-- اطلاعات روز کاری (فقط نمایش) -->
        <div class="card mb-4">
            <div class="card-body">
                <p><strong>تاریخ:</strong> <?= gregorian_to_jalali_format($order['work_date']) ?></p>
                <p><strong>همکار:</strong> <?= htmlspecialchars($partner_name) ?></p>
            </div>
        </div>

        <!-- فرم ویرایش سفارش -->
        <form id="edit-order-form" method="POST">
            <div class="mb-3">
                <label for="customer_name" class="form-label">نام مشتری</label>
                <input type="text" class="form-control" id="customer_name" name="customer_name"
                    value="<?= htmlspecialchars($order['customer_name']) ?>" required autocomplete="off">
            </div>

            <!-- انتخاب محصول -->
            <div class="mb-3">
                <label for="product_name" class="form-label">نام محصول</label>
                <input type="text" class="form-control product-input" id="product_name" name="product_name"
                    placeholder="3 حرف تایپ کنید..." autocomplete="off">
                <div id="product_suggestions" class="list-group position-absolute"
                    style="display: none; z-index: 1000; width: 250px;"></div>
                <input type="hidden" id="product_id" name="product_id">
                <input type="hidden" id="unit_price" name="unit_price">
            </div>
            <div class="mb-3">
                <label for="quantity" class="form-label">تعداد</label>
                <input type="number" class="form-control" id="quantity" name="quantity" value="1" min="1"
                    autocomplete="off">
            </div>
            <div class="mb-3">
                <label for="total_price" class="form-label">قیمت کل</label>
                <input type="text" class="form-control" id="total_price" name="total_price" readonly>
            </div>
            <button type="button" id="add_item_btn" class="btn btn-primary mb-3">افزودن محصول</button>

            <!-- جدول اقلام -->
            <div class="table-wrapper" id="items_table">
                <table class="table table-light">
                    <thead>
                        <tr>
                            <th>نام محصول</th>
                            <th>تعداد</th>
                            <th>قیمت واحد</th>
                            <th>قیمت کل</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody id="productsTable">
                        <?php
                        $initial_total = 0;
                        foreach ($items as $index => $item):
                            $initial_total += $item['total_price'];
                            ?>
                            <tr id="productRow_<?= $index ?>">
                                <td><?= htmlspecialchars($item['product_name']) ?></td>
                                <td><?= $item['quantity'] ?></td>
                                <td><?= number_format($item['unit_price'], 0) ?> تومان</td>
                                <td><?= number_format($item['total_price'], 0) ?> تومان</td>
                                <td>
                                    <button type="button" class="btn btn-danger btn-sm delete-item"
                                        data-index="<?= $index ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                                <input type="hidden" name="products[<?= $index ?>][name]"
                                    value="<?= htmlspecialchars($item['product_name']) ?>">
                                <input type="hidden" name="products[<?= $index ?>][quantity]"
                                    value="<?= $item['quantity'] ?>">
                                <input type="hidden" name="products[<?= $index ?>][unit_price]"
                                    value="<?= $item['unit_price'] ?>">
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="3"><strong>جمع کل</strong></td>
                            <td colspan="2"><strong id="total_amount"><?= number_format($initial_total, 0) ?>
                                    تومان</strong></td>
                        </tr>
                        <tr class="total-row">
                            <td colspan="2"><label for="discount" class="form-label">تخفیف</label></td>
                            <td><input type="number" class="form-control" id="discount" name="discount"
                                    value="<?= $order['discount'] ?>" min="0"></td>
                            <td colspan="2"><strong
                                    id="final_amount"><?= number_format($initial_total - $order['discount'], 0) ?>
                                    تومان</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- نمایش پیش‌فرض برای جمع کل و تخفیف -->
            <div class="mb-3">
                <p><strong>جمع کل:</strong> <span id="total_amount_display"><?= number_format($initial_total, 0) ?>
                        تومان</span></p>
                <p><strong>مبلغ نهایی:</strong> <span
                        id="final_amount_display"><?= number_format($initial_total - $order['discount'], 0) ?>
                        تومان</span></p>
            </div>

            <button type="submit" class="btn btn-success mt-3">ذخیره تغییرات</button>
            <a href="orders.php" class="btn btn-secondary mt-3">بازگشت</a>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let productCount = <?= count($items) ?>;

        // ساجستشن محصولات
        $('#product_name').on('input', function () {
            let query = $(this).val();
            const work_details_id = '<?= htmlspecialchars($order['work_details_id'], ENT_QUOTES, 'UTF-8') ?>';
            console.log('Debug: Searching with work_details_id = ', work_details_id);
            if (query.length >= 3) {
                $.ajax({
                    url: 'get_products.php',
                    type: 'POST',
                    data: { query: query, work_details_id: work_details_id },
                    success: function (response) {
                        $('#product_suggestions').html(response).show();
                    },
                    error: function (xhr, status, error) {
                        console.error('AJAX Error: ', error);
                    }
                });
            } else {
                $('#product_suggestions').hide();
            }
        });
        $(document).on('click', '.product-suggestion', function () {
            let product = $(this).data('product');
            $('#product_name').val(product.product_name);
            $('#product_id').val(product.product_id);
            $('#unit_price').val(product.unit_price);
            $('#total_price').val((1 * product.unit_price).toLocaleString('fa') + ' تومان');
            $('#product_suggestions').hide();
            $('#quantity').focus();
        });

        $('#quantity').on('input', function () {
            let quantity = $(this).val();
            let unit_price = $('#unit_price').val();
            let total = quantity * unit_price;
            $('#total_price').val(total.toLocaleString('fa') + ' تومان');
        });

        // افزودن محصول جدید
        $('#add_item_btn').on('click', addProduct);

        function addProduct() {
            const productName = $('#product_name').val();
            const quantity = $('#quantity').val();
            const unitPrice = $('#unit_price').val();
            const work_details_id = '<?= $order['work_details_id'] ?>'; // اضافه كردن تاريخ كارى

            if (!productName || !quantity || !unitPrice) {
                alert('لطفاً تمام فیلدها را پر کنید.');
                return;
            }

            const total = quantity * unitPrice;
            const row = `
        <tr id="productRow_${productCount}">
            <td>${productName}</td>
            <td>${quantity}</td>
            <td>${parseInt(unitPrice).toLocaleString('fa')} تومان</td>
            <td>${parseInt(total).toLocaleString('fa')} تومان</td>
            <td>
                <button type="button" class="btn btn-danger btn-sm delete-item" data-index="${productCount}">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
            <input type="hidden" name="products[${productCount}][name]" value="${productName}">
            <input type="hidden" name="products[${productCount}][quantity]" value="${quantity}">
            <input type="hidden" name="products[${productCount}][unit_price]" value="${unitPrice}">
        </tr>
    `;

            $('#productsTable').append(row);
            updateTotals();
            productCount++;

            // پاک کردن فرم
            $('#product_name').val('');
            $('#quantity').val('1');
            $('#total_price').val('');
            $('#product_id').val('');
            $('#unit_price').val('');
        }

        // حذف محصول
        function deleteProduct(index) {
            if (confirm('آیا از حذف این محصول مطمئن هستید؟')) {
                $(`#productRow_${index}`).remove();
                updateTotals();
            }
        }

        // به‌روزرسانی جمع کل و تخفیف
        function updateTotals() {
            let totalAmount = 0;
            $('#productsTable tr').each(function () {
                if ($(this).attr('id') && $(this).attr('id').startsWith('productRow_')) {
                    const unitPrice = parseInt($(this).find('input[name^="products["][name$="[unit_price]"]').val());
                    const quantity = parseInt($(this).find('input[name^="products["][name$="[quantity]"]').val());
                    totalAmount += unitPrice * quantity;
                }
            });

            const discount = parseInt($('#discount').val()) || 0;
            const finalAmount = totalAmount - discount;

            $('#total_amount').text(totalAmount.toLocaleString('fa') + ' تومان');
            $('#final_amount').text(finalAmount.toLocaleString('fa') + ' تومان');
            $('#total_amount_display').text(totalAmount.toLocaleString('fa') + ' تومان');
            $('#final_amount_display').text(finalAmount.toLocaleString('fa') + ' تومان');
        }

        // رویدادها
        $('#add_item_btn').on('click', addProduct);

        $(document).on('click', '.delete-item', function () {
            const index = $(this).data('index');
            deleteProduct(index);
        });

        $('#discount').on('input', function () {
            updateTotals();
        });

        // بارگذاری اولیه
        $(document).ready(function () {
            updateTotals();
        });
    </script>

    <?php require_once 'footer.php'; ?>