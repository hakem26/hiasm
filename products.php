<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

echo "<!-- دیباگ: بعد از لود header -->";

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date)
{
    if (!$gregorian_date)
        return "نامشخص";
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

$is_admin = ($_SESSION['role'] === 'admin');
$is_seller = ($_SESSION['role'] === 'seller');
echo "<!-- دیباگ: is_admin = " . ($is_admin ? 'true' : 'false') . ", is_seller = " . ($is_seller ? 'true' : 'false') . " -->";

// پردازش افزودن محصول (فقط برای ادمین)
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $product_name = trim($_POST['product_name']);
    $unit_price = trim($_POST['unit_price']);
    $partner_profit = (float) ($_POST['partner_profit'] ?? 0.00); // سود همکار
    if (!empty($product_name) && !empty($unit_price) && is_numeric($unit_price)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO Products (product_name, unit_price, partner_profit) VALUES (?, ?, ?)");
            $stmt->execute([$product_name, $unit_price, $partner_profit]);
            echo "<script>alert('محصول با موفقیت اضافه شد!'); window.location.href='products.php';</script>";
        } catch (Exception $e) {
            echo "<script>alert('خطا در افزودن محصول: " . $e->getMessage() . "');</script>";
        }
    } else {
        echo "<script>alert('لطفاً نام محصول و قیمت را به درستی وارد کنید!');</script>";
    }
}

// پردازش حذف محصول (فقط برای ادمین)
if ($is_admin && isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $product_id = (int) $_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM Products WHERE product_id = ?");
        $stmt->execute([$product_id]);
        echo "<script>alert('محصول با موفقیت حذف شد!'); window.location.href='products.php';</script>";
    } catch (Exception $e) {
        echo "<script>alert('خطا در حذف محصول: " . $e->getMessage() . "');</script>";
    }
}

// پردازش به‌روزرسانی موجودی مدیر
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin_inventory'])) {
    $product_id = (int) $_POST['product_id'];
    $new_quantity = (int) $_POST['new_quantity'];

    try {
        $stmt = $pdo->prepare("INSERT INTO Admin_Inventory (product_id, quantity) VALUES (?, ?) 
                             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
        $stmt->execute([$product_id, $new_quantity]);
        echo "<script>alert('موجودی مدیر با موفقیت به‌روزرسانی شد!'); window.location.href='products.php';</script>";
    } catch (Exception $e) {
        echo "<script>alert('خطا در به‌روزرسانی موجودی: " . $e->getMessage() . "');</script>";
    }
}

// پردازش به‌روزرسانی سود همکار
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_partner_profit'])) {
    $product_id = (int) $_POST['product_id'];
    $partner_profit = (float) $_POST['partner_profit'];

    try {
        $stmt = $pdo->prepare("UPDATE Products SET partner_profit = ? WHERE product_id = ?");
        $stmt->execute([$partner_profit, $product_id]);
        echo "<script>alert('سود همکار با موفقیت به‌روزرسانی شد!'); window.location.href='products.php';</script>";
    } catch (Exception $e) {
        echo "<script>alert('خطا در به‌روزرسانی سود همکار: " . $e->getMessage() . "');</script>";
    }
}

// پردازش تخصیص موجودی به همکار 1
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['allocate_inventory'])) {
    $product_id = (int) $_POST['product_id'];
    $user_id = (int) $_POST['user_id'];
    $quantity = (int) $_POST['quantity'];
    $work_month_id = (int) $_POST['work_month_id'];

    try {
        // بررسی موجودی مدیر
        $admin_inventory_query = $pdo->prepare("SELECT quantity FROM Admin_Inventory WHERE product_id = ?");
        $admin_inventory_query->execute([$product_id]);
        $admin_inventory = $admin_inventory_query->fetch(PDO::FETCH_ASSOC);
        $admin_quantity = $admin_inventory ? $admin_inventory['quantity'] : 0;

        if ($admin_quantity < $quantity) {
            echo "<script>alert('موجودی مدیر کافی نیست! موجودی فعلی: $admin_quantity'); window.location.href='products.php';</script>";
            exit;
        }

        // کسر از موجودی مدیر
        $update_admin_query = $pdo->prepare("UPDATE Admin_Inventory SET quantity = quantity - ? WHERE product_id = ?");
        $update_admin_query->execute([$quantity, $product_id]);

        // اضافه کردن به موجودی همکار 1
        $update_user_query = $pdo->prepare("
            INSERT INTO Inventory (user_id, product_id, quantity) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE quantity = quantity + ?
        ");
        $update_user_query->execute([$user_id, $product_id, $quantity, $quantity]);

        // ثبت تراکنش
        $transaction_query = $pdo->prepare("
            INSERT INTO Inventory_Transactions (product_id, user_id, quantity, work_month_id) 
            VALUES (?, ?, ?, ?)
        ");
        $transaction_query->execute([$product_id, $user_id, $quantity, $work_month_id]);

        echo "<script>alert('موجودی با موفقیت تخصیص داده شد!'); window.location.href='products.php';</script>";
    } catch (Exception $e) {
        echo "<script>alert('خطا در تخصیص موجودی: " . $e->getMessage() . "');</script>";
    }
}

// دریافت محصولات
$products = [];
try {
    $products = $pdo->query("SELECT * FROM Products ORDER BY product_id DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo "<!-- دیباگ: تعداد محصولات = " . count($products) . " -->";

    // مرتب‌سازی بر اساس الفبای فارسی
    $collator = new Collator('fa_IR');
    usort($products, function ($a, $b) use ($collator) {
        return $collator->compare($a['product_name'], $b['product_name']);
    });

    // دریافت آخرین قیمت برای هر محصول
    foreach ($products as &$product) {
        $stmt = $pdo->prepare("SELECT unit_price FROM Product_Price_History WHERE product_id = ? ORDER BY start_date DESC LIMIT 1");
        $stmt->execute([$product['product_id']]);
        $latest_price = $stmt->fetch(PDO::FETCH_ASSOC);
        $product['latest_price'] = $latest_price ? $latest_price['unit_price'] : null;
    }
    unset($product);

    // دریافت موجودی مدیر
    if ($is_admin) {
        foreach ($products as &$product) {
            $stmt = $pdo->prepare("SELECT quantity FROM Admin_Inventory WHERE product_id = ?");
            $stmt->execute([$product['product_id']]);
            $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
            $product['admin_inventory'] = $inventory ? $inventory['quantity'] : 0;
        }
        unset($product);
    }

    // دریافت موجودی برای همکار 1
    $current_user_id = $_SESSION['user_id'];
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM Partners WHERE user_id1 = ?");
    $stmt_check->execute([$current_user_id]);
    $is_partner1 = $stmt_check->fetchColumn() > 0;

    if ($is_partner1) {
        foreach ($products as &$product) {
            $stmt = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ?");
            $stmt->execute([$current_user_id, $product['product_id']]);
            $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
            $product['inventory'] = $inventory ? $inventory['quantity'] : 0;
        }
        unset($product);
    }

    // دریافت تاریخچه قیمت‌ها برای فروشنده
    if ($is_seller) {
        foreach ($products as &$product) {
            $stmt = $pdo->prepare("SELECT * FROM Product_Price_History WHERE product_id = ? ORDER BY start_date DESC LIMIT 2");
            $stmt->execute([$product['product_id']]);
            $product['price_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($product['price_history'] as &$price) {
                $stmt = $pdo->prepare("SELECT unit_price FROM Product_Price_History WHERE product_id = ? AND start_date < ? ORDER BY start_date DESC LIMIT 1");
                $stmt->execute([$product['product_id'], $price['start_date']]);
                $previous_price = $stmt->fetch(PDO::FETCH_ASSOC);
                $price['previous_price'] = $previous_price ? $previous_price['unit_price'] : $product['unit_price'];
            }
            unset($price);
        }
        unset($product);
    }
} catch (Exception $e) {
    echo "<!-- خطا در کوئری محصولات: " . $e->getMessage() . " -->";
}

// دریافت کاربران همکار 1 و ماه‌های کاری برای تخصیص
$partner1_users = [];
$work_months = [];
if ($is_admin) {
    $partner1_query = $pdo->query("SELECT DISTINCT u.user_id, u.full_name 
                                   FROM Users u 
                                   JOIN Partners p ON u.user_id = p.user_id1 
                                   WHERE u.role = 'seller'");
    $partner1_users = $partner1_query->fetchAll(PDO::FETCH_ASSOC);

    $work_months_query = $pdo->query("SELECT work_month_id, start_date, end_date FROM Work_Months ORDER BY start_date DESC");
    $work_months = $work_months_query->fetchAll(PDO::FETCH_ASSOC);
}

// محاسبه اندیس ستون "تغییرات" قبل از جاوااسکریپت
$changes_column_index = 3; // مقدار پیش‌فرض
if ($is_admin && $is_partner1) {
    $changes_column_index = 6; // به خاطر اضافه شدن ستون "سود همکار"
} elseif ($is_admin || $is_partner1) {
    $changes_column_index = 5;
}
?>

<div class="container-fluid">
    <h5 class="card-title">مدیریت محصولات</h5>
    <br>
    <?php if ($is_admin): ?>
        <form method="POST" class="row g-3 mb-3">
            <div class="col-auto">
                <input type="text" class="form-control" name="product_name" placeholder="نام محصول" required>
            </div>
            <div class="col-auto">
                <input type="number" class="form-control" name="unit_price" placeholder="قیمت واحد (تومان)" step="0.01" required>
            </div>
            <div class="col-auto">
                <input type="number" class="form-control" name="partner_profit" placeholder="سود همکار (درصد)" step="0.01" value="0.00">
            </div>
            <div class="col-auto">
                <button type="submit" name="add_product" class="btn btn-primary">افزودن محصول</button>
            </div>
        </form>
    <?php endif; ?>

    <?php if (!empty($products)): ?>
        <div class="table-responsive" style="overflow-x: auto; width: 100%;">
            <table id="productsTable" class="table table-light table-hover display nowrap" style="width: 100%; min-width: 1200px;">
                <thead>
                    <tr>
                        <th>شناسه</th>
                        <th>نام محصول</th>
                        <th>قیمت واحد (تومان)</th>
                        <?php if ($is_admin): ?>
                            <th>موجودی مدیر</th>
                            <th>سود همکار (%)</th>
                            <th>تخصیص به همکار</th>
                            <th>عملیات</th>
                        <?php endif; ?>
                        <?php if ($is_seller && $is_partner1): ?>
                            <th>موجودی شما</th>
                        <?php endif; ?>
                        <?php if ($is_seller): ?>
                            <th>تغییرات</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?= $product['product_id'] ?></td>
                            <td><?= htmlspecialchars($product['product_name']) ?></td>
                            <td>
                                <?php
                                $display_price = $product['latest_price'] ?? $product['unit_price'];
                                echo number_format($display_price, 0, '', ',');
                                ?>
                            </td>
                            <?php if ($is_admin): ?>
                                <td>
                                    <span id="admin_inventory_<?= $product['product_id'] ?>"><?= $product['admin_inventory'] ?></span>
                                    <button type="button" class="btn btn-secondary btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#adminInventoryModal_<?= $product['product_id'] ?>">
                                        تغییر
                                    </button>
                                </td>
                                <td>
                                    <span id="partner_profit_<?= $product['product_id'] ?>"><?= number_format($product['partner_profit'], 2) ?></span>
                                    <button type="button" class="btn btn-secondary btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#partnerProfitModal_<?= $product['product_id'] ?>">
                                        تغییر
                                    </button>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#allocateModal_<?= $product['product_id'] ?>">
                                        تخصیص
                                    </button>
                                </td>
                                <td>
                                    <a href="edit_product.php?id=<?= $product['product_id'] ?>" class="btn btn-warning btn-sm">ویرایش</a>
                                    <a href="products.php?delete=<?= $product['product_id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('آیا مطمئن هستید؟')">حذف</a>
                                    <a href="manage_price.php?product_id=<?= $product['product_id'] ?>" class="btn btn-info btn-sm">مدیریت قیمت</a>
                                </td>
                            <?php endif; ?>
                            <?php if ($is_seller && $is_partner1): ?>
                                <td>
                                    <span id="inventory_<?= $product['product_id'] ?>"><?= $product['inventory'] ?></span>
                                </td>
                            <?php endif; ?>
                            <?php if ($is_seller): ?>
                                <td>
                                    <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#priceModal_<?= $product['product_id'] ?>">
                                        تغییرات
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Admin Inventory Modal -->
        <?php if ($is_admin): ?>
            <?php foreach ($products as $product): ?>
                <div class="modal fade" id="adminInventoryModal_<?= $product['product_id'] ?>" tabindex="-1" aria-labelledby="adminInventoryModalLabel_<?= $product['product_id'] ?>" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="adminInventoryModalLabel_<?= $product['product_id'] ?>">ویرایش موجودی مدیر برای <?= htmlspecialchars($product['product_name']) ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form method="POST">
                                    <input type="hidden" name="product_id" value="<?= $product['product_id'] ?>">
                                    <div class="mb-3">
                                        <label for="new_quantity_<?= $product['product_id'] ?>" class="form-label">تعداد جدید</label>
                                        <input type="number" class="form-control" id="new_quantity_<?= $product['product_id'] ?>" name="new_quantity" min="0" required>
                                    </div>
                                    <button type="submit" name="update_admin_inventory" class="btn btn-primary">ذخیره</button>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Partner Profit Modal -->
                <div class="modal fade" id="partnerProfitModal_<?= $product['product_id'] ?>" tabindex="-1" aria-labelledby="partnerProfitModalLabel_<?= $product['product_id'] ?>" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="partnerProfitModalLabel_<?= $product['product_id'] ?>">ویرایش سود همکار برای <?= htmlspecialchars($product['product_name']) ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form method="POST">
                                    <input type="hidden" name="product_id" value="<?= $product['product_id'] ?>">
                                    <div class="mb-3">
                                        <label for="partner_profit_<?= $product['product_id'] ?>" class="form-label">سود همکار (درصد)</label>
                                        <input type="number" class="form-control" id="partner_profit_<?= $product['product_id'] ?>" name="partner_profit" step="0.01" min="0" value="<?= $product['partner_profit'] ?>" required>
                                    </div>
                                    <button type="submit" name="update_partner_profit" class="btn btn-primary">ذخیره</button>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Allocate Inventory Modal -->
                <div class="modal fade" id="allocateModal_<?= $product['product_id'] ?>" tabindex="-1" aria-labelledby="allocateModalLabel_<?= $product['product_id'] ?>" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="allocateModalLabel_<?= $product['product_id'] ?>">تخصیص موجودی برای <?= htmlspecialchars($product['product_name']) ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form method="POST">
                                    <input type="hidden" name="product_id" value="<?= $product['product_id'] ?>">
                                    <div class="mb-3">
                                        <label for="user_id_<?= $product['product_id'] ?>" class="form-label">همکار 1</label>
                                        <select class="form-select" id="user_id_<?= $product['product_id'] ?>" name="user_id" required>
                                            <option value="">انتخاب کنید</option>
                                            <?php foreach ($partner1_users as $user): ?>
                                                <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['full_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="quantity_<?= $product['product_id'] ?>" class="form-label">تعداد</label>
                                        <input type="number" class="form-control" id="quantity_<?= $product['product_id'] ?>" name="quantity" min="1" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="work_month_id_<?= $product['product_id'] ?>" class="form-label">ماه کاری</label>
                                        <select class="form-select" id="work_month_id_<?= $product['product_id'] ?>" name="work_month_id" required>
                                            <option value="">انتخاب کنید</option>
                                            <?php foreach ($work_months as $month): ?>
                                                <option value="<?= $month['work_month_id'] ?>">
                                                    <?= gregorian_to_jalali_format($month['start_date']) ?> تا <?= gregorian_to_jalali_format($month['end_date']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" name="allocate_inventory" class="btn btn-primary">تخصیص</button>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Price History Modal -->
        <?php if ($is_seller): ?>
            <?php foreach ($products as $product): ?>
                <div class="modal fade" id="priceModal_<?= $product['product_id'] ?>" tabindex="-1" aria-labelledby="priceModalLabel_<?= $product['product_id'] ?>" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="priceModalLabel_<?= $product['product_id'] ?>">تغییرات قیمت برای <?= htmlspecialchars($product['product_name']) ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <?php if (!empty($product['price_history'])): ?>
                                    <ul class="list-group">
                                        <?php foreach ($product['price_history'] as $price): ?>
                                            <li class="list-group-item">
                                                <?= gregorian_to_jalali_format($price['start_date']) ?>
                                                <?php if ($price['end_date']): ?>
                                                    تا <?= gregorian_to_jalali_format($price['end_date']) ?>
                                                <?php else: ?>
                                                    (تاکنون)
                                                <?php endif; ?>
                                                : از <?= number_format($price['previous_price'], 0, '', ',') ?> به
                                                <?= number_format($price['unit_price'], 0, '', ',') ?> تومان
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-center">این محصول تغییر قیمتی نداشته است.</p>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php else: ?>
        <div class="alert alert-warning text-center">محصولی وجود ندارد.</div>
    <?php endif; ?>
</div>

<!-- جاوااسکریپت DataTables -->
<script>
    $(document).ready(function () {
        $('#productsTable').DataTable({
            "pageLength": 10, // 10 ردیف در هر صفحه
            "scrollX": true, // فعال کردن اسکرول افقی
            "scrollCollapse": true, // اجازه می‌دهد اسکرول افقی با عرض صفحه تنظیم بشه
            "paging": true, // فعال کردن صفحه‌بندی
            "autoWidth": true, // غیرفعال کردن تنظیم خودکار عرض
            "ordering": true, // فعال کردن مرتب‌سازی ستون‌ها
            "responsive": false, // غیرفعال کردن حالت ریسپانسیو
            "language": {
                "decimal": "",
                "emptyTable": "داده‌ای در جدول وجود ندارد",
                "info": "نمایش START تا END از TOTAL ردیف",
                "infoEmpty": "نمایش 0 تا 0 از 0 ردیف",
                "infoFiltered": "(فیلتر شده از MAX ردیف کل)",
                "lengthMenu": "نمایش MENU ردیف",
                "loadingRecords": "در حال بارگذاری...",
                "processing": "در حال پردازش...",
                "search": "جستجو:",
                "zeroRecords": "هیچ ردیف منطبقی یافت نشد",
                "paginate": {
                    "first": "اولین",
                    "last": "آخرین",
                    "next": "بعدی",
                    "previous": "قبلی"
                }
            },
            "columnDefs": [
                { "targets": "_all", "className": "text-right" }, // وسط‌چین کردن همه ستون‌ها
                { "targets": "_all", "className": "text-start" }, // وسط‌چین کردن همه ستون‌ها
                { "targets": 0, "width": "50px" },  // شناسه
                { "targets": 1, "width": "200px" }, // نام محصول
                { "targets": 2, "width": "120px" }  // قیمت واحد
                <?php if ($is_admin): ?>,
                    { "targets": 3, "width": "150px" },  // موجودی مدیر
                    { "targets": 4, "width": "100px" },  // سود همکار
                    { "targets": 5, "width": "150px" },  // تخصیص به همکار
                    { "targets": 6, "width": "150px" }   // عملیات
                <?php endif; ?>
                <?php if ($is_seller && $is_partner1): ?>,
                    { "targets": <?php echo $is_admin ? 7 : 3; ?>, "width": "100px" }  // موجودی شما
                <?php endif; ?>
                <?php if ($is_seller): ?>,
                    { "targets": <?php echo $changes_column_index; ?>, "width": "80px" }  // تغییرات
                <?php endif; ?>
            ]
        });
    });
</script>

<?php require_once 'footer.php'; ?>