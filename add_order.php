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

// ریست کردن سشن order_items در ابتدای صفحه برای جلوگیری از نمایش فاکتور قبلی
if (!isset($_SESSION['is_order_in_progress']) || !$_SESSION['is_order_in_progress']) {
    unset($_SESSION['order_items']);
}
$_SESSION['is_order_in_progress'] = true;

// دریافت اطلاعات روز کاری از work_details_id (از GET)
$work_details_id = $_GET['work_details_id'] ?? '';
$work_info = [];
if ($work_details_id) {
    $stmt_work = $pdo->prepare("SELECT wd.work_date, wd.partner_id FROM Work_Details wd WHERE wd.id = ?");
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
            if ($work_info['partner_id'] != $current_user_id) {
                $work_info = [];
            }
        }
    }
}

// تنظیم خودکار partner_id اگه هنوز ثبت نشده
if ($work_details_id && empty($work_info)) {
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM Work_Details WHERE id = ?");
    $stmt_check->execute([$work_details_id]);
    if ($stmt_check->fetchColumn() == 0) {
        // ثبت خودکار Work_Details با partner_id کاربر فعلی
        $stmt_insert = $pdo->prepare("
            INSERT INTO Work_Details (id, work_date, work_month_id, partner_id)
            VALUES (?, CURDATE(), (SELECT work_month_id FROM Work_Months WHERE CURDATE() BETWEEN start_date AND end_date LIMIT 1), ?)
        ");
        $stmt_insert->execute([$work_details_id, $current_user_id]);
        $work_info = ['work_date' => date('Y-m-d'), 'partner_id' => $current_user_id];
    }
}

if (empty($work_info)) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>لطفاً ابتدا یک روز کاری انتخاب کنید یا روز کاری معتبر نیست.</div></div>";
    require_once 'footer.php';
    exit;
}

// دریافت نام همکار
$partner_name = '';
if ($work_info['partner_id']) {
    $partner_name = $pdo->prepare("SELECT full_name FROM Users WHERE user_id = ?");
    $partner_name->execute([$work_info['partner_id']]);
    $partner_name = $partner_name->fetchColumn() ?: 'نامشخص';
}

// مقادیر اولیه
$items = isset($_SESSION['order_items']) ? $_SESSION['order_items'] : [];
$customer_name = '';
$total_amount = array_sum(array_column($items, 'total_price'));
$discount = 0;
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
                <p><strong>همکار:</strong> <?= htmlspecialchars($partner_name) ?></p>
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
        // تابع برای ارسال درخواست Fetch
        async function sendRequest(url, data) {
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams(data)
                });
                return await response.json();
            } catch (error) {
                console.error('Error:', error);
                return { success: false, message: 'خطایی در ارسال درخواست رخ داد.' };
            }
        }

        // رندر جدول آیتم‌ها
        function renderItemsTable(data) {
            if (!data.items || data.items.length === 0) {
                document.getElementById('items_table').innerHTML = '';
                return;
            }

            document.getElementById('items_table').innerHTML = `
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
                            <td><input type="number" class="form-control" id="discount" name="discount" value="${data.discount}" min="0"></td>
                            <td><strong>${Number(data.final_amount).toLocaleString('fa')} تومان</strong></td>
                        </tr>
                    </tbody>
                </table>
            `;
        }

        document.addEventListener('DOMContentLoaded', () => {
            // ساجستشن محصولات با jQuery
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

            // افزودن محصول
            document.getElementById('add_item_btn').addEventListener('click', async () => {
                const customer_name = document.getElementById('customer_name').value;
                const product_id = document.getElementById('product_id').value;
                const quantity = document.getElementById('quantity').value;
                const unit_price = document.getElementById('unit_price').value;
                const discount = document.getElementById('discount')?.value || 0;

                if (!customer_name || !product_id || !quantity || !unit_price) {
                    alert('لطفاً همه فیلدها را پر کنید.');
                    return;
                }

                const data = {
                    action: 'add_item',
                    customer_name,
                    product_id,
                    quantity,
                    unit_price,
                    discount
                };

                const response = await sendRequest('ajax_handler.php', data);
                if (response.success) {
                    renderItemsTable(response.data);
                    document.getElementById('product_name').value = '';
                    document.getElementById('quantity').value = '1';
                    document.getElementById('total_price').value = '';
                    document.getElementById('product_id').value = '';
                    document.getElementById('unit_price').value = '';
                } else {
                    alert(response.message);
                }
            });

            // به‌روزرسانی تخفیف
            document.getElementById('items_table').addEventListener('input', async (e) => {
                if (e.target.id === 'discount') {
                    const discount = e.target.value;
                    const data = {
                        action: 'update_discount',
                        discount
                    };

                    const response = await sendRequest('ajax_handler.php', data);
                    if (response.success) {
                        document.getElementById('discount').value = response.data.discount; // به‌روزرسانی مقدار تخفیف
                        document.getElementById('final_amount').innerText = Number(response.data.final_amount).toLocaleString('fa') + ' تومان';
                    } else {
                        alert(response.message);
                    }
                }
            });

            // بستن فاکتور
            document.getElementById('finalize_order_btn').addEventListener('click', async () => {
                const customer_name = document.getElementById('customer_name').value;
                const discount = document.getElementById('discount')?.value || 0;

                if (!customer_name) {
                    alert('لطفاً نام مشتری را وارد کنید.');
                    return;
                }

                const data = {
                    action: 'finalize_order',
                    work_details_id: '<?= $work_details_id ?>',
                    customer_name,
                    discount
                };

                const response = await sendRequest('ajax_handler.php', data);
                if (response.success) {
                    alert(response.message);
                    window.location.href = response.data.redirect;
                } else {
                    alert(response.message);
                }
            });
        });
    </script>

<?php require_once 'footer.php'; ?>