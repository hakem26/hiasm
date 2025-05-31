<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php'; // برای تاریخ شمسی احتمالی

$is_admin = ($_SESSION['role'] === 'admin');
if ($is_admin) {
    header("Location: orders.php");
    exit;
}
$current_user_id = $_SESSION['user_id'];

$temp_order_id = $_GET['temp_order_id'] ?? '';
$work_month_id = $_GET['work_month_id'] ?? '';
if (!$temp_order_id || !is_numeric($temp_order_id) || !$work_month_id || !is_numeric($work_month_id)) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>شناسه سفارش یا ماه کاری نامعتبر است.</div></div>";
    require_once 'footer.php';
    exit;
}

// تابع تبدیل تاریخ (برای استفاده احتمالی)
function gregorian_to_jalali_format($gregorian_date) {
    if (empty($gregorian_date) || !strtotime($gregorian_date)) {
        return 'نامشخص';
    }
    $date_parts = explode(' ', $gregorian_date)[0]; // فقط تاریخ
    list($gy, $gm, $gd) = explode('-', $date_parts);
    $gy = (int)$gy;
    $gm = (int)$gm;
    $gd = (int)$gd;
    if ($gy < 1000 || $gm < 1 || $gm > 12 || $gd < 1 || $gd > 31) {
        return 'نامشخص';
    }
    $jalali = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jalali[0], $jalali[1], $jalali[2]);
}

// بررسی دسترسی همکار1
try {
    $stmt = $pdo->prepare("
        SELECT tmp.*, p.user_id1
        FROM `Temp_Orders` tmp
        JOIN `Work_Details` wd ON wd.work_month_id = :work_month_id
        JOIN `Partners` p ON wd.partner_id = p.partner_id
        WHERE tmp.temp_order_id = :temp_order_id AND tmp.user_id = :user_id AND p.user_id1 = :user_id
        LIMIT 1
    ");
    $stmt->execute([
        ':work_month_id' => $work_month_id,
        ':temp_order_id' => $temp_order_id,
        ':user_id' => $current_user_id
    ]);
    $temp_order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$temp_order) {
        echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>سفارش یافت نشد یا دسترسی ندارید.</div></div>";
        require_once 'footer.php';
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching temp order: " . $e->getMessage());
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>خطا در دریافت اطلاعات سفارش.</div></div>";
    require_once 'footer.php';
    exit;
}

$partner1_id = $temp_order['user_id1'];

// لود آیتم‌های سفارش
try {
    $stmt = $pdo->prepare("SELECT * FROM `Temp_Order_Items` WHERE temp_order_id = ?");
    $stmt->execute([$temp_order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching temp order items: " . $e->getMessage());
    $items = [];
}

// لود قیمت‌های فاکتور
try {
    $stmt = $pdo->prepare("SELECT item_index, invoice_price, is_postal, postal_price FROM `Invoice_Prices` WHERE order_id = ?");
    $stmt->execute([$temp_order_id]);
    $invoice_prices = [];
    $postal_enabled = false;
    $postal_price = 50000;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['is_postal']) {
            $postal_enabled = true;
            $postal_price = $row['postal_price'];
            $invoice_prices['postal'] = $row['postal_price'];
        } else {
            $invoice_prices[$row['item_index']] = $row['invoice_price'];
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching invoice prices: " . $e->getMessage());
    $invoice_prices = [];
    $postal_enabled = false;
    $postal_price = 50000;
}

// تنظیم سشن
$_SESSION['edit_temp_order_items'] = $items;
$_SESSION['edit_temp_order_id'] = $temp_order_id;
$_SESSION['edit_temp_order_discount'] = $temp_order['discount'];
$_SESSION['invoice_prices'] = $invoice_prices;
$_SESSION['postal_enabled'] = $postal_enabled;
$_SESSION['postal_price'] = $postal_price;
?>

<div class="container-fluid mt-5">
    <h5 class="card-title mb-4">ویرایش سفارش موقت #<?= $temp_order_id ?></h5>

    <form id="edit-temp-order-form">
        <div class="mb-3">
            <label for="customer_name" class="form-label">نام مشتری</label>
            <input type="text" class="form-control" id="customer_name" name="customer_name" value="<?= htmlspecialchars($temp_order['customer_name']) ?>" required autocomplete="off">
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
            <button type="button" id="edit_item_btn" class="btn btn-warning" style="display: none;">ویرایش محصول</button>
            <input type="hidden" id="edit_index" name="edit_index">
        </div>

        <div id="items_table">
            <!-- جدول آیتم‌ها با جاوااسکریپت پر می‌شود -->
        </div>

        <div class="mb-3">
            <label class="form-check-label">
                <input type="checkbox" id="postal_option" name="postal_option" <?= $postal_enabled ? 'checked' : '' ?>> فعال کردن ارسال پستی
            </label>
            <div id="postal_price_container" style="display: <?= $postal_enabled ? 'block' : 'none' ?>;">
                <label for="postal_price" class="form-label">هزینه ارسال پستی (تومان)</label>
                <input type="number" class="form-control" id="postal_price" name="postal_price" value="<?= $postal_price ?>" min="0">
            </div>
        </div>

        <div class="mb-3">
            <p><strong>جمع کل:</strong> <span id="total_amount_display"><?= number_format($temp_order['total_amount'], 0) ?> تومان</span></p>
            <p><strong>تخفیف:</strong> <input type="number" id="discount" name="discount" value="<?= $temp_order['discount'] ?>" min="0"></p>
            <p><strong>هزینه ارسال پستی:</strong> <span id="postal_price_display"><?= $postal_enabled ? number_format($postal_price, 0) . ' تومان' : '0 تومان' ?></span></p>
            <p><strong>مبلغ نهایی:</strong> <span id="final_amount_display"><?= number_format($temp_order['final_amount'], 0) ?> تومان</span></p>
        </div>

        <button type="button" id="save_temp_order_btn" class="btn btn-success mt-3">ذخیره تغییرات</button>
        <a href="orders.php?work_month_id=<?= htmlspecialchars($work_month_id) ?>" class="btn btn-secondary mt-3">بازگشت</a>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function () {
    // لود اولیه جدول
    let initialItems = <?= json_encode($items, JSON_UNESCAPED_UNICODE) ?>;
    renderItemsTable({
        items: initialItems,
        total_amount: <?= $temp_order['total_amount'] ?>,
        discount: <?= $temp_order['discount'] ?>,
        final_amount: <?= $temp_order['final_amount'] ?>,
        postal_enabled: <?= json_encode($postal_enabled) ?>,
        postal_price: <?= $postal_price ?>
    });

    // پیشنهاد محصولات
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
        $('#quantity').val(1);
        $('#total_price').val((1 * product.unit_price).toLocaleString('fa') + ' تومان');
        $('#product_suggestions').hide();
        $('#quantity').focus();
    });

    // محاسبه قیمت کل
    $('#quantity, #unit_price, #extra_sale').on('input', function () {
        let quantity = Number($('#quantity').val()) || 0;
        let unit_price = Number($('#unit_price').val()) || 0;
        let extra_sale = Number($('#extra_sale').val()) || 0;
        let total = quantity * (unit_price + extra_sale);
        $('#total_price').val(total.toLocaleString('fa') + ' تومان');
    });

    // افزودن محصول
    $('#add_item_btn').on('click', function () {
        let data = {
            action: 'add_edit_temp_item',
            customer_name: $('#customer_name').val(),
            product_id: $('#product_id').val(),
            quantity: $('#quantity').val(),
            unit_price: $('#unit_price').val(),
            extra_sale: $('#extra_sale').val(),
            discount: $('#discount').val(),
            temp_order_id: '<?= $temp_order_id ?>',
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

    // ویرایش محصول
    $('#edit_item_btn').on('click', function () {
        let data = {
            action: 'edit_edit_temp_item',
            customer_name: $('#customer_name').val(),
            product_id: $('#product_id').val(),
            quantity: $('#quantity').val(),
            unit_price: $('#unit_price').val(),
            extra_sale: $('#extra_sale').val(),
            discount: $('#discount').val(),
            index: $('#edit_index').val(),
            temp_order_id: '<?= $temp_order_id ?>',
            partner1_id: '<?= $partner1_id ?>'
        };

        $.post('ajax_handler.php', data, function (response) {
            if (response.success) {
                renderItemsTable(response.data);
                resetForm();
                $('#add_item_btn').show();
                $('#edit_item_btn').hide();
            } else {
                alert(response.message);
            }
        }, 'json');
    });

    // حذف محصول
    $('#items_table').on('click', '.delete-item', function () {
        let index = $(this).data('index');
        if (confirm('آیا از حذف این محصول مطمئن هستید؟')) {
            $.post('ajax_handler.php', {
                action: 'delete_edit_temp_item',
                index: index,
                temp_order_id: '<?= $temp_order_id ?>',
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

    // انتخاب محصول برای ویرایش
    $('#items_table').on('click', '.edit-item', function () {
        let index = $(this).data('index');
        let items = <?= json_encode($items, JSON_UNESCAPED_UNICODE) ?>;
        let item = items[index];
        if (item) {
            $('#product_name').val(item.product_name);
            $('#product_id').val(item.product_id);
            $('#quantity').val(item.quantity);
            $('#unit_price').val(item.unit_price);
            $('#extra_sale').val(item.extra_sale);
            $('#total_price').val(Number(item.total_price).toLocaleString('fa') + ' تومان');
            $('#edit_index').val(index);
            $('#add_item_btn').hide();
            $('#edit_item_btn').show();
        }
    });

    // تنظیم قیمت فاکتور
    $('#items_table').on('click', '.set-invoice-price', function () {
        let index = $(this).data('index');
        let invoice_price = prompt('قیمت فاکتور را وارد کنید (تومان):', 0);
        if (invoice_price !== null && !isNaN(invoice_price) && invoice_price >= 0) {
            $.post('ajax_handler.php', {
                action: 'set_invoice_price',
                index: index,
                invoice_price: invoice_price,
                order_id: '<?= $temp_order_id ?>'
            }, function (response) {
                if (response.success) {
                    alert(response.message);
                    renderItemsTable({
                        items: <?= json_encode($items, JSON_UNESCAPED_UNICODE) ?>,
                        total_amount: <?= $temp_order['total_amount'] ?>,
                        discount: <?= $temp_order['discount'] ?>,
                        final_amount: <?= $temp_order['final_amount'] ?>,
                        postal_enabled: <?= json_encode($postal_enabled) ?>,
                        postal_price: <?= $postal_price ?>
                    });
                } else {
                    alert(response.message);
                }
            }, 'json');
        }
    });

    // به‌روزرسانی تخفیف
    $('#discount').on('input', function () {
        $.post('ajax_handler.php', {
            action: 'update_edit_temp_discount',
            discount: $(this).val(),
            temp_order_id: '<?= $temp_order_id ?>'
        }, function (response) {
            if (response.success) {
                renderItemsTable(response.data);
            } else {
                alert(response.message);
            }
        }, 'json');
    });

    // مدیریت گزینه ارسال پستی
    $('#postal_option').on('change', function () {
        let enable_postal = $(this).is(':checked');
        $('#postal_price_container').toggle(enable_postal);
        $.post('ajax_handler.php', {
            action: 'set_postal_option',
            enable_postal: enable_postal,
            order_id: '<?= $temp_order_id ?>'
        }, function (response) {
            if (response.success) {
                renderItemsTable(response.data);
                $('#postal_price_display').text(Number(response.data.postal_price).toLocaleString('fa') + ' تومان');
            } else {
                alert(response.message);
            }
        }, 'json');
    });

    $('#postal_price').on('input', function () {
        $.post('ajax_handler.php', {
            action: 'set_invoice_price',
            index: 'postal',
            invoice_price: $(this).val(),
            order_id: '<?= $temp_order_id ?>'
        }, function (response) {
            if (response.success) {
                $('#postal_price_display').text(Number($(this).val()).toLocaleString('fa') + ' تومان');
            } else {
                alert(response.message);
            }
        }, 'json');
    });

    // ذخیره تغییرات
    $('#save_temp_order_btn').on('click', function () {
        let items = <?= json_encode($items, JSON_UNESCAPED_UNICODE) ?>;
        if (!items || items.length === 0) {
            if (!confirm('هیچ محصولی در سفارش وجود ندارد. آیا می‌خواهید سفارش بدون محصول ذخیره شود؟')) {
                return;
            }
        }

        let data = {
            action: 'save_edit_temp_order',
            temp_order_id: '<?= $temp_order_id ?>',
            customer_name: $('#customer_name').val(),
            discount: $('#discount').val(),
            partner1_id: '<?= $partner1_id ?>'
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

    // رندر جدول آیتم‌ها
    function renderItemsTable(data) {
        let html = '<table class="table table-light"><thead><tr><th>نام محصول</th><th>تعداد</th><th>قیمت واحد</th><th>اضافه فروش</th><th>قیمت کل</th><th>قیمت فاکتور</th><th>عملیات</th></tr></thead><tbody>';
        if (data.items && data.items.length > 0) {
            data.items.forEach((item, index) => {
                let invoice_price = <?= json_encode($invoice_prices, JSON_UNESCAPED_UNICODE) ?>[index] || 0;
                html += `<tr>
                    <td>${item.product_name}</td>
                    <td>${item.quantity}</td>
                    <td>${Number(item.unit_price).toLocaleString('fa')} تومان</td>
                    <td>${Number(item.extra_sale).toLocaleString('fa')} تومان</td>
                    <td>${Number(item.total_price).toLocaleString('fa')} تومان</td>
                    <td>
                        <span class="invoice-price">${Number(invoice_price).toLocaleString('fa')} تومان</span>
                        <button class="btn btn-info btn-sm set-invoice-price" data-index="${index}">تنظیم قیمت</button>
                    </td>
                    <td>
                        <button class="btn btn-warning btn-sm edit-item" data-index="${index}">ویرایش</button>
                        <button class="btn btn-danger btn-sm delete-item" data-index="${index}">حذف</button>
                    </td>
                </tr>`;
            });
        }
        html += `<tr><td colspan="4">جمع کل</td><td>${Number(data.total_amount).toLocaleString('fa')} تومان</td><td colspan="2"></td></tr>`;
        html += '</tbody></table>';
        $('#items_table').html(html);
        $('#total_amount_display').text(Number(data.total_amount).toLocaleString('fa') + ' تومان');
        $('#final_amount_display').text(Number(data.final_amount).toLocaleString('fa') + ' تومان');
        $('#postal_price_display').text(Number(data.postal_price || 0).toLocaleString('fa') + ' تومان');
    }

    // ریست فرم
    function resetForm() {
        $('#product_name').val('');
        $('#product_id').val('');
        $('#quantity').val('1');
        $('#unit_price').val('');
        $('#extra_sale').val('0');
        $('#total_price').val('');
        $('#edit_index').val('');
        $('#add_item_btn').show();
        $('#edit_item_btn').hide();
    }
});
</script>

<?php require_once 'footer.php'; ?>