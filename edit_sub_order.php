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
function gregorian_to_jalali_format($datetime)
{
    $date = date('Y-m-d', strtotime($datetime));
    list($gy, $gm, $gd) = explode('-', $date);
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

// دریافت order_id
$order_id = $_GET['order_id'] ?? '';
if (!$order_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>شناسه پیش‌فاکتور مشخص نشده است.</div></div>";
    require_once 'footer.php';
    exit;
}

// بررسی دسترسی و پیش‌فاکتور بودن
$stmt = $pdo->prepare("
    SELECT o.order_id, o.work_details_id, o.customer_name, o.total_amount, o.discount, o.final_amount, o.is_main_order, wd.work_date, wd.work_month_id
    FROM Orders o
    LEFT JOIN Work_Details wd ON o.work_details_id = wd.id
    WHERE o.order_id = ? AND o.is_main_order = 0 AND o.user_id = ?
");
$stmt->execute([$order_id, $current_user_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>پیش‌فاکتور یافت نشد یا شما دسترسی ویرایش آن را ندارید.</div></div>";
    require_once 'footer.php';
    exit;
}

// پاکسازی سشن قبلی و لود اقلام
unset($_SESSION['sub_order_items']);
unset($_SESSION['sub_discount']);
unset($_SESSION['sub_invoice_prices']);
unset($_SESSION['sub_postal_enabled']);
unset($_SESSION['sub_postal_price']);
unset($_SESSION['sub_order_id']);
$_SESSION['sub_order_items'] = [];
$_SESSION['sub_order_id'] = $order_id;

$stmt_items = $pdo->prepare("SELECT * FROM Order_Items WHERE order_id = ? ORDER BY id ASC");
$stmt_items->execute([$order_id]);
$items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
foreach ($items as $index => $item) {
    $stmt_product = $pdo->prepare("SELECT product_id FROM Products WHERE product_name = ? LIMIT 1");
    $stmt_product->execute([$item['product_name']]);
    $product = $stmt_product->fetch(PDO::FETCH_ASSOC);
    
    $_SESSION['sub_order_items'][$index] = [
        'product_id' => $product ? $product['product_id'] : null,
        'product_name' => $item['product_name'],
        'quantity' => $item['quantity'],
        'unit_price' => $item['unit_price'],
        'extra_sale' => $item['extra_sale'] ?? 0,
        'total_price' => $item['total_price'],
        'original_index' => $index
    ];
}
$_SESSION['sub_discount'] = $order['discount'];
$_SESSION['sub_invoice_prices'] = [];
$_SESSION['sub_postal_enabled'] = false;
$_SESSION['sub_postal_price'] = 0;

// لود قیمت‌های فاکتور و پستی
$stmt_invoice = $pdo->prepare("SELECT item_index, invoice_price, is_postal, postal_price FROM Invoice_Prices WHERE order_id = ?");
$stmt_invoice->execute([$order_id]);
$invoice_data = $stmt_invoice->fetchAll(PDO::FETCH_ASSOC);
foreach ($invoice_data as $row) {
    if ($row['is_postal']) {
        $_SESSION['sub_postal_enabled'] = true;
        $_SESSION['sub_postal_price'] = $row['postal_price'];
        $_SESSION['sub_invoice_prices']['postal'] = $row['postal_price'];
    } else {
        $_SESSION['sub_invoice_prices'][$row['item_index']] = $row['invoice_price'];
    }
}

// لود اطلاعات ماه کاری
$work_month_id = $order['work_details_id'] ? $order['work_month_id'] : 0;
?>

<style>
    body, .container-fluid {
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
    .order-items-table th, .order-items-table td {
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
        .col-6 { width: 50%; }
        .col-md-3, .col-md-6 { width: 50%; }
    }
    .hidden { display: none; }
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
                        <option value="">انتخاب کنید</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label for="customer_name" class="form-label">نام مشتری</label>
            <input type="text" class="form-control" id="customer_name" name="customer_name" value="<?php echo htmlspecialchars($order['customer_name']); ?>" required autocomplete="off">
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
                                    <button type="button" class="btn btn-warning btn-sm edit-item" data-index="<?= $index ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
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
            <p><strong>جمع کل:</strong> <span id="total_amount_display"><?= number_format($total_amount, 0) ?> تومان</span></p>
            <p><strong>مبلغ نهایی:</strong> <span id="final_amount_display"><?= number_format($final_amount, 0) ?> تومان</span></p>
        </div>

        <button type="button" id="save_changes_btn" class="btn btn-success mt-3">ذخیره تغییرات</button>
        <a href="orders.php" class="btn btn-secondary mt-3">بازگشت</a>
    </form>
</div>

<!-- مودال تنظیم قیمت -->
<div class="modal fade" id="invoicePriceModal" tabindex="-1" aria-labelledby="invoicePriceModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="invoicePriceModalLabel">تنظیم قیمت فاکتور</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
async function sendRequest(url, data) {
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data)
        });
        return await response.json();
    } catch (error) {
        console.error('Error:', error);
        return { success: false, message: 'خطا در ارسال درخواست رخ داد.' };
    }
}

function renderItemsTable(data) {
    const itemsTable = document.getElementById('items_table');
    const totalAmountDisplay = document.getElementById('total_amount_display');
    const finalAmountDisplay = document.getElementById('final_amount_display');
    const invoicePrices = data.invoice_prices || <?php echo json_encode($_SESSION['sub_invoice_prices'], JSON_UNESCAPED_UNICODE); ?> || {};
    const postalEnabled = data.sub_postal_enabled || <?php echo $_SESSION['sub_postal_enabled'] ? 'true' : 'false' ?>;
    const postalPrice = data.sub_postal_price || <?php echo $_SESSION['sub_postal_price'] ?>;

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
                    <th>قیمت واحد (تومان)</th>
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
                        <td>${Number(item.unit_price).toLocaleString('fa')} تومان</td>
                        <td>${Number(item.extra_sale).toLocaleString('fa')} تومان</td>
                        <td>${Number(item.total_price).toLocaleString('fa')} تومان</td>
                        <td>
                            <button type="button" class="btn btn-info btn-sm set-invoice-price" data-index="${index}">
                                تنظیم قیمت
                            </button>
                            <span class="invoice-price" data-index="${index}">
                                ${Number(invoicePrices[index] || item.total_price).toLocaleString('fa')} تومان
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
                                ${Number(invoicePrices['postal'] || postalPrice).toLocaleString('fa')} تومان
                            </span>
                        </td>
                        <td>-</td>
                    </tr>
                ` : ''}
                <tr class="total-row">
                    <td colspan="4"><strong>جمع کل</strong></td>
                    <td><strong id="total_amount">${Number(data.total_amount).toLocaleString('fa')} تومان</strong></td>
                    <td colspan="2"></td>
                </tr>
                <tr class="total-row">
                    <td><label for="discount" class="form-label">تخفیف</label></td>
                    <td><input type="number" class="form-control" id="discount" name="discount" value="${data.discount}" min="0"></td>
                    <td><strong id="final_amount">${Number(data.final_amount).toLocaleString('fa')} تومان</strong></td>
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

    totalAmountDisplay.textContent = Number(data.total_amount).toLocaleString('fa') + ' تومان';
    finalAmountDisplay.textContent = Number(data.final_amount).toLocaleString('fa') + ' تومان';
}

document.addEventListener('DOMContentLoaded', () => {
    let initialInventory = 0;
    let editingIndex = null;

    const $convertCheckbox = $('#convert_to_main');
    const $partnerSelect = $('#partner_id');
    const $workDateSelect = $('#work_date');
    const $partnerContainer = $('#partner_container');
    const $workDateContainer = $('#work_date_container');

    // مخفی کردن باکس‌ها در ابتدا
    $partnerContainer.addClass('hidden');
    $workDateContainer.addClass('hidden');

    // مدیریت چک‌باکس تبدیل
    $convertCheckbox.on('change', function() {
        if ($(this).is(':checked')) {
            $partnerContainer.removeClass('hidden');
            loadPartners();
        } else {
            $partnerContainer.addClass('hidden');
            $workDateContainer.addClass('hidden');
            $partnerSelect.empty().append('<option value="">انتخاب همکار</option>');
            $workDateSelect.empty().append('<option value="">انتخاب کنید</option>');
        }
    });

    // لود همکارها
    function loadPartners() {
        $.ajax({
            url: 'sub_order_handler.php',
            type: 'POST',
            data: { action: 'get_partners', work_month_id: '<?php echo $work_month_id; ?>' },
            success: function(response) {
                if (response.success) {
                    $partnerSelect.empty().append('<option value="">انتخاب همکار</option>');
                    response.data.partners.forEach(partner => {
                        $partnerSelect.append(`<option value="${partner.user_id}">${partner.full_name}</option>`);
                    });
                } else {
                    alert(response.message);
                }
            },
            error: function() {
                alert('خطا در دریافت همکارها.');
            }
        });
    }

    // لود روزهای کاری
    $partnerSelect.on('change', function() {
        const partnerId = $(this).val();
        const workMonthId = '<?php echo $work_month_id; ?>';
        if (partnerId && workMonthId) {
            $workDateContainer.removeClass('hidden');
            $.ajax({
                url: 'sub_order_handler.php',
                type: 'POST',
                data: { action: 'get_work_days', partner_id: partnerId, work_details_id: workMonthId },
                success: function(response) {
                    if (response.success) {
                        $workDateSelect.empty().append('<option value="">انتخاب کنید</option>');
                        response.data.work_days.forEach(date => {
                            const jalaliDate = date.split('-').reverse().join('/');
                            $workDateSelect.append(`<option value="${date}">${jalaliDate}</option>`);
                        });
                    } else {
                        alert(response.message);
                    }
                },
                error: function() {
                    alert('خطا در دریافت روزهای کاری.');
                }
            });
        } else {
            $workDateContainer.addClass('hidden');
            $workDateSelect.empty().append('<option value="">انتخاب کنید</option>');
        }
    });

    $('#product_name').on('input', function () {
        let query = $(this).val();
        const work_details_id = $workDateSelect.val() || '<?php echo $order['work_details_id']; ?>';
        const partner_id = $convertCheckbox.is(':checked') ? $partnerSelect.val() : null;
        if (query.length >= 3) {
            $.ajax({
                url: 'get_sub_order_products.php',
                type: 'POST',
                data: { query: query, work_details_id: work_details_id, partner_id: partner_id },
                success: function (response) {
                    if (response.trim() === '') {
                        $('#product_suggestions').hide();
                    } else {
                        $('#product_suggestions').html(response).show();
                    }
                },
                error: function (xhr, status, error) {
                    console.error('AJAX Error:', status, error);
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
        if (typeof product === 'string') {
            product = JSON.parse(product);
        }
        $('#product_name').val(product.product_name).prop('disabled', false);
        $('#product_id').val(product.product_id);
        $('#unit_price').val(product.unit_price);
        $('#extra_sale').val(0);
        $('#adjusted_price').val(Number(product.unit_price).toLocaleString('fa') + ' تومان');
        $('#total_price').val((1 * product.unit_price).toLocaleString('fa') + ' تومان');
        $('#product_suggestions').hide();

        initialInventory = product.inventory || 0;
        $('#inventory_quantity').text(initialInventory);
        $('#quantity').val(1);
        updateInventoryDisplay();

        $('#quantity').focus();
    });

    $('#quantity, #extra_sale').on('input', function () {
        let quantity = Number($('#quantity').val()) || 0;
        let unit_price = Number($('#unit_price').val()) || 0;
        let extra_sale = Number($('#extra_sale').val()) || 0;
        let adjusted_price = unit_price + extra_sale;
        let total = quantity * adjusted_price;
        $('#adjusted_price').val(adjusted_price.toLocaleString('fa') + ' تومان');
        $('#total_price').val(total.toLocaleString('fa') + ' تومان');
        updateInventoryDisplay();
    });

    function updateInventoryDisplay() {
        let quantity = Number($('#quantity').val()) || 0;
        let items = <?php echo json_encode($_SESSION['sub_order_items'], JSON_UNESCAPED_UNICODE); ?>;
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

    document.getElementById('add_item_btn').addEventListener('click', async () => {
        const customer_name = document.getElementById('customer_name').value;
        const product_id = document.getElementById('product_id').value;
        const quantity = Number(document.getElementById('quantity').value) || 0;
        const unit_price = Number(document.getElementById('unit_price').value) || 0;
        const extra_sale = Number(document.getElementById('extra_sale').value) || 0;
        const discount = document.getElementById('discount')?.value || 0;
        const work_details_id = $workDateSelect.val() || '<?php echo $order['work_details_id']; ?>';
        const partner_id = $convertCheckbox.is(':checked') ? $partnerSelect.val() : '<?php echo $current_user_id; ?>';

        if (!customer_name || !product_id || quantity <= 0 || unit_price <= 0 || ($convertCheckbox.is(':checked') && (!partner_id || !work_details_id))) {
            alert('لطفاً همه فیلدها را پر کنید و تعداد را بیشتر از صفر وارد کنید.');
            return;
        }

        const items = <?php echo json_encode($_SESSION['sub_order_items'], JSON_UNESCAPED_UNICODE); ?>;
        if (items.some(item => item.product_id === product_id && (editingIndex === null || items[editingIndex].product_id !== product_id))) {
            alert('این محصول قبلاً در پیش‌فاکتور ثبت شده است.');
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
            order_id: '<?php echo $order_id; ?>'
        };

        const addResponse = await sendRequest('sub_order_handler.php', data);
        if (addResponse.success) {
            renderItemsTable(addResponse.data);
            resetForm();
        } else {
            alert(addResponse.message);
        }
    });

    document.getElementById('edit_item_btn').addEventListener('click', async () => {
        const customer_name = document.getElementById('customer_name').value;
        const product_id = document.getElementById('product_id').value;
        const quantity = Number(document.getElementById('quantity').value) || 0;
        const unit_price = Number(document.getElementById('unit_price').value) || 0;
        const extra_sale = Number(document.getElementById('extra_sale').value) || 0;
        const discount = document.getElementById('discount').value || 0;

        if (!quantity || quantity <= 0) {
            alert('لطفاً تعداد را بیشتر از صفر وارد کنید.');
            return;
        }

        const data = {
            action: 'edit_sub_item',
            customer_name,
            product_id,
            quantity,
            unit_price,
            extra_sale,
            discount,
            index: editingIndex,
            order_id: '<?php echo $order_id; ?>',
            partner_id: $convertCheckbox.is(':checked') ? $partnerSelect.val() : '<?php echo $current_user_id; ?>'
        };

        const editResponse = await sendRequest('sub_order_handler.php', data);
        if (editResponse.success) {
            renderItemsTable(editResponse.data);
            resetForm();
        } else {
            alert(editResponse.message);
        }
    });

    document.getElementById('items_table').addEventListener('click', async (e) => {
        if (e.target.closest('.delete-item')) {
            const index = e.target.closest('.delete-item').getAttribute('data-index');
            if (confirm('آیا از حذف این محصول مطمئن هستید؟')) {
                const data = {
                    action: 'delete_sub_item',
                    index: index,
                    order_id: '<?php echo $order_id; ?>',
                    partner_id: $convertCheckbox.is(':checked') ? $partnerSelect.val() : '<?php echo $current_user_id; ?>'
                };

                const response = await sendRequest('sub_order_handler.php', data);
                if (response.success) {
                    renderItemsTable(response.data);
                    resetForm();
                } else {
                    alert(response.message);
                }
            }
        } else if (e.target.closest('.edit-item')) {
            const index = e.target.closest('.edit-item').getAttribute('data-index');
            editingIndex = index;
            const items = <?php echo json_encode($_SESSION['sub_order_items'], JSON_UNESCAPED_UNICODE); ?>;
            const item = items[index];

            $('#product_name').val(item.product_name).prop('disabled', true);
            $('#product_id').val(item.product_id);
            $('#quantity').val(item.quantity);
            $('#unit_price').val(item.unit_price);
            $('#extra_sale').val(item.extra_sale ?? 0);
            $('#adjusted_price').val((Number(item.unit_price) + Number(item.extra_sale ?? 0)).toLocaleString('fa') + ' تومان');
            $('#total_price').val((item.quantity * (Number(item.unit_price) + Number(item.extra_sale ?? 0))).toLocaleString('fa') + ' تومان');

            const partner_id = $convertCheckbox.is(':checked') ? $partnerSelect.val() : '<?php echo $current_user_id; ?>';
            $.ajax({
                url: 'get_sub_order_products.php',
                type: 'POST',
                data: {
                    query: item.product_name,
                    work_details_id: '<?php echo $order['work_details_id']; ?>',
                    partner_id: partner_id
                },
                success: function (response) {
                    const suggestions = $(response);
                    const productSuggestion = suggestions.filter(`[data-product-id="${item.product_id}"]`);
                    if (productSuggestion.length) {
                        const product = JSON.parse(productSuggestion.data('product'));
                        initialInventory = product.inventory || 0;
                        updateInventoryDisplay();
                    } else {
                        $('#inventory_quantity').text('0');
                        alert('خطا در دریافت موجودی.');
                    }
                },
                error: function () {
                    $('#inventory_quantity').text('0');
                    alert('خطا در دریافت موجودی.');
                }
            });

            $('#add_item_btn').hide();
            $('#edit_item_btn').show();
        } else if (e.target.closest('.set-invoice-price')) {
            const index = e.target.closest('.set-invoice-price').getAttribute('data-index');
            $('#invoice_price_index').val(index);
            const currentPrice = <?php echo json_encode($_SESSION['sub_invoice_prices'], JSON_UNESCAPED_UNICODE); ?>[index] || '';
            $('#invoice_price').val(currentPrice);
            $('#invoicePriceModal').modal('show');
        }
    });

    document.getElementById('save_invoice_price').addEventListener('click', async () => {
        const index = $('#invoice_price_index').val();
        const invoicePrice = $('#invoice_price').val();

        if (invoicePrice === '' || invoicePrice < 0) {
            alert('لطفاً یک قیمت معتبر وارد کنید.');
            return;
        }

        const data = {
            action: 'set_sub_invoice_price',
            index: index,
            invoice_price: invoicePrice,
            order_id: '<?php echo $order_id; ?>'
        };

        const response = await sendRequest('sub_order_handler.php', data);
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
                enable_postal: enablePostal,
                order_id: '<?php echo $order_id; ?>'
            };

            const response = await sendRequest('sub_order_handler.php', data);
            if (response.success) {
                renderItemsTable(response.data);
            } else {
                alert(response.message);
            }
        }
    });

    document.getElementById('items_table').addEventListener('input', async (e) => {
        if (e.target.id === 'discount') {
            const discount = e.target.value || 0;
            const data = {
                action: 'update_sub_discount',
                discount,
                order_id: '<?php echo $order_id; ?>'
            };

            const response = await sendRequest('sub_order_handler.php', data);
            if (response.success) {
                renderItemsTable(response.data);
            } else {
                alert(response.message);
            }
        }
    });

    document.getElementById('save_changes_btn').addEventListener('click', async () => {
        const customer_name = document.getElementById('customer_name').value;
        const work_details_id = $workDateSelect.val() || '<?php echo $order['work_details_id']; ?>';
        const partner_id = $convertCheckbox.is(':checked') ? $partnerSelect.val() : '<?php echo $current_user_id; ?>';
        const discount = document.getElementById('discount')?.value || 0;
        const convert_to_main = document.getElementById('convert_to_main').checked;

        if (!customer_name || !work_details_id || ($convertCheckbox.is(':checked') && !partner_id)) {
            alert('لطفاً همه فیلدها را پر کنید.');
            return;
        }

        const data = {
            action: 'finalize_sub_order',
            order_id: '<?php echo $order_id; ?>',
            work_details_id,
            customer_name,
            discount,
            partner_id,
            convert_to_main
        };

        const response = await sendRequest('sub_order_handler.php', data);
        if (response.success) {
            alert(response.message);
            window.location.href = response.data.redirect;
        } else {
            alert(response.message);
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
        editingIndex = null;
        $('#add_item_btn').show();
        $('#edit_item_btn').hide();
    }
});
</script>

<?php require_once 'footer.php'; ?>