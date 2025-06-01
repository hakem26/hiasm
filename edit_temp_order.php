<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

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

// ذخیره work_month_id در سشن
$_SESSION['work_month_id'] = $work_month_id;

// تابع تبدیل تاریخ
function gregorian_to_jalali_format($gregorian_date)
{
    if (empty($gregorian_date) || !strtotime($gregorian_date)) {
        return 'نامشخص';
    }
    $date_parts = explode(' ', $gregorian_date)[0];
    list($gy, $gm, $gd) = explode('-', $date_parts);
    $gy = (int) $gy;
    $gm = (int) $gm;
    $gd = (int) $gd;
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
        FROM Temp_Orders tmp
        JOIN Work_Details wd ON wd.work_month_id = ?
        JOIN Partners p ON wd.partner_id = p.partner_id
        WHERE tmp.temp_order_id = ? AND tmp.user_id = ? AND p.user_id1 = ?
        LIMIT 1
    ");
    $stmt->execute([$work_month_id, $temp_order_id, $current_user_id, $current_user_id]);
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
    $stmt = $pdo->prepare("SELECT * FROM Temp_Order_Items WHERE temp_order_id = ?");
    $stmt->bindValue(1, (int) $temp_order_id, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // دیباگ قوی‌تر
    $debug_data = [
        'temp_order_id' => $temp_order_id,
        'items_count' => count($items),
        'items' => $items
    ];
    file_put_contents('debug_items.log', print_r($debug_data, true) . "\n\n", FILE_APPEND);
    if (empty($items)) {
        error_log("No items found for temp_order_id: $temp_order_id");
    }
    foreach ($items as $index => &$item) {
        $item['item_index'] = (int) ($item['item_index'] ?? $index);
    }
    unset($item); // شکستن رفرنس
} catch (PDOException $e) {
    error_log("Error fetching temp order items: " . $e->getMessage());
    file_put_contents('debug_items.log', "Error fetching items for temp_order_id $temp_order_id: " . $e->getMessage() . "\n", FILE_APPEND);
    $items = [];
}

// لود قیمت‌های فاکتور
try {
    $stmt = $pdo->prepare("SELECT item_index, invoice_price, is_postal, postal_price FROM Invoice_Prices WHERE order_id = ?");
    $stmt->execute([$temp_order_id]);
    $invoice_prices = [];
    $postal_enabled = false;
    $postal_price = 50000;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['is_postal']) {
            $postal_enabled = true;
            $postal_price = (float) ($row['postal_price'] ?? 50000);
            $invoice_prices['postal'] = $postal_price;
        } else {
            $invoice_prices[(int) $row['item_index']] = (float) $row['invoice_price'];
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
$_SESSION['edit_temp_order_discount'] = (float) ($temp_order['discount'] ?? 0);
$_SESSION['invoice_prices'] = $invoice_prices;
$_SESSION['postal_enabled'] = $postal_enabled;
$_SESSION['postal_price'] = $postal_price;
$_SESSION['is_temp_order_in_progress'] = true;
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

    @media (max-width: 991px) {
        .col-6 {
            width: 50%;
        }

        .col-md-3,
        .col-md-6 {
            width: 50%;
        }
    }
</style>

<div class="container-fluid mt-5">
    <h5 class="card-title mb-4">ویرایش سفارش موقت #<?= $temp_order_id ?></h5>

    <form id="edit-temp-order-form">
        <div class="mb-3">
            <label for="customer_name" class="form-label">نام مشتری</label>
            <input type="text" class="form-control" id="customer_name" name="customer_name"
                value="<?= htmlspecialchars($temp_order['customer_name']) ?>" required autocomplete="off">
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
            <button type="button" id="edit_item_btn" class="btn btn-warning" style="display: none;">ثبت ویرایش</button>
            <input type="hidden" id="edit_index" name="edit_index">
        </div>

        <div class="table-wrapper" id="items_table">
            <!-- جدول آیتم‌ها با جاوااسکریپت پر می‌شود -->
        </div>

        <div class="mb-3">
            <label class="form-check-label">
                <input type="checkbox" id="postal_option" name="postal_option" <?= $postal_enabled ? 'checked' : '' ?>>
                فعال کردن ارسال پستی
            </label>
            <div id="postal_price_container" style="display: <?= $postal_enabled ? 'block' : 'none' ?>;">
                <label for="postal_price" class="form-label">هزینه ارسال پستی (تومان)</label>
                <input type="number" class="form-control" id="postal_price" name="postal_price"
                    value="<?= $postal_price ?>" min="0">
            </div>
        </div>

        <div class="mb-3">
            <p><strong>جمع کل:</strong> <span
                    id="total_amount_display"><?= number_format($temp_order['total_amount'], 0) ?> تومان</span></p>
            <p><strong>تخفیف:</strong> <input type="number" id="discount" name="discount"
                    value="<?= $temp_order['discount'] ?>" min="0"></p>
            <p><strong>هزینه ارسال پستی:</strong> <span
                    id="postal_price_display"><?= $postal_enabled ? number_format($postal_price, 0) . ' تومان' : '0 تومان' ?></span>
            </p>
            <p><strong>مبلغ نهایی:</strong> <span
                    id="final_amount_display"><?= number_format($temp_order['final_amount'], 0) ?> تومان</span></p>
        </div>

        <button type="button" id="save_temp_order_btn" class="btn btn-success mt-3">ذخیره تغییرات</button>
        <a href="orders.php?work_month_id=<?= htmlspecialchars($work_month_id) ?>"
            class="btn btn-secondary mt-3">بازگشت</a>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const text = await response.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON response:', text);
                throw new Error('پاسخ سرور نامعتبر است: ' + text.substring(0, 100));
            }
        } catch (error) {
            console.error('Fetch error:', error);
            return { success: false, message: 'خطایی در ارسال درخواست رخ داد: ' + error.message };
        }
    }

    async function fetchCurrentItems() {
        try {
            const response = await sendRequest('ajax_handler.php', {
                action: 'get_edit_temp_order_items',
                temp_order_id: '<?= $temp_order_id ?>',
                work_month_id: '<?= $work_month_id ?>'
            });
            console.log('fetchCurrentItems response:', response); // دیباگ
            if (response.success && response.data && response.data.items) {
                const items = response.data.items || [];
                await sendRequest('ajax_handler.php', {
                    action: 'sync_edit_temp_items',
                    items: JSON.stringify(items),
                    temp_order_id: '<?= $temp_order_id ?>',
                    work_month_id: '<?= $work_month_id ?>'
                });
                console.log('fetchCurrentItems items:', items); // دیباگ
                return items;
            } else {
                console.error('Failed to fetch items:', response.message);
                file_put_contents('debug_js.log', 'fetchCurrentItems failed: ' + JSON.stringify(response) + '\n', FILE_APPEND);
                return [];
            }
        } catch (error) {
            console.error('Error fetching items:', error);
            file_put_contents('debug_js.log', 'fetchCurrentItems error: ' + error.message + '\n', FILE_APPEND);
            return [];
        }
    }

    function renderItemsTable(data) {
        console.log('renderItemsTable data:', data); // دیباگ
        const itemsTable = document.getElementById('items_table');
        const totalAmountDisplay = document.getElementById('total_amount_display');
        const finalAmountDisplay = document.getElementById('final_amount_display');
        const postalPriceDisplay = document.getElementById('postal_price_display');
        const invoicePrices = data.invoice_prices || {};
        const items = data.items || [];
        const postalEnabled = data.postal_enabled || false;
        const postalPrice = data.postal_price || 50000;

        if (!items || items.length === 0) {
            itemsTable.innerHTML = '<p>هیچ محصولی در سفارش وجود ندارد.</p>';
            totalAmountDisplay.textContent = '0 تومان';
            finalAmountDisplay.textContent = (postalEnabled ? Number(postalPrice) : 0).toLocaleString('fa') + ' تومان';
            postalPriceDisplay.textContent = (postalEnabled ? Number(postalPrice) : 0).toLocaleString('fa') + ' تومان';
            return;
        }

        itemsTable.innerHTML = `
        <table class="table table-light order-items-table">
            <thead>
                <tr>
                    <th>ردیف</th>
                    <th>نام محصول</th>
                    <th>تعداد</th>
                    <th>قیمت واحد</th>
                    <th>اضافه فروش</th>
                    <th>قیمت کل</th>
                    <th>قیمت فاکتور (واحد)</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                ${items.map((item, i) => {
            const unitPrice = Number(item.unit_price) || 0;
            const extraSale = Number(item.extra_sale) || 0;
            const index = Number(item.item_index) || i; // استفاده از item_index
            const invoicePrice = Number(invoicePrices[index] ?? (unitPrice + extraSale)) || 0;
            return `
                        <tr id="item_row_${index}">
                            <td>${i + 1}</td>
                            <td>${item.product_name}</td>
                            <td>${item.quantity}</td>
                            <td>${unitPrice.toLocaleString('fa')} تومان</td>
                            <td>${extraSale.toLocaleString('fa')} تومان</td>
                            <td>${Number(item.total_price).toLocaleString('fa')} تومان</td>
                            <td>
                                <button class="btn btn-info btn-sm set-invoice-price" data-index="${index}">تنظیم قیمت</button>
                                <span class="invoice-price">${invoicePrice.toLocaleString('fa')} تومان</span>
                            </td>
                            <td>
                                <button class="btn btn-warning btn-sm edit-item" data-index="${index}"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-danger btn-sm delete-item" data-index="${index}"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    `;
        }).join('')}
                ${postalEnabled ? `
                    <tr class="postal-row">
                        <td>-</td>
                        <td>ارسال پستی</td>
                        <td>-</td>
                        <td>-</td>
                        <td>-</td>
                        <td>${Number(postalPrice).toLocaleString('fa')} تومان</td>
                        <td>-</td>
                        <td>-</td>
                    </tr>
                ` : ''}
                <tr class="total-row">
                    <td colspan="5">جمع کل</td>
                    <td>${Number(data.total_amount || 0).toLocaleString('fa')} تومان</td>
                    <td colspan="2"></td>
                </tr>
            </tbody>
        </table>
    `;
        totalAmountDisplay.textContent = Number(data.total_amount || 0).toLocaleString('fa') + ' تومان';
        finalAmountDisplay.textContent = Number(data.final_amount || 0).toLocaleString('fa') + ' تومان';
        postalPriceDisplay.textContent = (postalEnabled ? Number(postalPrice) : 0).toLocaleString('fa') + ' تومان';
    }

    function resetForm() {
        document.getElementById('product_name').value = '';
        document.getElementById('product_id').value = '';
        document.getElementById('quantity').value = '1';
        document.getElementById('unit_price').value = '';
        document.getElementById('extra_sale').value = '0';
        document.getElementById('total_price').value = '';
        document.getElementById('edit_index').value = '';
        document.getElementById('add_item_btn').style.display = 'inline-block';
        document.getElementById('edit_item_btn').style.display = 'none';
    }

    document.addEventListener('DOMContentLoaded', () => {
        // لود اولیه آیتم‌ها
        let initialItems = <?= json_encode($items ?? [], JSON_UNESCAPED_UNICODE) ?>;
        console.log('Initial items:', initialItems); // دیباگ
        renderItemsTable({
            items: initialItems,
            total_amount: <?= json_encode($temp_order['total_amount'] ?? 0, JSON_UNESCAPED_UNICODE) ?>,
            discount: <?= json_encode($temp_order['discount'] ?? 0, JSON_UNESCAPED_UNICODE) ?>,
            final_amount: <?= json_encode($temp_order['final_amount'] ?? 0, JSON_UNESCAPED_UNICODE) ?>,
            postal_enabled: <?= json_encode($postal_enabled, JSON_UNESCAPED_UNICODE) ?>,
            postal_price: <?= json_encode($postal_price, JSON_UNESCAPED_UNICODE) ?>,
            invoice_prices: <?= json_encode($invoice_prices ?? [], JSON_UNESCAPED_UNICODE) ?>
        });

        // جستجوی محصول
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

        // انتخاب محصول
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

        // آپدیت قیمت کل
        $('#quantity, #unit_price, #extra_sale').on('input', function () {
            let quantity = Number($('#quantity').val()) || 0;
            let unit_price = Number($('#unit_price').val()) || 0;
            let extra_sale = Number($('#extra_sale').val()) || 0;
            let total = quantity * (unit_price + extra_sale);
            $('#total_price').val(total.toLocaleString('fa') + ' تومان');
        });

        // افزودن محصول
        document.getElementById('add_item_btn').addEventListener('click', async (e) => {
            e.preventDefault();
            const data = {
                action: 'add_edit_temp_item',
                customer_name: document.getElementById('customer_name').value,
                product_id: document.getElementById('product_id').value,
                quantity: document.getElementById('quantity').value,
                unit_price: document.getElementById('unit_price').value,
                extra_sale: document.getElementById('extra_sale').value || 0,
                discount: document.getElementById('discount').value || 0,
                temp_order_id: '<?= $temp_order_id ?>',
                partner1_id: '<?= $partner1_id ?>',
                work_month_id: '<?= $work_month_id ?>'
            };
            console.log('Add item data:', data); // دیباگ
            const response = await sendRequest('ajax_handler.php', data);
            if (response.success) {
                renderItemsTable(response.data);
                resetForm();
            } else {
                alert(response.message);
            }
        });

        // ویرایش محصول
        document.getElementById('edit_item_btn').addEventListener('click', async (e) => {
            e.preventDefault();
            const data = {
                action: 'edit_edit_temp_item',
                customer_name: document.getElementById('customer_name').value,
                product_id: document.getElementById('product_id').value,
                quantity: document.getElementById('quantity').value,
                unit_price: document.getElementById('unit_price').value,
                extra_sale: document.getElementById('extra_sale').value || 0,
                discount: document.getElementById('discount').value || 0,
                index: document.getElementById('edit_index').value,
                temp_order_id: '<?= $temp_order_id ?>',
                partner1_id: '<?= $partner1_id ?>',
                work_month_id: '<?= $work_month_id ?>'
            };
            console.log('Edit item data:', data); // دیباگ
            const response = await sendRequest('ajax_handler.php', data);
            if (response.success) {
                renderItemsTable(response.data);
                resetForm();
            } else {
                alert(response.message);
            }
        });

        // مدیریت جدول (حذف، ویرایش، قیمت فاکتور)
        document.getElementById('items_table').addEventListener('click', async (e) => {
            if (e.target.closest('.delete-item')) {
                e.preventDefault();
                const index = e.target.closest('.delete-item').getAttribute('data-index');
                if (confirm('آیا از حذف این محصول مطمئن هستید؟')) {
                    const data = {
                        action: 'delete_edit_temp_item',
                        index: index,
                        temp_order_id: '<?= $temp_order_id ?>',
                        partner1_id: '<?= $partner1_id ?>',
                        work_month_id: '<?= $work_month_id ?>'
                    };
                    console.log('Delete item data:', data); // دیباگ
                    const response = await sendRequest('ajax_handler.php', data);
                    if (response.success) {
                        renderItemsTable(response.data);
                    } else {
                        alert(response.message);
                    }
                }
            } else if (e.target.closest('.edit-item')) {
                e.preventDefault();
                const index = Number(e.target.closest('.edit-item').getAttribute('data-index'));
                const items = await fetchCurrentItems();
                console.log('Edit items:', items, 'index:', index); // دیباگ
                const item = items.find(i => Number(i.item_index) === index);
                if (item) {
                    document.getElementById('product_name').value = item.product_name;
                    document.getElementById('product_id').value = item.product_id;
                    document.getElementById('quantity').value = item.quantity;
                    document.getElementById('unit_price').value = item.unit_price;
                    document.getElementById('extra_sale').value = item.extra_sale;
                    document.getElementById('total_price').value = Number(item.total_price).toLocaleString('fa') + ' تومان';
                    document.getElementById('edit_index').value = index;
                    document.getElementById('add_item_btn').style.display = 'none';
                    document.getElementById('edit_item_btn').style.display = 'inline-block';
                } else {
                    console.error('Item not found for index:', index);
                    alert('محصول یافت نشد. لطفاً صفحه را رفرش کنید.');
                }
            } else if (e.target.closest('.set-invoice-price')) {
                e.preventDefault();
                const index = Number(e.target.closest('.set-invoice-price').getAttribute('data-index'));
                const items = await fetchCurrentItems();
                console.log('Set invoice price items:', items, 'index:', index); // دیباگ
                const item = items.find(i => Number(i.item_index) === index);
                if (!item) {
                    console.error('Item not found for index:', index);
                    alert('آیتم مورد نظر یافت نشد.');
                    return;
                }
                const response = await sendRequest('ajax_handler.php', {
                    action: 'get_edit_temp_order_items',
                    temp_order_id: '<?= $temp_order_id ?>',
                    work_month_id: '<?= $work_month_id ?>'
                });
                const invoicePrices = response.data?.invoice_prices || {};
                const defaultPrice = Number(invoicePrices[index] ?? (Number(item.unit_price) + Number(item.extra_sale)));
                const invoicePrice = prompt('قیمت فاکتور واحد را وارد کنید (تومان):', defaultPrice);
                if (invoicePrice !== null && !isNaN(invoicePrice) && invoicePrice >= 0) {
                    const data = {
                        action: 'set_invoice_price',
                        index: index,
                        invoice_price: invoicePrice,
                        order_id: '<?= $temp_order_id ?>',
                        work_month_id: '<?= $work_month_id ?>'
                    };
                    console.log('Set invoice price data:', data); // دیباگ
                    const priceResponse = await sendRequest('ajax_handler.php', data);
                    if (priceResponse.success) {
                        await sendRequest('ajax_handler.php', {
                            action: 'sync_edit_temp_items',
                            items: JSON.stringify(items),
                            temp_order_id: '<?= $temp_order_id ?>',
                            work_month_id: '<?= $work_month_id ?>'
                        });
                        priceResponse.data.invoice_prices = priceResponse.data.invoice_prices || {};
                        priceResponse.data.invoice_prices[index] = Number(invoicePrice);
                        renderItemsTable(priceResponse.data);
                    } else {
                        alert(priceResponse.message);
                    }
                }
            }
        });
    });

    // رویدادهای مستقل
    document.getElementById('postal_option').addEventListener('change', async (e) => {
        e.preventDefault();
        const enablePostal = document.getElementById('postal_option').checked;
        document.getElementById('postal_price_container').style.display = enablePostal ? 'block' : 'none';
        const postalPrice = Number(document.getElementById('postal_price').value) || 50000;
        const data = {
            action: 'set_postal_option',
            enable_postal: enablePostal,
            postal_price: postalPrice,
            order_id: '<?= $temp_order_id ?>',
            work_month_id: '<?= $work_month_id ?>'
        };
        console.log('Postal option data:', data); // دیباگ
        const response = await sendRequest('ajax_handler.php', data);
        if (response.success) {
            response.data.postal_enabled = enablePostal;
            response.data.postal_price = postalPrice;
            response.data.invoice_prices = response.data.invoice_prices || {};
            if (enablePostal) {
                response.data.invoice_prices['postal'] = postalPrice;
            } else {
                delete response.data.invoice_prices['postal'];
            }
            document.getElementById('postal_price_display').textContent = (enablePostal ? postalPrice : 0).toLocaleString('fa') + ' تومان';
            renderItemsTable(response.data);
        } else {
            alert(response.message);
        }
    });

    document.getElementById('postal_price').addEventListener('input', async (e) => {
        e.preventDefault();
        const data = {
            action: 'set_invoice_price',
            index: 'postal',
            invoice_price: document.getElementById('postal_price').value || 50000,
            order_id: '<?= $temp_order_id ?>',
            work_month_id: '<?= $work_month_id ?>'
        };
        console.log('Postal price data:', data); // دیباگ
        const response = await sendRequest('ajax_handler.php', data);
        if (response.success) {
            document.getElementById('postal_price_display').textContent = Number(document.getElementById('postal_price').value).toLocaleString('fa') + ' تومان';
            renderItemsTable(response.data);
        } else {
            alert(response.message);
        }
    });

    document.getElementById('discount').addEventListener('input', async (e) => {
        e.preventDefault();
        const data = {
            action: 'update_edit_temp_discount',
            discount: document.getElementById('discount').value || 0,
            temp_order_id: '<?= $temp_order_id ?>',
            work_month_id: '<?= $work_month_id ?>'
        };
        console.log('Discount data:', data); // دیباگ
        const response = await sendRequest('ajax_handler.php', data);
        if (response.success) {
            renderItemsTable(response.data);
        } else {
            alert(response.message);
        }
    });

    document.getElementById('save_temp_order_btn').addEventListener('click', async (e) => {
        e.preventDefault();
        const items = await fetchCurrentItems();
        console.log('Save order items:', items); // دیباگ
        if (!items || items.length === 0) {
            if (!confirm('هیچ محصولی در سفارش وجود ندارد. آیا می‌خواهید سفارش بدون محصول ذخیره شود؟')) {
                return;
            }
        }
        const data = {
            action: 'save_edit_temp_order',
            temp_order_id: '<?= $temp_order_id ?>',
            customer_name: document.getElementById('customer_name').value,
            discount: document.getElementById('discount').value || 0,
            partner1_id: '<?= $partner1_id ?>',
            work_month_id: '<?= $work_month_id ?>',
            items: JSON.stringify(items) // ارسال آیتم‌ها
        };
        console.log('Save order data:', data); // دیباگ
        const response = await sendRequest('ajax_handler.php', data);
        if (response.success) {
            alert(response.message);
            window.location.href = response.data.redirect;
        } else {
            alert(response.message);
        }
    });
</script>

<?php require_once 'footer.php'; ?>