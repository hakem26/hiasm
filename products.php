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

// چک کردن اینکه آیا فروشنده همکار 1 هست یا نه
$is_partner1 = false;
if ($is_seller) {
    $current_user_id = $_SESSION['user_id'];
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM Partners WHERE user_id1 = ?");
    $stmt_check->execute([$current_user_id]);
    $is_partner1 = $stmt_check->fetchColumn() > 0;
    echo "<!-- دیباگ: is_partner1 = " . ($is_partner1 ? 'true' : 'false') . " -->";
}

// پردازش افزودن محصول (فقط برای ادمین)
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $product_name = trim($_POST['product_name']);
    $unit_price = trim($_POST['unit_price']);
    $partner_profit = (float) ($_POST['partner_profit'] ?? 0.00);
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

// پردازش به‌روزرسانی موجودی مدیر (فقط برای ادمین)
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

// پردازش درخواست تخصیص توسط فروشنده (فقط برای همکار 1)
if ($is_seller && $is_partner1 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_inventory'])) {
    $product_id = (int) $_POST['product_id'];
    $quantity = (int) $_POST['quantity'];
    $user_id = (int) $_SESSION['user_id'];

    try {
        // اضافه کردن به موجودی فروشنده
        $update_user_query = $pdo->prepare("
            INSERT INTO Inventory (user_id, product_id, quantity) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE quantity = quantity + ?
        ");
        $update_user_query->execute([$user_id, $product_id, $quantity, $quantity]);

        // ثبت تراکنش
        $transaction_query = $pdo->prepare("
            INSERT INTO Inventory_Transactions (product_id, user_id, quantity) 
            VALUES (?, ?, ?)
        ");
        $transaction_query->execute([$product_id, $user_id, $quantity]);

        echo "<script>alert('درخواست شما با موفقیت ثبت و تخصیص داده شد!'); window.location.href='products.php';</script>";
    } catch (Exception $e) {
        echo "<script>alert('خطا در درخواست تخصیص: " . $e->getMessage() . "');</script>";
    }
}

// پردازش بازگشت محصول توسط فروشنده (فقط برای همکار 1)
if ($is_seller && $is_partner1 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_inventory'])) {
    $product_id = (int) $_POST['product_id'];
    $quantity = (int) $_POST['quantity'];
    $user_id = (int) $_SESSION['user_id'];

    try {
        // چک کردن موجودی فعلی فروشنده
        $stmt = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ?");
        $stmt->execute([$user_id, $product_id]);
        $current_quantity = $stmt->fetchColumn() ?: 0;

        if ($current_quantity < $quantity) {
            echo "<script>alert('موجودی کافی برای بازگشت ندارید!'); window.location.href='products.php';</script>";
            exit;
        }

        // کاهش موجودی فروشنده
        $update_user_query = $pdo->prepare("
            UPDATE Inventory 
            SET quantity = quantity - ? 
            WHERE user_id = ? AND product_id = ?
        ");
        $update_user_query->execute([$quantity, $user_id, $product_id]);

        // ثبت تراکنش بازگشت (با مقدار منفی)
        $transaction_query = $pdo->prepare("
            INSERT INTO Inventory_Transactions (product_id, user_id, quantity) 
            VALUES (?, ?, ?)
        ");
        $transaction_query->execute([$product_id, $user_id, -$quantity]);

        echo "<script>alert('محصول با موفقیت به مدیر بازگردانده شد!'); window.location.href='products.php';</script>";
    } catch (Exception $e) {
        echo "<script>alert('خطا در بازگشت محصول: " . $e->getMessage() . "');</script>";
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
    foreach ($products as &$product) {
        $stmt = $pdo->prepare("SELECT quantity FROM Admin_Inventory WHERE product_id = ?");
        $stmt->execute([$product['product_id']]);
        $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
        $product['admin_inventory'] = $inventory ? $inventory['quantity'] : 0;
    }
    unset($product);

    // دریافت موجودی برای فروشنده فعلی (فقط برای همکار 1)
    if ($is_seller && $is_partner1) {
        $current_user_id = $_SESSION['user_id'];
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
                <input type="number" class="form-control" name="unit_price" placeholder="قیمت واحد (تومان)" step="0.01"
                    required>
            </div>
            <div class="col-auto">
                <input type="number" class="form-control" name="partner_profit" placeholder="سود همکار (درصد)" step="0.01"
                    value="0.00">
            </div>
            <div class="col-auto">
                <button type="submit" name="add_product" class="btn btn-primary">افزودن محصول</button>
            </div>
        </form>
    <?php endif; ?>

    <?php if (!empty($products)): ?>
        <div class="table-responsive" style="overflow-x: auto; width: 100%;">
            <table id="productsTable" class="table table-light table-hover display nowrap"
                style="width: 100%; min-width: 800px;">
                <thead>
                    <tr>
                        <th>نام محصول</th>
                        <th>قیمت واحد (تومان)</th>
                        <?php if ($is_seller && $is_partner1): ?>
                            <th>موجودی شما</th>
                            <th>تخصیص</th>
                        <?php endif; ?>
                        <?php if ($is_seller): ?>
                            <th>تغییرات</th>
                        <?php endif; ?>
                        <?php if ($is_admin): ?>
                            <th>سود همکار (%)</th>
                            <th>عملیات</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?= htmlspecialchars($product['product_name']) ?></td>
                            <td>
                                <?php
                                $display_price = $product['latest_price'] ?? $product['unit_price'];
                                echo number_format($display_price, 0, '', ',');
                                ?>
                            </td>
                            <?php if ($is_seller && $is_partner1): ?>
                                <td>
                                    <span id="inventory_<?= $product['product_id'] ?>"><?= $product['inventory'] ?></span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal"
                                        data-bs-target="#requestModal_<?= $product['product_id'] ?>">
                                        درخواست
                                    </button>
                                    <button type="button" class="btn btn-warning btn-sm ms-1" data-bs-toggle="modal"
                                        data-bs-target="#returnModal_<?= $product['product_id'] ?>">
                                        بازگشت
                                    </button>
                                </td>
                            <?php endif; ?>
                            <?php if ($is_seller): ?>
                                <td>
                                    <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal"
                                        data-bs-target="#priceModal_<?= $product['product_id'] ?>">
                                        تغییرات
                                    </button>
                                </td>
                            <?php endif; ?>
                            <?php if ($is_admin): ?>
                                <td>
                                    <span
                                        id="partner_profit_<?= $product['product_id'] ?>"><?= number_format($product['partner_profit'], 2) ?></span>
                                    <button type="button" class="btn btn-secondary btn-sm ms-2" data-bs-toggle="modal"
                                        data-bs-target="#partnerProfitModal_<?= $product['product_id'] ?>">
                                        تغییر
                                    </button>
                                </td>
                                <td>
                                    <a href="edit_product.php?id=<?= $product['product_id'] ?>"
                                        class="btn btn-warning btn-sm">ویرایش</a>
                                    <a href="products.php?delete=<?= $product['product_id'] ?>" class="btn btn-danger btn-sm"
                                        onclick="return confirm('آیا مطمئن هستید؟')">حذف</a>
                                    <a href="manage_price.php?product_id=<?= $product['product_id'] ?>"
                                        class="btn btn-info btn-sm">مدیریت قیمت</a>
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
                <div class="modal fade" id="adminInventoryModal_<?= $product['product_id'] ?>" tabindex="-1"
                    aria-labelledby="adminInventoryModalLabel_<?= $product['product_id'] ?>" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="adminInventoryModalLabel_<?= $product['product_id'] ?>">ویرایش موجودی
                                    مدیر برای <?= htmlspecialchars($product['product_name']) ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form method="POST">
                                    <input type="hidden" name="product_id" value="<?= $product['product_id'] ?>">
                                    <div class="mb-3">
                                        <label for="new_quantity_<?= $product['product_id'] ?>" class="form-label">تعداد
                                            جدید</label>
                                        <input type="number" class="form-control" id="new_quantity_<?= $product['product_id'] ?>"
                                            name="new_quantity" min="0" required>
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
                <div class="modal fade" id="partnerProfitModal_<?= $product['product_id'] ?>" tabindex="-1"
                    aria-labelledby="partnerProfitModalLabel_<?= $product['product_id'] ?>" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="partnerProfitModalLabel_<?= $product['product_id'] ?>">ویرایش سود همکار
                                    برای <?= htmlspecialchars($product['product_name']) ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form method="POST">
                                    <input type="hidden" name="product_id" value="<?= $product['product_id'] ?>">
                                    <div class="mb-3">
                                        <label for="partner_profit_<?= $product['product_id'] ?>" class="form-label">سود همکار
                                            (درصد)</label>
                                        <input type="number" class="form-control" id="partner_profit_<?= $product['product_id'] ?>"
                                            name="partner_profit" step="0.01" min="0" value="<?= $product['partner_profit'] ?>"
                                            required>
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
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Request Inventory Modal (فقط برای همکار 1) -->
        <?php if ($is_seller && $is_partner1): ?>
            <?php foreach ($products as $product): ?>
                <div class="modal fade" id="requestModal_<?= $product['product_id'] ?>" tabindex="-1"
                    aria-labelledby="requestModalLabel_<?= $product['product_id'] ?>" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="requestModalLabel_<?= $product['product_id'] ?>">درخواست تخصیص برای
                                    <?= htmlspecialchars($product['product_name']) ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form method="POST" id="requestForm_<?= $product['product_id'] ?>"
                                    onsubmit="return confirmRequest(<?= $product['product_id'] ?>, '<?= htmlspecialchars($product['product_name']) ?>')">
                                    <input type="hidden" name="product_id" value="<?= $product['product_id'] ?>">
                                    <div class="mb-3">
                                        <label for="quantity_<?= $product['product_id'] ?>" class="form-label">تعداد
                                            درخواستی</label>
                                        <input type="number" class="form-control" id="quantity_<?= $product['product_id'] ?>"
                                            name="quantity" min="1" required>
                                    </div>
                                    <button type="submit" name="request_inventory" class="btn btn-primary">درخواست و تخصیص</button>
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

        <!-- Return Inventory Modal (فقط برای همکار 1) -->
        <?php if ($is_seller && $is_partner1): ?>
            <?php foreach ($products as $product): ?>
                <div class="modal fade" id="returnModal_<?= $product['product_id'] ?>" tabindex="-1"
                    aria-labelledby="returnModalLabel_<?= $product['product_id'] ?>" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="returnModalLabel_<?= $product['product_id'] ?>">بازگشت محصول
                                    <?= htmlspecialchars($product['product_name']) ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form method="POST" id="returnForm_<?= $product['product_id'] ?>"
                                    onsubmit="return confirmReturn(<?= $product['product_id'] ?>, '<?= htmlspecialchars($product['product_name']) ?>')">
                                    <input type="hidden" name="product_id" value="<?= $product['product_id'] ?>">
                                    <div class="mb-3">
                                        <label for="return_quantity_<?= $product['product_id'] ?>" class="form-label">تعداد
                                            بازگشتی</label>
                                        <input type="number" class="form-control" id="return_quantity_<?= $product['product_id'] ?>"
                                            name="quantity" min="1" max="<?= $product['inventory'] ?>" required>
                                        <small class="form-text text-muted">موجودی فعلی شما: <?= $product['inventory'] ?></small>
                                    </div>
                                    <button type="submit" name="return_inventory" class="btn btn-warning">بازگشت محصول</button>
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
                <div class="modal fade" id="priceModal_<?= $product['product_id'] ?>" tabindex="-1"
                    aria-labelledby="priceModalLabel_<?= $product['product_id'] ?>" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="priceModalLabel_<?= $product['product_id'] ?>">تغییرات قیمت برای
                                    <?= htmlspecialchars($product['product_name']) ?></h5>
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

<script>
    function confirmRequest(productId, productName) {
        var quantity = document.getElementById('quantity_' + productId).value;
        if (!quantity || quantity < 1) {
            alert('لطفاً تعداد معتبر وارد کنید!');
            return false;
        }
        var message = 'شما تعداد ' + quantity + ' عدد درخواست تخصیص محصول ' + productName + ' را داده‌اید. آیا اطمینان دارید؟';
        return confirm(message);
    }

    function confirmReturn(productId, productName) {
        var quantity = document.getElementById('return_quantity_' + productId).value;
        var maxQuantity = document.getElementById('return_quantity_' + productId).max;
        if (!quantity || quantity < 1 || quantity > maxQuantity) {
            alert('لطفاً تعداد معتبر وارد کنید (حداکثر ' + maxQuantity + ')!');
            return false;
        }
        var message = 'شما تعداد ' + quantity + ' عدد بازگشت محصول ' + productName + ' را داده‌اید. آیا اطمینان دارید؟';
        return confirm(message);
    }
</script>

<!-- جاوااسکریپت DataTables -->
<script>
    $(document).ready(function () {
        $('#productsTable').DataTable({
            "pageLength": 10,
            "scrollX": true,
            "scrollCollapse": true,
            "paging": true,
            "autoWidth": true,
            "ordering": true,
            "responsive": false,
            "language": {
                "decimal": "",
                "emptyTable": "داده‌ای در جدول وجود ندارد",
                "info": "نمایش _START_ تا _END_ از _TOTAL_ ردیف",
                "infoEmpty": "نمایش 0 تا 0 از 0 ردیف",
                "infoFiltered": "(فیلتر شده از _MAX_ ردیف کل)",
                "lengthMenu": "نمایش _MENU_ ردیف",
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
                { "targets": "_all", "className": "text-center" },
            ]
        });
    });
</script>

<?php require_once 'footer.php'; ?>