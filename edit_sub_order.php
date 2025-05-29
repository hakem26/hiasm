<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date)
{
    if (!$gregorian_date || !preg_match('/^\d{4}-\d{2}-\d{2}/', $gregorian_date)) {
        return 'نامشخص';
    }
    try {
        list($gy, $gm, $gd) = explode('-', $gregorian_date);
        $gy = (int) $gy;
        $gm = (int) $gm;
        $gd = (int) $gd;
        if ($gy < 1000 || $gm < 1 || $gm > 12 || $gd < 1 || $gd > 31) {
            return 'نامشخص';
        }
        list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
        return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
    } catch (Exception $e) {
        error_log("Error in gregorian_to_jalali_format: " . $e->getMessage());
        return 'نامشخص';
    }
}

$is_admin = ($_SESSION['role'] === 'admin');
if ($is_admin) {
    header("Location: orders.php");
    exit;
}
$current_user_id = $_SESSION['user_id'];

$order_id = $_GET['order_id'] ?? '';
$work_month_id = $_GET['work_month_id'] ?? '';

if (!$order_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>شناسه سفارش مشخص نشده است.</div></div>";
    require_once 'footer.php';
    exit;
}

// Try to fetch work_month_id from Orders if not provided in URL
if (!$work_month_id) {
    $stmt = $pdo->prepare("
        SELECT wd.work_month_id 
        FROM Orders o
        JOIN Work_Details wd ON o.work_details_id = wd.id
        WHERE o.order_id = ? AND o.is_main_order = 0
    ");
    $stmt->execute([$order_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $work_month_id = $result['work_month_id'];
    }
}

if (!$work_month_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>ماه کاری مشخص نشده است.</div></div>";
    require_once 'footer.php';
    exit;
}

$stmt = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
$stmt->execute([$work_month_id]);
$month = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$month) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>ماه کاری یافت نشد.</div></div>";
    require_once 'footer.php';
    exit;
}

$stmt = $pdo->prepare("SELECT order_id FROM Orders WHERE order_id = ? AND is_main_order = 0");
$stmt->execute([$order_id]);
if (!$stmt->fetch()) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>پیش‌فاکتور یافت نشد یا قابل ویرایش نیست.</div></div>";
    require_once 'footer.php';
    exit;
}
?>

<style>
    body,
    .container-fluid {
        overflow-x: hidden !important;
    }

    .table-wrapper {
        width: 100%;
        overflow-x: auto !important;
        overflow-y: visible;
        -webkit-overflow-scrolling: touch;
    }

    .order-items-table {
        width: 100%;
        min-width: 800px;
        border-collapse: collapse;
    }

    .order-items-table th,
    .order-items-table td {
        vertical-align: middle !important;
        white-space: nowrap !important;
        padding: 8px;
        min-width: 120px;
    }

    .order-items-table .total-row td {
        font-weight: bold;
    }

    .order-items-table .total-row input#discount {
        width: 150px;
        margin: 0 auto;
    }

    @media (max-width: 991px) {

        .col-6,
        .col-md-3,
        .col-md-6 {
            width: 50%;
        }
    }

    .hidden {
        display: none;
    }
</style>

<div class="container-fluid mt-5">
    <h5 class="card-title mb-4">ویرایش پیش‌فاکتور</h5>

    <form id="edit-sub-order-form">
        <div class="card mb-4">
            <div class="card-body">
                <div class="mb-3">
                    <label for="convert_to_main" class="form-label">تبدیل به فاکتور اصلی</label>
                    <input type="checkbox" id="convert_to_main" name="convert_to_main">
                </div>
                <div class="mb-3 hidden" id="partner_container">
                    <label for="partner_id" class="form-label">همکار</label>
                    <select id="partner_id" name="partner_id" class="form-select">
                        <option value="">انتخاب همکار</option>
                    </select>
                </div>
                <div class="mb-3 hidden" id="work_date_container">
                    <label for="work_date" class="form-label">تاریخ</label>
                    <select id="work_date" name="work_date" class="form-select">
                        <option value="">انتخاب تاریخ</option>
                    </select>
                </div>
            </div>
        </div>

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

        <button type="button" id="update_order_btn" class="btn btn-success mt-3">به‌روزرسانی پیش‌فاکتور</button>
        <button type="button" id="convert_order_btn" class="btn btn-primary mt-3">تبدیل به فاکتور اصلی</button>
        <a href="orders.php?work_month_id=<?= $work_month_id ?>" class="btn btn-secondary mt-3">بازگشت</a>
    </form>
</div>

<div class="modal fade" id="invoicePriceModal" tabindex="-1" aria-labelledby="invoicePriceModalLabel"
    aria-hidden="true">
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
                if (!json.success && json.message === 'undefined') {
                    json.message = 'خطای ناشناخته در سرور. لطفاً دوباره تلاش کنید.';
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
            itemsTable.innerHTML = '';
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

        // Load sub-order data
        const loadResponse = await sendRequest('sub_order_handler.php', {
            action: 'load_sub_order',
            order_id: '<?= $order_id ?>'
        });
        if (loadResponse.success) {
            document.getElementById('customer_name').value = loadResponse.data.order.customer_name;
            renderItemsTable(loadResponse.data);
        } else {
            alert(loadResponse.message);
            window.location.assign('orders.php?work_month_id=<?= $work_month_id ?>');
            return;
        }

        $convertCheckbox.on('change', function () {
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
            $.ajax({
                url: 'sub_order_handler.php',
                type: 'POST',
                data: { 
                    action: 'get_related_partners', 
                    work_month_id: '<?= $work_month_id ?>',
                    current_user_id: '<?= $current_user_id ?>'
                },
                success: function (response) {
                    console.log('Load Partners Response:', response);
                    if (response.success && response.data.partners && response.data.partners.length > 0) {
                        $partnerSelect.empty().append('<option value="">انتخاب همکار</option>');
                        response.data.partners.forEach(partner => {
                            $partnerSelect.append(`<option value="${partner.user_id}">${partner.full_name}</option>`);
                        });
                    } else {
                        console.error('Load Partners Error:', response.message);
                        alert('هیچ همکاری برای این ماه کاری یافت نشد.');
                        $partnerSelect.empty().append('<option value="">هیچ همکاری یافت نشد</option>');
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Load Partners AJAX Error:', status, error, xhr.responseText);
                    alert('خطا در دریافت همکارها.');
                }
            });
        }

        $partnerSelect.on('change', function () {
            const partnerId = $(this).val();
            const workMonthId = '<?= $work_month_id ?>';
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
                    success: function (response) {
                        console.log('Work Days Response:', response);
                        if (response.success && response.data.work_days && response.data.work_days.length > 0) {
                            $workDateSelect.empty().append('<option value="">انتخاب تاریخ</option>');
                            response.data.work_days.forEach(day => {
                                $workDateSelect.append(`<option value="${day.id}">${day.jalali_date}</option>`);
                            });
                        } else {
                            console.error('Work Days Error:', response.message);
                            alert('هیچ روز کاری برای این همکار یافت نشد.');
                            $workDateSelect.empty().append('<option value="">هیچ تاریخی یافت نشد</option>');
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('Work Days AJAX Error:', status, error, xhr.responseText);
                        alert('خطا در دریافت روزهای کاری.');
                    }
                });
            } else {
                $workDateContainer.addClass('hidden');
                $workDateSelect.empty().append('<option value="">انتخاب تاریخ</option>');
            }
        });

        $('#product_name').on('input', function () {
            let query = $(this).val().trim();
            const work_details_id = $convertCheckbox.is(':checked') ? $workDateSelect.val() || '<?= $work_month_id ?>' : '<?= $work_month_id ?>';
            const partner_id = $convertCheckbox.is(':checked') ? $partnerSelect.val() || '<?= $current_user_id ?>' : '<?= $current_user_id ?>';
            if (query.length >= 2) {
                $.ajax({
                    url: 'get_sub_order_products.php',
                    type: 'POST',
                    data: { query: query, work_details_id: work_details_id, partner_id: partner_id },
                    success: function (response) {
                        console.log('Product Suggestions Response:', response);
                        if (response.trim() === '') {
                            $('#product_suggestions').hide();
                        } else {
                            $('#product_suggestions').html(response).show();
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('Product AJAX Error:', status, error, xhr.responseText);
                        $('#product_suggestions').hide();
                        alert('خطا در جستجوی محصولات.');
                    }
                });
            } else {
                $('#product_suggestions').hide();
            }
        });

        $(document).on('click', '.product-suggestion', function (e) {
            e.preventDefault();
            let product = $(this).data('product');
            if (typeof product === 'string') {
                try {
                    product = JSON.parse(product);
                } catch (e) {
                    console.error('Product Parse Error:', e);
                    return;
                }
            }
            $('#product_name').val(product.product_name).prop('disabled', false);
            $('#product_id').val(product.product_id);
            $('#unit_price').val(product.unit_price);
            $('#extra_sale').val(0);
            $('#adjusted_price').val(Number(product.unit_price).toLocaleString('fa-IR') + ' تومان');
            $('#total_price').val((1 * product.unit_price).toLocaleString('fa-IR') + ' تومان');
            $('#product_suggestions').hide();

            initialInventory = product.inventory || 0;
            $('#inventory_quantity').text(initialInventory);
            $('#quantity').val(1);
            updateInventory();

            $('#quantity').focus();
        });

        $('#quantity, #extra_sale').on('input', function () {
            let quantity = Number($('#quantity').val()) || 0;
            let unit_price = Number($('#unit_price').val()) || 0;
            let extra_sale = Number($('#extra_sale').val()) || 0;
            let adjusted_price = unit_price + extra_sale;
            let total = quantity * adjusted_price;
            $('#adjusted_price').val(adjusted_price.toLocaleString('fa-IR') + ' تومان');
            $('#total_price').val(total.toLocaleString('fa-IR') + ' تومان');
            updateInventory();
        });

        function updateInventory() {
            let quantity = Number($('#quantity').val()) || 0;
            let items = <?= json_encode($_SESSION['sub_order_items'] ?? []) ?>;
            let product_id = $('#product_id').val();
            let totalUsed = 0;

            items.forEach(item => {
                if (item.product_id === product_id) {
                    totalUsed += parseInt(item.quantity);
                }
            });

            let remainingInventory = initialInventory - totalUsed;
            $('#inventory_quantity').text(remainingInventory);
        }

        document.getElementById('add_item_btn').addEventListener('click', async () => {
            const customer_name = document.getElementById('customer_name').value.trim();
            const product_id = document.getElementById('product_id').value;
            const quantity = Number(document.getElementById('quantity').value) || 0;
            const unit_price = Number(document.getElementById('unit_price').value) || 0;
            const extra_sale = Number(document.getElementById('extra_sale').value) || 0;
            const discount = document.getElementById('discount') ? Number(document.getElementById('discount').value) || 0 : 0;
            const work_details_id = $convertCheckbox.is(':checked') ? $workDateSelect.val() : '<?= $work_month_id ?>';
            const partner_id = $convertCheckbox.is(':checked') ? $partnerSelect.val() || '<?= $current_user_id ?>' : '<?= $current_user_id ?>';

            if (!customer_name || !product_id || quantity <= 0 || unit_price <= 0) {
                alert('لطفاً تمام فیلدها را پر کنید و تعداد بیشتر از ۰ باشد.');
                return;
            }

            const data = {
                action: 'add_sub_item',
                customer_name,
                product_id,
                quantity,
                unit_price,
                extra_sale,
                discount,
                work_details_id,
                partner_id,
                product_name: document.getElementById('product_name').value
            };

            const addResponse = await sendRequest('sub_order_handler.php', data);
            console.log('Add Item Response:', addResponse);
            if (addResponse.success) {
                renderItemsTable(addResponse.data);
                resetForm();
            } else {
                alert(addResponse.message);
            }
        });

        document.getElementById('items_table').addEventListener('click', async (e) => {
            if (e.target.closest('.delete-item')) {
                const index = e.target.closest('.delete-item').getAttribute('data-index');
                if (confirm('آیا از حذف این محصول مطمئن هستید؟')) {
                    const data = {
                        action: 'delete_sub_item',
                        index,
                        partner_id: $convertCheckbox.is(':checked') ? $partnerSelect.val() || '<?= $current_user_id ?>' : '<?= $current_user_id ?>'
                    };

                    const response = await sendRequest('sub_order_handler.php', data);
                    console.log('Delete Item Response:', response);
                    if (response.success) {
                        renderItemsTable(response.data);
                        resetForm();
                    } else {
                        alert(response.message);
                    }
                }
            } else if (e.target.closest('.set-invoice-price')) {
                const index = e.target.closest('.set-invoice-price').getAttribute('data-index');
                $('#invoice_price_index').val(index);
                const currentPrice = loadResponse.data.invoice_prices[index] || '';
                $('#invoice_price').val(currentPrice);
                $('#invoicePriceModal').modal('show');
            }
        });

        document.getElementById('save_invoice_price').addEventListener('click', async () => {
            const index = $('#invoice_price_index').val();
            const invoicePrice = Number($('#invoice_price').val());

            if (isNaN(invoicePrice) || invoicePrice < 0) {
                alert('لطفاً یک قیمت معتبر وارد کنید.');
                return;
            }

            const data = {
                action: 'set_sub_invoice_price',
                index,
                invoice_price: invoicePrice
            };

            const response = await sendRequest('sub_order_handler.php', data);
            console.log('Set Invoice Price Response:', response);
            if (response.success) {
                renderItemsTable(response.data);
                $('#invoicePriceModal').modal('hide');
            } else {
                alert(response.message);
            }
        });

        document.getElementById('items_table').addEventListener('change', async (e) => {
            if (e.target.id === 'postal_option') {
                const enablePostal = e.target.checked;
                const data = {
                    action: 'set_sub_postal_option',
                    enable_postal: enablePostal
                };

                const response = await sendRequest('sub_order_handler.php', data);
                console.log('Set Postal Option Response:', response);
                if (response.success) {
                    renderItemsTable(response.data);
                } else {
                    alert(response.message);
                }
            }
        });

        document.getElementById('items_table').addEventListener('input', async (e) => {
            if (e.target.id === 'discount') {
                const discount = Number(e.target.value) || 0;
                if (discount < 0) {
                    alert('تخفیف نمی‌تواند منفی باشد.');
                    e.target.value = 0;
                    return;
                }
                const data = {
                    action: 'update_sub_discount',
                    discount
                };

                const response = await sendRequest('sub_order_handler.php', data);
                console.log('Update Discount Response:', response);
                if (response.success) {
                    renderItemsTable(response.data);
                } else {
                    alert(response.message);
                }
            }
        });

        document.getElementById('update_order_btn').addEventListener('click', async () => {
            const customer_name = document.getElementById('customer_name').value.trim();
            const discount = document.getElementById('discount') ? Number(document.getElementById('discount').value) || 0 : 0;

            if (!customer_name) {
                alert('لطفاً نام مشتری را وارد کنید.');
                return;
            }

            const response = await sendRequest('sub_order_handler.php', { action: 'get_items' });
            console.log('Get Items Response:', response);
            if (!response.success || !response.data.items || response.data.items.length === 0) {
                alert('لطفاً حداقل یک محصول به پیش‌فاکتور اضافه کنید.');
                return;
            }

            const data = {
                action: 'update_sub_order',
                order_id: '<?= $order_id ?>',
                customer_name,
                discount,
                work_month_id: '<?= $work_month_id ?>'
            };

            const updateResponse = await sendRequest('sub_order_handler.php', data);
            console.log('Update Response:', updateResponse);
            if (updateResponse.success) {
                alert(updateResponse.message);
                window.location.assign(updateResponse.data.redirect);
            } else {
                alert(updateResponse.message);
            }
        });

        document.getElementById('convert_order_btn').addEventListener('click', async () => {
            const customer_name = document.getElementById('customer_name').value.trim();
            const work_details_id = $convertCheckbox.is(':checked') ? $workDateSelect.val() : '';
            const partner_id = $convertCheckbox.is(':checked') ? $partnerSelect.val() || '<?= $current_user_id ?>' : '<?= $current_user_id ?>';
            const discount = document.getElementById('discount') ? Number(document.getElementById('discount').value) || 0 : 0;

            if (!customer_name) {
                alert('لطفاً نام مشتری را وارد کنید.');
                return;
            }
            if ($convertCheckbox.is(':checked') && (!partner_id || !work_details_id)) {
                alert('لطفاً همکار و تاریخ را انتخاب کنید.');
                return;
            }
            if (!$convertCheckbox.is(':checked')) {
                alert('برای تبدیل به فاکتور اصلی، تیک تبدیل را بزنید.');
                return;
            }

            const response = await sendRequest('sub_order_handler.php', { action: 'get_items' });
            console.log('Get Items Response:', response);
            if (!response.success || !response.data.items || response.data.items.length === 0) {
                alert('لطفاً حداقل یک محصول به پیش‌فاکتور اضافه کنید.');
                return;
            }

            const data = {
                action: 'convert_to_main_order',
                order_id: '<?= $order_id ?>',
                work_details_id,
                partner_id,
                work_month_id: '<?= $work_month_id ?>'
            };

            const convertResponse = await sendRequest('sub_order_handler.php', data);
            console.log('Convert Response:', convertResponse);
            if (convertResponse.success) {
                alert(convertResponse.message);
                window.location.assign(convertResponse.data.redirect);
            } else {
                alert(convertResponse.message);
            }
        });

        function resetForm() {
            $('#product_name').val('').prop('disabled', false);
            $('#product_id').val('');
            $('#quantity').val('1');
            $('#unit_price').val('');
            $('#extra_sale').val('0');
            $('#adjusted_price').val('');
            $('#total_price').val('');
            $('#inventory_quantity').text('0');
            initialInventory = 0;
            $('#add_item_btn').show();
            $('#edit_item_btn').hide();
        }
    });
</script>

<?php require_once 'footer.php'; ?>