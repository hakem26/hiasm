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
function gregorian_to_jalali_format($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
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

// دریافت order_id از GET
$order_id = $_GET['order_id'] ?? '';
if (!$order_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>شناسه سفارش مشخص نشده است.</div></div>";
    require_once 'footer.php';
    exit;
}

// بررسی دسترسی کاربر به سفارش
$stmt = $pdo->prepare("
    SELECT o.order_id, o.work_details_id, o.customer_name, o.total_amount, o.discount, o.final_amount, wd.work_date, wd.partner_id
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE o.order_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
");
$stmt->execute([$order_id, $current_user_id, $current_user_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>سفارش یافت نشد یا شما دسترسی ویرایش آن را ندارید.</div></div>";
    require_once 'footer.php';
    exit;
}

// دریافت اقلام سفارش از دیتابیس
$items_stmt = $pdo->prepare("SELECT * FROM Order_Items WHERE order_id = ?");
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// دریافت نام همکار و partner1_id
$partner_name = 'نامشخص';
$partner1_id = null;
if ($order['partner_id']) {
    $stmt_partner = $pdo->prepare("
        SELECT u1.user_id AS user1_id, u1.full_name AS user1_name, u2.user_id AS user2_id, u2.full_name AS user2_name
        FROM Partners p
        LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
        LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
        WHERE p.partner_id = ?
    ");
    $stmt_partner->execute([$order['partner_id']]);
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
        $partner1_id = $user1_id; // همکار 1 برای موجودی
    }
}

if (!$partner1_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>همکار 1 یافت نشد. لطفاً با مدیر سیستم تماس بگیرید.</div></div>";
    require_once 'footer.php';
    exit;
}

// پاک کردن سشن قبلی و بارگذاری اقلام اصلی سفارش
unset($_SESSION['edit_order_items']);
unset($_SESSION['edit_order_id']);
unset($_SESSION['edit_order_discount']);
$_SESSION['edit_order_items'] = array_map(function ($item) use ($pdo) {
    $stmt_product = $pdo->prepare("SELECT product_id FROM Products WHERE product_name = ? LIMIT 1");
    $stmt_product->execute([$item['product_name']]);
    $product = $stmt_product->fetch(PDO::FETCH_ASSOC);
    return [
        'product_id' => $product ? $product['product_id'] : null,
        'product_name' => $item['product_name'],
        'quantity' => $item['quantity'],
        'unit_price' => $item['unit_price'],
        'total_price' => $item['total_price']
    ];
}, $items);
$_SESSION['edit_order_id'] = $order_id;
$_SESSION['edit_order_discount'] = $order['discount'];
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
</style>

<div class="container-fluid mt-5">
    <h5 class="card-title mb-4">ویرایش سفارش</h5>

    <div class="card mb-4">
        <div class="card-body">
            <p><strong>تاریخ:</strong> <?= gregorian_to_jalali_format($order['work_date']) ?></p>
            <p><strong>همکار:</strong> <?= htmlspecialchars($partner_name) ?></p>
        </div>
    </div>

    <form id="edit-order-form">
        <div class="mb-3">
            <label for="customer_name" class="form-label">نام مشتری</label>
            <input type="text" class="form-control" id="customer_name" name="customer_name"
                value="<?= htmlspecialchars($order['customer_name']) ?>" required autocomplete="off">
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12">
                <label for="product_name" class="form-label">نام محصول</label>
                <input type="text" class="form-control" id="product_name" name="product_name"
                    placeholder="جستجو یا وارد کنید..." style="width: 100%;">
                <div id="product_suggestions" class="list-group position-absolute"
                    style="width: 100%; z-index: 1000; display: none;"></div>
                <input type="hidden" id="product_id" name="product_id">
            </div>
            <div class="col-3">
                <label for="quantity" class="form-label">تعداد</label>
                <input type="number" class="form-control" id="quantity" name="quantity" value="1" min="1"
                    style="width: 100%;">
            </div>
            <div class="col-9">
                <label for="unit_price" class="form-label">قیمت واحد (تومان)</label>
                <input type="number" class="form-control" id="unit_price" name="unit_price" readonly
                    style="width: 100%;">
            </div>
            <div class="row mb-3">
                <div class="col-6">
                    <label for="total_price" class="form-label">قیمت کل</label>
                    <input type="text" class="form-control" id="total_price" name="total_price" readonly>
                </div>
                <div class="col-6">
                    <label for="inventory_quantity" class="form-label">موجودی</label>
                    <p class="form-control-static" id="inventory_quantity">0</p>
                </div>
            </div>
            <div class="col-12">
                <button type="button" id="add_item_btn" class="btn btn-primary mb-3">افزودن محصول</button>
                <button type="button" id="edit_item_btn" class="btn btn-warning mb-3" style="display: none;">ثبت
                    ویرایش</button>
            </div>
        </div>

        <div class="table-wrapper" id="items_table">
            <?php if (!empty($_SESSION['edit_order_items'])): ?>
                <table class="table table-light order-items-table">
                    <thead>
                        <tr>
                            <th>نام محصول</th>
                            <th>تعداد</th>
                            <th>قیمت واحد</th>
                            <th>قیمت کل</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($_SESSION['edit_order_items'] as $index => $item): ?>
                            <tr id="item_row_<?= $index ?>">
                                <td><?= htmlspecialchars($item['product_name']) ?></td>
                                <td><?= $item['quantity'] ?></td>
                                <td><?= number_format($item['unit_price'], 0) ?></td>
                                <td><?= number_format($item['total_price'], 0) ?></td>
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
                        <?php
                        $total_amount = array_sum(array_column($_SESSION['edit_order_items'], 'total_price'));
                        $discount = $_SESSION['edit_order_discount'];
                        $final_amount = $total_amount - $discount;
                        ?>
                        <tr class="total-row">
                            <td colspan="2"><strong>جمع کل</strong></td>
                            <td><strong id="total_amount"><?= number_format($total_amount, 0) ?> تومان</strong></td>
                        </tr>
                        <tr class="total-row">
                            <td><label for="discount" class="form-label">تخفیف</label></td>
                            <td><input type="number" class="form-control" id="discount" name="discount"
                                    value="<?= $discount ?>" min="0"></td>
                            <td><strong id="final_amount"><?= number_format($final_amount, 0) ?> تومان</strong></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <p><strong>جمع کل:</strong> <span id="total_amount_display"><?= number_format($total_amount, 0) ?>
                    تومان</span></p>
            <p><strong>مبلغ نهایی:</strong> <span id="final_amount_display"><?= number_format($final_amount, 0) ?>
                    تومان</span></p>
        </div>

        <button type="button" id="save_changes_btn" class="btn btn-success mt-3">ذخیره تغییرات</button>
        <a href="orders.php" class="btn btn-secondary mt-3">بازگشت</a>
    </form>
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
            return { success: false, message: 'خطایی در ارسال درخواست رخ داد.' };
        }
    }

    function renderItemsTable(data) {
        const itemsTable = document.getElementById('items_table');
        const totalAmountDisplay = document.getElementById('total_amount_display');
        const finalAmountDisplay = document.getElementById('final_amount_display');

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
                    <th>قیمت کل</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                ${data.items.map((item, index) => `
                    <tr id="item_row_${index}">
                        <td>${item.product_name}</td>
                        <td>${item.quantity}</td>
                        <td>${Number(item.unit_price).toLocaleString('fa')} تومان</td>
                        <td>${Number(item.total_price).toLocaleString('fa')} تومان</td>
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
                <tr class="total-row">
                    <td colspan="2"><strong>جمع کل</strong></td>
                    <td><strong id="total_amount">${Number(data.total_amount).toLocaleString('fa')} تومان</strong></td>
                </tr>
                <tr class="total-row">
                    <td><label for="discount" class="form-label">تخفیف</label></td>
                    <td><input type="number" class="form-control" id="discount" name="discount" value="${data.discount}" min="0"></td>
                    <td><strong id="final_amount">${Number(data.final_amount).toLocaleString('fa')} تومان</strong></td>
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
            let query = $(this).val();
            const work_details_id = '<?= htmlspecialchars($order['work_details_id'], ENT_QUOTES, 'UTF-8') ?>';
            if (query.length >= 3) {
                $.ajax({
                    url: 'get_products.php',
                    type: 'POST',
                    data: { query: query, work_details_id: work_details_id },
                    success: function (response) {
                        $('#product_suggestions').html(response).show();
                    },
                    error: function (xhr, status, error) {
                        console.error('AJAX Error: ', error);
                    }
                });
            } else {
                $('#product_suggestions').hide();
            }
        });

        $(document).on('click', '.product-suggestion', function () {
            let product = $(this).data('product');
            $('#product_name').val(product.product_name).prop('disabled', false);
            $('#product_id').val(product.product_id);
            $('#unit_price').val(product.unit_price);
            $('#total_price').val((1 * product.unit_price).toLocaleString('fa') + ' تومان');
            $('#product_suggestions').hide();

            $.ajax({
                url: 'get_inventory.php',
                type: 'POST',
                data: {
                    product_id: product.product_id,
                    user_id: '<?= $partner1_id ?>',
                    work_details_id: '<?= $order['work_details_id'] ?>'
                },
                success: function (response) {
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
                error: function () {
                    $('#inventory_quantity').text('0');
                    alert('خطا در دریافت موجودی.');
                }
            });

            $('#quantity').focus();
        });

        $('#quantity').on('input', function () {
            let quantity = $(this).val();
            let unit_price = $('#unit_price').val();
            let total = quantity * unit_price;
            $('#total_price').val(total.toLocaleString('fa') + ' تومان');
            updateInventoryDisplay();
        });

        function updateInventoryDisplay() {
            let quantity = $('#quantity').val();
            let items = <?= json_encode($_SESSION['edit_order_items']) ?>;
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
            const quantity = document.getElementById('quantity').value;
            const unit_price = document.getElementById('unit_price').value;
            const discount = document.getElementById('discount')?.value || 0;

            if (!customer_name || !product_id || !quantity || !unit_price || quantity <= 0) {
                alert('لطفاً همه فیلدها را پر کنید و تعداد را بیشتر از صفر وارد کنید.');
                return;
            }

            const items = <?= json_encode($_SESSION['edit_order_items']) ?>;
            if (items.some(item => item.product_id === product_id)) {
                alert('این محصول قبلاً در فاکتور ثبت شده است. در صورت ویرایش تعداد روی دکمه ویرایش کلیک کنید!');
                return;
            }

            const data = {
                action: 'add_edit_item',
                customer_name,
                product_id,
                quantity,
                unit_price,
                discount,
                order_id: '<?= $order_id ?>',
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

        document.getElementById('edit_item_btn').addEventListener('click', async () => {
            const customer_name = document.getElementById('customer_name').value;
            const product_id = document.getElementById('product_id').value;
            const quantity = document.getElementById('quantity').value;
            const unit_price = document.getElementById('unit_price').value;
            const discount = document.getElementById('discount')?.value || 0;

            if (!quantity || quantity <= 0) {
                alert('لطفاً تعداد را بیشتر از صفر وارد کنید.');
                return;
            }

            const data = {
                action: 'edit_edit_item',
                customer_name,
                product_id,
                quantity,
                unit_price,
                discount,
                index: editingIndex,
                order_id: '<?= $order_id ?>',
                partner1_id: '<?= $partner1_id ?>'
            };

            const editResponse = await sendRequest('ajax_handler.php', data);
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
                        action: 'delete_edit_item',
                        index: index,
                        order_id: '<?= $order_id ?>',
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
            } else if (e.target.closest('.edit-item')) {
                const index = e.target.closest('.edit-item').getAttribute('data-index');
                editingIndex = index;
                const items = <?= json_encode($_SESSION['edit_order_items']) ?>;
                const item = items[index];

                $('#product_name').val(item.product_name).prop('disabled', true);
                $('#product_id').val(item.product_id);
                $('#quantity').val(item.quantity);
                $('#unit_price').val(item.unit_price);
                $('#total_price').val((item.quantity * item.unit_price).toLocaleString('fa') + ' تومان');

                $.ajax({
                    url: 'get_inventory.php',
                    type: 'POST',
                    data: {
                        product_id: item.product_id,
                        user_id: '<?= $partner1_id ?>',
                        work_details_id: '<?= $order['work_details_id'] ?>'
                    },
                    success: function (response) {
                        if (response.success) {
                            initialInventory = response.data.inventory || 0;
                            updateInventoryDisplay();
                        } else {
                            $('#inventory_quantity').text('0');
                            alert('خطا در دریافت موجودی: ' + response.message);
                        }
                    }
                });

                $('#add_item_btn').hide();
                $('#edit_item_btn').show();
            }
        });

        document.getElementById('items_table').addEventListener('input', async (e) => {
            if (e.target.id === 'discount') {
                const discount = e.target.value || 0;
                const data = {
                    action: 'update_edit_discount',
                    discount,
                    order_id: '<?= $order_id ?>'
                };

                const response = await sendRequest('ajax_handler.php', data);
                if (response.success) {
                    // فقط مقادیر جمع کل و نهایی رو آپدیت کن، بدون رندر کل جدول
                    document.getElementById('total_amount').textContent = Number(response.data.total_amount).toLocaleString('fa') + ' تومان';
                    document.getElementById('final_amount').textContent = Number(response.data.final_amount).toLocaleString('fa') + ' تومان';
                    document.getElementById('total_amount_display').textContent = Number(response.data.total_amount).toLocaleString('fa') + ' تومان';
                    document.getElementById('final_amount_display').textContent = Number(response.data.final_amount).toLocaleString('fa') + ' تومان';
                } else {
                    alert(response.message);
                }
            }
        });

        document.getElementById('save_changes_btn').addEventListener('click', async () => {
            const customer_name = document.getElementById('customer_name').value;
            const discount = document.getElementById('discount')?.value || 0;

            if (!customer_name) {
                alert('لطفاً نام مشتری را وارد کنید.');
                return;
            }

            const data = {
                action: 'save_edit_order',
                order_id: '<?= $order_id ?>',
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