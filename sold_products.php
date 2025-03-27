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

// بررسی نقش کاربر
$current_user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user_role = $stmt->fetchColumn();

// دریافت تاریخ‌های شروع از Work_Months برای تعیین سال‌های شمسی
$stmt = $pdo->query("SELECT DISTINCT start_date FROM Work_Months ORDER BY start_date DESC");
$work_months_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// لاگ برای دیباگ
error_log("Work_Months start_date: " . print_r($work_months_data, true));

// محاسبه سال‌های شمسی بر اساس تاریخ‌های start_date
$years = [];
foreach ($work_months_data as $month) {
    $jalali_year = get_persian_year($month['start_date']);
    $years[] = $jalali_year;
}
$years = array_unique($years);
rsort($years); // مرتب‌سازی نزولی (جدیدترین سال اول)

// لاگ برای دیباگ
error_log("Jalali years: " . print_r($years, true));

// تنظیم سال پیش‌فرض (جدیدترین سال شمسی)
$selected_year = $_GET['year'] ?? ($years[0] ?? null); // پیش‌فرض: جدیدترین سال شمسی
$selected_month = $_GET['work_month_id'] ?? 'all'; // پیش‌فرض: "همه"
$selected_partner_id = $_GET['partner_id'] ?? 'all'; // پیش‌فرض: "همه"

// پیدا کردن work_month_idهایی که توی سال شمسی انتخاب‌شده هستن
$selected_work_month_ids = [];
if ($selected_year) {
    $stmt = $pdo->query("SELECT work_month_id, start_date FROM Work_Months");
    $all_work_months = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_work_months as $month) {
        $jalali_year = get_persian_year($month['start_date']);
        if ($jalali_year == $selected_year) {
            $selected_work_month_ids[] = $month['work_month_id'];
        }
    }
}

// لاگ برای دیباگ
error_log("Selected year: $selected_year, Selected work_month_ids: " . print_r($selected_work_month_ids, true));

// جمع کل فروش و تعداد کل محصولات
$total_sales = 0;
$total_quantity = 0;
if (!empty($selected_work_month_ids)) {
    try {
        $query = "
            SELECT COALESCE(SUM(o.total_amount), 0) AS total_sales,
                   COALESCE(SUM(oi.quantity), 0) AS total_quantity
            FROM Orders o
            JOIN Order_Items oi ON o.order_id = oi.order_id
            JOIN Work_Details wd ON o.work_details_id = wd.id
            WHERE wd.work_month_id IN (" . implode(',', array_fill(0, count($selected_work_month_ids), '?')) . ")
            AND EXISTS (
                SELECT 1 FROM Partners p 
                WHERE p.partner_id = wd.partner_id 
                AND (p.user_id1 = ? OR p.user_id2 = ?)
            )
        ";
        $params = array_merge($selected_work_month_ids, [$current_user_id, $current_user_id]);

        if ($user_role === 'admin') {
            $query = "
                SELECT COALESCE(SUM(o.total_amount), 0) AS total_sales,
                       COALESCE(SUM(oi.quantity), 0) AS total_quantity
                FROM Orders o
                JOIN Order_Items oi ON o.order_id = oi.order_id
                JOIN Work_Details wd ON o.work_details_id = wd.id
                WHERE wd.work_month_id IN (" . implode(',', array_fill(0, count($selected_work_month_ids), '?')) . ")
            ";
            $params = $selected_work_month_ids;
        }

        if ($selected_month !== 'all') {
            $query .= " AND wd.work_month_id = ?";
            $params[] = $selected_month;
        }

        if ($selected_partner_id !== 'all') {
            $query .= " AND EXISTS (
                SELECT 1 FROM Partners p 
                WHERE p.partner_id = wd.partner_id 
                AND (p.user_id1 = ? OR p.user_id2 = ?)
            )";
            $params[] = $selected_partner_id;
            $params[] = $selected_partner_id;
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_sales = $summary['total_sales'] ?? 0;
        $total_quantity = $summary['total_quantity'] ?? 0;

        // لاگ برای دیباگ
        error_log("Total sales and quantity: " . print_r($summary, true));
    } catch (Exception $e) {
        error_log("Error fetching total sales and quantity: " . $e->getMessage());
    }
}

// لیست محصولات فروخته‌شده
$products = [];
if (!empty($selected_work_month_ids)) {
    try {
        $query = "
            SELECT oi.product_name, oi.unit_price, SUM(oi.quantity) AS total_quantity, SUM(oi.total_price) AS total_price
            FROM Order_Items oi
            JOIN Orders o ON oi.order_id = o.order_id
            JOIN Work_Details wd ON o.work_details_id = wd.id
            JOIN Partners p ON wd.partner_id = p.partner_id
            JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
            WHERE wd.work_month_id IN (" . implode(',', array_fill(0, count($selected_work_month_ids), '?')) . ")
        ";
        $params = $selected_work_month_ids;

        if ($user_role !== 'admin') {
            $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
            $params[] = $current_user_id;
            $params[] = $current_user_id;
        }

        if ($selected_month !== 'all') {
            $query .= " AND wd.work_month_id = ?";
            $params[] = $selected_month;
        }

        if ($selected_partner_id !== 'all') {
            $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
            $params[] = $selected_partner_id;
            $params[] = $selected_partner_id;
        }

        $query .= " GROUP BY oi.product_name, oi.unit_price ORDER BY oi.product_name COLLATE utf8mb4_persian_ci";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // لاگ برای دیباگ
        error_log("Products: " . print_r($products, true));
    } catch (Exception $e) {
        error_log("Error fetching sold products: " . $e->getMessage());
        $products = [];
    }
}

// دریافت لیست ماه‌های کاری برای فیلتر
$work_months = [];
if (!empty($selected_work_month_ids)) {
    $query = "
        SELECT DISTINCT wm.work_month_id, wm.start_date, wm.end_date
        FROM Work_Months wm
        JOIN Work_Details wd ON wm.work_month_id = wd.work_month_id
        JOIN Partners p ON wd.partner_id = p.partner_id
        WHERE wd.work_month_id IN (" . implode(',', array_fill(0, count($selected_work_month_ids), '?')) . ")
    ";
    $params = $selected_work_month_ids;

    if ($user_role !== 'admin') {
        $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
        $params[] = $current_user_id;
        $params[] = $current_user_id;
    }

    $query .= " ORDER BY wm.start_date DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $work_months = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // لاگ برای دیباگ
    error_log("Work months: " . print_r($work_months, true));
}

// دریافت لیست همکاران برای فیلتر
$partners = [];
if (!empty($selected_work_month_ids) && $selected_month !== 'all') {
    $query = "
        SELECT DISTINCT u.user_id, u.full_name
        FROM Users u
        JOIN Partners p ON (u.user_id = p.user_id1 OR u.user_id = p.user_id2)
        JOIN Work_Details wd ON p.partner_id = wd.partner_id
        JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
        WHERE wd.work_month_id IN (" . implode(',', array_fill(0, count($selected_work_month_ids), '?')) . ")
        AND wd.work_month_id = ?
    ";
    $params = array_merge($selected_work_month_ids, [$selected_month]);

    if ($user_role !== 'admin') {
        $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
        $params[] = $current_user_id;
        $params[] = $current_user_id;
        $query .= " AND u.user_id != ?";
        $params[] = $current_user_id;
    }

    $query .= " ORDER BY u.full_name";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // لاگ برای دیباگ
    error_log("Partners: " . print_r($partners, true));
}
?>

<div class="container-fluid mt-5">
    <h5 class="card-title mb-4">لیست محصولات فروخته‌شده</h5>

    <!-- نمایش جمع کل‌ها -->
    <div class="summary-text">
        <p>تعداد کل: <span id="total-quantity"><?= number_format($total_quantity, 0) ?></span> عدد</p>
        <p>مبلغ کل: <span id="total-sales"><?= number_format($total_sales, 0)?></span> تومان</p>
    </div>

    <!-- فرم فیلترها -->
    <div class="mb-4">
        <div class="row g-3">
            <div class="col-md-4">
                <label for="year" class="form-label">سال</label>
                <select name="year" id="year" class="form-select">
                    <?php foreach ($years as $year): ?>
                        <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                            <?= $year ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="work_month_id" class="form-label">ماه کاری</label>
                <select name="work_month_id" id="work_month_id" class="form-select">
                    <option value="all" <?= $selected_month === 'all' ? 'selected' : '' ?>>همه</option>
                    <?php foreach ($work_months as $month): ?>
                        <option value="<?= $month['work_month_id'] ?>" <?= $selected_month == $month['work_month_id'] ? 'selected' : '' ?>>
                            <?= gregorian_to_jalali_format($month['start_date']) . ' تا ' . gregorian_to_jalali_format($month['end_date']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="partner_id" class="form-label">همکار</label>
                <select name="partner_id" id="partner_id" class="form-select">
                    <option value="all" <?= $selected_partner_id === 'all' ? 'selected' : '' ?>>همه</option>
                    <?php foreach ($partners as $partner): ?>
                        <option value="<?= $partner['user_id'] ?>" <?= $selected_partner_id == $partner['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($partner['full_name']) ?>
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
            if (!year) {
                $('#work_month_id').html('<option value="all">همه</option>');
                $('#partner_id').html('<option value="all">همه</option>');
                return;
            }
            console.log('Loading months for year:', year); // لاگ برای دیباگ
            $.ajax({
                url: 'get_months_for_sold_products.php',
                type: 'POST',
                data: { year: year },
                success: function(response) {
                    console.log('Months response:', response); // لاگ برای دیباگ
                    $('#work_month_id').html('<option value="all">همه</option>' + response);
                    $('#partner_id').html('<option value="all">همه</option>');
                },
                error: function(xhr, status, error) {
                    console.error('Error loading months:', error, xhr.responseText);
                    $('#work_month_id').html('<option value="all">همه</option>');
                    $('#partner_id').html('<option value="all">همه</option>');
                }
            });
        }

        // تابع برای بارگذاری همکاران بر اساس سال و ماه
        function loadPartners(year, work_month_id) {
            if (!year || work_month_id === 'all') {
                $('#partner_id').html('<option value="all">همه</option>');
                return;
            }
            console.log('Loading partners for year:', year, 'and month:', work_month_id); // لاگ برای دیباگ
            $.ajax({
                url: 'get_partners_for_sold_products.php',
                type: 'POST',
                data: { year: year, work_month_id: work_month_id },
                success: function(response) {
                    console.log('Partners response:', response); // لاگ برای دیباگ
                    $('#partner_id').html('<option value="all">همه</option>' + response);
                },
                error: function(xhr, status, error) {
                    console.error('Error loading partners:', error, xhr.responseText);
                    $('#partner_id').html('<option value="all">همه</option>');
                }
            });
        }

        // تابع برای بارگذاری محصولات و جمع کل‌ها
        function loadProducts() {
            const year = $('#year').val();
            const work_month_id = $('#work_month_id').val();
            const partner_id = $('#partner_id').val();

            console.log('Loading products for year:', year, 'month:', work_month_id, 'partner:', partner_id); // لاگ برای دیباگ
            $.ajax({
                url: 'get_sold_products.php',
                type: 'GET',
                data: {
                    year: year,
                    work_month_id: work_month_id,
                    partner_id: partner_id
                },
                dataType: 'json',
                success: function(response) {
                    console.log('Products response:', response); // لاگ برای دیباگ
                    if (response.success) {
                        $('#total-quantity').text(new Intl.NumberFormat('fa-IR').format(response.total_quantity));
                        $('#total-sales').text(new Intl.NumberFormat('fa-IR').format(response.total_sales));
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
            loadPartners(initial_year, $('#work_month_id').val());
        }
        loadProducts();

        // رویدادهای تغییر
        $('#year').on('change', function() {
            const year = $(this).val();
            loadMonths(year);
            loadProducts();
        });

        $('#work_month_id').on('change', function() {
            const year = $('#year').val();
            const work_month_id = $(this).val();
            loadPartners(year, work_month_id);
            loadProducts();
        });

        $('#partner_id').on('change', function() {
            loadProducts();
        });
    });
</script>

<?php require_once 'footer.php'; ?>