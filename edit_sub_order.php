<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'db.php';

$order_id = $_GET['order_id'] ?? '';
$work_month_id = $_GET['work_month_id'] ?? '';
$current_user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

if (!$order_id || !$work_month_id) {
    header('Location: orders.php?work_month_id=' . urlencode($work_month_id));
    exit;
}

if ($is_admin) {
    header('Location: dashboard.php');
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

$stmt = $pdo->prepare("SELECT product_id, product_name FROM Products WHERE status = 1");
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
        .order-items-table { width: 100%; margin-top: 20px; }
        .total-row td { font-weight: bold; }
        .invoice-price { margin-right: 10px; }
        .postal-row td { background-color: #f8f9fa; }
        .form-label { margin-bottom: 0; }
        .btn-sm { font-size: 0.8rem; }
        .alert { margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h2 class="mb-4">ویرایش پیش‌فاکتور</h2>
        <form id="sub_order_form" method="POST">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="customer_name" class="form-label">نام مشتری</label>
                    <input type="text" class="form-control" id="customer_name" name="customer_name" required>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="product_search" class="form-label">جستجوی محصول</label>
                    <input type="text" class="form-control" id="product_search" placeholder="نام محصول را وارد کنید">
                    <select id="product_select" class="form-select mt-2" size="5" style="display: none;"></select>
                </div>
                <div class="col-md-2">
                    <label for="quantity" class="form-label">تعداد</label>
                    <input type="number" class="form-control" id="quantity" min="1" value="1">
                </div>
                <div class="col-md-2">
                    <label for="unit_price" class="form-label">قیمت واحد</label>
                    <input type="number" class="form-control" id="unit_price" min="0" value="0">
                </div>
                <div class="col-md-2">
                    <label for="extra_sale" class="form-label">اضافه فروش</label>
                    <input type="number" class="form-control" id="extra_sale" min="0" value="0">
                    <button type="button" class="btn btn-primary mt-2" id="add_item">اضافه کردن</button>
                </div>
            </div>

            <div id="items_table" class="mb-3"></div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">جمع کل</label>
                    <div id="total_amount_display" class="form-control">0 تومان</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">مبلغ نهایی</label>
                    <div id="final_amount_display" class="form-control">0 تومان</div>
                </div>
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

    <script src="https://code.jquery.com/jquery-3.6.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
                return { success: false, message: 'خطا در پردازش پاسخ سرور.' };
            }
        } catch (error) {
            console.error('SendRequest Error:', error);
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

        if (!data.items || data.items.length === 0) {
            itemsTable.innerHTML = '<div class="alert alert-warning">هیچ محصولی در پیش‌فاکتور یافت نشد.</div>';
            totalAmountDisplay.textContent = '0 تومان';
            finalAmountDisplay.textContent = '0 تومان';
            return;
        }

        itemsTable.innerHTML = `
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
                    ${data.items.map((item, index) => `
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
                                <button type="button" class="btn btn-danger btn-sm delete-item" data-index="${index}">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    `).join('')}
                    ${postalEnabled ? `
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
                    ` : ''}
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
                        <td colspan="3"></td>
                    </tr>
                </tbody>
            </table>
        `;

        totalAmountDisplay.textContent = Number(data.total_amount).toLocaleString('fa-IR') + ' تومان';
        finalAmountDisplay.textContent = Number(data.final_amount).toLocaleString('fa-IR') + ' تومان';
    }

    document.addEventListener('DOMContentLoaded', async () => {
        let initialInventory = 0;
        const $convertCheckbox = $('#convert_to_main');
        const $partnerSelect = $('#partner_id');
        const $workDateSelect = $('#work_date');
        const $partnerContainer = $('#partner_container');
        const $workDateContainer = $('#work_date_container');

        $partnerContainer.addClass('hidden');
        $workDateContainer.addClass('hidden');

        console.log('Loading sub-order with order_id=<?= htmlspecialchars($order_id) ?>, work_month_id=<?= htmlspecialchars($work_month_id) ?>');

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

        const products = <?= json_encode($products, JSON_UNESCAPED_UNICODE) ?>;
        const $productSearch = $('#product_search');
        const $productSelect = $('#product_select');

        $productSearch.on('input', function () {
            const query = $(this).val().trim().toLowerCase();
            if (query.length < 2) {
                $productSelect.hide().empty();
                return;
            }

            const filteredProducts = products.filter(p => p.product_name.toLowerCase().includes(query));
            $productSelect.empty();
            if (filteredProducts.length > 0) {
                filteredProducts.forEach(p => {
                    $productSelect.append(`<option value="${p.product_id}" data-name="${p.product_name}">${p.product_name}</option>`);
                });
                $productSelect.show();
            } else {
                $productSelect.hide();
            }
        });

        $productSelect.on('change', function () {
            const selectedOption = $(this).find('option:selected');
            $productSearch.val(selectedOption.data('name'));
            $productSelect.hide();
        });

        $('#add_item').on('click', async function () {
            const productId = $productSelect.val() || '';
            const productName = $productSearch.val().trim();
            const quantity = parseFloat($('#quantity').val()) || 0;
            const unitPrice = parseFloat($('#unit_price').val()) || 0;
            const extraSale = parseFloat($('#extra_sale').val()) || 0;
            const discount = parseFloat($('#discount').val()) || 0;
            const customerName = $('#customer_name').val().trim();

            if (!customerName || !productId || !productName || quantity <= 0 || unitPrice <= 0) {
                alert('لطفاً تمام فیلدها را پر کنید.');
                return;
            }

            const response = await sendRequest('sub_order_handler.php', {
                action: 'add_sub_item',
                customer_name: customerName,
                product_id: productId,
                product_name: productName,
                quantity: quantity,
                unit_price: unitPrice,
                extra_sale: extraSale,
                discount: discount
            });

            if (response.success) {
                renderItemsTable(response.data);
                $productSearch.val('');
                $productSelect.hide().empty();
                $('#quantity').val(1);
                $('#unit_price').val(0);
                $('#extra_sale').val(0);
            } else {
                alert('خطا: ' + response.message);
            }
        });

        $(document).on('click', '.delete-item', async function () {
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

        $(document).on('click', '.set-invoice-price', async function () {
            const index = $(this).data('index');
            const newPrice = prompt('قیمت فاکتور جدید را وارد کنید:', '');
            if (newPrice === null || isNaN(parseFloat(newPrice)) || parseFloat(newPrice) < 0) {
                alert('لطفاً یک مقدار معتبر وارد کنید.');
                return;
            }

            const response = await sendRequest('sub_order_handler.php', {
                action: 'set_sub_invoice_price',
                index: index,
                invoice_price: parseFloat(newPrice)
            });

            if (response.success) {
                renderItemsTable(response.data);
            } else {
                alert('خطا: ' + response.message);
            }
        });

        $('#postal_option').on('change', async function () {
            const enablePostal = $(this).is(':checked');
            const postalPrice = enablePostal ? prompt('هزینه پست را وارد کنید:', '50000') : 0;
            if (enablePostal && (isNaN(parseFloat(postalPrice)) || parseFloat(postalPrice) < 0)) {
                alert('لطفاً هزینه پست معتبر وارد کنید.');
                $(this).prop('checked', false);
                return;
            }

            const response = await sendRequest('sub_order_handler.php', {
                action: 'set_sub_postal_option',
                enable_postal: enablePostal,
                postal_price: parseFloat(postalPrice) || 50000
            });

            if (response.success) {
                renderItemsTable(response.data);
            } else {
                alert('خطا: ' + response.message);
            }
        });

        $('#discount').on('change', async function () {
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

        $('#sub_order_form').on('submit', async function (e) {
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
    });
    </script>
</body>
</html>