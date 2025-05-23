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
if (!$is_admin) {
    header("Location: index.php");
    exit;
}

$product_id = isset($_GET['product_id']) && is_numeric($_GET['product_id']) ? (int) $_GET['product_id'] : null;
if (!$product_id) {
    die("شناسه محصول نامعتبر است!");
}

// دریافت اطلاعات محصول
$product = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM Products WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        die("محصولی با این شناسه یافت نشد!");
    }
} catch (Exception $e) {
    die("خطا در بارگذاری اطلاعات محصول: " . $e->getMessage());
}

echo "<!-- دیباگ: محصول بارگذاری شد: " . htmlspecialchars($product['product_name']) . " -->";

// دریافت تاریخچه قیمت‌ها (فقط 2 تغییر آخر)
$price_history = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM Product_Price_History WHERE product_id = ? ORDER BY start_date DESC LIMIT 2");
    $stmt->execute([$product_id]);
    $price_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<!-- دیباگ: تعداد تاریخچه قیمت‌ها = " . count($price_history) . " -->";

    // برای هر تغییر، قیمت قبلی رو محاسبه می‌کنیم
    foreach ($price_history as &$price) {
        $stmt = $pdo->prepare("SELECT unit_price FROM Product_Price_History WHERE product_id = ? AND start_date < ? ORDER BY start_date DESC LIMIT 1");
        $stmt->execute([$product_id, $price['start_date']]);
        $previous_price = $stmt->fetch(PDO::FETCH_ASSOC);
        $price['previous_price'] = $previous_price ? $previous_price['unit_price'] : $product['unit_price'];
    }
    unset($price);
} catch (Exception $e) {
    echo "<!-- خطا در کوئری تاریخچه قیمت‌ها: " . $e->getMessage() . " -->";
}

// پردازش فرم افزودن قیمت
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_price'])) {
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date = trim($_POST['end_date'] ?? '');
    $unit_price = trim($_POST['unit_price'] ?? '');
    // تبدیل تاریخ شمسی به میلادی برای تاریخ شروع
    if (!empty($start_date)) {
        list($jy, $jm, $jd) = explode('/', $start_date);
        list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
        $start_gregorian = "$gy-$gm-$gd";
    } else {
        $start_gregorian = null;
    }

    // تبدیل تاریخ شمسی به میلادی برای تاریخ پایان
    $end_gregorian = null;
    if (!empty($end_date)) {
        list($jy, $jm, $jd) = explode('/', $end_date);
        list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
        $end_gregorian = "$gy-$gm-$gd";
    }

    if (!empty($unit_price) && is_numeric($unit_price) && $start_gregorian) {
        try {
            // غیرفعال کردن قیمت قبلی (ست کردن end_date)
            $stmt_update = $pdo->prepare("
            UPDATE Product_Price_History 
            SET end_date = ? 
            WHERE product_id = ? AND end_date IS NULL
        ");
            $stmt_update->execute([$start_gregorian, $product_id]);

            // ثبت قیمت جدید
            $stmt = $pdo->prepare("
            INSERT INTO Product_Price_History (product_id, start_date, end_date, unit_price) 
            VALUES (?, ?, ?, ?)
        ");
            $stmt->execute([$product_id, $start_gregorian, $end_gregorian, $unit_price]);

            echo "<script>alert('قیمت با موفقیت اضافه شد!'); window.location.href='manage_price.php?product_id=$product_id';</script>";
        } catch (Exception $e) {
            echo "<script>alert('خطا در ثبت قیمت: " . $e->getMessage() . "');</script>";
        }
    } else {
        echo "<script>alert('لطفاً قیمت و تاریخ شروع را به درستی وارد کنید!');</script>";
    }
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="card-title">مدیریت قیمت برای <?= htmlspecialchars($product['product_name']) ?></h5>
        <a href="products.php" class="btn btn-secondary">بازگشت به لیست محصولات</a>
    </div>

    <form method="POST" action="">
        <input type="hidden" name="product_id" value="<?= $product_id ?>">
        <div class="mb-3">
            <label for="start_date" class="form-label">تاریخ شروع (شمسی)</label>
            <input type="text" class="form-control datepicker" id="start_date" name="start_date" required>
            <input type="hidden" class="start-date-alt">
        </div>
        <div class="mb-3">
            <label for="end_date" class="form-label">تاریخ پایان (شمسی) (اختیاری)</label>
            <input type="text" class="form-control datepicker" id="end_date" name="end_date" value="">
            <input type="hidden" class="end-date-alt">
        </div>
        <div class="mb-3">
            <label for="unit_price" class="form-label">قیمت واحد (تومان)</label>
            <input type="number" class="form-control" id="unit_price" name="unit_price" step="0.01" required>
        </div>
        <button type="submit" name="add_price" class="btn btn-primary">ثبت قیمت</button>
    </form>

    <?php if (!empty($price_history)): ?>
        <h6 class="mt-4">تاریخچه قیمت‌ها (2 تغییر آخر):</h6>
        <ul class="list-group">
            <?php foreach ($price_history as $price): ?>
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
    <?php endif; ?>
</div>

<script>
    console.log('اسکریپت شروع شد...');

    window.onload = function () {
        console.log('صفحه کامل لود شد، شروع اجرای جاوااسکریپت...');

        // چک کردن لود شدن jQuery و Persian Datepicker
        if (typeof $ === 'undefined') {
            console.error('jQuery لود نشده است!');
            return;
        }
        if (typeof $.fn.persianDatepicker === 'undefined') {
            console.error('Persian Datepicker لود نشده است!');
            return;
        }
        console.log('jQuery و Persian Datepicker لود شدند.');

        // تنظیم دیت‌پیکر برای تاریخ شروع
        $("#start_date").persianDatepicker({
            format: 'YYYY/MM/DD',
            altField: '.start-date-alt',
            altFormat: 'dddd D MMMM YYYY HH:mm a',
            autoClose: true,
            initialValue: false,
            calendar: {
                persian: {
                    locale: 'fa',
                    digits: true
                }
            },
            onSelect: function (unix) {
                var altValue = $(this.altField).val();
                console.log('تاریخ شروع انتخاب شد: ' + altValue);
            }
        });

        // تنظیم دیت‌پیکر برای تاریخ پایان
        $("#end_date").persianDatepicker({
            format: 'YYYY/MM/DD',
            altField: '.end-date-alt',
            altFormat: 'dddd D MMMM YYYY HH:mm a',
            autoClose: true,
            initialValue: false,
            calendar: {
                persian: {
                    locale: 'fa',
                    digits: true
                }
            },
            onSelect: function (unix) {
                var altValue = $(this.altField).val();
                console.log('تاریخ پایان انتخاب شد: ' + altValue);
            }
        });

        console.log('دیت‌پیکرها برای start_date و end_date فعال شدند.');
    };
</script>

<?php
echo "<!-- دیباگ: قبل از لود فوتر -->";
require_once 'footer.php';
echo "<!-- دیباگ: بعد از لود فوتر -->";
?>