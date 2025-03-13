<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

echo "<!-- دیباگ: بعد از لود header -->";

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    if (!$gregorian_date) return "نامشخص";
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
    if (!empty($product_name) && !empty($unit_price) && is_numeric($unit_price)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO Products (product_name, unit_price) VALUES (?, ?)");
            $stmt->execute([$product_name, $unit_price]);
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
    $product_id = (int)$_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM Products WHERE product_id = ?");
        $stmt->execute([$product_id]);
        echo "<script>alert('محصول با موفقیت حذف شد!'); window.location.href='products.php';</script>";
    } catch (Exception $e) {
        echo "<script>alert('خطا در حذف محصول: " . $e->getMessage() . "');</script>";
    }
}

// دریافت محصولات
$products = [];
try {
    $products = $pdo->query("SELECT * FROM Products ORDER BY product_id DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo "<!-- دیباگ: تعداد محصولات = " . count($products) . " -->";

    // دریافت آخرین قیمت برای هر محصول
    foreach ($products as &$product) {
        $stmt = $pdo->prepare("SELECT unit_price FROM Product_Price_History WHERE product_id = ? ORDER BY start_date DESC LIMIT 1");
        $stmt->execute([$product['product_id']]);
        $latest_price = $stmt->fetch(PDO::FETCH_ASSOC);
        $product['latest_price'] = $latest_price ? $latest_price['unit_price'] : null;
    }
    unset($product);

    // دریافت تاریخچه قیمت‌ها برای هر محصول (فقط 2 تغییر آخر برای نمایش در مودال)
    if ($is_seller) {
        foreach ($products as &$product) {
            $stmt = $pdo->prepare("SELECT * FROM Product_Price_History WHERE product_id = ? ORDER BY start_date DESC LIMIT 2");
            $stmt->execute([$product['product_id']]);
            $product['price_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // برای هر تغییر، قیمت قبلی رو محاسبه می‌کنیم
            foreach ($product['price_history'] as &$price) {
                // اگر این اولین تغییر قیمت باشه (آخرین رکورد توی لیست مرتب‌شده)، قیمت قبلی از Products میاد
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

    <?php if ($is_admin): ?>
        <form method="POST" class="row g-3 mb-3">
            <div class="col-auto">
                <input type="text" class="form-control" name="product_name" placeholder="نام محصول" required>
            </div>
            <div class="col-auto">
                <input type="number" class="form-control" name="unit_price" placeholder="قیمت واحد (تومان)" step="0.01" required>
            </div>
            <div class="col-auto">
                <button type="submit" name="add_product" class="btn btn-primary">افزودن محصول</button>
            </div>
        </form>
    <?php endif; ?>

    <?php if (!empty($products)): ?>
        <table class="table table-light table-hover">
            <thead>
                <tr>
                    <th>شناسه</th>
                    <th>نام محصول</th>
                    <th>قیمت واحد (تومان)</th>
                    <?php if ($is_admin): ?>
                        <th>عملیات</th>
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
                                <a href="edit_product.php?id=<?= $product['product_id'] ?>" class="btn btn-warning btn-sm">ویرایش</a>
                                <a href="products.php?delete=<?= $product['product_id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('آیا مطمئن هستید؟')">حذف</a>
                                <a href="manage_price.php?product_id=<?= $product['product_id'] ?>" class="btn btn-info btn-sm">مدیریت قیمت</a>
                            </td>
                        <?php endif; ?>
                        <?php if ($is_seller): ?>
                            <td>
                                <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#priceModal_<?= $product['product_id'] ?>">
                                    تغییرات
                                </button>

                                <!-- مودال برای نمایش تاریخچه قیمت -->
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
                                                                : از <?= number_format($price['previous_price'], 0, '', ',') ?> به <?= number_format($price['unit_price'], 0, '', ',') ?> تومان
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
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="alert alert-warning text-center">محصولی وجود ندارد.</div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>