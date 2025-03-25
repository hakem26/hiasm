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

// دریافت اقلام سفارش
$items_stmt = $pdo->prepare("SELECT * FROM Order_Items WHERE order_id = ?");
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// دریافت نام همکار
$partner_name = 'نامشخص';
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
    }
}

// دریافت user_id همکار ۱ برای مدیریت موجودی
$stmt_partner = $pdo->prepare("SELECT user_id1 FROM Partners WHERE partner_id = ?");
$stmt_partner->execute([$order['partner_id']]);
$partner_data = $stmt_partner->fetch(PDO::FETCH_ASSOC);
$partner1_id = $partner_data['user_id1'] ?? null;

if (!$partner1_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>همکار ۱ یافت نشد. لطفاً با مدیر سیستم تماس بگیرید.</div></div>";
    require_once 'footer.php';
    exit;
}

// مدیریت ارسال فرم (ویرایش سفارش)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_name = $_POST['customer_name'] ?? '';
    $discount = (int) ($_POST['discount'] ?? 0);
    $products = $_POST['products'] ?? [];

    if (empty($customer_name) || empty($products)) {
        echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>لطفاً تمام فیلدهای الزامی را پر کنید.</div></div>";
    } else {
        // محاسبه مجموع
        $total_amount = 0;
        foreach ($products as $product) {
            $total_amount += ($product['quantity'] * $product['unit_price']);
        }
        $final_amount = $total_amount - $discount;

        $pdo->beginTransaction();
        try {
            // برگرداندن موجودی محصولات قبلی برای همکار ۱
            foreach ($items as $item) {
                $stmt_product = $pdo->prepare("SELECT product_id FROM Products WHERE product_name = ? LIMIT 1");
                $stmt_product->execute([$item['product_name']]);
                $product = $stmt_product->fetch(PDO::FETCH_ASSOC);
                $product_id = $product ? $product['product_id'] : null;

                if ($product_id) {
                    $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
                    $stmt_inventory->execute([$partner1_id, $product_id]);
                    $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);

                    $current_quantity = $inventory ? $inventory['quantity'] : 0;
                    $new_quantity = $current_quantity + $item['quantity'];

                    $stmt_update = $pdo->prepare("INSERT INTO Inventory (user_id, product_id, quantity) VALUES (?, ?, ?) 
                                               ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
                    $stmt_update->execute([$partner1_id, $product_id, $new_quantity]);
                }
            }

            // چک کردن موجودی محصولات جدید برای همکار ۱
            foreach ($products as $product) {
                $stmt_product = $pdo->prepare("SELECT product_id FROM Products WHERE product_name = ? LIMIT 1");
                $stmt_product->execute([$product['name']]);
                $product_data = $stmt_product->fetch(PDO::FETCH_ASSOC);
                $product_id = $product_data ? $product_data['product_id'] : null;

                if ($product_id) {
                    $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
                    $stmt_inventory->execute([$partner1_id, $product_id]);
                    $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);

                    $current_quantity = $inventory ? $inventory['quantity'] : 0;
                    if ($current_quantity < $product['quantity']) {
                        throw new Exception("موجودی کافی برای محصول '{$product['name']}' نیست. موجودی: $current_quantity، درخواست: {$product['quantity']}");
                    }

                    $new_quantity = $current_quantity - $product['quantity'];
                    $stmt_update = $pdo->prepare("INSERT INTO Inventory (user_id, product_id, quantity) VALUES (?, ?, ?) 
                                               ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
                    $stmt_update->execute([$partner1_id, $product_id, $new_quantity]);
                }
            }

            // به‌روزرسانی جدول Orders
            $stmt = $pdo->prepare("
                UPDATE Orders 
                SET customer_name = ?, total_amount = ?, discount = ?, final_amount = ?
                WHERE order_id = ?
            ");
            $stmt->execute([$customer_name, $total_amount, $discount, $final_amount, $order_id]);

            // حذف اقلام قبلی از Order_Items
            $stmt = $pdo->prepare("DELETE FROM Order_Items WHERE order_id = ?");
            $stmt->execute([$order_id]);

            // اضافه کردن اقلام جدید به Order_Items
            foreach ($products as $product) {
                $stmt = $pdo->prepare("
                    INSERT INTO Order_Items (order_id, product_name, quantity, unit_price, total_price)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $total_price = $product['quantity'] * $product['unit_price'];
                $stmt->execute([
                    $order_id,
                    $product['name'],
                    $product['quantity'],
                    $product['unit_price'],
                    $total_price
                ]);
            }

            $pdo->commit();
            echo "<div class='container-fluid mt-5'><div class='alert alert-success text-center'>سفارش با موفقیت ویرایش شد. <a href='orders.php'>بازگشت به لیست سفارشات</a></div></div>";
            require_once 'footer.php';
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>خطا در ویرایش سفارش: " . $e->getMessage() . "</div></div>";
        }
    }
}
?>

    <div class="container-fluid mt-5">
        <h5 class="card-title mb-4">ویرایش سفارش</h5>

        <!-- اطلاعات روز کاری (فقط نمایش) -->
        <div class="card mb-4">
            <div class="card-body">
                <p><strong>تاریخ:</strong> <?= gregorian_to_jalali_format($order['work_date']) ?></p>
                <p><strong>همکار:</strong> <?= htmlspecialchars($partner_name) ?></p>
            </div>
        </div>

        <!-- فرم ویرایش سفارش -->
        <form id="edit-order-form" method="POST">
            <div class="mb-3">
                <label for="customer_name" class="form-label">نام مشتری</label>
                <input type="text" class="form-control" id="customer_name" name="customer_name"
                    value="<?= htmlspecialchars($order['customer_name']) ?>" required autocomplete="off">
            </div>

            <!-- فرم افزودن محصول -->
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
                </div>
            </div>

            <!-- جدول اقلام -->
            <div class="table-wrapper" id="items_table">
                <table class="table table-light">
                    <thead>
                        <tr>
                            <th>نام محصول</th>
                            <th>تعداد</th>
                            <th>قیمت واحد</th>
                            <th>قیمت کل</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody id="productsTable">
                        <?php
                        $initial_total = 0;
                        foreach ($items as $index => $item):
                            $initial_total += $item['total_price'];
                            ?>
                            <tr id="productRow_<?= $index ?>">
                                <td><?= htmlspecialchars($item['product_name']) ?></td>
                                <td><?= $item['quantity'] ?></td>
                                <td><?= number_format($item['unit_price'], 0) ?> تومان</td>
                                <td><?= number_format($item['total_price'], 0) ?> تومان</td>
                                <td>
                                    <button type="button" class="btn btn-danger btn-sm delete-item"
                                        data-index="<?= $index ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                                <input type="hidden" name="products[<?= $index ?>][name]"
                                    value="<?= htmlspecialchars($item['product_name']) ?>">
                                <input type="hidden" name="products[<?= $index ?>][quantity]"
                                    value="<?= $item['quantity'] ?>">
                                <input type="hidden" name="products[<?= $index ?>][unit_price]"
                                    value="<?= $item['unit_price'] ?>">
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="3"><strong>جمع کل</strong></td>
                            <td colspan="2"><strong id="total_amount"><?= number_format($initial_total, 0) ?>
                                    تومان</strong></td>
                        </tr>
                        <tr class="total-row">
                            <td colspan="2"><label for="discount" class="form-label">تخفیف</label></td>
                            <td><input type="number" class="form-control" id="discount" name="discount"
                                    value="<?= $order['discount'] ?>" min="0"></td>
                            <td colspan="2"><strong
                                    id="final_amount"><?= number_format($initial_total - $order['discount'], 0) ?>
                                    تومان</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- نمایش پیش‌فرض برای جمع کل و تخفیف -->
            <div class="mb-3">
                <p><strong>جمع کل:</strong> <span id="total_amount_display"><?= number_format($initial_total, 0) ?>
                        تومان</span></p>
                <p><strong>مبلغ نهایی:</strong> <span
                        id="final_amount_display"><?= number_format($initial_total - $order['discount'], 0) ?>
                        تومان</span></p>
            </div>

            <button type="submit" class="btn btn-success mt-3">ذخیره تغییرات</button>
            <a href="orders.php" class="btn btn-secondary mt-3">بازگشت</a>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let productCount = <?= count($items) ?>;
        let initialInventory = 0; // متغیر برای ذخیره موجودی اولیه

        // ساجستشن محصولات
        $('#product_name').on('input', function () {
            let query = $(this).val();
            const work_details_id = '<?= htmlspecialchars($order['work_details_id'], ENT_QUOTES, 'UTF-8') ?>';
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
                    user_id: '<?= $partner1_id ?>'
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

        // افزودن محصول جدید
        $('#add_item_btn').on('click', addProduct);

        function addProduct() {
            const productName = $('#product_name').val().trim();
            const quantity = $('#quantity').val().trim();
            const unitPrice = $('#unit_price').val().trim();
            const productId = $('#product_id').val().trim();

            console.log('Debug: Adding product - Name:', productName, 'Quantity:', quantity, 'UnitPrice:', unitPrice, 'ProductID:', productId, 'Partner1_ID:', '<?= $partner1_id ?>');

            if (!productName || !quantity || !unitPrice || quantity <= 0) {
                alert('لطفاً همه فیلدها را پر کنید و تعداد را بیشتر از صفر وارد کنید.');
                return;
            }

            // اضافه کردن محصول بدون چک کردن موجودی (چک کردن توی سرور انجام می‌شه)
            const total = quantity * unitPrice;
            const row = `
                <tr id="productRow_${productCount}">
                    <td>${productName}</td>
                    <td>${quantity}</td>
                    <td>${parseInt(unitPrice).toLocaleString('fa')} تومان</td>
                    <td>${parseInt(total).toLocaleString('fa')} تومان</td>
                    <td>
                        <button type="button" class="btn btn-danger btn-sm delete-item" data-index="${productCount}">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                    <input type="hidden" name="products[${productCount}][name]" value="${productName}">
                    <input type="hidden" name="products[${productCount}][quantity]" value="${quantity}">
                    <input type="hidden" name="products[${productCount}][unit_price]" value="${unitPrice}">
                </tr>
            `;

            $('#productsTable').append(row);
            updateTotals();
            productCount++;

            // پاک کردن فرم
            $('#product_name').val('');
            $('#quantity').val('1');
            $('#total_price').val('');
            $('#product_id').val('');
            $('#unit_price').val('');
            $('#inventory_quantity').text('0');
            initialInventory = 0;
        }

        // حذف محصول
        function deleteProduct(index) {
            if (confirm('آیا از حذف این محصول مطمئن هستید؟')) {
                // دریافت اطلاعات محصول برای برگرداندن موجودی
                const productName = $(`#productRow_${index}`).find('input[name^="products["][name$="[name]"]').val();
                const quantity = parseInt($(`#productRow_${index}`).find('input[name^="products["][name$="[quantity]"]').val());

                // ارسال درخواست AJAX برای برگرداندن موجودی
                $.ajax({
                    url: 'update_inventory.php',
                    type: 'POST',
                    data: {
                        product_name: productName,
                        quantity: quantity,
                        user_id: '<?= $partner1_id ?>',
                        action: 'add' // برای اضافه کردن به موجودی
                    },
                    success: function (response) {
                        if (response.success) {
                            $(`#productRow_${index}`).remove();
                            updateTotals();
                        } else {
                            alert('خطا در به‌روزرسانی موجودی: ' + response.message);
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('AJAX Error: ', error);
                        alert('خطا در به‌روزرسانی موجودی.');
                    }
                });
            }
        }

        // به‌روزرسانی جمع کل و تخفیف
        function updateTotals() {
            let totalAmount = 0;
            $('#productsTable tr').each(function () {
                if ($(this).attr('id') && $(this).attr('id').startsWith('productRow_')) {
                    const unitPrice = parseInt($(this).find('input[name^="products["][name$="[unit_price]"]').val());
                    const quantity = parseInt($(this).find('input[name^="products["][name$="[quantity]"]').val());
                    totalAmount += unitPrice * quantity;
                }
            });

            const discount = parseInt($('#discount').val()) || 0;
            const finalAmount = totalAmount - discount;

            $('#total_amount').text(totalAmount.toLocaleString('fa') + ' تومان');
            $('#final_amount').text(finalAmount.toLocaleString('fa') + ' تومان');
            $('#total_amount_display').text(totalAmount.toLocaleString('fa') + ' تومان');
            $('#final_amount_display').text(finalAmount.toLocaleString('fa') + ' تومان');
        }

        // رویدادها
        $('#add_item_btn').on('click', addProduct);

        $(document).on('click', '.delete-item', function () {
            const index = $(this).data('index');
            deleteProduct(index);
        });

        $('#discount').on('input', function () {
            updateTotals();
        });

        // بارگذاری اولیه
        $(document).ready(function () {
            updateTotals();
        });
    </script>

    <?php require_once 'footer.php'; ?>