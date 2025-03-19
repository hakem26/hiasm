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
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

// تابع برای دریافت نام ماه شمسی
function get_jalali_month_name($month) {
    $month_names = [
        1 => 'فروردین', 2 => 'اردیبشهت', 3 => 'خرداد',
        4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
        10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    ];
    return $month_names[$month] ?? '';
}

// تابع تبدیل سال میلادی به سال شمسی
function gregorian_year_to_jalali($gregorian_year) {
    list($jy, $jm, $jd) = gregorian_to_jalali($gregorian_year, 1, 1);
    return $jy;
}

// بررسی نقش کاربر
$current_user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user_role = $stmt->fetchColumn();

// دریافت سال‌های موجود از دیتابیس (میلادی)
$stmt = $pdo->query("SELECT DISTINCT YEAR(start_date) AS year FROM Work_Months ORDER BY year DESC");
$years_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
$years = array_column($years_db, 'year');

// تنظیم سال پیش‌فرض (سال جاری شمسی)
$current_gregorian_year = date('Y'); // مثلاً 2025
$current_jalali_year = gregorian_year_to_jalali($current_gregorian_year); // مثلاً 1403

// دریافت مقادیر فیلترها
$selected_year = $_GET['year'] ?? $current_gregorian_year; // پیش‌فرض: سال جاری میلادی
$selected_user_id = $_GET['user_id'] ?? ($user_role === 'admin' ? 'all' : $current_user_id); // پیش‌فرض: "همه" برای ادمین
$selected_month = $_GET['work_month_id'] ?? 'all'; // پیش‌فرض: "همه"
$selected_work_date = $_GET['work_date'] ?? 'all'; // پیش‌فرض: "همه"

// لیست محصولات فروخته‌شده
$products = [];
try {
    $query = "
        SELECT oi.product_name, oi.unit_price, SUM(oi.quantity) AS total_quantity, SUM(oi.total_price) AS total_price
        FROM Order_Items oi
        JOIN Orders o ON oi.order_id = o.order_id
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
        WHERE YEAR(wm.start_date) = ?
    ";
    $params = [$selected_year];

    // فیلتر همکار
    if ($selected_user_id !== 'all') {
        $query .= " AND p.user_id1 = ?";
        $params[] = $selected_user_id;
    }

    // فیلتر ماه کاری
    if ($selected_month !== 'all') {
        $query .= " AND wd.work_month_id = ?";
        $params[] = $selected_month;
    }

    // فیلتر روز کاری
    if ($selected_work_date !== 'all') {
        $query .= " AND wd.work_date = ?";
        $params[] = $selected_work_date;
    }

    $query .= " GROUP BY oi.product_name, oi.unit_price ORDER BY oi.product_name COLLATE utf8mb4_persian_ci";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching sold products: " . $e->getMessage());
    $products = [];
}

// دریافت لیست روزهای کاری برای فیلتر
$work_dates = [];
if ($selected_year && $selected_month !== 'all') {
    $query = "
        SELECT DISTINCT wd.work_date
        FROM Work_Details wd
        JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
        WHERE YEAR(wm.start_date) = ?
    ";
    $params = [$selected_year];

    if ($selected_month !== 'all') {
        $query .= " AND wd.work_month_id = ?";
        $params[] = $selected_month;
    }

    if ($selected_user_id !== 'all') {
        $query .= " AND wd.partner_id IN (SELECT partner_id FROM Partners WHERE user_id1 = ?)";
        $params[] = $selected_user_id;
    }

    $query .= " ORDER BY wd.work_date DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $work_dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لیست محصولات فروخته‌شده</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <div class="container-fluid mt-5">
        <h5 class="card-title mb-4">لیست محصولات فروخته‌شده</h5>

        <!-- فرم فیلترها -->
        <div class="mb-4">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="year" class="form-label">سال</label>
                    <select name="year" id="year" class="form-select">
                        <?php foreach ($years as $year): ?>
                            <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                                <?= gregorian_year_to_jalali($year) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($user_role === 'admin'): ?>
                    <div class="col-md-3">
                        <label for="user_id" class="form-label">همکار</label>
                        <select name="user_id" id="user_id" class="form-select">
                            <option value="all" <?= $selected_user_id === 'all' ? 'selected' : '' ?>>همه</option>
                            <?php
                            $stmt = $pdo->query("SELECT user_id, full_name FROM Users WHERE role = 'seller' ORDER BY full_name");
                            while ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                echo '<option value="' . $user['user_id'] . '" ' . ($selected_user_id == $user['user_id'] ? 'selected' : '') . '>' . htmlspecialchars($user['full_name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="col-md-3">
                    <label for="work_month_id" class="form-label">ماه کاری</label>
                    <select name="work_month_id" id="work_month_id" class="form-select">
                        <option value="all" <?= $selected_month === 'all' ? 'selected' : '' ?>>همه</option>
                        <!-- ماه‌ها با AJAX بارگذاری می‌شن -->
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="work_date" class="form-label">روز کاری</label>
                    <select name="work_date" id="work_date" class="form-select">
                        <option value="all" <?= $selected_work_date === 'all' ? 'selected' : '' ?>>همه</option>
                        <?php foreach ($work_dates as $date): ?>
                            <option value="<?= $date ?>" <?= $selected_work_date == $date ? 'selected' : '' ?>>
                                <?= gregorian_to_jalali_format($date) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- جدول محصولات -->
        <div class="table-responsive" id="products-table">
            <table class="table table-light">
                <thead>
                    <tr>
                        <th>ردیف</th>
                        <th>اقلام</th>
                        <th>قیمت واحد</th>
                        <th>تعداد</th>
                        <th>قیمت کل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="5" class="text-center">محصولی یافت نشد.</td>
                        </tr>
                    <?php else: ?>
                        <?php $row_number = 1; ?>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?= $row_number++ ?></td>
                                <td><?= htmlspecialchars($product['product_name']) ?></td>
                                <td><?= number_format($product['unit_price'], 0) ?> تومان</td>
                                <td><?= $product['total_quantity'] ?></td>
                                <td><?= number_format($product['total_price'], 0) ?> تومان</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // تابع برای بارگذاری ماه‌ها بر اساس سال
            function loadMonths(year) {
                const selected_user_id = $('#user_id').val() || '<?= $current_user_id ?>';
                if (!year) {
                    $('#work_month_id').html('<option value="all">همه</option>');
                    return;
                }
                $.ajax({
                    url: 'get_months.php',
                    type: 'POST',
                    data: { year: year, user_id: selected_user_id },
                    success: function(response) {
                        $('#work_month_id').html('<option value="all">همه</option>' + response);
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading months:', error);
                        $('#work_month_id').html('<option value="all">همه</option>');
                    }
                });
            }

            // تابع برای بارگذاری روزهای کاری بر اساس سال و ماه
            function loadWorkDates(year, work_month_id) {
                const selected_user_id = $('#user_id').val() || '<?= $current_user_id ?>';
                $.ajax({
                    url: 'get_work_dates.php',
                    type: 'POST',
                    data: { year: year, work_month_id: work_month_id, user_id: selected_user_id },
                    success: function(response) {
                        $('#work_date').html('<option value="all">همه</option>' + response);
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading work dates:', error);
                        $('#work_date').html('<option value="all">همه</option>');
                    }
                });
            }

            // تابع برای بارگذاری محصولات
            function loadProducts() {
                const year = $('#year').val();
                const user_id = $('#user_id').val() || '<?= $current_user_id ?>';
                const work_month_id = $('#work_month_id').val();
                const work_date = $('#work_date').val();

                $.ajax({
                    url: 'get_sold_products.php',
                    type: 'GET',
                    data: {
                        year: year,
                        user_id: user_id,
                        work_month_id: work_month_id,
                        work_date: work_date
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#products-table').html(response.html);
                        } else {
                            $('#products-table').html('<div class="alert alert-danger text-center">خطا: ' + response.message + '</div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', { status: status, error: error, response: xhr.responseText });
                        $('#products-table').html('<div class="alert alert-danger text-center">خطایی در بارگذاری محصولات رخ داد: ' + error + '</div>');
                    }
                });
            }

            // بارگذاری اولیه
            const initial_year = $('#year').val();
            if (initial_year) {
                loadMonths(initial_year);
                loadWorkDates(initial_year, $('#work_month_id').val());
            }
            loadProducts();

            // رویدادهای تغییر
            $('#year').on('change', function() {
                const year = $(this).val();
                loadMonths(year);
                loadWorkDates(year, $('#work_month_id').val());
                loadProducts();
            });

            $('#user_id').on('change', function() {
                const year = $('#year').val();
                loadMonths(year);
                loadWorkDates(year, $('#work_month_id').val());
                loadProducts();
            });

            $('#work_month_id').on('change', function() {
                const year = $('#year').val();
                loadWorkDates(year, $(this).val());
                loadProducts();
            });

            $('#work_date').on('change', function() {
                loadProducts();
            });
        });
    </script>

    <?php require_once 'footer.php'; ?>
</body>
</html>