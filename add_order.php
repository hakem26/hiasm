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
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

$is_admin = ($_SESSION['role'] === 'admin');
if ($is_admin) {
    header("Location: orders.php");
    exit;
}
$current_user_id = $_SESSION['user_id'];

$work_details_id = $_GET['work_details_id'] ?? '';
if (!$work_details_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>شناسه کار مشخص نشده است.</div></div>";
    require_once 'footer.php';
    exit;
}

$stmt = $pdo->prepare("
    SELECT wd.id, wd.work_date, wd.partner_id
    FROM Work_Details wd
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE wd.id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
");
$stmt->execute([$work_details_id, $current_user_id, $current_user_id]);
$work = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$work) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>کار یافت نشد یا شما دسترسی به آن ندارید.</div></div>";
    require_once 'footer.php';
    exit;
}

$partner_name = 'نامشخص';
$partner1_id = null;
if ($work['partner_id']) {
    $stmt_partner = $pdo->prepare("
        SELECT u1.user_id AS user1_id, u1.full_name AS user1_name, u2.user_id AS user2_id, u2.full_name AS user2_name
        FROM Partners p
        LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
        LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
        WHERE p.partner_id = ?
    ");
    $stmt_partner->execute([$work['partner_id']]);
    $partner_data = $stmt_partner->fetch(PDO::FETCH_ASSOC);

    if ($partner_data) {
        $user1_id = $partner_data['user1_id'];
        $user2_id = $partner_data['user2_id'];
        $user1_name = $partner_data['user1_name'] ?: 'نامشخص';
        $user2_name = $partner_data['user2_name'] ?: 'نامشخص';

        if ($user1_id == $current_user_id && $user2_name != 'نامشخص') {
            $partner_name = $user2_name;
        } elseif ($user2_id == $current_user_id && $user1_name != 'نامشخص') {
            $partner_name = $user1_name;
        }
        $partner1_id = $user1_id;
    }
}

if (!$partner1_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>همکار 1 یافت نشد. لطفاً با مدیر سیستم تماس بگیرید.</div></div>";
    require_once 'footer.php';
    exit;
}

unset($_SESSION['order_items']);
unset($_SESSION['discount']);
unset($_SESSION['invoice_prices']);
$_SESSION['order_items'] = [];
$_SESSION['discount'] = 0;
$_SESSION['invoice_prices'] = ['postal' => 50000]; // پیش‌فرض 50,000 برای ارسال پستی
$_SESSION['is_order_in_progress'] = true;
$_SESSION['postal_enabled'] = false; // هنوز فعال نیست تا تیک بخوره
$_SESSION['postal_price'] = 50000; // پیش‌فرض قیمت پستی
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
    <h5 class="card-title mb-4">ثبت سفارش جدید</h5>

    <div class="card mb-4">
        <div class="card-body">
            <p><strong>تاریخ:</strong> <?= gregorian_to_jalali_format($work['work_date']) ?></p>
            <p><strong>همکار:</strong> <?= htmlspecialchars($partner_name) ?></p>
        </div>
    </div>

    <form id="add-order-form">
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

        <div class="table-wrapper" id="items_table">
            <?php if (!empty($_SESSION['order_items'])): ?>
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
                        <?php foreach ($_SESSION['order_items'] as $index => $item): ?>
                            <tr id="item_row_<?= $index ?>">
                                <td><?= htmlspecialchars($item['product_name']) ?></td>
                                <td><?= $item['quantity'] ?></td>
                                <td><?= number_format($item['unit_price'], 0) ?></td>
                                <td><?= number_format($item['extra_sale'], 0) ?></td>
                                <td><?= number_format($item['total_price'], 0) ?></td>
                                <td>
                                    <button type="button" class="btn btn-info btn-sm set-invoice-price"
                                        data-index="<?= $index ?>">
                                        تنظیم قیمت
                                    </button>
                                    <span class="invoice-price" data-index="<?= $index ?>">
                                        <?= number_format($_SESSION['invoice_prices'][$index] ?? $item['total_price'], 0) ?>
                                        تومان
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-danger btn-sm delete-item" data-index="<?= $index ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($_SESSION['postal_enabled']): ?>
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
                                        <?= number_format($_SESSION['invoice_prices']['postal'] ?? $_SESSION['postal_price'], 0) ?>
                                        تومان
                                    </span>
                                </td>
                                <td>-</td>
                            </tr>
                        <?php endif; ?>
                        <?php
                        $total_amount = array_sum(array_column($_SESSION['order_items'], 'total_price'));
                        $discount = $_SESSION['discount'];
                        $final_amount = $total_amount - $discount + ($_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0);
                        ?>
                        <tr class="total-row">
                            <td colspan="4"><strong>جمع کل</strong></td>
                            <td><strong id="total_amount"><?= number_format($total_amount, 0) ?> تومان</strong></td>
                            <td colspan="2"></td>
                        </tr>
                        <tr class="total-row">
                            <td><label for="discount" class="form-label">تخفیف</label></td>
                            <td><input type="number" class="form-control" id="discount" name="discount"
                                    value="<?= $discount ?>" min="0"></td>
                            <td><strong id="final_amount"><?= number_format($final_amount, 0) ?> تومان</strong></td>
                            <td colspan="2"></td>
                        </tr>
                        <tr class="total-row">
                            <td><label for="postal_option" class="form-label">پست سفارش</label></td>
                            <td><input type="checkbox" id="postal_option" name="postal_option"
                                    <?= $_SESSION['postal_enabled'] ? 'checked' : '' ?>></td>
                            <td colspan="3"></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <p><strong>جمع کل:</strong> <span id="total_amount_display"><?= number_format($total_amount ?? 0, 0) ?>
                    تومان</span></p>
            <p><strong>مبلغ نهایی:</strong> <span id="final_amount_display"><?= number_format($final_amount ?? 0, 0) ?>
                    تومان</span></p>
        </div>

        <button type="button" id="finalize_order_btn" class="btn btn-success mt-3">ثبت فاکتور</button>
        <a href="orders.php" class="btn btn-secondary mt-3">بازگشت</a>
    </form>
</div>

<!-- مودال تنظیم قیمت فاکتور -->
<div class="modal fade" id="invoicePriceModal" tabindex="-1" aria-labelledby="invoicePriceModalLabel"
    aria-hidden="true">
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
            const rawResponse = await response.text();
            console.log('Raw response from ' + url + ':', rawResponse);
            try {
                return JSON.parse(rawResponse);
            } catch (e) {
                console.error('JSON Parse Error:', e, 'Raw Response:', rawResponse);
                throw e;
            }
        } catch (error) {
            console.error('Request Error:', error);
            return { success: false, message: 'خطایی در ارسال درخواست رخ داد.' };
        }
    }

    function renderItemsTable(data) {
        const itemsTable = document.getElementById('items_table');
        const totalAmountDisplay = document.getElementById('total_amount_display');
        const finalAmountDisplay = document.getElementById('final_amount_display');
        const invoicePrices = data.invoice_prices || {};
        const postalEnabled = data.postal_enabled || <?= json_encode($_SESSION['postal_enabled']) ?>;
        const postalPrice = data.postal_price || <?= json_encode($_SESSION['postal_price']) ?>;
        const totalAmount = data.total_amount || 0;
        let finalAmount = data.final_amount || totalAmount;

        if (postalEnabled) {
            finalAmount += Number(invoicePrices['postal'] || postalPrice);
        }
        finalAmount -= Number(data.discount || 0);

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
                        <td>${Number(item.unit_price).toLocaleString('fa')} تومان</td>
                        <td>${Number(item.extra_sale).toLocaleString('fa')} تومان</td>
                        <td>${Number(item.total_price).toLocaleString('fa')} تومان</td>
                        <td>
                            <button type="button" class="btn btn-info btn-sm set-invoice-price" data-index="${index}">
                                تنظیم قیمت
                            </button>
                            <span class="invoice-price" data-index="${index}">
                                ${Number(invoicePrices[index] ?? item.unit_price).toLocaleString('fa')} تومان
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
                                ${Number(invoicePrices['postal'] ?? postalPrice).toLocaleString('fa')} تومان
                            </span>
                        </td>
                        <td>-</td>
                    </tr>
                ` : ''}
                <tr class="total-row">
                    <td colspan="4"><strong>جمع کل</strong></td>
                    <td><strong id="total_amount">${Number(totalAmount).toLocaleString('fa')} تومان</strong></td>
                    <td colspan="2"></td>
                </tr>
                <tr class="total-row">
                    <td><label for="discount" class="form-label">تخفیف</label></td>
                    <td><input type="number" class="form-control" id="discount" name="discount" value="${data.discount || 0}" min="0"></td>
                    <td><strong id="final_amount">${Number(finalAmount).toLocaleString('fa')} تومان</strong></td>
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

        totalAmountDisplay.textContent = Number(totalAmount).toLocaleString('fa') + ' تومان';
        finalAmountDisplay.textContent = Number(finalAmount).toLocaleString('fa') + ' تومان';
    }

    document.addEventListener('DOMContentLoaded', () => {
        let initialInventory = 0;
        let editingIndex = null;

        $('#product_name').on('input', function () {
            let query = $(this).val();
            const work_details_id = '<?= htmlspecialchars($work['id'], ENT_QUOTES, 'UTF-8') ?>';
            console.log('Search query:', query);
            if (query.length >= 3) {
                $.ajax({
                    url: 'get_products.php',
                    type: 'POST',
                    data: { query: query, work_details_id: work_details_id },
                    success: function (response) {
                        console.log('get_products raw response:', response);
                        if (response.trim() === '') {
                            $('#product_suggestions').html('<div class="list-group-item">محصولی یافت نشد</div>').show();
                        } else {
                            $('#product_suggestions').html(response).show();
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('AJAX Error:', status, error, xhr.responseText);
                        $('#product_suggestions').html('<div class="list-group-item">خطا در جستجو</div>').show();
                    }
                });
            } else {
                $('#product_suggestions').hide();
            }
        });

        $(document).on('click', '.product-suggestion', function (e) {
            e.preventDefault();
            let product = $(this).data('product');
            console.log('Selected product:', product);
            if (typeof product === 'string') {
                try {
                    product = JSON.parse(product);
                } catch (e) {
                    console.error('JSON parse error:', e);
                    alert('خطا در پردازش محصول.');
                    return;
                }
            }
            $('#product_name').val(product.product_name).prop('disabled', false);
            $('#product_id').val(product.product_id);
            $('#unit_price').val(product.unit_price);
            $('#extra_sale').val(0);
            $('#adjusted_price').val(Number(product.unit_price).toLocaleString('fa') + ' تومان');
            $('#total_price').val((1 * product.unit_price).toLocaleString('fa') + ' تومان');
            $('#product_suggestions').hide();

            $.ajax({
                url: 'get_inventory.php',
                type: 'POST',
                data: {
                    product_id: product.product_id,
                    user_id: '<?= $partner1_id ?>',
                    work_details_id: '<?= $work['id'] ?>'
                },
                success: function (response) {
                    console.log('get_inventory response:', response);
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
                error: function (xhr, status, error) {
                    console.error('Inventory AJAX Error:', status, error, xhr.responseText);
                    $('#inventory_quantity').text('0');
                    alert('خطا در دریافت موجودی.');
                }
            });

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
            let items = <?= json_encode($_SESSION['order_items']) ?>;
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
            const customer_name = document.getElementById('customer_name').value.trim();
            const product_id = document.getElementById('product_id').value;
            const quantity = Number(document.getElementById('quantity').value) || 0;
            const unit_price = Number(document.getElementById('unit_price').value) || 0;
            const extra_sale = Number(document.getElementById('extra_sale').value) || 0;
            const discount = document.getElementById('discount')?.value || 0;

            console.log('Add item fields:', {
                customer_name,
                product_id,
                quantity,
                unit_price,
                extra_sale,
                discount
            });

            if (!customer_name || !product_id || quantity <= 0 || unit_price <= 0) {
                alert('لطفاً همه فیلدها را پر کنید و تعداد را بیشتر از صفر وارد کنید.');
                return;
            }

            const items = <?= json_encode($_SESSION['order_items']) ?>;
            if (items.some(item => item.product_id === product_id)) {
                alert('این محصول قبلاً در فاکتور ثبت شده است.');
                return;
            }

            const data = {
                action: 'add_item',
                customer_name,
                product_id,
                quantity,
                unit_price,
                extra_sale,
                discount,
                work_details_id: '<?= $work['id'] ?>',
                partner1_id: '<?= $partner1_id ?>'
            };

            const addResponse = await sendRequest('ajax_handler.php', data);
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
                        action: 'delete_item',
                        index: index,
                        partner1_id: '<?= $partner1_id ?>'
                    };

                    const response = await sendRequest('ajax_handler.php', data);
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
                let currentPrice = 0;
                if (index === 'postal') {
                    currentPrice = <?= json_encode($_SESSION['invoice_prices']['postal'] ?? 50000) ?>;
                } else {
                    const items = <?= json_encode($_SESSION['order_items']) ?>;
                    currentPrice = items[index] ? Number(items[index].unit_price) : 0;
                    if (<?= json_encode($_SESSION['invoice_prices']) ?>[index]) {
                        currentPrice = <?= json_encode($_SESSION['invoice_prices']) ?>[index];
                    }
                }
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
                action: 'set_invoice_price',
                index: index,
                invoice_price: invoicePrice,
                partner1_id: '<?= $partner1_id ?>'
            };

            const response = await sendRequest('ajax_handler.php', data);
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
                    action: 'set_postal_option',
                    enable_postal: enablePostal,
                    partner1_id: '<?= $partner1_id ?>'
                };

                const response = await sendRequest('ajax_handler.php', data);
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
                    action: 'update_discount',
                    discount,
                    partner1_id: '<?= $partner1_id ?>'
                };

                const response = await sendRequest('ajax_handler.php', data);
                if (response.success) {
                    renderItemsTable(response.data);
                } else {
                    alert(response.message);
                }
            }
        });

        document.getElementById('finalize_order_btn').addEventListener('click', async () => {
            const customer_name = document.getElementById('customer_name').value;
            const discount = document.getElementById('discount')?.value || 0;

            if (!customer_name) {
                alert('لطفاً نام مشتری را وارد کنید.');
                return;
            }

            const data = {
                action: 'finalize_order',
                work_details_id: '<?= $work['id'] ?>',
                customer_name,
                discount,
                partner1_id: '<?= $partner1_id ?>'
            };

            const response = await sendRequest('ajax_handler.php', data);
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