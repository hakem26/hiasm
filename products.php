<?php
// فعال کردن نمایش خطاها برای دیباگ
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// session_start() رو حذف کردیم چون توی header.php هست
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

// تابع دریافت تاریخ امروز به‌صورت شمسی
function get_today_jalali() {
    $jdf = new jdf();
    return $jdf->jdate('Y/m/d', '', '', '', 'en');
}

// چک کردن نقش کاربر
$is_admin = ($_SESSION['role'] === 'admin');

// دیباگ: چک کردن مقدار $is_admin
echo "<!-- دیباگ: is_admin = " . ($is_admin ? 'true' : 'false') . " -->";

// پردازش فرم افزودن محصول (فقط برای ادمین)
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $product_name = trim($_POST['product_name']);
    $unit_price = trim($_POST['unit_price']);

    if (!empty($product_name) && !empty($unit_price) && is_numeric($unit_price)) {
        $stmt = $pdo->prepare("INSERT INTO Products (product_name, unit_price) VALUES (?, ?)");
        $stmt->execute([$product_name, $unit_price]);
        echo "<script>alert('محصول با موفقیت اضافه شد!'); window.location.href='products.php';</script>";
    } else {
        echo "<script>alert('لطفاً نام محصول و قیمت را به درستی وارد کنید!');</script>";
    }
}

// پردازش حذف محصول (فقط برای ادمین)
if ($is_admin && isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $product_id = (int) $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM Products WHERE product_id = ?");
    $stmt->execute([$product_id]);
    echo "<script>alert('محصول با موفقیت حذف شد!'); window.location.href='products.php';</script>";
}

// دریافت لیست محصولات
try {
    $products = $pdo->query("SELECT * FROM Products ORDER BY product_id DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo "<!-- دیباگ: تعداد محصولات = " . count($products) . " -->";
} catch (Exception $e) {
    echo "<!-- خطا در کوئری محصولات: " . $e->getMessage() . " -->";
    $products = [];
}

// دریافت تاریخچه قیمت‌ها
$price_history = [];
if (!empty($products)) {
    $product_ids = array_column($products, 'product_id');
    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
    try {
        $stmt = $pdo->prepare("SELECT * FROM Product_Price_History WHERE product_id IN ($placeholders) ORDER BY start_date DESC");
        $stmt->execute($product_ids);
        $price_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<!-- دیباگ: تعداد تاریخچه قیمت‌ها = " . count($price_history) . " -->";
    } catch (Exception $e) {
        echo "<!-- خطا در کوئری تاریخچه قیمت‌ها: " . $e->getMessage() . " -->";
        $price_history = [];
    }
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="card-title">مدیریت محصولات</h5>
    </div>

    <!-- فرم افزودن محصول (فقط برای ادمین) -->
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

    <!-- جدول نمایش محصولات -->
    <?php if (!empty($products)): ?>
        <div style="overflow-x: auto;">
            <table class="table table-light table-hover">
                <thead>
                    <tr>
                        <th>شناسه</th>
                        <th>نام محصول</th>
                        <th>قیمت واحد (تومان)</th>
                        <?php if ($is_admin): ?>
                            <th>عملیات</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <?php 
                        // دیباگ: چک کردن product_id
                        echo "<!-- دیباگ: product_id = " . $product['product_id'] . " -->";
                        ?>
                        <tr>
                            <td><?= $product['product_id'] ?></td>
                            <td><?= htmlspecialchars($product['product_name']) ?></td>
                            <td><?= number_format($product['unit_price'], 0, '', ',') ?></td>
                            <?php if ($is_admin): ?>
                                <td>
                                    <a href="edit_product.php?id=<?= $product['product_id'] ?>" class="btn btn-warning btn-sm">ویرایش</a>
                                    <a href="products.php?delete=<?= $product['product_id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('آیا مطمئن هستید؟')">حذف</a>
                                    <a href="manage_price.php?product_id=<?= $product['product_id'] ?>" class="btn btn-info btn-sm">مدیریت قیمت</a>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="alert alert-warning text-center">محصولی وجود ندارد.</div>
    <?php endif; ?>
</div>

<?php
echo "<!-- دیباگ: قبل از لود فوتر -->";
require_once 'footer.php';
echo "<!-- دیباگ: بعد از لود فوتر -->";
?>