<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'header.php';
require_once 'db.php';

$is_admin = ($_SESSION['role'] === 'admin');
if ($is_admin) {
    header("Location: orders.php");
    exit;
}
$current_user_id = $_SESSION['user_id'];

$work_month_id = $_GET['work_month_id'] ?? '';
if (!$work_month_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>ماه کاری مشخص نشده است.</div></div>";
    require_once 'footer.php';
    exit;
}

// بررسی اینکه کاربر همکار1 است
$stmt = $pdo->prepare("
    SELECT p.user_id1
    FROM Work_Details wd
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE wd.work_month_id = ? AND p.user_id1 = ?
    LIMIT 1
");
$stmt->execute([$work_month_id, $current_user_id]);
$partner = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$partner) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>شما به‌عنوان همکار 1 دسترسی ندارید.</div></div>";
    require_once 'footer.php';
    exit;
}

$partner1_id = $partner['user_id1'];

unset($_SESSION['temp_order_items']);
unset($_SESSION['discount']);
unset($_SESSION['invoice_prices']);
$_SESSION['temp_order_items'] = [];
$_SESSION['discount'] = 0;
$_SESSION['invoice_prices'] = ['postal' => 50000];
$_SESSION['is_temp_order_in_progress'] = true;
$_SESSION['postal_enabled'] = false;
$_SESSION['postal_price'] = 50000;
?>

<div class="container-fluid mt-5">
    <h5 class="card-title mb-4">ثبت سفارش بدون تاریخ</h5>

    <form id="add-temp-order-form">
        <div class="mb-3">
            <label for="customer_name" class="form-label">نام مشتری</label>
            <input type="text" class="form-control" id="customer_name" name="customer_name" required autocomplete="off">
        </div>

        <div class="mb-3">
            <label for="product_name" class="form-label">نام محصول</label>
            <input type="text" class="form-control" id="product_name" name="product_name" placeholder="جستجو یا وارد کنید...">
            <div id="product_suggestions" class="list-group position-absolute" style="width: 100%; z-index: 1000; display: none;"></div>
            <input type="hidden" id="product_id" name="product_id">
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <label for="quantity" class="form-label">تعداد</label>
                <input type="number" class="form-control" id="quantity" name="quantity" value="1" min="1">
            </div>
            <div class="col-6 col-md-3">
                <label for="unit_price" class="form-label">قیمت واحد (تومان)</label>
                <input type="number" class="form-control" id="unit_price" name="unit_price" readonly>
            </div>
            <div class="col-6 col-md-3">
                <label for="extra_sale" class="form-label">اضافه فروش (تومان)</label>
                <input type="number" class="form-control" id="extra_sale" name="extra_sale" value="0" min="0">
            </div>
            <div class="col-6 col-md-3">
                <label for="total_price" class="form-label">قیمت کل</label>
                <input type="text" class="form-control" id="total_price" name="total_price" readonly>
            </div>
        </div>

        <div class="mb-3">
            <button type="button" id="add_item_btn" class="btn btn-primary">افزودن محصول</button>
        </div>

        <div id="items_table">
            <!-- جدول آیتم‌ها با جاوااسکریپت پر می‌شود -->
        </div>

        <div class="mb-3">
            <p><strong>جمع کل:</strong> <span id="total_amount_display">0 تومان</span></p>
            <p><strong>تخفیف:</strong> <input type="number" id="discount" name="discount" value="0" min="0"></p>
            <p><strong>مبلغ نهایی:</strong> <span id="final_amount_display">0 تومان</span></p>
        </div>

        <button type="button" id="finalize_temp_order_btn" class="btn btn-success mt-3">ثبت سفارش موقت</button>
        <a href="orders.php?work_month_id=<?= htmlspecialchars($work_month_id) ?>" class="btn btn-secondary mt-3">بازگشت</a>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function () {
    $('#product_name').on('input', function () {
        let query = $(this).val();
        if (query.length >= 3) {
            $.ajax({
                url: 'get_products.php',
                type: 'POST',
                data: { query: query, is_temp_order: true },
                success: function (response) {
                    if (response.trim() === '') {
                        $('#product_suggestions').hide();
                    } else {
                        $('#product_suggestions').html(response).show();
                    }
                }
            });
        } else {
            $('#product_suggestions').hide();
        }
    });

    $(document).on('click', '.product-suggestion', function (e) {
        e.preventDefault();
        let product = $(this).data('product');
        $('#product_name').val(product.product_name);
        $('#product_id').val(product.product_id);
        $('#unit_price').val(product.unit_price);
        $('#extra_sale').val(0);
        $('#total_price').val((1 * product.unit_price).toLocaleString('fa') + ' تومان');
        $('#product_suggestions').hide();
        $('#quantity').focus();
    });

    $('#quantity, #extra_sale').on('input', function () {
        let quantity = Number($('#quantity').val()) || 0;
        let unit_price = Number($('#unit_price').val()) || 0;
        let extra_sale = Number($('#extra_sale').val()) || 0;
        let total = quantity * (unit_price + extra_sale);
        $('#total_price').val(total.toLocaleString('fa') + ' تومان');
    });

    $('#add_item_btn').on('click', function () {
        let data = {
            action: 'add_temp_item',
            customer_name: $('#customer_name').val(),
            product_id: $('#product_id').val(),
            quantity: $('#quantity').val(),
            unit_price: $('#unit_price').val(),
            extra_sale: $('#extra_sale').val(),
            discount: $('#discount').val(),
            partner1_id: '<?= $partner1_id ?>'
        };

        $.post('ajax_handler.php', data, function (response) {
            if (response.success) {
                renderItemsTable(response.data);
                resetForm();
            } else {
                alert(response.message);
            }
        }, 'json');
    });

    $('#items_table').on('click', '.delete-item', function () {
        let index = $(this).data('index');
        if (confirm('آیا از حذف این محصول مطمئن هستید؟')) {
            $.post('ajax_handler.php', {
                action: 'delete_temp_item',
                index: index,
                partner1_id: '<?= $partner1_id ?>'
            }, function (response) {
                if (response.success) {
                    renderItemsTable(response.data);
                } else {
                    alert(response.message);
                }
            }, 'json');
        }
    });

    $('#discount').on('input', function () {
        $.post('ajax_handler.php', {
            action: 'update_discount',
            discount: $(this).val()
        }, function (response) {
            if (response.success) {
                renderItemsTable(response.data);
            } else {
                alert(response.message);
            }
        }, 'json');
    });

    $('#finalize_temp_order_btn').on('click', function () {
        let data = {
            action: 'finalize_temp_order',
            customer_name: $('#customer_name').val(),
            discount: $('#discount').val(),
            partner1_id: '<?= $partner1_id ?>',
            work_month_id: '<?= $work_month_id ?>'
        };

        $.post('ajax_handler.php', data, function (response) {
            if (response.success) {
                alert(response.message);
                window.location.href = response.data.redirect;
            } else {
                alert(response.message);
            }
        }, 'json');
    });

    function renderItemsTable(data) {
        let html = '<table class="table table-light"><thead><tr><th>نام محصول</th><th>تعداد</th><th>قیمت واحد</th><th>اضافه فروش</th><th>قیمت کل</th><th>عملیات</th></tr></thead><tbody>';
        if (data.items && data.items.length > 0) {
            data.items.forEach((item, index) => {
                html += `<tr>
                    <td>${item.product_name}</td>
                    <td>${item.quantity}</td>
                    <td>${Number(item.unit_price).toLocaleString('fa')} تومان</td>
                    <td>${Number(item.extra_sale).toLocaleString('fa')} تومان</td>
                    <td>${Number(item.total_price).toLocaleString('fa')} تومان</td>
                    <td><button class="btn btn-danger btn-sm delete-item" data-index="${index}"><i class="fas fa-trash"></i></button></td>
                </tr>`;
            });
        }
        html += `<tr><td colspan="4">جمع کل</td><td>${Number(data.total_amount).toLocaleString('fa')} تومان</td><td></td></tr>`;
        html += '</tbody></table>';
        $('#items_table').html(html);
        $('#total_amount_display').text(Number(data.total_amount).toLocaleString('fa') + ' تومان');
        $('#final_amount_display').text(Number(data.final_amount).toLocaleString('fa') + ' تومان');
    }

    function resetForm() {
        $('#product_name').val('');
        $('#product_id').val('');
        $('#quantity').val('1');
        $('#unit_price').val('');
        $('#extra_sale').val('0');
        $('#total_price').val('');
    }
});
</script>

<?php require_once 'footer.php'; ?>