<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل میلادی به شمسی
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

// ریست کردن سشن order_items در ابتدای صفحه برای جلوگیری از نمایش فاکتور قبلی
if (!isset($_SESSION['is_order_in_progress']) || !$_SESSION['is_order_in_progress']) {
    unset($_SESSION['order_items']);
}
$_SESSION['is_order_in_progress'] = true;

// دریافت اطلاعات روز کاری از work_details_id (از GET)
$work_details_id = $_GET['work_details_id'] ?? '';
$work_info = [];
if ($work_details_id) {
    $stmt_work = $pdo->prepare("SELECT wd.work_date, wd.partner_id FROM Work_Details wd WHERE wd.id = ?");
    $stmt_work->execute([$work_details_id]);
    $work_info = $stmt_work->fetch(PDO::FETCH_ASSOC);

    if ($work_info) {
        $stmt_partner = $pdo->prepare("
            SELECT p.partner_id
            FROM Partners p
            WHERE (p.user_id1 = ? OR p.user_id2 = ?) 
            AND p.partner_id = (SELECT partner_id FROM Work_Details WHERE id = ?)
        ");
        $stmt_partner->execute([$current_user_id, $current_user_id, $work_details_id]);
        $partner_access = $stmt_partner->fetch(PDO::FETCH_ASSOC);

        if (!$partner_access) {
            if ($work_info['partner_id'] != $current_user_id) {
                $work_info = [];
            }
        }
    }
}

// تنظیم خودکار partner_id اگه هنوز ثبت نشده
if ($work_details_id && empty($work_info)) {
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM Work_Details WHERE id = ?");
    $stmt_check->execute([$work_details_id]);
    if ($stmt_check->fetchColumn() == 0) {
        $stmt_insert = $pdo->prepare("
            INSERT INTO Work_Details (id, work_date, work_month_id, partner_id)
            VALUES (?, CURDATE(), (SELECT work_month_id FROM Work_Months WHERE CURDATE() BETWEEN start_date AND end_date LIMIT 1), ?)
        ");
        $stmt_insert->execute([$work_details_id, $current_user_id]);
        $work_info = ['work_date' => date('Y-m-d'), 'partner_id' => $current_user_id];
    }
}

if (empty($work_info)) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>لطفاً ابتدا یک روز کاری انتخاب کنید یا روز کاری معتبر نیست.</div></div>";
    require_once 'footer.php';
    exit;
}

// دریافت نام همکار از جفت همکارها
$partner_name = 'نامشخص';
if ($work_info['partner_id']) {
    $stmt_partner = $pdo->prepare("
        SELECT u1.user_id AS user1_id, u1.full_name AS user1_name, u2.user_id AS user2_id, u2.full_name AS user2_name
        FROM Partners p
        LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
        LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
        WHERE p.partner_id = ?
    ");
    $stmt_partner->execute([$work_info['partner_id']]);
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
    }
}

// مقادیر اولیه
$items = isset($_SESSION['order_items']) ? $_SESSION['order_items'] : [];
$customer_name = '';
$total_amount = array_sum(array_column($items, 'total_price'));
$discount = 0;
$final_amount = $total_amount - $discount;

// دریافت user_id همکار ۱ برای مدیریت موجودی
$stmt_partner = $pdo->prepare("SELECT user_id1 FROM Partners WHERE partner_id = ?");
$stmt_partner->execute([$work_info['partner_id']]);
$partner_data = $stmt_partner->fetch(PDO::FETCH_ASSOC);
$partner1_id = $partner_data['user_id1'] ?? null;

if (!$partner1_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>همکار ۱ یافت نشد. لطفاً با مدیر سیستم تماس بگیرید.</div></div>";
    require_once 'footer.php';
    exit;
}
?>

<style>
    /* جلوگیری از اسکرول افقی صفحه اصلی */
    body,
    .container-fluid {
        overflow-x: hidden !important;
    }

    /* تنظیمات div اطراف جدول */
    .table-wrapper {
        width: 100%;
        overflow-x: auto !important;
        /* اسکرول افقی فقط برای جدول */
        overflow-y: visible;
        /* جلوگیری از اسکرول عمودی غیرضروری */
        -webkit-overflow-scrolling: touch;
        /* اسکرول روان در دستگاه‌های لمسی */
    }

    /* تنظیمات جدول */
    .order-items-table {
        width: 100%;
        min-width: 800px;
        /* حداقل عرض جدول برای فعال شدن اسکرول */
        border-collapse: collapse;
    }

    /* تنظیمات ستون‌ها */
    .order-items-table th,
    .order-items-table td {
        vertical-align: middle !important;
        white-space: nowrap !important;
        /* جلوگیری از شکستن متن */
        padding: 8px;
        min-width: 120px;
        /* حداقل عرض ستون‌ها */
    }

    /* استایل برای ردیف جمع کل و تخفیف */
    .order-items-table .total-row td {
        font-weight: bold;
    }

    /* تنظیم عرض ورودی تخفیف */
    .order-items-table .total-row input#discount {
        width: 150px;
        margin: 0 auto;
    }
</style>

<div class="container-fluid mt-5">
    <h5 class="card-title mb-4">ثبت فاکتور</h5>

    <!-- اطلاعات روز کاری (فقط نمایش) -->
    <div class="card mb-4">
        <div class="card-body">
            <p><strong>تاریخ:</strong> <?= gregorian_to_jalali_format($work_info['work_date']) ?></p>
            <p><strong>همکار:</strong> <?= htmlspecialchars($partner_name) ?></p>
        </div>
    </div>

    <!-- فرم ثبت فاکتور -->
    <form id="order-form">
        <div class="mb-3">
            <label for="customer_name" class="form-label">نام مشتری</label>
            <input type="text" class="form-control" id="customer_name" name="customer_name"
                value="<?= htmlspecialchars($customer_name) ?>" required autocomplete="off">
        </div>

        <!-- انتخاب محصول -->
        <div class="row g-3 mb-3">
            <div class="col-12">
                <label for="product_name" class="form-label">نام محصول</label>
                <input type="text" class="form-control" id="product_name" name="product_name"
                    placeholder="جستجو یا وارد کنید..." required style="width: 100%;">
                <div id="product_suggestions" class="list-group position-absolute"
                    style="width: 100%; z-index: 1000; display: none;"></div>
                <input type="hidden" id="product_id" name="product_id">
            </div>
            <div class="col-3">
                <label for="quantity" class="form-label">تعداد</label>
                <input type="number" class="form-control" id="quantity" name="quantity" value="1" min="1" required
                    autocomplete="off" style="width: 100%;">
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
            </div>
        </div>

        <!-- جدول فاکتور -->
        <div class="table-wrapper" id="items_table">
            <?php if (!empty($items)): ?>
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
                        <?php foreach ($items as $index => $item): ?>
                            <tr id="item_row_<?= $index ?>">
                                <td><?= htmlspecialchars($item['product_name']) ?></td>
                                <td><?= $item['quantity'] ?></td>
                                <td><?= number_format($item['unit_price'], 0) ?></td>
                                <td><?= number_format($item['total_price'], 0) ?></td>
                                <td>
                                    <button type="button" class="btn btn-danger btn-sm delete-item" data-index="<?= $index ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
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

        <!-- نمایش پیش‌فرض برای جمع کل و تخفیف -->
        <div class="mb-3">
            <p><strong>جمع کل:</strong> <span id="total_amount_display"><?= number_format($total_amount, 0) ?>
                    تومان</span></p>
            <p><strong>مبلغ نهایی:</strong> <span id="final_amount_display"><?= number_format($final_amount, 0) ?>
                    تومان</span></p>
        </div>

        <button type="button" id="finalize_order_btn" class="btn btn-success mt-3">بستن فاکتور</button>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // تابع برای ارسال درخواست Fetch
    async function sendRequest(url, data) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(data)
            });
            return await response.json();
        } catch (error) {
            console.error('Error:', error);
            return { success: false, message: 'خطایی در ارسال درخواست رخ داد.' };
        }
    }

    // رندر جدول آیتم‌ها
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
        let initialInventory = 0; // متغیر برای ذخیره موجودی اولیه

        // ساجستشن محصولات با jQuery
        $('#product_name').on('input', function () {
            let query = $(this).val();
            const work_details_id = '<?= htmlspecialchars($work_details_id, ENT_QUOTES, 'UTF-8') ?>';
            console.log('Debug: Searching with work_details_id = ', work_details_id);
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
            $('#product_name').val(product.product_name);
            $('#product_id').val(product.product_id);
            $('#unit_price').val(product.unit_price);
            $('#total_price').val((1 * product.unit_price).toLocaleString('fa') + ' تومان');
            $('#product_suggestions').hide();

            // دریافت موجودی محصول برای همکار ۱ (فقط برای نمایش لیبل)
            console.log('Fetching inventory for product_id:', product.product_id, 'user_id:', '<?= $partner1_id ?>');
            $.ajax({
                url: 'get_inventory.php',
                type: 'POST',
                data: {
                    product_id: product.product_id,
                    user_id: '<?= $partner1_id ?>' // همکار ۱
                },
                success: function (response) {
                    console.log('Inventory response (display):', response);
                    if (response.success) {
                        let inventory = response.data.inventory || 0;
                        initialInventory = inventory;
                        $('#inventory_quantity').text(inventory);
                        $('#quantity').val(1); // مقدار پیش‌فرض تعداد
                        updateInventoryDisplay(); // به‌روزرسانی نمایش موجودی
                    } else {
                        console.error('Failed to fetch inventory:', response.message);
                        $('#inventory_quantity').text('0');
                        alert('خطا در دریافت موجودی: ' + response.message);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('AJAX Error: ', error);
                    $('#inventory_quantity').text('0');
                    alert('خطا در دریافت موجودی.');
                }
            });

            $('#quantity').focus();
        });

        // به‌روزرسانی قیمت کل و موجودی با تغییر تعداد
        $('#quantity').on('input', function () {
            let quantity = $(this).val();
            let unit_price = $('#unit_price').val();
            let total = quantity * unit_price;
            $('#total_price').val(total.toLocaleString('fa') + ' تومان');
            updateInventoryDisplay();
        });

        // تابع برای به‌روزرسانی نمایش موجودی
        function updateInventoryDisplay() {
            let quantity = $('#quantity').val();
            let remainingInventory = initialInventory - quantity;
            $('#inventory_quantity').text(remainingInventory);
        }

        // افزودن محصول
        document.getElementById('add_item_btn').addEventListener('click', async () => {
            const customer_name = document.getElementById('customer_name').value;
            const product_id = document.getElementById('product_id').value;
            const quantity = document.getElementById('quantity').value;
            const unit_price = document.getElementById('unit_price').value;
            const discount = document.getElementById('discount')?.value || 0;
            const work_details_id = '<?= htmlspecialchars($work_details_id, ENT_QUOTES, 'UTF-8') ?>';

            console.log('Debug: Adding item - ProductID:', product_id, 'Quantity:', quantity, 'UnitPrice:', unit_price, 'Partner1_ID:', '<?= $partner1_id ?>');

            if (!customer_name || !product_id || !quantity || !unit_price || quantity <= 0) {
                alert('لطفاً همه فیلدها را پر کنید و تعداد را بیشتر از صفر وارد کنید.');
                return;
            }

            const data = {
                action: 'add_item',
                customer_name,
                product_id,
                quantity,
                unit_price,
                discount,
                work_details_id,
                partner1_id: '<?= $partner1_id ?>' // برای کسر موجودی همکار ۱
            };

            const addResponse = await sendRequest('ajax_handler.php', data);
            if (addResponse.success) {
                renderItemsTable(addResponse.data);
                document.getElementById('product_name').value = '';
                document.getElementById('quantity').value = '1';
                document.getElementById('total_price').value = '';
                document.getElementById('product_id').value = '';
                document.getElementById('unit_price').value = '';
                document.getElementById('inventory_quantity').textContent = '0';
                initialInventory = 0;
            } else {
                alert(addResponse.message);
            }
        });

        // حذف محصول
        document.getElementById('items_table').addEventListener('click', async (e) => {
            if (e.target.closest('.delete-item')) {
                const index = e.target.closest('.delete-item').getAttribute('data-index');
                if (confirm('آیا از حذف این محصول مطمئن هستید؟')) {
                    // دریافت اطلاعات محصول برای برگرداندن موجودی
                    const productName = document.querySelector(`#item_row_${index} td:nth-child(1)`).textContent;
                    const quantity = parseInt(document.querySelector(`#item_row_${index} td:nth-child(2)`).textContent);

                    // ارسال درخواست AJAX برای برگرداندن موجودی
                    const inventoryResponse = await sendRequest('update_inventory.php', {
                        product_name: productName,
                        quantity: quantity,
                        user_id: '<?= $partner1_id ?>',
                        action: 'add' // برای اضافه کردن به موجودی
                    });

                    if (!inventoryResponse.success) {
                        alert('خطا در به‌روزرسانی موجودی: ' + inventoryResponse.message);
                        return;
                    }

                    const data = {
                        action: 'delete_item',
                        index: index,
                        partner1_id: '<?= $partner1_id ?>'
                    };

                    const response = await sendRequest('ajax_handler.php', data);
                    if (response.success) {
                        renderItemsTable(response.data);
                    } else {
                        alert(response.message);
                    }
                }
            }
        });

        // به‌روزرسانی تخفیف
        document.getElementById('items_table').addEventListener('input', async (e) => {
            if (e.target.id === 'discount') {
                const discount = e.target.value;
                const data = {
                    action: 'update_discount',
                    discount
                };

                const response = await sendRequest('ajax_handler.php', data);
                if (response.success) {
                    const discountInput = document.getElementById('discount');
                    const finalAmountDisplay = document.getElementById('final_amount');
                    const finalAmountGlobalDisplay = document.getElementById('final_amount_display');

                    if (discountInput) {
                        discountInput.value = response.data.discount;
                    }
                    if (finalAmountDisplay) {
                        finalAmountDisplay.innerText = Number(response.data.final_amount).toLocaleString('fa') + ' تومان';
                    }
                    if (finalAmountGlobalDisplay) {
                        finalAmountGlobalDisplay.textContent = Number(response.data.final_amount).toLocaleString('fa') + ' تومان';
                    }
                } else {
                    alert(response.message);
                }
            }
        });

        // بستن فاکتور
        document.getElementById('finalize_order_btn').addEventListener('click', async () => {
            const customer_name = document.getElementById('customer_name').value;
            const discount = document.getElementById('discount')?.value || 0;

            if (!customer_name) {
                alert('لطفاً نام مشتری را وارد کنید.');
                return;
            }

            const data = {
                action: 'finalize_order',
                work_details_id: '<?= $work_details_id ?>',
                customer_name,
                discount,
                partner1_id: '<?= $partner1_id ?>' // برای کسر موجودی همکار ۱
            };

            const response = await sendRequest('ajax_handler.php', data);
            if (response.success) {
                alert(response.message);
                window.location.href = response.data.redirect;
            } else {
                alert(response.message);
            }
        });
    });
</script>

<?php require_once 'footer.php'; ?>