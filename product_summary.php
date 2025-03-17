<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';
require_once 'persian_year.php';

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

// تابع تبدیل سال میلادی به سال شمسی
function gregorian_year_to_jalali($gregorian_year)
{
    list($jy, $jm, $jd) = gregorian_to_jalali($gregorian_year, 1, 1);
    return $jy;
}

// تعریف تابع number_to_day
function number_to_day($day_number)
{
    $days = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];
    return $days[$day_number - 1] ?? 'نامعلوم';
}

// بررسی نقش کاربر
$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];

$years = [];
$work_months = [];
$partners = [];
$work_details = [];
$products = [];

// دریافت سال‌های موجود از دیتابیس (بر اساس سال شمسی)
$stmt = $pdo->query("SELECT DISTINCT start_date FROM Work_Months ORDER BY start_date DESC");
$work_months_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
$years = [];
foreach ($work_months_data as $month) {
    list($gy, $gm, $gd) = explode('-', $month['start_date']);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    $years[] = $jy;
}
$years = array_unique($years);
rsort($years);

// تنظیم سال پیش‌فرض به سال جاری شمسی
$current_persian_year = get_persian_current_year();
$selected_year = $_GET['year'] ?? $current_persian_year;

// دریافت ماه‌های کاری بر اساس سال شمسی
$stmt = $pdo->query("SELECT * FROM Work_Months ORDER BY start_date DESC");
$all_work_months = $stmt->fetchAll(PDO::FETCH_ASSOC);

$work_months = [];
foreach ($all_work_months as $month) {
    list($gy, $gm, $gd) = explode('-', $month['start_date']);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    if ($jy == $selected_year) {
        $work_months[] = $month;
    }
}

$selected_work_month_id = $_GET['work_month_id'] ?? 'all';
$selected_partner_id = $_GET['user_id'] ?? 'all';
$selected_work_day_id = $_GET['work_day_id'] ?? 'all';

// دریافت همکاران (کامل برای ادمین، محدود برای غیرادمین)
if ($is_admin) {
    $partners_query = $pdo->query("SELECT user_id, full_name FROM Users WHERE role = 'seller' ORDER BY full_name");
    $partners = $partners_query->fetchAll(PDO::FETCH_ASSOC);
} else {
    $partners_query = $pdo->prepare("
        SELECT DISTINCT u.user_id, u.full_name 
        FROM Partners p
        JOIN Users u ON u.user_id IN (p.user_id1, p.user_id2)
        WHERE (p.user_id1 = ? OR p.user_id2 = ?) AND u.role = 'seller'
        ORDER BY u.full_name
    ");
    $partners_query->execute([$current_user_id, $current_user_id]);
    $partners = $partners_query->fetchAll(PDO::FETCH_ASSOC);
}

// دریافت جزئیات روزهای کاری
if ($selected_work_month_id && $selected_work_month_id != 'all') {
    $month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
    $month_query->execute([$selected_work_month_id]);
    $month = $month_query->fetch(PDO::FETCH_ASSOC);

    if ($month) {
        $start_date = new DateTime($month['start_date']);
        $end_date = new DateTime($month['end_date']);
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));

        if ($is_admin) {
            $partner_query = $pdo->prepare("
                SELECT p.partner_id, p.work_day AS stored_day_number, u1.user_id AS user_id1, u1.full_name AS user1, 
                       COALESCE(u2.user_id, u1.user_id) AS user_id2, COALESCE(u2.full_name, u1.full_name) AS user2
                FROM Partners p
                JOIN Users u1 ON p.user_id1 = u1.user_id
                LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
                GROUP BY p.partner_id
            ");
            $partner_query->execute();
        } else {
            $partner_query = $pdo->prepare("
                SELECT p.partner_id, p.work_day AS stored_day_number, u1.user_id AS user_id1, u1.full_name AS user1, 
                       COALESCE(u2.user_id, u1.user_id) AS user_id2, COALESCE(u2.full_name, u1.full_name) AS user2
                FROM Partners p
                JOIN Users u1 ON p.user_id1 = u1.user_id
                LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
                WHERE (p.user_id1 = ? OR p.user_id2 = ?) AND u1.role = 'seller'
                GROUP BY p.partner_id
            ");
            $partner_query->execute([$current_user_id, $current_user_id]);
        }
        $partners_in_work = $partner_query->fetchAll(PDO::FETCH_ASSOC);

        foreach ($partners_in_work as $partner) {
            $partner_id = $partner['partner_id'];
            foreach ($date_range as $date) {
                $work_date = $date->format('Y-m-d');
                $day_number_php = (int) date('N', strtotime($work_date));
                $adjusted_day_number = ($day_number_php + 5) % 7;
                if ($adjusted_day_number == 0)
                    $adjusted_day_number = 7;

                if ($partner['stored_day_number'] == $adjusted_day_number && (!$selected_partner_id || $selected_partner_id == 'all' || $partner['user_id1'] == $selected_partner_id || $partner['user_id2'] == $selected_partner_id)) {
                    $detail_query = $pdo->prepare("
                        SELECT * FROM Work_Details 
                        WHERE work_date = ? AND work_month_id = ? AND partner_id = ?
                    ");
                    $detail_query->execute([$work_date, $selected_work_month_id, $partner_id]);
                    $existing_detail = $detail_query->fetch(PDO::FETCH_ASSOC);

                    if ($existing_detail) {
                        $work_details[] = [
                            'work_details_id' => $existing_detail['id'],
                            'work_date' => $work_date,
                            'work_day' => number_to_day($adjusted_day_number),
                            'partner_id' => $partner_id,
                            'user1' => $partner['user1'],
                            'user2' => $partner['user2'],
                            'user_id1' => $partner['user_id1'],
                            'user_id2' => $partner['user_id2']
                        ];
                    }
                }
            }
        }
    }
}

// کوئری برای دریافت محصولات فروخته‌شده
$products_query = "
    SELECT oi.product_name, oi.unit_price, SUM(oi.quantity) AS total_quantity
    FROM Order_Items oi
    JOIN Orders o ON oi.order_id = o.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id";

$conditions = [];
$params = [];

if (!$is_admin) {
    $conditions[] = "EXISTS (
        SELECT 1 FROM Partners p 
        WHERE p.partner_id = wd.partner_id 
        AND (p.user_id1 = ? OR p.user_id2 = ?)
    )";
    $params[] = $current_user_id;
    $params[] = $current_user_id;
}

if ($selected_year) {
    // فیلتر سال شمسی رو توی PHP اعمال می‌کنیم، نه SQL
}

if ($selected_work_month_id && $selected_work_month_id != 'all') {
    $conditions[] = "wd.work_month_id = ?";
    $params[] = $selected_work_month_id;
}

if ($selected_partner_id && $selected_partner_id != 'all') {
    $conditions[] = "EXISTS (
        SELECT 1 FROM Partners p 
        WHERE p.partner_id = wd.partner_id 
        AND (p.user_id1 = ? OR p.user_id2 = ?)
    )";
    $params[] = $selected_partner_id;
    $params[] = $selected_partner_id;
}

if ($selected_work_day_id && $selected_work_day_id != 'all') {
    $conditions[] = "wd.id = ?";
    $params[] = $selected_work_day_id;
}

if (!empty($conditions)) {
    $products_query .= " WHERE " . implode(" AND ", $conditions);
}

$products_query .= " GROUP BY oi.product_name, oi.unit_price";

$stmt_products = $pdo->prepare($products_query);
$stmt_products->execute($params);
$products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);

// فیلتر سال شمسی بعد از اجرای کوئری (توی PHP)
if ($selected_year) {
    $filtered_products = [];
    foreach ($products as $product) {
        $stmt_work_details = $pdo->prepare("
            SELECT wm.start_date
            FROM Work_Details wd
            JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
            JOIN Orders o ON wd.id = o.work_details_id
            JOIN Order_Items oi ON o.order_id = oi.order_id
            WHERE oi.product_name = ? AND oi.unit_price = ?
            LIMIT 1
        ");
        $stmt_work_details->execute([$product['product_name'], $product['unit_price']]);
        $work_detail = $stmt_work_details->fetch(PDO::FETCH_ASSOC);

        if ($work_detail) {
            list($gy, $gm, $gd) = explode('-', $work_detail['start_date']);
            list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
            if ($jy == $selected_year) {
                $filtered_products[] = $product;
            }
        }
    }
    $products = $filtered_products;
}

// مرتب‌سازی بر اساس الفبای فارسی
$collator = new Collator('fa_IR');
usort($products, function ($a, $b) use ($collator) {
    return $collator->compare($a['product_name'], $b['product_name']);
});

// محاسبه جمع کل
$total_quantity = 0;
$total_amount = 0;
foreach ($products as $product) {
    $total_quantity += $product['total_quantity'];
    $total_amount += $product['total_quantity'] * $product['unit_price'];
}

// محاسبه جمع کل
$total_quantity = 0;
$total_amount = 0;
foreach ($products as $product) {
    $total_quantity += $product['total_quantity'];
    $total_amount += $product['total_quantity'] * $product['unit_price'];
}
?>

<div class="container-fluid">
    <h5 class="card-title mb-4">تجمیع محصولات</h5>
    <p class="mb-4">تعداد کل: <?= number_format($total_quantity, 0) ?> - مبلغ کل: <?= number_format($total_amount, 0) ?>
        تومان</p>

    <form method="GET" class="row g-3 mb-3">
        <div class="col-auto">
            <select name="year" class="form-select" onchange="this.form.submit()">
                <?php foreach ($years as $year): ?>
                    <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                        <?= $year ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <select name="work_month_id" class="form-select" onchange="this.form.submit()">
                <option value="all" <?= $selected_work_month_id == 'all' ? 'selected' : '' ?>>همه ماه‌ها</option>
                <?php foreach ($work_months as $month): ?>
                    <option value="<?= $month['work_month_id'] ?>" <?= $selected_work_month_id == $month['work_month_id'] ? 'selected' : '' ?>>
                        <?= gregorian_to_jalali_format($month['start_date']) ?> تا
                        <?= gregorian_to_jalali_format($month['end_date']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <select name="user_id" class="form-select" onchange="this.form.submit()">
                <option value="all" <?= $selected_partner_id == 'all' ? 'selected' : '' ?>>همه همکاران</option>
                <?php foreach ($partners as $partner): ?>
                    <option value="<?= htmlspecialchars($partner['user_id']) ?>"
                        <?= $selected_partner_id == $partner['user_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($partner['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <select name="work_day_id" class="form-select" onchange="this.form.submit()">
                <option value="all" <?= $selected_work_day_id == 'all' ? 'selected' : '' ?>>همه روزها</option>
                <?php foreach ($work_details as $day): ?>
                    <option value="<?= $day['work_details_id'] ?>" <?= $selected_work_day_id == $day['work_details_id'] ? 'selected' : '' ?>>
                        <?= gregorian_to_jalali_format($day['work_date']) ?> (<?= $day['user1'] ?> -
                        <?= $day['user2'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if (!empty($products)): ?>
        <div class="table-wrapper">
            <table id="productsTable" class="table table-light table-hover">
                <thead>
                    <tr>
                        <th>ردیف</th>
                        <th>نام محصول</th>
                        <th>قیمت واحد</th>
                        <th>تعداد</th>
                        <th>قیمت کل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $row = 1;
                    foreach ($products as $product): ?>
                        <tr>
                            <td><?= $row++ ?></td>
                            <td><?= htmlspecialchars($product['product_name']) ?></td>
                            <td><?= number_format($product['unit_price'], 0) ?></td>
                            <td><?= number_format($product['total_quantity'], 0) ?></td>
                            <td><?= number_format($product['total_quantity'] * $product['unit_price'], 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="alert alert-warning text-center">محصولی ثبت نشده است.</div>
    <?php endif; ?>
</div>

<script>
    $(document).ready(function () {
        $('#productsTable').DataTable({         // 10 ردیف در هر صفحه
            "scrollX": true,            // فعال کردن اسکرول افقی
            "paging": false,             // فعال کردن صفحه‌بندی
            "autoWidth": true,         // غیرفعال کردن تنظیم خودکار عرض
            "ordering": true,           // فعال کردن مرتب‌سازی ستون‌ها
            "responsive": false,        // غیرفعال کردن حالت ریسپانسیو
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
                { "targets": "_all", "className": "text-center" } // وسط‌چین کردن همه ستون‌ها
                { "targets": 0, "width": "50px" },  // شناسه
                { "targets": 1, "width": "200px" }, // نام محصول
                { "targets": 2, "width": "120px" }  // قیمت واحد
            ]
        });
    });
</script>

<?php require_once 'footer.php'; ?>