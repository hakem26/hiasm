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

$work_month_id = $_GET['work_month_id'] ?? '';
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

$_SESSION['sub_order_items'] = $_SESSION['sub_order_items'] ?? [];
$_SESSION['sub_discount'] = $_SESSION['sub_discount'] ?? 0;
$_SESSION['sub_invoice_prices'] = $_SESSION['sub_invoice_prices'] ?? ['postal' => 50000];
$_SESSION['sub_postal_enabled'] = $_SESSION['sub_postal_enabled'] ?? false;
$_SESSION['sub_postal_price'] = $_SESSION['sub_postal_price'] ?? 50000;
$_SESSION['is_sub_order_in_progress'] = true;
?>

<style>
    body, .container-fluid { overflow-x: hidden !important; }
    .table-wrapper { width: 100%; overflow-x: auto !important; overflow-y: visible; -webkit-overflow-scrolling: touch; }
    .order-items-table { width: 100%; min-width: 800px; border-collapse: collapse; }
    .order-items-table th, .order-items-table td { vertical-align: middle !important; white-space: nowrap !important; padding: 8px; min-width: 120px; }
    .order-items-table .total-row td { font-weight: bold; }
    .order-items-table .total-row input#discount { width: 150px; margin: 0 auto; }
    @media (max-width: 991px) { .col-6, .col-md-3, .col-md-6 { width: 50%; } }
    .hidden { display: none; }
</style>

<div class="container-fluid mt-5">
    <h5 class="card-title mb-4">ثبت پیش‌فاکتور</h5>

    <form id="add-sub-order-form">
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

        <div class="table-wrapper" id="items_table">
            <?php if (!empty($_SESSION['sub_order_items'])): ?>
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
                                    <?php foreach ($_SESSION['sub_order_items'] as $index => $item): ?>
                                                    <tr id="item_row_<?= $index ?>">
                                                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                                                        <td><?= $item['quantity'] ?></td>
                                                        <td><?= number_format($item['unit_price'], 0) ?></td>
                                                        <td><?= number_format($item['extra_sale'], 0) ?></td>
                                                        <td><?= number_format($item['total_price'], 0) ?></td>
                                                        <td>
                                                            <button type="button" class="btn btn-info btn-sm set-invoice-price" data-index="<?= $index ?>">
                                                                تنظیم قیمت
                                                            </button>
                                                            <span class="invoice-price" data-index="<?= $index ?>">
                                                                <?= number_format($_SESSION['sub_invoice_prices'][$index] ?? $item['total_price'], 0) ?> تومان
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <button type="button" class="btn btn-danger btn-sm delete-item" data-index="<?= $index ?>">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if ($_SESSION['sub_postal_enabled']): ?>
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
                                                                <?= number_format($_SESSION['sub_invoice_prices']['postal'] ?? $_SESSION['sub_postal_price'], 0) ?> تومان
                                                            </span>
                                                        </td>
                                                        <td>-</td>
                                                    </tr>
                                    <?php endif; ?>
                                    <?php
                                    $total_amount = array_sum(array_column($_SESSION['sub_order_items'], 'total_price'));
                                    $discount = $_SESSION['sub_discount'];
                                    $final_amount = $total_amount - $discount + ($_SESSION['sub_postal_enabled'] ? $_SESSION['sub_postal_price'] : 0);
                                    ?>
                                    <tr class="total-row">
                                        <td colspan="4"><strong>جمع کل</strong></td>
                                        <td><strong id="total_amount"><?= number_format($total_amount, 0) ?> تومان</strong></td>
                                        <td colspan="2"></td>
                                    </tr>
                                    <tr class="total-row">
                                        <td><label for="discount" class="form-label">تخفیف</label></td>
                                        <td><input type="number" class="form-control" id="discount" name="discount" value="<?= $discount ?>" min="0"></td>
                                        <td><strong id="final_amount"><?= number_format($final_amount, 0) ?> تومان</strong></td>
                                        <td colspan="2"></td>
                                    </tr>
                                    <tr class="total-row">
                                        <td><label for="postal_option" class="form-label">پست سفارش</label></td>
                                        <td><input type="checkbox" id="postal_option" name="postal_option" <?= $_SESSION['sub_postal_enabled'] ? 'checked' : '' ?>></td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tbody>
                            </table>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <p><strong>جمع کل:</strong> <span id="total_amount_display"><?= number_format($total_amount ?? 0, 0) ?> تومان</span></p>
            <p><strong>مبلغ نهایی:</strong> <span id="final_amount_display"><?= number_format($final_amount ?? 0, 0) ?> تومان</span></p>
        </div>

        <button type="button" id="finalize_order_btn" class="btn btn-success mt-3">ثبت پیش‌فاکتور</button>
        <a href="orders.php" class="btn btn-secondary mt-3">بازگشت</a>
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
        console.log('Raw response:', text); // برای دیباگ
        try {
            const json = JSON.parse(text);
            if (!json.success && !json.message) {
                json.message = 'خطای ناشناخته در سرور.';
            }
            return json;
        } catch (e) {
            console.error('JSON Parse Error:', e, 'Response:', text);
            return { success: false, message: 'خطا در پردازش پاسخ سرور: ' + text.substring(0, 100) };
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
                            <button type="button" class="btn btn-warning btn-sm edit-item" data-index="${index}">
                                <i class="fas fa-edit"></i>
                            </button>
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
    let quantity = Number($('#quantity').val()) || 0;
    let unit_price = Number($('#unit_price').val()) || 0;
    let extra_sale = Number($('#extra_sale').val()) || 0;
    let adjusted_price = unit_price + extra_sale;
    let total_price = quantity * adjusted_price;

    $('#adjusted_price').val(adjusted_price.toLocaleString('fa-IR') + ' تومان');
    $('#total_price').val(total_price.toLocaleString('fa-IR') + ' تومان');
    updateInventoryDisplay();
}

function updateInventoryDisplay() {
    let quantity = Number($('#quantity').val()) || 0;
    let items = <?= json_encode($_SESSION['sub_order_items'] ?? []) ?>;
    let product_id = $('#product_id').val();
    let totalUsed = 0;

    items.forEach(item => {
        if (item.product_id === product_id && (editingIndex === null || item !== items[editingIndex])) {
            totalUsed += parseInt(item.quantity);
        }
    });

    let remainingInventory = initialInventory - totalUsed - (editingIndex === null ? quantity : 0);
    $('#inventory_quantity').text(remainingInventory);
}

$(document).ready(function() {
    $('#convert_to_main').on('change', function() {
        const isChecked = $(this).is(':checked');
        $('#partner_container').toggleClass('hidden', !isChecked);
        $('#work_date_container').toggleClass('hidden', !isChecked);

        if (isChecked) {
            const work_month_id = '<?= $work_month_id ?>';
            $.ajax({
                url: 'sub_order_handler.php',
                type: 'POST',
                data: { action: 'get_partners', work_month_id: work_month_id },
                success: function(response) {
                    if (response.success) {
                        let options = '<option value="">انتخاب همکار</option>';
                        response.data.partners.forEach(partner => {
                            options += `<option value="${partner.user_id}">${partner.full_name}</option>`;
                        });
                        $('#partner_id').html(options);
                    } else {
                        alert(response.message);
                        $('#convert_to_main').prop('checked', false);
                        $('#partner_container').addClass('hidden');
                        $('#work_date_container').addClass('hidden');
                    }
                },
                error: function() {
                    alert('خطا در دریافت لیست همکاران.');
                    $('#convert_to_main').prop('checked', false);
                    $('#partner_container').addClass('hidden');
                    $('#work_date_container').addClass('hidden');
                }
            });
        } else {
            $('#partner_id').html('<option value="">انتخاب همکار</option>');
            $('#work_date').html('<option value="">انتخاب تاریخ</option>');
        }
    });

    $('#partner_id').on('change', function() {
        const partner_id = $(this).val();
        const work_month_id = '<?= $work_month_id ?>';
        if (partner_id) {
            $.ajax({
                url: 'sub_order_handler.php',
                type: 'POST',
                data: { action: 'get_work_days', partner_id: partner_id, work_month_id: work_month_id },
                success: function(response) {
                    if (response.success) {
                        let options = '<option value="">انتخاب تاریخ</option>';
                        response.data.work_days.forEach(day => {
                            options += `<option value="${day.id}">${day.jalali_date}</option>`;
                        });
                        $('#work_date').html(options);
                    } else {
                        alert(response.message);
                        $('#work_date').html('<option value="">انتخاب تاریخ</option>');
                    }
                },
                error: function() {
                    alert('خطا در دریافت روزهای کاری.');
                    $('#work_date').html('<option value="">انتخاب تاریخ</option>');
                }
            });
        } else {
            $('#work_date').html('<option value="">انتخاب تاریخ</option>');
        }
    });

    $('#product_name').on('input', function() {
        let query = $(this).val();
        if (query.length >= 3) {
            $.ajax({
                url: 'get_products.php',
                type: 'POST',
                data: { query: query },
                success: function(response) {
                    if (response.trim() === '') {
                        $('#product_suggestions').hide();
                    } else {
                        $('#product_suggestions').html(response).show();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    $('#product_suggestions').hide();
                }
            });
        } else {
            $('#product_suggestions').hide();
        }
    });

    $(document).on('click', '.product-suggestion', function(e) {
        e.preventDefault();
        let product = $(this).data('product');
        if (typeof product === 'string') {
            product = JSON.parse(product);
        }
        $('#product_name').val(product.product_name).prop('disabled', false);
        $('#product_id').val(product.product_id);
        $('#unit_price').val(product.unit_price);
        $('#extra_sale').val(0);
        $('#adjusted_price').val(Number(product.unit_price).toLocaleString('fa-IR') + ' تومان');
        $('#total_price').val((1 * product.unit_price).toLocaleString('fa-IR') + ' تومان');
        $('#product_suggestions').hide();

        $.ajax({
            url: 'get_inventory.php',
            type: 'POST',
            data: {
                product_id: product.product_id,
                user_id: '<?= $current_user_id ?>',
                is_sub_order: 1 // نشانه برای پیش‌فاکتور
            },
            success: function(response) {
                if (response.success) {
                    initialInventory = response.data.inventory || 0;
                    $('#inventory_quantity').text(initialInventory);
                    $('#quantity').val(1);
                    updateInventoryDisplay();
                } else {
                    $('#inventory_quantity').text('0');
                    alert('خطا در دریافت موجودی: ' + response.message);
                }
            },
            error: function() {
                $('#inventory_quantity').text('0');
                alert('خطا در دریافت موجودی.');
            }
        });

        $('#quantity').focus();
        updatePrices();
    });

    $('#quantity, #unit_price, #extra_sale').on('input', updatePrices);

    $('#add_item_btn').on('click', async function() {
        const customer_name = $('#customer_name').val().trim();
        const product_id = $('#product_id').val();
        const quantity = Number($('#quantity').val()) || 0;
        const unit_price = Number($('#unit_price').val()) || 0;
        const extra_sale = Number($('#extra_sale').val()) || 0;
        const discount = Number($('#discount').val()) || 0;
        const product_name = $('#product_name').val().trim();

        if (!customer_name || !product_id || !product_name || quantity <= 0 || unit_price <= 0) {
            alert('لطفاً همه فیلدها را پر کنید و تعداد را بیشتر از صفر کنید.');
            return;
        }

        const items = <?= json_encode($_SESSION['sub_order_items'] ?? []) ?>;
        if (editingIndex === null && items.some(item => item.product_id === product_id)) {
            alert('این محصول قبلاً در پیش‌فاکتور ثبت شده است.');
            return;
        }

        const data = {
            action: editingIndex !== null ? 'edit_sub_item' : 'add_sub_item',
            customer_name,
            product_id,
            product_name,
            quantity,
            unit_price,
            extra_sale,
            discount,
            work_details_id: $('#work_date').val(),
            partner_id: $('#partner_id').val() || '<?= $current_user_id ?>',
            index: editingIndex
        };

        const response = await sendRequest('sub_order_handler.php', data);
        if (response.success) {
            renderItemsTable(response.data);
            resetForm();
        } else {
            alert(response.message);
        }
    });

    $('#edit_item_btn').on('click', async function() {
        await $('#add_item_btn').trigger('click');
    });

    $(document).on('click', '.edit-item', function() {
        const index = $(this).data('index');
        const items = <?= json_encode($_SESSION['sub_order_items'] ?? []) ?>;
        const item = items[index];

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
            alert(response.message);
        }
    });

    $(document).on('click', '.set-invoice-price', function() {
        const index = $(this).data('index');
        const currentPrice = $(`.invoice-price[data-index="${index}"]`).text().replace(/[^\d]/g, '');
        $('#invoice_price').val(currentPrice);
        $('#invoice_price_index').val(index);
        $('#invoicePriceModal').modal('show');
    });

    $('#save_invoice_price').on('click', async function() {
        const index = $('#invoice_price_index').val();
        const invoice_price = Number($('#invoice_price').val()) || 0;

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
            alert(response.message);
        }
    });

    $('#postal_option').on('change', async function() {
        const enable_postal = $(this).is(':checked');
        const response = await sendRequest('sub_order_handler.php', {
            action: 'set_sub_postal_option',
            enable_postal: enable_postal
        });

        if (response.success) {
            renderItemsTable(response.data);
        } else {
            alert(response.message);
        }
    });

    $('#discount').on('change', async function() {
        const discount = Number($(this).val()) || 0;
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
            alert(response.message);
        }
    });

    $('#save_sub_order_btn').on('click', async function() {
        const customer_name = $('#customer_name').val().trim();
        const work_details_id = $('#work_date').val();
        const partner_id = $('#partner_id').val() || '<?= $current_user_id ?>';
        const discount = Number($('#discount').val()) || 0;
        const convert_to_main = $('#convert_to_main').is(':checked') ? 1 : 0;
        const work_month_id = '<?= $work_month_id ?>';

        if (!customer_name) {
            alert('لطفاً نام مشتری را وارد کنید.');
            return;
        }

        if (convert_to_main && (!partner_id || !work_details_id)) {
            alert('لطفاً همکار و تاریخ کاری را انتخاب کنید.');
            return;
        }

        const data = {
            action: 'finalize_sub_order',
            customer_name,
            work_details_id,
            partner_id,
            discount,
            convert_to_main,
            work_month_id
        };

        console.log('Sending finalize_sub_order request:', data); // برای دیباگ
        const response = await sendRequest('sub_order_handler.php', data);
        console.log('Finalize response:', response); // برای دیباگ
        if (response.success) {
            window.location.href = response.data.redirect;
        } else {
            alert('خطا: ' + response.message);
        }
    });

    // بارگذاری اولیه آیتم‌ها
sendRequest('sub_order_handler.php', { action: 'get_items' }).then(response => {
    console.log('Get items response:', response); // برای دیباگ
    if (response.success) {
        renderItemsTable(response.data);
    } else {
        console.error('Get items failed:', response.message);
        // اگر آیتم‌ها لود نشدن، جدول خالی نشون بده
        renderItemsTable({ items: [], total_amount: 0, discount: 0, final_amount: 0, invoice_prices: {}, sub_postal_enabled: false, sub_postal_price: 50000 });
    }
});
});
</script>
</script>

<?php require_once 'footer.php'; ?>