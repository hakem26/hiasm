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
    $stmt_work = $pdo->prepare("
        SELECT wd.work_date, wd.partner_id, wd.agency_owner_id,
               (SELECT username FROM Users WHERE id = wd.partner_id) AS partner_name,
               (SELECT username FROM Users WHERE id = wd.agency_owner_id) AS agency_owner_name
        FROM Work_Details wd
        WHERE wd.id = ? AND (wd.partner_id = ? OR wd.agency_owner_id = ?)
    ");
    $stmt_work->execute([$work_details_id, $current_user_id, $current_user_id]);
    $work_info = $stmt_work->fetch(PDO::FETCH_ASSOC);
}
if (empty($work_info)) {
    die("اطلاعات روز کاری یافت نشد یا دسترسی ندارید.");
}

// پردازش فرم افزودن محصول
$items = [];
$customer_name = '';
$total_amount = 0;
$discount = 0;
$final_amount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $customer_name = $_POST['customer_name'];
    $product_id = $_POST['product_id'];
    $quantity = (int)$_POST['quantity'];
    $unit_price = (float)$_POST['unit_price'];
    $total_price = $quantity * $unit_price;

    $items[] = [
        'product_id' => $product_id,
        'quantity' => $quantity,
        'unit_price' => $unit_price,
        'total_price' => $total_price
    ];
    $total_amount += $total_price;
    $final_amount = $total_amount - $discount;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize_order'])) {
    $customer_name = $_POST['customer_name'];
    $discount = (float)$_POST['discount'];
    $final_amount = $total_amount - $discount;

    // ثبت سفارش
    $stmt_order = $pdo->prepare("INSERT INTO Orders (work_details_id, customer_name, total_amount, discount, final_amount, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt_order->execute([$work_details_id, $customer_name, $total_amount, $discount, $final_amount]);

    $order_id = $pdo->lastInsertId();

    // ثبت آیتم‌ها
    foreach ($items as $item) {
        $stmt_item = $pdo->prepare("INSERT INTO Order_Items (order_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
        $stmt_item->execute([$order_id, $item['product_id'], $item['quantity'], $item['unit_price'], $item['total_price']]);
    }

    echo "<script>alert('فاکتور با موفقیت ثبت گردید'); window.location.href='orders.php';</script>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ثبت فاکتور</title>
    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="style.css">
    <style>
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        table {
            min-width: 600px;
            table-layout: auto;
        }
        th, td {
            white-space: nowrap;
            padding: 8px 12px;
        }
        .product-input {
            width: 300px;
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
                <p><strong>همکار:</strong> <?= $work_info['partner_name'] ?> - <?= $work_info['agency_owner_name'] ?></p>
            </div>
        </div>

        <!-- فرم ثبت فاکتور -->
        <form method="POST" id="order-form">
            <div class="mb-3">
                <label for="customer_name" class="form-label">نام مشتری</label>
                <input type="text" class="form-control" id="customer_name" name="customer_name" value="<?= htmlspecialchars($customer_name) ?>" required>
            </div>

            <!-- انتخاب محصول -->
            <div class="mb-3">
                <label for="product_name" class="form-label">نام محصول</label>
                <input type="text" class="form-control product-input" id="product_name" name="product_name" placeholder="3 حرف تایپ کنید..." required>
                <div id="product_suggestions" class="list-group position-absolute" style="display: none; z-index: 1000; width: 300px;"></div>
                <input type="hidden" id="product_id" name="product_id">
                <input type="hidden" id="unit_price" name="unit_price">
            </div>
            <div class="mb-3">
                <label for="quantity" class="form-label">تعداد</label>
                <input type="number" class="form-control" id="quantity" name="quantity" value="1" min="1" required>
            </div>
            <div class="mb-3">
                <label for="total_price" class="form-label">قیمت کل</label>
                <input type="text" class="form-control" id="total_price" name="total_price" readonly>
            </div>
            <button type="submit" name="add_item" class="btn btn-primary mb-3">افزودن محصول</button>

            <!-- جدول فاکتور -->
            <?php if (!empty($items)): ?>
                <div class="table-wrapper">
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
                                    <td><?= htmlspecialchars($item['product_id']) ?></td> <!-- بعداً اسم محصول رو می‌گیریم -->
                                    <td><?= $item['quantity'] ?></td>
                                    <td><?= number_format($item['unit_price'], 0) ?> تومان</td>
                                    <td><?= number_format($item['total_price'], 0) ?> تومان</td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td colspan="3"><strong>جمع کل</strong></td>
                                <td><strong><?= number_format($total_amount, 0) ?> تومان</strong></td>
                            </tr>
                            <tr>
                                <td colspan="2"><label for="discount" class="form-label">تخفیف</label></td>
                                <td><input type="number" class="form-control" id="discount" name="discount" value="<?= $discount ?>" min="0" onchange="this.form.submit()"></td>
                                <td><strong><?= number_format($final_amount, 0) ?> تومان</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <button type="submit" name="finalize_order" class="btn btn-success mt-3">بستن فاکتور</button>
        </form>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
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
                $('#product_name').val(product.name);
                $('#product_id').val(product.id);
                $('#unit_price').val(product.price);
                $('#total_price').val((1 * product.price).toLocaleString('fa') + ' تومان');
                $('#product_suggestions').hide();
                $('#quantity').focus();
            });

            $('#quantity').on('input', function() {
                let quantity = $(this).val();
                let unit_price = $('#unit_price').val();
                let total = quantity * unit_price;
                $('#total_price').val(total.toLocaleString('fa') + ' تومان');
            });
        });
    </script>

<?php require_once 'footer.php'; ?>