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
if (!$is_admin) {
    header("Location: index.php");
    exit;
}

// دریافت product_id از URL
$product_id = isset($_GET['product_id']) && is_numeric($_GET['product_id']) ? (int)$_GET['product_id'] : null;
if (!$product_id) {
    die("شناسه محصول نامعتبر است!");
    // دیباگ: اگه به اینجا برسه، فوتر لود نمیشه
}

// دریافت اطلاعات محصول
try {
    $stmt = $pdo->prepare("SELECT * FROM Products WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        die("محصولی با این شناسه یافت نشد!");
    }
} catch (Exception $e) {
    echo "<!-- خطا در کوئری محصول: " . $e->getMessage() . " -->";
    die("خطا در بارگذاری اطلاعات محصول!");
}

// دریافت تاریخچه قیمت‌ها
$price_history = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM Product_Price_History WHERE product_id = ? ORDER BY start_date DESC");
    $stmt->execute([$product_id]);
    $price_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<!-- دیباگ: تعداد تاریخچه قیمت‌ها = " . count($price_history) . " -->";
} catch (Exception $e) {
    echo "<!-- خطا در کوئری تاریخچه قیمت‌ها: " . $e->getMessage() . " -->";
    $price_history = [];
}

// پردازش فرم افزودن قیمت
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_price'])) {
    $start_date = trim($_POST['start_date']);
    $end_date = trim($_POST['end_date']);
    $unit_price = trim($_POST['unit_price']);
    $is_current_day = isset($_POST['is_current_day']) ? 1 : 0;

    // تبدیل تاریخ شمسی به میلادی
    list($jy, $jm, $jd) = explode('/', $start_date);
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    $start_gregorian = "$gy-$gm-$gd";

    if (!empty($end_date)) {
        list($jy, $jm, $jd) = explode('/', $end_date);
        list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
        $end_gregorian = "$gy-$gm-$gd";
    } else {
        $end_gregorian = NULL;
    }

    if (!empty($unit_price) && is_numeric($unit_price)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO Product_Price_History (product_id, start_date, end_date, unit_price) VALUES (?, ?, ?, ?)");
            $stmt->execute([$product_id, $start_gregorian, $end_gregorian, $unit_price]);
            echo "<script>alert('قیمت با موفقیت اضافه شد!'); window.location.href='manage_price.php?product_id=$product_id';</script>";
        } catch (Exception $e) {
            echo "<script>alert('خطا در ثبت قیمت: " . $e->getMessage() . "');</script>";
        }
    } else {
        echo "<script>alert('لطفاً قیمت را به درستی وارد کنید!');</script>";
    }
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="card-title">مدیریت قیمت برای <?= htmlspecialchars($product['product_name']) ?></h5>
        <a href="products.php" class="btn btn-secondary">بازگشت به لیست محصولات</a>
    </div>

    <!-- فرم مدیریت قیمت -->
    <form method="POST" action="">
        <input type="hidden" name="product_id" value="<?= $product_id ?>">
        <div class="mb-3">
            <label for="start_date" class="form-label">تاریخ شروع (شمسی)</label>
            <input type="text" class="form-control persian-date" id="start_date" name="start_date" required>
        </div>
        <div class="mb-3">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="is_current_day" name="is_current_day" value="1" checked>
                <label class="form-check-label" for="is_current_day">روز جاری</label>
            </div>
            <label for="end_date" class="form-label">تاریخ پایان (شمسی) (اختیاری)</label>
            <input type="text" class="form-control persian-date optional-date" id="end_date" name="end_date">
        </div>
        <div class="mb-3">
            <label for="unit_price" class="form-label">قیمت واحد (تومان)</label>
            <input type="number" class="form-control" id="unit_price" name="unit_price" step="0.01" required>
        </div>
        <button type="submit" name="add_price" class="btn btn-primary">ثبت قیمت</button>
    </form>

    <!-- نمایش تاریخچه قیمت‌ها -->
    <?php if (!empty($price_history)): ?>
        <h6 class="mt-4">تاریخچه قیمت‌ها:</h6>
        <ul class="list-group">
            <?php foreach ($price_history as $price): ?>
                <li class="list-group-item">
                    <?= gregorian_to_jalali_format($price['start_date']) ?>
                    <?php if ($price['end_date']): ?>
                        تا <?= gregorian_to_jalali_format($price['end_date']) ?>
                    <?php else: ?>
                        (تاکنون)
                    <?php endif; ?>
                    : <?= number_format($price['unit_price'], 0, '', ',') ?> تومان
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<!-- اسکریپت‌ها -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // دیباگ: چک کردن لود شدن jQuery و Persian Datepicker
        console.log('jQuery لود شده:', typeof $ !== 'undefined');
        console.log('Persian Datepicker لود شده:', typeof $.fn.persianDatepicker !== 'undefined');

        // Datepicker برای تاریخ شروع
        const startDateInputs = document.querySelectorAll('.persian-date:not(.optional-date)');
        if (startDateInputs.length > 0 && typeof $.fn.persianDatepicker !== 'undefined') {
            startDateInputs.forEach(input => {
                $(input).persianDatepicker({
                    format: 'YYYY/MM/DD',
                    autoClose: true,
                    calendar: {
                        persian: {
                            locale: 'fa',
                            digits: true
                        }
                    }
                });
                console.log('Datepicker برای تاریخ شروع فعال شد برای ID:', input.id);
            });
        } else {
            console.error('Datepicker برای تاریخ شروع لود نشده یا عنصر یافت نشد!');
        }

        // Datepicker برای تاریخ پایان
        const endDateInputs = document.querySelectorAll('.optional-date');
        if (endDateInputs.length > 0 && typeof $.fn.persianDatepicker !== 'undefined') {
            endDateInputs.forEach(input => {
                $(input).persianDatepicker({
                    format: 'YYYY/MM/DD',
                    autoClose: true,
                    calendar: {
                        persian: {
                            locale: 'fa',
                            digits: true
                        }
                    },
                    initialValue: false,
                    onSelect: function(unix) {
                        console.log('تاریخ پایان انتخاب شد: ', unix);
                    },
                    onHide: function() {
                        if (!this.getState().selectedUnix) {
                            $(this.$input).val('');
                        }
                    }
                });
                console.log('Datepicker برای تاریخ پایان فعال شد برای ID:', input.id);
            });
        } else {
            console.error('Datepicker برای تاریخ پایان لود نشده یا عنصر یافت نشد!');
        }

        // مدیریت چک‌باکس "روز جاری"
        function updateCurrentDay() {
            const today = '<?php echo get_today_jalali(); ?>';
            endDateInputs.forEach(endDate => {
                const $endDate = $(endDate);
                const $checkbox = $('#is_current_day');
                console.log('چک‌باکس وضعیت:', $checkbox.is(':checked'), 'برای فیلد:', $endDate.attr('id'));
                if ($checkbox.is(':checked')) {
                    $endDate.val(today).trigger('change');
                    $endDate.prop('disabled', true);
                    $endDate.addClass('disabled');
                    console.log('فیلد تاریخ پایان غیرفعال شد و تاریخ امروز تنظیم شد:', today);
                } else {
                    $endDate.val('').trigger('change');
                    $endDate.prop('disabled', false);
                    $endDate.removeClass('disabled');
                    console.log('فیلد تاریخ پایان فعال شد');
                }
            });
        }

        // اجرا وقتی صفحه لود میشه
        updateCurrentDay();

        // اجرا وقتی چک‌باکس تغییر می‌کنه
        document.querySelector('#is_current_day').addEventListener('change', () => {
            console.log('چک‌باکس تغییر کرد');
            updateCurrentDay();
        });
    });
</script>

</div>
<!-- پایان main-container -->

<!-- Bootstrap RTL JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"></script>
<script>
    // اطمینان از لود شدن بوت‌استرپ و فعال‌سازی دراپ‌داون‌ها
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
            const dropdowns = document.querySelectorAll('.dropdown-toggle');
            dropdowns.forEach(dropdown => {
                new bootstrap.Dropdown(dropdown);
            });
        } else {
            console.error('Bootstrap Dropdown is not available.');
        }
    });
</script>

</body>

</html>