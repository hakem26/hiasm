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

// پردازش افزودن یا ویرایش قیمت (فقط برای ادمین)
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_price'])) {
    $product_id = (int) $_POST['product_id'];
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
        $end_gregorian = NULL; // برای بازه جاری یا وقتی چک‌باکس انتخاب نشده
    }

    if (!empty($unit_price) && is_numeric($unit_price)) {
        $stmt = $pdo->prepare("INSERT INTO Product_Price_History (product_id, start_date, end_date, unit_price) VALUES (?, ?, ?, ?)");
        $stmt->execute([$product_id, $start_gregorian, $end_gregorian, $unit_price]);
        echo "<script>alert('قیمت با موفقیت اضافه شد!'); window.location.href='products.php';</script>";
    } else {
        echo "<script>alert('لطفاً قیمت را به درستی وارد کنید!');</script>";
    }
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
                                    <button type="button" class="btn btn-info btn-sm open-modal" data-bs-toggle="modal" data-bs-target="#priceModal<?= $product['product_id'] ?>" data-product-id="<?= $product['product_id'] ?>">مدیریت قیمت</button>
                                </td>
                            <?php endif; ?>
                        </tr>

                        <!-- مودال مدیریت قیمت -->
                        <?php if ($is_admin): ?>
                            <div class="modal fade" id="priceModal<?= $product['product_id'] ?>" tabindex="-1" aria-labelledby="priceModalLabel<?= $product['product_id'] ?>" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content bg-light">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="priceModalLabel<?= $product['product_id'] ?>">مدیریت قیمت برای <?= htmlspecialchars($product['product_name']) ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <form method="POST" action="">
                                                <input type="hidden" name="product_id" value="<?= $product['product_id'] ?>">
                                                <div class="mb-3">
                                                    <label for="start_date_<?= $product['product_id'] ?>" class="form-label">تاریخ شروع (شمسی)</label>
                                                    <input type="text" class="form-control persian-date" id="start_date_<?= $product['product_id'] ?>" name="start_date" required>
                                                </div>
                                                <div class="mb-3">
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input" id="is_current_day_<?= $product['product_id'] ?>" name="is_current_day" value="1" checked>
                                                        <label class="form-check-label" for="is_current_day_<?= $product['product_id'] ?>">روز جاری</label>
                                                    </div>
                                                    <label for="end_date_<?= $product['product_id'] ?>" class="form-label">تاریخ پایان (شمسی) (اختیاری)</label>
                                                    <input type="text" class="form-control persian-date optional-date" id="end_date_<?= $product['product_id'] ?>" name="end_date">
                                                </div>
                                                <div class="mb-3">
                                                    <label for="unit_price_<?= $product['product_id'] ?>" class="form-label">قیمت واحد (تومان)</label>
                                                    <input type="number" class="form-control" id="unit_price_<?= $product['product_id'] ?>" name="unit_price" step="0.01" required>
                                                </div>
                                                <button type="submit" name="add_price" class="btn btn-primary">ثبت قیمت</button>
                                            </form>
                                            <!-- نمایش تاریخچه قیمت‌ها -->
                                            <?php
                                            $product_prices = array_filter($price_history, fn($p) => $p['product_id'] == $product['product_id']);
                                            if (!empty($product_prices)):
                                                echo "<!-- دیباگ: تعداد قیمت‌ها برای محصول " . $product['product_id'] . " = " . count($product_prices) . " -->";
                                            ?>
                                                <h6 class="mt-4">تاریخچه قیمت‌ها:</h6>
                                                <ul class="list-group">
                                                    <?php foreach ($product_prices as $price): ?>
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
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="alert alert-warning text-center">محصولی وجود ندارد.</div>
    <?php endif; ?>
</div>

<!-- اسکریپت‌ها -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // مدیریت باز کردن مودال
        document.querySelectorAll('.open-modal').forEach(button => {
            button.addEventListener('click', (e) => {
                const modalId = button.getAttribute('data-bs-target');
                console.log('دکمه کلیک شد برای مودال: ', modalId);
                if (document.querySelector(modalId)) {
                    console.log('مودال با شناسه ', modalId, ' وجود دارد و باید باز شود.');
                    const modal = new bootstrap.Modal(document.querySelector(modalId), {
                        backdrop: true,
                        keyboard: true
                    });
                    modal.show();

                    // فعال‌سازی Datepicker بعد از باز شدن مودال
                    document.querySelector(modalId).addEventListener('shown.bs.modal', () => {
                        console.log('مودال باز شد، فعال‌سازی Datepicker...');

                        // Datepicker برای تاریخ شروع
                        document.querySelectorAll(`${modalId} .persian-date:not(.optional-date)`).forEach(input => {
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
                        });
                        console.log('Datepicker برای تاریخ شروع فعال شد');

                        // Datepicker برای تاریخ پایان
                        document.querySelectorAll(`${modalId} .optional-date`).forEach(input => {
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
                        });
                        console.log('Datepicker برای تاریخ پایان فعال شد');

                        // مدیریت چک‌باکس "روز جاری"
                        updateCurrentDay();
                    });
                } else {
                    console.error('مودال با شناسه ', modalId, ' یافت نشد!');
                }
            });
        });

        // مدیریت بستن مودال و پاکسازی backdrop
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('hidden.bs.modal', () => {
                console.log('مودال بسته شد، پاکسازی backdrop...');
                document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
                document.body.classList.remove('modal-open');
                modal.classList.remove('show');
            });
        });

        // تابع مدیریت چک‌باکس "روز جاری"
        function updateCurrentDay() {
            const today = '<?php echo get_today_jalali(); ?>';
            document.querySelectorAll('.optional-date').forEach(endDate => {
                const $endDate = $(endDate);
                const $checkbox = $('#' + $endDate.attr('id').replace('end_date', 'is_current_day'));
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

        // اجرا وقتی چک‌باکس تغییر می‌کنه
        document.querySelectorAll('input[name="is_current_day"]').forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                console.log('چک‌باکس تغییر کرد');
                updateCurrentDay();
            });
        });
    });
</script>

<?php
echo "<!-- دیباگ: قبل از لود فوتر -->";
require_once 'footer.php';
echo "<!-- دیباگ: بعد از لود فوتر -->";
?>