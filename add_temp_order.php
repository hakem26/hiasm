<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once 'db.php';
require_once 'header.php';

// بررسی نقش ادمین
$is_admin = ($_SESSION['role'] === 'admin');
if ($is_admin) {
    header('Location: orders.php');
    exit;
}

// بررسی work_month_id
$work_month_id = $_GET['work_month_id'] ?? '';
if (!$work_month_id || !is_numeric($work_month_id)) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>ماه کاری مشخص نشده است.</div></div>";
    require_once 'footer.php';
    exit;
}

// تنظیم partner1_id
$partner1_id = $_SESSION['user_id'];

// پاک‌سازی کامل سشن‌های مربوط به سفارش موقت
unset($_SESSION['temp_order_items']);
unset($_SESSION['invoice_prices']);
unset($_SESSION['discount']);
unset($_SESSION['postal_enabled']);
unset($_SESSION['postal_price']);
unset($_SESSION['is_temp_order_in_progress']);

// تنظیم سشن‌های جدید
$_SESSION['work_month_id'] = $work_month_id;
$_SESSION['temp_order_items'] = [];
$_SESSION['invoice_prices'] = [];
$_SESSION['discount'] = 0;
$_SESSION['postal_enabled'] = false;
$_SESSION['postal_price'] = 50000;
$_SESSION['is_temp_order_in_progress'] = true;

// گرفتن محصولات برای فرم (بدون فیلتر user_id)
try {
    $stmt = $pdo->query("SELECT product_id, product_name, unit_price FROM Products");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching products: " . $e->getMessage());
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>خطا در دریافت محصولات.</div></div>";
    require_once 'footer.php';
    exit;
}
?>

<div class="container-fluid mt-5">
    <h5 class="card-title mb-4">ثبت سفارش موقت (بدون تاریخ)</h5>

    <form id="add-temp-order-form">
        <div class="mb-3">
            <label for="customer_name" class="form-label">نام مشتری</label>
            <input type="text" class="form-control" id="customer_name" name="customer_name" required autocomplete="off">
        </div>

        <div class="mb-3">
            <label for="product_name" class="form-label">نام محصول</label>
            <input type="text" class="form-control" id="product_name" name="product_name"
                placeholder="جستجو یا وارد کنید...">
            <div id="product_suggestions" class="list-group position-absolute"
                style="width: 100%; z-index: 1000; display: none;"></div>
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
            <button type="button" id="edit_item_btn" class="btn btn-warning" style="display: none;">ویرایش
                محصول</button>
            <input type="hidden" id="edit_index" name="edit_index">
        </div>

        <div id="items_table">
            <!-- جدول آیتم‌ها با جاوااسکریپت پر می‌شود -->
        </div>

        <div class="mb-3">
            <label class="form-check-label">
                <input type="checkbox" id="postal_option" name="postal_option" <?= $_SESSION['postal_enabled'] ? 'checked' : '' ?>> فعال کردن ارسال پستی
            </label>
            <div id="postal_price_container" style="display: <?= $_SESSION['postal_enabled'] ? 'block' : 'none' ?>;">
                <label for="postal_price" class="form-label">هزینه ارسال پستی (تومان)</label>
                <input type="number" class="form-control" id="postal_price" name="postal_price"
                    value="<?= $_SESSION['postal_price'] ?>" min="0">
            </div>
        </div>

        <div class="mb-3">
            <p><strong>جمع کل:</strong> <span id="total_amount_display">0 تومان</span></p>
            <p><strong>تخفیف:</strong> <input type="number" id="discount" name="discount" value="0" min="0"></p>
            <p><strong>هزینه ارسال پستی:</strong> <span
                    id="postal_price_display"><?= $_SESSION['postal_enabled'] ? number_format($_SESSION['postal_price'], 0) . ' تومان' : '0 تومان' ?></span>
            </p>
            <p><strong>مبلغ نهایی:</strong> <span id="final_amount_display">0 تومان</span></p>
        </div>

        <button type="button" id="finalize_temp_order_btn" class="btn btn-success mt-3">ثبت سفارش موقت</button>
        <a href="orders.php?work_month_id=<?= htmlspecialchars($work_month_id) ?>"
            class="btn btn-secondary mt-3">بازگشت</a>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    $(document).ready(function () {
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
                    },
                    error: function () {
                        $('#product_suggestions').hide();
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
        $('#add_item_btn').on('click', function (e) {
            e.preventDefault();
            let data = {
                action: 'add_temp_item',
                customer_name: $('#customer_name').val(),
                product_id: $('#product_id').val(),
                quantity: $('#quantity').val(),
                unit_price: $('#unit_price').val(),
                extra_sale: $('#extra_sale').val() || 0,
                discount: $('#discount').val() || 0,
                partner1_id: '<?= $partner1_id ?>',
                work_month_id: '<?= $work_month_id ?>'
            };

            $.post('ajax_handler.php', data, function (response) {
                if (response.success) {
                    renderItemsTable(response.data);
                    resetForm();
                } else {
                    alert(response.message);
                }
            }, 'json').fail(function (xhr, status, error) {
                alert('خطای سرور: ' + error);
            });
        });

        // ویرایش محصول
        $('#edit_item_btn').on('click', function (e) {
            e.preventDefault();
            let data = {
                action: 'edit_temp_item',
                customer_name: $('#customer_name').val(),
                product_id: $('#product_id').val(),
                quantity: $('#quantity').val(),
                unit_price: $('#unit_price').val(),
                extra_sale: $('#extra_sale').val() || 0,
                discount: $('#discount').val() || 0,
                index: $('#edit_index').val(),
                partner1_id: '<?= $partner1_id ?>',
                work_month_id: '<?= $work_month_id ?>'
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
            }, 'json').fail(function (xhr, status, error) {
                alert('خطای سرور: ' + error);
            });
        });

        // حذف محصول
        $('#items_table').on('click', '.delete-item', function (e) {
            e.preventDefault();
            let index = $(this).data('index');
            if (confirm('آیا از حذف این محصول مطمئن هستید؟')) {
                $.post('ajax_handler.php', {
                    action: 'delete_temp_item',
                    index: index,
                    partner1_id: '<?= $partner1_id ?>',
                    work_month_id: '<?= $work_month_id ?>'
                }, function (response) {
                    if (response.success) {
                        renderItemsTable(response.data);
                    } else {
                        alert(response.message);
                    }
                }, 'json').fail(function (xhr, status, error) {
                    alert('خطای سرور: ' + error);
                });
            }
        });

        // انتخاب محصول برای ویرایش
        $('#items_table').on('click', '.edit-item', function (e) {
            e.preventDefault();
            const index = $(this).data('index');
            const items = <?= json_encode($_SESSION['temp_order_items'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
            const item = items[index];
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
                $('#product_name').focus();
            } else {
                alert('هیچ محصولی در لیست وجود ندارد یا محصول حذف شده است.');
            }
        });

        // تنظیم قیمت فاکتور
        $('#items_table').on('click', '.set-invoice-price', async function (e) {
            e.preventDefault();
            const index = $(this).data('index');
            const items = <?= json_encode($_SESSION['temp_order_items'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
            if (!items[index]) {
                alert('هیچ محصولی در لیست وجود ندارد یا محصول حذف شده است.');
                return;
            }
            const item = items[index];
            const defaultPrice = Number(item.unit_price) + Number(item.extra_sale);
            const invoicePrice = prompt('قیمت فاکتور واحد را وارد کنید (تومان):', defaultPrice);
            if (invoicePrice !== null && !isNaN(invoicePrice) && invoicePrice >= 0) {
                const response = await $.post('ajax_handler.php', {
                    action: 'set_invoice_price',
                    index: index,
                    invoice_price: invoicePrice,
                    work_month_id: '<?= $work_month_id ?>'
                }, 'json');
                if (response.success) {
                    await $.post('ajax_handler.php', {
                        action: 'sync_temp_items',
                        items: JSON.stringify(items),
                        work_month_id: '<?= $work_month_id ?>'
                    }, 'json');
                    alert(response.message);
                    response.data.invoice_prices = response.data.invoice_prices || {};
                    response.data.invoice_prices[index] = Number(invoicePrice);
                    renderItemsTable(response.data);
                } else {
                    alert(response.message);
                }
            }
        });

        // به‌روزرسانی تخفیف
        $('#discount').on('input', function () {
            $.post('ajax_handler.php', {
                action: 'update_discount',
                discount: $(this).val() || 0,
                work_month_id: '<?= $work_month_id ?>'
            }, function (response) {
                if (response.success) {
                    renderItemsTable(response.data);
                } else {
                    alert(response.message);
                }
            }, 'json').fail(function (xhr, status, error) {
                alert('خطای سرور: ' + error);
            });
        });

        // مدیریت گزینه ارسال پستی
        $('#postal_option').on('change', function () {
            let enable_postal = $(this).is(':checked');
            $('#postal_price_container').toggle(enable_postal);
            $.post('ajax_handler.php', {
                action: 'set_postal_option',
                enable_postal: enable_postal,
                postal_price: $('#postal_price').val() || 50000,
                work_month_id: '<?= $work_month_id ?>'
            }, function (response) {
                if (response.success) {
                    renderItemsTable(response.data);
                    $('#postal_price_display').text(Number(response.data.postal_price).toLocaleString('fa') + ' تومان');
                } else {
                    alert(response.message);
                }
            }, 'json').fail(function (xhr, status, error) {
                alert('خطای سرور: ' + error);
            });
        });

        $('#postal_price').on('input', function () {
            $.post('ajax_handler.php', {
                action: 'set_invoice_price',
                index: 'postal',
                invoice_price: $(this).val() || 50000,
                work_month_id: '<?= $work_month_id ?>'
            }, function (response) {
                if (response.success) {
                    $('#postal_price_display').text(Number($(this).val()).toLocaleString('fa') + ' تومان');
                } else {
                    alert(response.message);
                }
            }, 'json').fail(function (xhr, status, error) {
                alert('خطای سرور: ' + error);
            });
        });

        // نهایی کردن سفارش
        $('#finalize_temp_order_btn').on('click', function (e) {
            e.preventDefault();
            let data = {
                action: 'finalize_temp_order',
                customer_name: $('#customer_name').val(),
                discount: $('#discount').val() || 0,
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
            }, 'json').fail(function (xhr, status, error) {
                alert('خطای سرور: ' + error);
            });
        });

        // رندر جدول آیتم‌ها
        function renderItemsTable(data) {
            let html = '<table class="table table-light"><thead><tr><th>نام محصول</th><th>تعداد</th><th>قیمت واحد</th><th>اضافه فروش</th><th>قیمت کل</th><th>قیمت فاکتور</th><th>عملیات</th></tr></thead><tbody>';
            if (data.items && data.items.length > 0) {
                data.items.forEach((item, index) => {
                    let invoice_price = data.invoice_price && index == data.index ? data.invoice_price : (<?= json_encode($_SESSION['invoice_prices'] ?? [], JSON_UNESCAPED_UNICODE) ?>[index] || item.unit_price + item.extra_sale);
                    html += `<tr>
                    <td>${item.product_name}</td>
                    <td>${item.quantity}</td>
                    <td>${Number(item.unit_price).toLocaleString('fa')} تومان</td>
                    <td>${Number(item.extra_sale).toLocaleString('fa')} تومان</td>
                    <td>${Number(item.total_price).toLocaleString('fa')} تومان</td>
                    <td>
                        <span class="invoice-price">${Number(invoice_price).toLocaleString('fa')} تومان</span>
                        <button class="btn btn-info btn-sm set-invoice-price" data-index="${index}"><i class="fas fa-edit"></i></button>
                    </td>
                    <td>
                        <button class="btn btn-warning btn-sm edit-item" data-index="${index}"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-danger btn-sm delete-item" data-index="${index}"><i class="fas fa-trash"></i></button>
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