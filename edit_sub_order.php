<?php
// Start session and check authentication
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Include dependencies
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

// Convert Gregorian date to Jalali format
function gregorian_to_jalali_format($gregorian_date) {
    if (!$gregorian_date || !preg_match('/^\d{4}-\d{2}-\d{2}/', $gregorian_date)) {
        return 'نامشخص';
    }
    try {
        list($gy, $gm, $gd) = explode('-', $gregorian_date);
        $gy = (int)$gy;
        $gm = (int)$gm;
        $gd = (int)$gd;
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

// Restrict admin access
$is_admin = ($_SESSION['role'] === 'admin');
if ($is_admin) {
    header("Location: orders.php");
    exit;
}
$current_user_id = $_SESSION['user_id'];

// Validate sub_order_id and work_month_id
$sub_order_id = filter_input(INPUT_GET, 'sub_order_id', FILTER_SANITIZE_STRING);
$work_month_id = filter_input(INPUT_GET, 'work_month_id', FILTER_VALIDATE_INT);
$missing_params = [];
if (!$sub_order_id || !ctype_digit($sub_order_id)) {
    $missing_params[] = 'sub_order_id';
}
if (!$work_month_id) {
    $missing_params[] = 'work_month_id';
}
if (!empty($missing_params)) {
    $error_message = 'پارامترهای نامعتبر: ' . implode(', ', $missing_params) . ' یافت نشد یا نامعتبر است.';
    error_log("Invalid parameters in edit_sub_order.php: " . $error_message . " | URL: " . $_SERVER['REQUEST_URI']);
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>$error_message</div></div>";
    require_once 'footer.php';
    exit;
}

// Fetch sub-order details
try {
    $stmt = $pdo->prepare("SELECT * FROM Sub_Orders WHERE sub_order_id = ? AND partner_id IN (SELECT partner_id FROM Partners WHERE user_id1 = ? OR user_id2 = ?)");
    $stmt->execute([$sub_order_id, $current_user_id, $current_user_id]);
    $sub_order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sub_order) {
        error_log("Sub-order not found or access denied: sub_order_id=$sub_order_id, user_id=$current_user_id");
        echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>پیش‌فاکتور یافت نشد یا دسترسی ندارید.</div></div>";
        require_once 'footer.php';
        exit;
    }
} catch (PDOException $e) {
    error_log("Database error in edit_sub_order.php: " . $e->getMessage());
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>خطای دیتابیس: " . htmlspecialchars($e->getMessage()) . "</div></div>";
    require_once 'footer.php';
    exit;
}

// Fetch work details for the current user
try {
    $stmt = $pdo->prepare("
        SELECT id, work_date
        FROM Work_Details
        WHERE work_month_id = ? AND partner_id IN (
            SELECT partner_id FROM Partners WHERE user_id1 = ? OR user_id2 = ?
        )
        ORDER BY work_date DESC
    ");
    $stmt->execute([$work_month_id, $current_user_id, $current_user_id]);
    $work_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($work_details)) {
        error_log("No work details found for work_month_id=$work_month_id, user_id=$current_user_id");
        echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>هیچ تاریخ کاری برای این ماه یافت نشد.</div></div>";
        require_once 'footer.php';
        exit;
    }
} catch (PDOException $e) {
    error_log("Database error fetching work details: " . $e->getMessage());
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>خطای دیتابیس: " . htmlspecialchars($e->getMessage()) . "</div></div>";
    require_once 'footer.php';
    exit;
}

// Initialize session variables
$_SESSION['sub_order_items'] = [];
$_SESSION['sub_discount'] = $sub_order['discount'] ?? 0;
$_SESSION['sub_invoice_prices'] = ['postal' => 50000]; // Reset to avoid conflicts
$_SESSION['sub_postal_enabled'] = $sub_order['sub_postal_enabled'] ?? false;
$_SESSION['sub_postal_price'] = $sub_order['sub_postal_price'] ?? 50000;
$_SESSION['is_sub_order_in_progress'] = true;

// Load existing items
try {
    $stmt = $pdo->prepare("SELECT * FROM Sub_Order_Items WHERE sub_order_id = ?");
    $stmt->execute([$sub_order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $item) {
        $_SESSION['sub_order_items'][] = [
            'product_id' => $item['product_id'] ?? 0, // Handle missing product_id
            'product_name' => $item['product_name'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'extra_sale' => $item['extra_sale'],
            'total_price' => $item['total_price']
        ];
    }
} catch (PDOException $e) {
    error_log("Database error loading sub-order items: " . $e->getMessage());
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>خطای دیتابیس در لود آیتم‌ها: " . htmlspecialchars($e->getMessage()) . "</div></div>";
    require_once 'footer.php';
    exit;
}
?>

<style>
    body, .container-fluid { overflow-x: hidden !important; }
    .table-wrapper { width: 100%; overflow-x: auto !important; overflow-y: visible; -webkit-overflow-scrolling: touch; }
    .order-items-table { width: 100%; min-width: 800px; border-collapse: collapse; }
    .order-items-table th, .order-items-table td { vertical-align: middle !important; white-space: nowrap !important; padding: 8px; min-width: 120px; }
    .order-items-table .total-row td { font-weight: bold; }
    .order-items-table .total-row input#discount { width: 150px; margin: 0 auto; }
    @media (max-width: 991px) { .col-6, .col-md-3, .col-md-6 { width: 50%; } }
    .list-group { max-height: 200px; overflow-y: auto; }
</style>

<div class="container-fluid mt-5">
    <h5 class="card-title mb-4">ویرایش پیش‌فاکتور</h5>

    <form id="edit-sub-order-form">
        <input type="hidden" name="sub_order_id" value="<?= htmlspecialchars($sub_order_id) ?>">
        <div class="mb-3">
            <label for="customer_name" class="form-label">نام مشتری</label>
            <input type="text" class="form-control" id="customer_name" name="customer_name" value="<?= htmlspecialchars($sub_order['customer_name']) ?>" required autocomplete="off">
        </div>

        <div class="mb-3">
            <label for="work_date" class="form-label">تاریخ کاری</label>
            <select id="work_date" name="work_date" class="form-select" required>
                <option value="">انتخاب تاریخ</option>
                <?php foreach ($work_details as $detail): ?>
                    <option value="<?= htmlspecialchars($detail['id']) ?>" <?= $detail['id'] == $sub_order['work_details_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(gregorian_to_jalali_format($detail['work_date'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
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

        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" id="convert_to_main" name="convert_to_main">
            <label class="form-check-label" for="convert_to_main">تبدیل به فاکتور اصلی</label>
        </div>

        <div class="mb-3" id="partner_container" style="display: none;">
            <label for="partner_id" class="form-label">همکار</label>
            <select id="partner_id" name="partner_id" class="form-select">
                <option value="">انتخاب همکار</option>
            </select>
        </div>

        <div class="mb-3" id="work_date_main" style="display: none;">
            <label for="work_date_main_select" class="form-label">تاریخ کاری</label>
            <select id="work_date_main_select" name="work_date_main" class="form-select">
                <option value="">انتخاب تاریخ</option>
            </select>
        </div>

        <button type="button" id="save_sub_order_btn" class="btn btn-success mt-3">ذخیره پیش‌فاکتور</button>
        <a href="orders.php?work_month_id=<?= htmlspecialchars($work_month_id) ?>" class="btn btn-secondary mt-3">بازگشت</a>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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
        console.log('Raw response:', text);
        try {
            const json = JSON.parse(text);
            if (!json.success && !json.message) {
                json.message = 'خطای ناشناخته در سرور.';
            }
            return json;
        } catch (e) {
            console.error('JSON Parse Error:', e, 'Response:', text);
            alert('خطا در پردازش پاسخ سرور: ' + text.substring(0, 100));
            return { success: false, message: 'خطا در پردازش پاسخ سرور: ' + text };
        }
    } catch (error) {
        console.error('SendRequest Error:', error);
        alert('خطا در ارسال درخواست: ' + error.message);
        return { success: false, message: 'خطا در ارسال درخواست: ' + error.message };
    }
}

function renderItemsTable(data) {
    const itemsTable = document.getElementById('items_table');
    const totalAmountDisplay = document.getElementById('total_amount_display');
    const finalAmountDisplay = document.getElementById('final_amount_display');
    const invoicePrices = data?.invoice_prices || {};
    const postalEnabled = data?.sub_postal_enabled || false;
    const postalPrice = data?.sub_postal_price || 50000;

    itemsTable.innerHTML = '';
    if (!data?.items || data.items.length === 0) {
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
                        ${Number(invoicePrices[index] || item.total_price).toLocaleString('fa-IR')} تومان
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
    tableHtml += `
            <tr class="total-row">
                <td colspan="4"><strong>جمع کل</strong></td>
                <td><strong id="total_amount">${Number(data.total_amount).toLocaleString('fa-IR')} تومان</strong></td>
                <td colspan="2"></td>
            </tr>
            <tr class="total-row">
                <td><label for="discount">تخفیف</label></td>
                <td><input type="number" class="form-control" id="discount" name="discount" value="${data.discount}" min="0"></td>
                <td><strong id="final_amount">${Number(data.final_amount).toLocaleString('fa-IR')} تومان</strong></td>
                <td colspan="2"></td>
            </tr>
            <tr class="total-row">
                <td><label for="postal_option">پست سفارش</label></td>
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

$(document).ready(function() {
    console.log('Document ready');

    $('#convert_to_main').on('change', function() {
        if ($(this).is(':checked')) {
            $('#partner_container').show();
            sendRequest('sub_order_handler.php', {
                action: 'get_related_partners',
                work_month_id: '<?= $work_month_id ?>'
            }).then(response => {
                if (response.success) {
                    response.data.partners.forEach(partner => {
                        $('#partner_id').append(`<option value="${partner.user_id}">${partner.full_name}</option>`);
                    });
                } else {
                    alert(response.message);
                }
            }).catch(error => {
                console.error('Error fetching partners:', error);
                alert('خطا در لود همکارها: ' + error.message);
            });
        } else {
            $('#partner_container, #work_date_main').hide();
            $('#partner_id, #work_date_main_select').empty().append('<option value="">انتخاب</option>');
        }
    });

    $('#partner_id').on('change', function() {
        const partnerId = $(this).val();
        if (partnerId) {
            $('#work_date_main').show();
            sendRequest('sub_order_handler.php', {
                action: 'get_partner_work_days',
                partner_id: partnerId,
                work_month_id: '<?= $work_month_id ?>'
            }).then(response => {
                if (response.success) {
                    $('#work_date_main_select').empty().append('<option value="">انتخاب تاریخ</option>');
                    response.data.work_days.forEach(day => {
                        $('#work_date_main_select').append(`<option value="${day.id}">${day.jalali_date}</option>`);
                    });
                } else {
                    alert(response.message);
                }
            }).catch(error => {
                console.error('Error fetching work days:', error);
                alert('خطا در لود تاریخ‌های کاری: ' + error.message);
            });
        } else {
            $('#work_date_main').hide();
            $('#work_date_main_select').empty().append('<option value="">انتخاب تاریخ</option>');
        }
    });

    $('#product_name').on('input', function() {
        const query = $(this).val().trim();
        const work_details_id = $('#work_date').val();
        const partner_id = '<?= $current_user_id ?>';
        if (query.length >= 3) {
            $.post('get_sub_order_products.php', { query, work_details_id, partner_id }, function(response) {
                console.log('Product suggestions response:', response);
                if (response.trim() === '' || response.includes('محصولی یافت نشد')) {
                    $('#product_suggestions').hide();
                } else {
                    $('#product_suggestions').html(response).show();
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                console.error('Product search error:', textStatus, errorThrown);
                $('#product_suggestions').hide();
                alert('خطا در جستجوی محصولات: ' + textStatus);
            });
        } else {
            $('#product_suggestions').hide();
        }
    });

    $(document).on('click', '.product-suggestion', function(e) {
        e.preventDefault();
        let product = $(this).data('product');
        if (typeof product === 'string') {
            try {
                product = JSON.parse(product);
            } catch (e) {
                console.error('Product parse error:', e);
                alert('خطا در انتخاب محصول: ' + e.message);
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

        sendRequest('get_inventory.php', {
            product_id: product.product_id,
            user_id: '<?= $current_user_id ?>',
            is_sub_order: 1
        }).then(response => {
            if (response.success) {
                initialInventory = response.data.inventory || 0;
                $('#inventory_quantity').text(initialInventory);
                $('#quantity').val(1);
            } else {
                $('#inventory_quantity').text('0');
                alert('خطا در دریافت موجودی: ' + response.message);
            }
        }).catch(error => {
            console.error('Get inventory error:', error);
            $('#inventory_quantity').text('0');
            alert('خطا در دریافت موجودی: ' + error.message);
        });

        $('#quantity').focus();
        updatePrices();
    });

    $('#quantity, #unit_price, #extra_sale').on('input', updatePrices);

    $('#add_item_btn').on('click', async function() {
        const customerName = $('#customer_name').val().trim();
        const product_id = $('#product_id').val();
        const quantity = Number($('#quantity').val()) || 0;
        const unit_price = Number($('#unit_price').val()) || 0;
        const extra_sale = Number($('#extra_sale').val()) || 0;
        const discount = Number($('#discount').val()) || 0;
        const product_name = $('#product_name').val().trim();
        const work_details_id = $('#work_date').val();

        if (!customerName || !product_id || !product_name || quantity <= 0 || unit_price <= 0 || !work_details_id) {
            alert('لطفاً همه فیلدها را پر کنید.');
            return;
        }

        try {
            const response = await sendRequest('sub_order_handler.php', {
                action: 'add_sub_item',
                customer_name: customerName,
                product_id: product_id,
                product_name: product_name,
                quantity: quantity,
                unit_price: unit_price,
                extra_sale: extra_sale,
                discount: discount,
                work_details_id: work_details_id,
                partner_id: '<?= $current_user_id ?>'
            });

            if (response.success) {
                renderItemsTable(response.data);
                resetForm();
            } else {
                alert(response.message);
            }
        } catch (error) {
            console.error('Add item error:', error);
            alert('خطا در افزودن محصول: ' + error.message);
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
        try {
            const response = await sendRequest('sub_order_handler.php', {
                action: 'delete_sub_item',
                index: index
            });

            if (response.success) {
                renderItemsTable(response.data);
            } else {
                alert(response.message);
            }
        } catch (error) {
            console.error('Delete item error:', error);
            alert('خطا در حذف محصول: ' + error.message);
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
        try {
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
        } catch (error) {
            console.error('Save invoice price error:', error);
            alert('خطا در تنظیم قیمت فاکتور: ' + error.message);
        }
    });

    $('#postal_option').on('change', async function() {
        const enable_postal = $(this).is(':checked');
        const postal_price = Number($('#postal_price').val()) || 50000;
        $('#postal_price').prop('disabled', !enable_postal);
        try {
            const response = await sendRequest('sub_order_handler.php', {
                action: 'set_sub_postal_option',
                enable_postal: enable_postal,
                postal_price: postal_price
            });

            if (response.success) {
                renderItemsTable(response.data);
            } else {
                alert(response.message);
            }
        } catch (error) {
            console.error('Set postal option error:', error);
            alert('خطا در تنظیم گزینه پستی: ' + error.message);
        }
    });

    $('#postal_price').on('input', async function() {
        const postal_price = Number($(this).val()) || 50000;
        if (postal_price < 0) {
            $(this).val(50000);
            return;
        }
        if ($('#postal_option').is(':checked')) {
            try {
                const response = await sendRequest('sub_order_handler.php', {
                    action: 'set_sub_postal_option',
                    enable_postal: true,
                    postal_price: postal_price
                });

                if (response.success) {
                    renderItemsTable(response.data);
                } else {
                    alert(response.message);
                }
            } catch (error) {
                console.error('Set postal price error:', error);
                alert('خطا در تنظیم قیمت پستی: ' + error.message);
            }
        }
    });

    $('#discount').on('change', async function() {
        const discount = Number($(this).val()) || 0;
        if (discount < 0) {
            alert('تخفیف نمی‌تواند منفی باشد.');
            $(this).val(0);
            return;
        }
        try {
            const response = await sendRequest('sub_order_handler.php', {
                action: 'update_sub_discount',
                discount: discount
            });

            if (response.success) {
                renderItemsTable(response.data);
            } else {
                alert(response.message);
            }
        } catch (error) {
            console.error('Update discount error:', error);
            alert('خطا در به‌روزرسانی تخفیف: ' + error.message);
        }
    });

    $('#save_sub_order_btn').on('click', async function() {
        console.log('Save order button clicked');
        const customerName = $('#customer_name').val().trim();
        const work_details_id = $('#work_date').val();
        const discount = Number($('#discount').val()) || 0;
        const sub_order_id = '<?= htmlspecialchars($sub_order_id) ?>';
        const work_month_id = '<?= htmlspecialchars($work_month_id) ?>';
        const convert_to_main = $('#convert_to_main').is(':checked');
        const partner_id = $('#partner_id').val();
        const work_date_main = $('#work_date_main_select').val();

        console.log('Form data:', { customerName, work_details_id, discount, sub_order_id, work_month_id, convert_to_main, partner_id, work_date_main });

        if (!customerName || !work_details_id) {
            alert('لطفاً نام مشتری و تاریخ کاری را وارد کنید.');
            return;
        }

        if (convert_to_main && (!partner_id || !work_date_main)) {
            alert('لطفاً همکار و تاریخ کاری را برای فاکتور اصلی انتخاب کنید.');
            return;
        }

        try {
            const action = convert_to_main ? 'convert_to_main_order' : 'update_sub_order';
            const data = {
                action: action,
                customer_name: customerName,
                work_details_id: work_details_id,
                discount: discount,
                sub_order_id: sub_order_id,
                work_month_id: work_month_id,
                partner_id: '<?= $current_user_id ?>'
            };
            if (convert_to_main) {
                data.main_work_details_id = work_date_main;
                data.main_partner_id = partner_id;
            }

            const response = await sendRequest('sub_order_handler.php', data);

            if (response.success) {
                console.log('Redirecting to:', response.data.redirect);
                window.location.href = response.data.redirect;
            } else {
                alert(response.message);
            }
        } catch (error) {
            console.error('Save order error:', error);
            alert('خطا در ذخیره پیش‌فاکتور: ' + error.message);
        }
    });

    // Initialize items table
    sendRequest('sub_order_handler.php', { action: 'get_items' }).then(response => {
        console.log('Get items response:', response);
        if (response.success) {
            renderItemsTable(response.data);
        } else {
            console.error('Get items failed:', response);
            renderItemsTable({ items: [], total_amount: 0, discount: 0, final_amount: 0, invoice_prices: {}, sub_postal_enabled: false, sub_postal_price: 50000 });
        }
    }).catch(error => {
        console.error('Get items error:', error);
        renderItemsTable({ items: [], total_amount: 0, discount: 0, final_amount: 0, invoice_prices: {}, sub_postal_enabled: false, sub_postal_price: 50000 });
    });
});
</script>

<?php require_once 'footer.php'; ?>