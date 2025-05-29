<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once 'db.php';
require_once 'jdf.php';

$order_id = $_GET['order_id'] ?? '';
$work_month_id = $_GET['work_month_id'] ?? '';
$current_user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

if (!$order_id || !$work_month_id) {
    header('Location: orders.php?work_month_id=' . urlencode($work_month_id));
    exit;
}

if ($is_admin) {
    header('Location: orders.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT o.order_id, o.customer_name, o.work_details_id, o.total_amount, o.discount, o.final_amount
    FROM Orders o
    WHERE o.order_id = ? AND o.is_main_order = 0
");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: orders.php?work_month_id=' . urlencode($work_month_id));
    exit;
}

// حذف شرط status از کوئری محصولات
$stmt = $pdo->prepare("SELECT product_id, product_name FROM Products");
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ویرایش پیش‌فاکتور</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Vazirmatn', sans-serif; }
        .hidden { display: none; }
        .order-items-table { width: 100%; min-width: 800px; border-collapse: collapse; }
        .order-items-table th, .order-items-table td { vertical-align: middle !important; white-space: nowrap !important; padding: 8px; min-width: 120px; }
        .total-row td { font-weight: bold; }
        .invoice-price { margin-right: 10px; }
        .postal-row td { background-color: #f8f9fa; }
        .form-label { margin-bottom: 0; }
        .btn-sm { font-size: 0.8rem; }
        .alert { margin-top: 20px; }
        .table-wrapper { width: 100%; overflow-x: auto !important; overflow-y: visible; -webkit-overflow-scrolling: touch; }
        .list-group { max-height: 200px; overflow-y: auto; }
    </style>
</head>
<body>
    <div class="container-fluid mt-5">
        <h5 class="card-title mb-4">ویرایش پیش‌فاکتور</h5>
        <form id="sub_order_form" method="POST">
            <div class="mb-3">
                <label for="customer_name" class="form-label">نام مشتری</label>
                <input type="text" class="form-control" id="customer_name" name="customer_name" required autocomplete="off">
            </div>

            <div class="mb-3 position-relative">
                <label for="product_name" class="form-label">نام محصول</label>
                <input type="text" class="form-control" id="product_name" name="product_name" placeholder="جستجو یا وارد کنید..." autocomplete="off">
                <div id="product_suggestions" class="list-group position-absolute w-100" style="z-index: 1000; display: none;"></div>
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
                    <label for="adjusted_price" class="form-label">قیمت نهایی واحد</label>
                    <input type="text" class="form-control" id="adjusted_price" name="adjusted_price" readonly>
                </div>
                <div class="col-6 col-md-6">
                    <label for="total_price" class="form-label">قیمت کل</label>
                    <input type="text" class="form-control" id="total_price" name="total_price" readonly>
                </div>
                <div class="col-6 col-md-6">
                    <label for="inventory_quantity" class="form-label">موجودی</label>
                    <p class="form-control-static" id="inventory_quantity">0</p>
                </div>
            </div>

            <div class="mb-3">
                <button type="button" id="add_item_btn" class="btn btn-primary">افزودن محصول</button>
                <button type="button" id="edit_item_btn" class="btn btn-warning" style="display: none;">ثبت ویرایش</button>
            </div>

            <div class="table-wrapper" id="items_table"></div>

            <div class="mb-3">
                <p><strong>جمع کل:</strong> <span id="total_amount_display">0 تومان</span></p>
                <p><strong>مبلغ نهایی:</strong> <span id="final_amount_display">0 تومان</span></p>
            </div>

            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="convert_to_main" name="convert_to_main">
                <label class="form-check-label" for="convert_to_main">تبدیل به فاکتور اصلی</label>
            </div>

            <div id="partner_container" class="mb-3 hidden">
                <label for="partner_id" class="form-label">انتخاب همکار</label>
                <select id="partner_id" class="form-select" name="partner_id">
                    <option value="">انتخاب همکار</option>
                </select>
            </div>

            <div id="work_date_container" class="mb-3 hidden">
                <label for="work_date" class="form-label">انتخاب تاریخ کاری</label>
                <select id="work_date" class="form-select" name="work_date">
                    <option value="">انتخاب تاریخ</option>
                </select>
            </div>

            <button type="submit" class="btn btn-success">ذخیره تغییرات</button>
            <a href="orders.php?work_month_id=<?= htmlspecialchars($work_month_id) ?>" class="btn btn-secondary">بازگشت</a>
        </form>
    </div>

    <div class="modal fade" id="invoicePriceModal" tabindex="-1" aria-labelledby="invoicePriceModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="invoicePriceModalLabel">تنظیم قیمت فاکتور</h5>
                    <button type="button" class="btn btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="invoice_price" class="form-label">قیمت فاکتور (تومان)</label>
                        <input type="number" class="form-control" id="invoice_price" name="invoice_price" min="0" required>
                        <input type="hidden" id="invoice_price_index" name="invoice_price_index">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="save_invoice_price">ذخیره</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let initialInventory = 0;
    let editingIndex = null;

    async function sendRequest(url, data) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(data)
            });
            const text = await response.text();
            console.log('Raw Response:', text);
            try {
                const json = JSON.parse(text);
                if (!json.success && !json.message) {
                    json.message = 'خطای ناشناخته در سرور.';
                }
                return json;
            } catch (e) {
                console.error('JSON Parse Error:', e, 'Response:', text);
                alert('خطا در پردازش پاسخ سرور: ' + text.substring(0, 100));
                return { success: false, message: 'خطا در پردازش پاسخ سرور.' };
            }
        } catch (error) {
            console.error('SendRequest Error:', error);
            alert('خطا در ارسال درخواست.');
            return { success: false, message: 'خطا در ارسال درخواست.' };
        }
    }

    function renderItemsTable(data) {
        const itemsTable = document.getElementById('items_table');
        const totalAmountDisplay = document.getElementById('total_amount_display');
        const finalAmountDisplay = document.getElementById('final_amount_display');
        const invoicePrices = data.invoice_prices || {};
        const postalEnabled = data.sub_postal_enabled || false;
        const postalPrice = data.sub_postal_price || 50000;

        itemsTable.innerHTML = '';
        if (!data.items || data.items.length === 0) {
            itemsTable.innerHTML = '<div class="alert alert-warning">هیچ محصولی در پیش‌فاکتور یافت نشد.</div>';
            totalAmountDisplay.textContent = '0 تومان';
            finalAmountDisplay.textContent = '0 تومان';
            return;
        }

        let tableHtml = `
            <table class="table table-light order-items-table">
                <thead>
                    <tr>
                        <th>نام محصول</th>
                        <th>تعداد</th>
                        <th>قیمت واحد</th>
                        <th>اضافه فروش</th>
                        <th>قیمت کل</th>
                        <th>قیمت فاکتور</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
        `;
        data.items.forEach((item, index) => {
            tableHtml += `
                <tr id="item_row_${index}">
                    <td>${item.product_name}</td>
                    <td>${item.quantity}</td>
                    <td>${Number(item.unit_price).toLocaleString('fa-IR')} تومان</td>
                    <td>${Number(item.extra_sale).toLocaleString('fa-IR')} تومان</td>
                    <td>${Number(item.total_price).toLocaleString('fa-IR')} تومان</td>
                    <td>
                        <button type="button" class="btn btn-info btn-sm set-invoice-price" data-index="${index}">
                            تنظیم قیمت
                        </button>
                        <span class="invoice-price" data-index="${index}">
                            ${Number(invoicePrices[index] ?? item.total_price).toLocaleString('fa-IR')} تومان
                        </span>
                    </td>
                    <td>
                        <button type="button" class="btn btn-warning btn-sm edit-item" data-index="${index}">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" class="btn btn-danger btn-sm delete-item" data-index="${index}">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        });
        if (postalEnabled) {
            tableHtml += `
                <tr class="postal-row">
                    <td>ارسال پستی</td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>
                    <td>
                        <button type="button" class="btn btn-info btn-sm set-invoice-price" data-index="postal">
                            تنظیم قیمت
                        </button>
                        <span class="invoice-price" data-index="postal">
                            ${Number(invoicePrices['postal'] ?? postalPrice).toLocaleString('fa-IR')} تومان
                        </span>
                    </td>
                    <td>-</td>
                </tr>
            `;
        }
        tableHtml += `
                <tr class="total-row">
                    <td colspan="4"><strong>جمع کل</strong></td>
                    <td><strong id="total_amount">${Number(data.total_amount).toLocaleString('fa-IR')} تومان</strong></td>
                    <td colspan="2"></td>
                </tr>
                <tr class="total-row">
                    <td><label for="discount" class="form-label">تخفیف</label></td>
                    <td><input type="number" class="form-control" id="discount" name="discount" value="${data.discount}" min="0"></td>
                    <td><strong id="final_amount">${Number(data.final_amount).toLocaleString('fa-IR')} تومان</strong></td>
                    <td colspan="2"></td>
                </tr>
                <tr class="total-row">
                    <td><label for="postal_option" class="form-label">پست سفارش</label></td>
                    <td><input type="checkbox" id="postal_option" name="postal_option" ${postalEnabled ? 'checked' : ''}></td>
                    <td><input type="number" class="form-control" id="postal_price" name="postal_price" value="${postalPrice}" min="0" ${postalEnabled ? '' : 'disabled'}></td>
                    <td colspan="2"></td>
                </tr>
                </tbody>
            </table>
        `;
        itemsTable.innerHTML = tableHtml;
        totalAmountDisplay.textContent = Number(data.total_amount).toLocaleString('fa-IR') + ' تومان';
        finalAmountDisplay.textContent = Number(data.final_amount).toLocaleString('fa-IR') + ' تومان';
    }

    function resetForm() {
        $('#product_name').val('').prop('disabled', false);
        $('#product_id').val('');
        $('#quantity').val(1);
        $('#unit_price').val('');
        $('#extra_sale').val(0);
        $('#adjusted_price').val('');
        $('#total_price').val('');
        $('#inventory_quantity').text('0');
        $('#add_item_btn').show();
        $('#edit_item_btn').hide();
        editingIndex = null;
        initialInventory = 0;
    }

    function updatePrices() {
        const quantity = Number($('#quantity').val()) || 0;
        const unit_price = Number($('#unit_price').val()) || 0;
        const extra_sale = Number($('#extra_sale').val()) || 0;
        const adjusted_price = unit_price + extra_sale;
        const total_price = quantity * adjusted_price;
        $('#adjusted_price').val(adjusted_price.toLocaleString('fa-IR') + ' تومان');
        $('#total_price').val(total_price.toLocaleString('fa-IR') + ' تومان');
    }

    document.addEventListener('DOMContentLoaded', async () => {
        const $convertCheckbox = $('#convert_to_main');
        const $partnerSelect = $('#partner_id');
        const $workDateSelect = $('#work_date');
        const $partnerContainer = $('#partner_container');
        const $workDateContainer = $('#work_date_container');

        $partnerContainer.addClass('hidden');
        $workDateContainer.addClass('hidden');

        console.log('Loading sub-order with order_id=<?= htmlspecialchars($order_id) ?>, work_month_id=<?= htmlspecialchars($work_month_id) ?>');

        // Load sub-order data
        const loadResponse = await sendRequest('sub_order_handler.php', {
            action: 'load_sub_order',
            order_id: '<?= htmlspecialchars($order_id) ?>'
        });
        console.log('Load Sub-Order Response:', loadResponse);
        if (loadResponse.success) {
            document.getElementById('customer_name').value = loadResponse.data.order.customer_name || '';
            renderItemsTable(loadResponse.data);
        } else {
            alert('خطا در بارگذاری پیش‌فاکتور: ' + loadResponse.message);
            console.error('Load Sub-Order Failed:', loadResponse.message);
            window.location.assign('orders.php?work_month_id=<?= htmlspecialchars($work_month_id) ?>');
            return;
        }

        $convertCheckbox.on('change', function () {
            console.log('Convert Checkbox Changed:', $(this).is(':checked'));
            if ($(this).is(':checked')) {
                $partnerContainer.removeClass('hidden');
                loadPartners();
            } else {
                $partnerContainer.addClass('hidden');
                $workDateContainer.addClass('hidden');
                $partnerSelect.empty().append('<option value="">انتخاب همکار</option>');
                $workDateSelect.empty().append('<option value="">انتخاب تاریخ</option>');
            }
        });

        function loadPartners() {
            console.log('Loading partners for work_month_id=<?= htmlspecialchars($work_month_id) ?>, current_user_id=<?= htmlspecialchars($current_user_id) ?>');
            $.ajax({
                url: 'sub_order_handler.php',
                type: 'POST',
                data: {
                    action: 'get_related_partners',
                    work_month_id: '<?= htmlspecialchars($work_month_id) ?>',
                    current_user_id: '<?= htmlspecialchars($current_user_id) ?>'
                },
                dataType: 'json',
                success: function (response) {
                    console.log('Load Partners Response:', response);
                    if (response.success && response.data.partners && response.data.partners.length > 0) {
                        $partnerSelect.empty().append('<option value="">انتخاب همکار</option>');
                        response.data.partners.forEach(partner => {
                            $partnerSelect.append(`<option value="${partner.user_id}">${partner.full_name}</option>`);
                        });
                    } else {
                        console.error('Load Partners Error:', response.message);
                        $partnerSelect.empty().append('<option value="">هیچ همکاری یافت نشد</option>');
                        alert('خطا در بارگذاری همکارها: ' + (response.message || 'خطای ناشناس'));
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Load Partners AJAX Error:', status, error, xhr.responseText);
                    $partnerSelect.empty().append('<option value="">هیچ همکاری یافت نشد</option>');
                    alert('خطا در دریافت همکارها: خطای سرور.');
                }
            });
        }

        $partnerSelect.on('change', function () {
            const partnerId = $(this).val();
            const workMonthId = '<?= htmlspecialchars($work_month_id) ?>';
            console.log('Partner selected:', partnerId, 'Work Month ID:', workMonthId);
            if (partnerId && workMonthId) {
                $workDateContainer.removeClass('hidden');
                $.ajax({
                    url: 'sub_order_handler.php',
                    type: 'POST',
                    data: {
                        action: 'get_partner_work_days',
                        partner_id: partnerId,
                        work_month_id: workMonthId
                    },
                    dataType: 'json',
                    success: function (response) {
                        console.log('Work Days Response:', response);
                        $workDateSelect.empty().append('<option value="">انتخاب تاریخ</option>');
                        if (response.success && response.data.work_days && response.data.work_days.length > 0) {
                            response.data.work_days.forEach(day => {
                                $workDateSelect.append(`<option value="${day.id}">${day.jalali_date}</option>`);
                            });
                        } else {
                            console.error('Work Days Error:', response.message);
                            $workDateSelect.append('<option value="">هیچ تاریخی یافت نشد</option>');
                            alert('خطا در بارگذاری روزهای کاری: ' + (response.message || 'خطای ناشناس'));
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('Work Days AJAX Error:', status, error, xhr.responseText);
                        $workDateSelect.empty().append('<option value="">هیچ تاریخی یافت نشد</option>');
                        alert('خطا در دریافت روزهای کاری: خطای سرور.');
                    }
                });
            } else {
                $workDateContainer.addClass('hidden');
                $workDateSelect.empty().append('<option value="">انتخاب تاریخ</option>');
            }
        });

        $('#product_name').on('input', function() {
            const query = $(this).val().trim();
            const work_details_id = '<?= htmlspecialchars($order['work_details_id']) ?>';
            const partner_id = '<?= htmlspecialchars($current_user_id) ?>';
            if (query.length >= 3) {
                $.post('get_sub_order_products.php', { query, work_details_id, partner_id }, function(response) {
                    console.log('Product suggestions response:', response);
                    if (response.trim() === '' || response.includes('محصولی یافت نشد')) {
                        $('#product_suggestions').hide();
                    } else {
                        $('#product_suggestions').html(response).show();
                    }
                }).fail(function() {
                    console.error('Product search error');
                    $('#product_suggestions').hide();
                    alert('خطا در جستجوی محصولات.');
                });
            } else {
                $('#product_suggestions').hide();
            }
        });

        $(document).on('click', '.product-suggestion', async function(e) {
            e.preventDefault();
            let product = $(this).data('product');
            if (typeof product === Flex) {
                try {
                    product = JSON.parse(product);
                } catch (e) {
                    console.error('Product parse error:', e);
                    alert('خطا در انتخاب محصول.');
                    return;
                }
            }
            $('#product_name').val(product.product_name).prop('disabled', false);
            $('#product_id').val(product.product_id);
            $('#unit_price').val(product.unit_price);
            $('#extra_sale').val(0');
            $('#adjusted_price').val(Number(product.unit_price).toLocaleString('fa-IR') + ' تومان');
            $('#total_price').val((1 * Number(product.unit_price)).toLocaleString('fa-IR') + ' تومان');
            $('#product_suggestions').hide();

            const response = await sendRequest('get_inventory.php', {
                product_id: product.product_id,
                user_id: '<?= $current_user_id ?>',
                is_sub_order: true
            });
            if (response.success) {
                initialInventory = response.data.inventory || 0;
                $('#inventory_quantity').text(initialInventory);
                $('#quantity').val(1);
            } else {
                $('#inventory_quantity').text('0');
                alert('خطا در دریافت موجودی: ' + response.message);
            }

            updatePrices();
        });

        $('#quantity, #unit_price, #extra_sale').on('input', updatePrices);

        $('#add_item_btn').on('click', async function() {
            const customerName = $('#customer_name').val().trim();
            const product_id = $('#product_id').val();
            const product_name = $('#product_name').val().trim();
            const quantity = parseFloat($('#quantity').val()) || 0;
            const unit_price = parseFloat($('#unit_price').val()) || 0;
            const extra_sale = parseFloat($('#extra_sale').val()) || 0;
            const discount = parseFloat($('#discount').val()) || 0;

            if (!customerName || !product_id || !product_name || quantity <= 0 || unit_price < 0) {
                alert('لطفاً تمام فیلدها را پر کنید.');
                return;
            }

            const response = await sendRequest('sub_order_handler.php', {
                action: 'add_sub_item',
                customer_name: customerName,
                product_id: product_id,
                product_name: product_name,
                quantity: quantity,
                unit_price: unit_price,
                extra_sale: extra_sale,
                discount: discount,
                work_details_id: '<?= htmlspecialchars($order['work_details_id']) ?>',
                partner_id: '<?= $current_user_id ?>'
            });

            if (response.success) {
                renderItemsTable(response.data);
                resetForm();
            } else {
                alert('خطا: ' + response.message);
            }
        });

        $('#edit_item_btn').on('click', async function() {
            $('#add_item_btn').trigger('click');
        });

        $(document).on('click', '.edit-item', function() {
            const index = $(this).data('index');
            const items = <?= json_encode($_SESSION['sub_order_items'] ?? []) ?>;
            const item = items[index];

            if (!item) {
                alert('آیتم یافت نشد.');
                return;
            }

            $('#product_name').val(item.product_name).prop('disabled', true);
            $('#product_id').val(item.product_id);
            $('#quantity').val(item.quantity);
            $('#unit_price').val(item.unit_price);
            $('#extra_sale').val(item.extra_sale);
            $('#inventory_quantity').text(initialInventory);
            updatePrices();

            editingIndex = index;
            $('#add_item_btn').hide();
            $('#edit_item_btn').show();
            $('#quantity').focus();
        });

        $(document).on('click', '.delete-item', async function() {
            const index = $(this).data('index');
            const response = await sendRequest('sub_order_handler.php', {
                action: 'delete_sub_item',
                index: index
            });

            if (response.success) {
                renderItemsTable(response.data);
            } else {
                alert('خطا: ' + response.message);
            }
        });

        $(document).on('click', '.set-invoice-price', function() {
            const index = $(this).data('index');
            const currentPrice = parseInt($(`.invoice-price[data-index="${index}"]`).text().replace(/[^\d]/g, '')) || 0;
            $('#invoice_price').val(currentPrice);
            $('#invoice_price_index').val(index);
            $('#invoicePriceModal').modal('show');
        });

        $('#save_invoice_price').on('click', async function() {
            const index = $('#invoice_price_index').val();
            const invoice_price = parseFloat($('#invoice_price').val()) || 0;
            if (invoice_price < 0) {
                alert('قیمت فاکتور نمی‌تواند منفی باشد.');
                return;
            }

            const response = await sendRequest('sub_order_handler.php', {
                action: 'set_sub_invoice_price',
                index: index,
                invoice_price: invoice_price
            });

            if (response.success) {
                renderItemsTable(response.data);
                $('#invoicePriceModal').modal('hide');
            } else {
                alert('خطا: ' + response.message);
            }
        });

        $('#postal_option').on('change', async function() {
            const enablePostal = $(this).is(':checked');
            const postal_price = parseFloat($('#postal_price').val()) || 50000;
            $('#postal_price').prop('disabled', !enablePostal);

            const response = await sendRequest('sub_order_handler.php', {
                action: 'set_sub_postal_option',
                enable_postal: enablePostal,
                postal_price: postal_price
            });

            if (response.success) {
                renderItemsTable(response.data);
            } else {
                alert('خطا: ' + response.message);
            }
        });

        $('#postal_price').on('input', async function() {
            const postal_price = parseFloat($(this).val()) || 50000;
            if (postal_price < 0) {
                $(this).val(50000);
                return;
            }
            if ($('#postal_option').is(':checked')) {
                const response = await sendRequest('sub_order_handler.php', {
                    action: 'set_sub_postal_option',
                    enable_postal: true,
                    postal_price: postal_price
                });
                if (response.success) {
                    renderItemsTable(response.data);
                } else {
                    alert('خطا: ' + response.message);
                }
            }
        });

        $('#discount').on('change', async function() {
            const discount = parseFloat($(this).val()) || 0;
            if (discount < 0) {
                alert('تخفیف نمی‌تواند منفی باشد.');
                $(this).val(0);
                return;
            }

            const response = await sendRequest('sub_order_handler.php', {
                action: 'update_sub_discount',
                discount: discount
            });

            if (response.success) {
                renderItemsTable(response.data);
            } else {
                alert('خطا: ' + response.message);
            }
        });

        $('#sub_order_form').on('submit', async function(e) {
            e.preventDefault();
            const customerName = $('#customer_name').val().trim();
            const discount = parseFloat($('#discount').val()) || 0;
            const convertToMain = $('#convert_to_main').is(':checked');
            const partnerId = $('#partner_id').val();
            const workDateId = $('#work_date').val();

            if (!customerName) {
                alert('نام مشتری الزامی است.');
                return;
            }

            if (convertToMain && (!partnerId || !workDateId)) {
                alert('لطفاً همکار و تاریخ کاری را انتخاب کنید.');
                return;
            }

            const data = {
                action: convertToMain ? 'convert_to_main_order' : 'update_sub_order',
                order_id: '<?= htmlspecialchars($order_id) ?>',
                customer_name: customerName,
                discount: discount,
                work_month_id: '<?= htmlspecialchars($work_month_id) ?>'
            };

            if (convertToMain) {
                data.partner_id = partnerId;
                data.work_details_id = workDateId;
            }

            const response = await sendRequest('sub_order_handler.php', data);

            if (response.success) {
                window.location.assign(response.data.redirect || 'orders.php?work_month_id=<?= htmlspecialchars($work_month_id) ?>');
            } else {
                alert('خطا: ' + response.message);
            }
        });

        // Initialize items table
        const initItems = await sendRequest('sub_order_handler.php', { action: 'get_items' });
        if (initItems.success) {
            renderItemsTable(initItems.data);
        } else {
            renderItemsTable({ items: [], total_amount: 0, discount: 0, final_amount: 0, invoice_prices: {}, sub_postal_enabled: false, sub_postal_price: 50000 });
        }
    });
    </script>
</body>
</html>