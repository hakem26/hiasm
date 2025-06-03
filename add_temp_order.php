<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'header.php';
require_once 'db.php';

$is_admin = ($_SESSION['role'] === 'admin');
if ($is_admin) {
    header("Location: temp_orders.php");
    exit;
}
$current_user_id = $_SESSION['user_id'];

// بررسی اینکه کاربر همکار1 در ماه کاری انتخاب‌شده است یا خیر
$work_month_id = $_GET['work_month_id'] ?? '';
if ($work_month_id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM Work_Details wd
        JOIN Partners p ON wd.partner_id = p.partner_id
        WHERE wd.work_month_id = ? AND p.user_id1 = ?
    ");
    $stmt->execute([$work_month_id, $current_user_id]);
    if ($stmt->fetchColumn() == 0) {
        echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>شما مجاز به ثبت سفارش بدون تاریخ نیستید.</div></div>";
        require_once 'footer.php';
        exit;
    }
}

// آماده‌سازی سشن برای اقلام سفارش
unset($_SESSION['temp_order_items']);
unset($_SESSION['discount']);
unset($_SESSION['invoice_prices']);
$_SESSION['temp_order_items'] = [];
$_SESSION['discount'] = 0;
$_SESSION['invoice_prices'] = ['postal' => 50000];
$_SESSION['is_temp_order_in_progress'] = true;
$_SESSION['postal_enabled'] = false;
$_SESSION['postal_price'] = 50000;
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
    <h5 class="card-title mb-4">ثبت سفارش بدون تاریخ</h5>

    <form id="add-temp-order-form">
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
            <?php if (!empty($_SESSION['temp_order_items'])): ?>
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
                        <?php foreach ($_SESSION['temp_order_items'] as $index => $item): ?>
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
                                        <?= number_format($_SESSION['invoice_prices'][$index] ?? $item['total_price'], 0) ?> تومان
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
                                        <?= number_format($_SESSION['invoice_prices']['postal'] ?? $_SESSION['postal_price'], 0) ?> تومان
                                    </span>
                                </td>
                                <td>-</td>
                            </tr>
                        <?php endif; ?>
                        <?php
                        $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
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
                            <td><input type="number" class="form-control" id="discount" name="discount" value="<?= $discount ?>" min="0"></td>
                            <td><strong id="final_amount"><?= number_format($final_amount, 0) ?> تومان</strong></td>
                            <td colspan="2"></td>
                        </tr>
                        <tr class="total-row">
                            <td><label for="postal_option" class="form-label">پست سفارش</label></td>
                            <td><input type="checkbox" id="postal_option" name="postal_option" <?= $_SESSION['postal_enabled'] ? 'checked' : '' ?>></td>
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

        <button type="button" id="finalize_order_btn" class="btn btn-success mt-3">ثبت فاکتور</button>
        <a href="temp_orders.php" class="btn btn-secondary mt-3">بازگشت</a>
    </form>
</div>

<!-- مودال تنظیم قیمت فاکتور -->
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
            const rawResponse = await response.text();
            console.log('Raw response from ' + url + ':', rawResponse); // لاگ پاسخ خام
            console.log('Response status:', response.status); // لاگ وضعیت HTTP
            console.log('Response headers:', response.headers.get('content-type')); // لاگ نوع محتوا
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
                                ${Number(invoicePrices[index] ?? item.total_price).toLocaleString('fa')} تومان
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

        $('#product_name').on('input', function () {
            let query = $(this).val().trim();
            console.log('Search query:', query);
            if (query.length >= 3) {
                $.ajax({
                    url: 'get_products.php',
                    type: 'POST',
                    data: { query: query },
                    success: function (response) {
                        console.log('get_products response:', response);
                        if (!response || response.trim() === '') {
                            $('#product_suggestions').html('<div class="list-group-item">محصولی یافت نشد</div>').show();
                        } else {
                            $('#product_suggestions').html(response).show();
                            console.log('Suggestions displayed:', $('#product_suggestions').html());
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
                url: 'get_temp_inventory.php',
                type: 'POST',
                data: {
                    product_id: product.product_id,
                    user_id: '<?= $current_user_id ?>'
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
            let items = <?= json_encode($_SESSION['temp_order_items']) ?>;
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

            if (!customer_name || !product_id || quantity <= 0 || unit_price <= 0) {
                alert('لطفاً همه فیلدها را پر کنید و تعداد را بیشتر از صفر وارد کنید.');
                return;
            }

            const items = <?= json_encode($_SESSION['temp_order_items']) ?>;
            if (items.some(item => item.product_id === product_id)) {
                alert('این محصول قبلاً در فاکتور ثبت شده است.');
                return;
            }

            const data = {
                action: 'add_temp_item',
                customer_name,
                product_id,
                quantity,
                unit_price,
                extra_sale,
                discount,
                user_id: '<?= $current_user_id ?>'
            };

            const addResponse = await sendRequest('ajax_temp_handler.php', data);
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
                        action: 'delete_temp_item',
                        index: index,
                        user_id: '<?= $current_user_id ?>'
                    };

                    const response = await sendRequest('ajax_temp_handler.php', data);
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
                const currentPrice = <?= json_encode($_SESSION['invoice_prices']) ?>[index] || '';
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
                action: 'set_temp_invoice_price',
                index: index,
                invoice_price: invoicePrice
            };

            const response = await sendRequest('ajax_temp_handler.php', data);
            if (response.success) {
                const priceSpan = document.querySelector(`.invoice-price[data-index="${index}"]`);
                if (priceSpan) {
                    priceSpan.textContent = Number(invoicePrice).toLocaleString('fa') + ' تومان';
                }
                $('#invoicePriceModal').modal('hide');
            } else {
                alert(response.message);
            }
        });

        document.getElementById('items_table').addEventListener('change', async (e) => {
            if (e.target.id === 'postal_option') {
                const enablePostal = e.target.checked;
                const data = {
                    action: 'set_temp_postal_option',
                    enable_postal: enablePostal
                };

                const response = await sendRequest('ajax_temp_handler.php', data);
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
                    action: 'update_temp_discount',
                    discount
                };

                const response = await sendRequest('ajax_temp_handler.php', data);
                if (response.success) {
                    document.getElementById('total_amount').textContent = Number(response.data.total_amount).toLocaleString('fa') + ' تومان';
                    document.getElementById('final_amount').textContent = Number(response.data.final_amount).toLocaleString('fa') + ' تومان';
                    document.getElementById('total_amount_display').textContent = Number(response.data.total_amount).toLocaleString('fa') + ' تومان';
                    document.getElementById('final_amount_display').textContent = Number(response.data.final_amount).toLocaleString('fa') + ' تومان';
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
                action: 'finalize_temp_order',
                customer_name,
                discount,
                user_id: '<?= $current_user_id ?>'
            };
            console.log('Data sent to finalize:', data); // لاگ داده‌های ارسالی

            const response = await sendRequest('ajax_temp_handler.php', data);
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

و ajax_temp_handler:
<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

// لاگ محلی به فایل debug.log
function logError($message) {
    $logFile = __DIR__ . '/debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function sendResponse($success, $message = '', $data = []) {
    $response = json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logError('JSON encode error: ' . json_last_error_msg());
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['success' => false, 'message' => 'خطا در تولید پاسخ JSON']);
        exit;
    }
    echo $response;
    exit;
}

if (!isset($_SESSION['user_id'])) {
    sendResponse(false, 'لطفاً ابتدا وارد شوید.');
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if (!$action) {
    sendResponse(false, 'اکشن مشخص نشده است.');
}

if (!isset($_SESSION['temp_order_items'])) {
    $_SESSION['temp_order_items'] = [];
}
if (!isset($_SESSION['discount'])) {
    $_SESSION['discount'] = 0;
}
if (!isset($_SESSION['invoice_prices'])) {
    $_SESSION['invoice_prices'] = ['postal' => 50000];
}
if (!isset($_SESSION['postal_enabled'])) {
    $_SESSION['postal_enabled'] = false;
}
if (!isset($_SESSION['postal_price'])) {
    $_SESSION['postal_price'] = 50000;
}

try {
    switch ($action) {
        case 'add_temp_item':
            $customer_name = $_POST['customer_name'] ?? '';
            $product_id = $_POST['product_id'] ?? '';
            $quantity = (int)($_POST['quantity'] ?? 0);
            $unit_price = (float)($_POST['unit_price'] ?? 0);
            $extra_sale = (float)($_POST['extra_sale'] ?? 0);
            $discount = (float)($_POST['discount'] ?? 0);

            if (!$customer_name || !$product_id || $quantity <= 0 || $unit_price <= 0) {
                sendResponse(false, 'لطفاً همه فیلدها را به درستی پر کنید.');
            }

            $stmt = $pdo->prepare("SELECT product_name FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                sendResponse(false, 'محصول یافت نشد.');
            }

            $items = $_SESSION['temp_order_items'];
            if (array_filter($items, fn($item) => $item['product_id'] === $product_id)) {
                sendResponse(false, 'این محصول قبلاً در فاکتور ثبت شده است.');
            }

            $total_price = $quantity * ($unit_price + $extra_sale);
            $item = [
                'product_id' => $product_id,
                'product_name' => $product['product_name'],
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'extra_sale' => $extra_sale,
                'total_price' => $total_price
            ];

            $_SESSION['temp_order_items'][] = $item;
            $_SESSION['discount'] = $discount;

            $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0);

            sendResponse(true, 'محصول با موفقیت اضافه شد.', [
                'items' => $_SESSION['temp_order_items'],
                'total_amount' => $total_amount,
                'final_amount' => $final_amount,
                'discount' => $discount,
                'invoice_prices' => $_SESSION['invoice_prices'],
                'postal_enabled' => $_SESSION['postal_enabled'],
                'postal_price' => $_SESSION['postal_price']
            ]);

        case 'delete_temp_item':
            $index = (int)($_POST['index'] ?? -1);
            if ($index < 0 || !isset($_SESSION['temp_order_items'][$index])) {
                sendResponse(false, 'آیتم یافت نشد.');
            }

            unset($_SESSION['temp_order_items'][$index]);
            $_SESSION['temp_order_items'] = array_values($_SESSION['temp_order_items']);
            unset($_SESSION['invoice_prices'][$index]);

            $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
            $final_amount = $total_amount - $_SESSION['discount'] + ($_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0);

            sendResponse(true, 'آیتم با موفقیت حذف شد.', [
                'items' => $_SESSION['temp_order_items'],
                'total_amount' => $total_amount,
                'final_amount' => $final_amount,
                'discount' => $_SESSION['discount'],
                'invoice_prices' => $_SESSION['invoice_prices'],
                'postal_enabled' => $_SESSION['postal_enabled'],
                'postal_price' => $_SESSION['postal_price']
            ]);

        case 'set_temp_invoice_price':
            $index = $_POST['index'] ?? '';
            $invoice_price = (float)($_POST['invoice_price'] ?? 0);

            if ($index === '' || $invoice_price < 0) {
                sendResponse(false, 'قیمت فاکتور معتبر نیست.');
            }

            $_SESSION['invoice_prices'][$index] = $invoice_price;
            sendResponse(true, 'قیمت فاکتور با موفقیت تنظیم شد.', [
                'invoice_prices' => $_SESSION['invoice_prices']
            ]);

        case 'set_temp_postal_option':
            $enable_postal = filter_var($_POST['enable_postal'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $_SESSION['postal_enabled'] = $enable_postal;

            $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
            $final_amount = $total_amount - $_SESSION['discount'] + ($enable_postal ? $_SESSION['postal_price'] : 0);

            sendResponse(true, 'گزینه پستی با موفقیت به‌روزرسانی شد.', [
                'items' => $_SESSION['temp_order_items'],
                'total_amount' => $total_amount,
                'final_amount' => $final_amount,
                'discount' => $_SESSION['discount'],
                'invoice_prices' => $_SESSION['invoice_prices'],
                'postal_enabled' => $_SESSION['postal_enabled'],
                'postal_price' => $_SESSION['postal_price']
            ]);

        case 'update_temp_discount':
            $discount = (float)($_POST['discount'] ?? 0);
            if ($discount < 0) {
                sendResponse(false, 'تخفیف معتبر نیست.');
            }

            $_SESSION['discount'] = $discount;
            $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0);

            sendResponse(true, 'تخفیف با موفقیت به‌روزرسانی شد.', [
                'items' => $_SESSION['temp_order_items'],
                'total_amount' => $total_amount,
                'final_amount' => $final_amount,
                'discount' => $discount,
                'invoice_prices' => $_SESSION['invoice_prices'],
                'postal_enabled' => $_SESSION['postal_enabled'],
                'postal_price' => $_SESSION['postal_price']
            ]);

        case 'finalize_temp_order':
            $customer_name = $_POST['customer_name'] ?? '';
            $discount = (float)($_POST['discount'] ?? 0);

            if (!$customer_name || empty($_SESSION['temp_order_items'])) {
                sendResponse(false, 'نام مشتری یا اقلام سفارش معتبر نیست.');
            }

            $pdo->beginTransaction();

            // ثبت سفارش در Temp_Orders
            $stmt = $pdo->prepare("
                INSERT INTO Temp_Orders (user_id, customer_name, total_amount, discount, final_amount, postal_enabled, postal_price, order_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $total_amount = array_sum(array_column($_SESSION['temp_order_items'], 'total_price'));
            $final_amount = $total_amount - $discount + ($_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0);
            $stmt->execute([
                $user_id,
                $customer_name,
                $total_amount,
                $discount,
                $final_amount,
                $_SESSION['postal_enabled'] ? 1 : 0,
                $_SESSION['postal_enabled'] ? $_SESSION['postal_price'] : 0
            ]);
            $order_id = $pdo->lastInsertId();

            // ثبت اقلام در Temp_Order_Items (بدون invoice_price)
            $stmt = $pdo->prepare("
                INSERT INTO Temp_Order_Items (temp_order_id, product_name, quantity, unit_price, extra_sale, total_price)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($_SESSION['temp_order_items'] as $index => $item) {
                $stmt->execute([
                    $order_id,
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['extra_sale'],
                    $item['total_price']
                ]);

                // آپدیت موجودی (اجازه به موجودی منفی)
                $stmt_inventory = $pdo->prepare("
                    INSERT INTO Inventory (user_id, product_id, quantity)
                    VALUES (?, ?, -?)
                    ON DUPLICATE KEY UPDATE quantity = quantity - ?
                ");
                $stmt_inventory->execute([$user_id, $item['product_id'], $item['quantity'], $item['quantity']]);
            }

            // ثبت قیمت‌های فاکتور در Invoice_Prices
            $invoice_prices = $_SESSION['invoice_prices'] ?? [];
            if (!empty($invoice_prices)) {
                $stmt_invoice = $pdo->prepare("
                    INSERT INTO Invoice_Prices (order_id, item_index, invoice_price, is_postal, postal_price)
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($invoice_prices as $index => $price) {
                    if ($index === 'postal' && $_SESSION['postal_enabled']) {
                        $stmt_invoice->execute([$order_id, -1, 0, true, $price]);
                    } elseif ($index !== 'postal') {
                        $stmt_invoice->execute([$order_id, $index, $price, false, 0]);
                    }
                }
            }

            $pdo->commit();

            unset($_SESSION['temp_order_items']);
            unset($_SESSION['discount']);
            unset($_SESSION['invoice_prices']);
            unset($_SESSION['postal_enabled']);
            unset($_SESSION['postal_price']);
            unset($_SESSION['is_temp_order_in_progress']);

            sendResponse(true, 'سفارش با موفقیت ثبت شد.', ['redirect' => 'temp_orders.php']);

        default:
            sendResponse(false, 'اکشن نامعتبر است.');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errorMessage = 'Error in ajax_temp_handler.php: ' . $e->getMessage();
    logError($errorMessage);
    sendResponse(false, 'خطا در پردازش درخواست: ' . $e->getMessage());
}
?>