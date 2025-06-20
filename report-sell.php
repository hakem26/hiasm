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
function gregorian_to_jalali_format($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

// تابع برای دریافت سال شمسی از تاریخ میلادی
function get_jalali_year($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    $gy = (int) $gy;
    $gm = (int) $gm;
    $gd = (int) $gd;
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return $jy;
}

// تابع برای دریافت نام ماه شمسی
function get_jalali_month_name($month)
{
    $month_names = [
        1 => 'فروردین',
        2 => 'اردیبشهت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند'
    ];
    return $month_names[$month] ?? '';
}

// بررسی نقش کاربر
$current_user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user_role = $stmt->fetchColumn();

// دریافت همه ماه‌ها برای استخراج سال‌های شمسی
$stmt = $pdo->query("SELECT start_date FROM Work_Months ORDER BY start_date DESC");
$months = $stmt->fetchAll(PDO::FETCH_ASSOC);

$years_jalali = [];
$year_mapping = []; // برای نگاشت سال شمسی به بازه تاریخ
foreach ($months as $month) {
    $jalali_year = get_jalali_year($month['start_date']);
    $gregorian_year = (int) date('Y', strtotime($month['start_date']));
    if (!in_array($jalali_year, $years_jalali)) {
        $years_jalali[] = $jalali_year;
        $year_mapping[$jalali_year] = [
            'start_date' => "$gregorian_year-03-21",
            'end_date' => ($gregorian_year + 1) . "-03-21"
        ];
        if ($jalali_year == 1404) {
            $year_mapping[$jalali_year]['start_date'] = "2025-03-21";
            $year_mapping[$jalali_year]['end_date'] = "2026-03-21";
        } elseif ($jalali_year == 1403) {
            $year_mapping[$jalali_year]['start_date'] = "2024-03-20";
            $year_mapping[$jalali_year]['end_date'] = "2025-03-21";
        }
    }
}
sort($years_jalali, SORT_NUMERIC);
$years_jalali = array_reverse($years_jalali); // مرتب‌سازی نزولی

// دیباگ سال‌ها
error_log("report_sell.php: Available years (jalali): " . implode(", ", $years_jalali));

// تنظیم پیش‌فرض به جدیدترین سال شمسی
$selected_year_jalali = $_GET['year'] ?? null;
if (!$selected_year_jalali) {
    $selected_year_jalali = $years_jalali[0] ?? get_jalali_year(date('Y-m-d')); // جدیدترین سال یا سال جاری
}

// دریافت همکار انتخاب‌شده
$selected_user_id = $_GET['user_id'] ?? ($user_role === 'admin' ? 'all' : $current_user_id); // پیش‌فرض "همه" برای ادمین
$selected_month = $_GET['work_month_id'] ?? '';

// متغیرهای جمع کل
$total_sales = 0;
$total_discount = 0;
$total_sessions = 0;

// محصولات
$products = [];

if ($selected_year_jalali && $selected_month && isset($year_mapping[$selected_year_jalali])) {
    $start_date = $year_mapping[$selected_year_jalali]['start_date'];
    $end_date = $year_mapping[$selected_year_jalali]['end_date'];

    // جمع کل فروش و تخفیف
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(o.total_amount), 0) AS total_sales,
               COALESCE(SUM(o.discount), 0) AS total_discount
        FROM Orders o
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
        WHERE wd.work_month_id = ? 
        AND wm.start_date >= ? AND wm.start_date < ?
        " . ($selected_user_id !== 'all' ? "AND (p.user_id1 = ? OR p.user_id2 = ?)" : "") . "
    ");
    $params = [$selected_month, $start_date, $end_date];
    if ($selected_user_id !== 'all') {
        $params[] = $selected_user_id;
        $params[] = $selected_user_id;
    }
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_sales = $summary['total_sales'] ?? 0;
    $total_discount = $summary['total_discount'] ?? 0;

    // تعداد جلسات (روزهای کاری)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT wd.work_date) AS total_sessions
        FROM Work_Details wd
        JOIN Partners p ON wd.partner_id = p.partner_id
        JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
        WHERE wd.work_month_id = ? 
        AND wm.start_date >= ? AND wm.start_date < ?
        " . ($selected_user_id !== 'all' ? "AND (p.user_id1 = ? OR p.user_id2 = ?)" : "") . "
    ");
    $params = [$selected_month, $start_date, $end_date];
    if ($selected_user_id !== 'all') {
        $params[] = $selected_user_id;
        $params[] = $selected_user_id;
    }
    $stmt->execute($params);
    $sessions = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_sessions = $sessions['total_sessions'] ?? 0;

    // لیست محصولات
    $stmt = $pdo->prepare("
        SELECT oi.product_name, oi.unit_price, SUM(oi.quantity) AS total_quantity, SUM(oi.total_price) AS total_price
        FROM Order_Items oi
        JOIN Orders o ON oi.order_id = o.order_id
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
        WHERE wd.work_month_id = ? 
        AND wm.start_date >= ? AND wm.start_date < ?
        " . ($selected_user_id !== 'all' ? "AND (p.user_id1 = ? OR p.user_id2 = ?)" : "") . "
        GROUP BY oi.product_name, oi.unit_price
        ORDER BY oi.product_name COLLATE utf8mb4_persian_ci
    ");
    $params = [$selected_month, $start_date, $end_date];
    if ($selected_user_id !== 'all') {
        $params[] = $selected_user_id;
        $params[] = $selected_user_id;
    }
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>گزارش فروش</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <div class="container-fluid mt-5">
        <h5 class="card-title mb-4">گزارش فروش</h5>

        <!-- جمع کل‌ها -->
        <div class="mb-4">
            <p>جمع کل فروش: <span id="total-sales"><?= number_format($total_sales, 0) ?></span> تومان</p>
            <p>جمع کل تخفیفات: <span id="total-discount"><?= number_format($total_discount, 0) ?></span> تومان</p>
            <p>مجموع جلسات آژانس: <span id="total-sessions"><?= $total_sessions ?></span> جلسه</p>
        </div>

        <!-- فرم فیلترها -->
        <div class="mb-4">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="year" class="form-label">سال</label>
                    <select name="year" id="year" class="form-select">
                        <option value="">همه</option>
                        <?php foreach ($years_jalali as $year): ?>
                            <option value="<?= $year ?>" <?= $selected_year_jalali == $year ? 'selected' : '' ?>>
                                <?= $year ?>
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
                <div>
                    <label for="work_month_id" class="form-label">ماه کاری</label>
                    <div class="input-group">
                        <button id="view-report-btn" class="btn btn-info" formtarget="_blank" disabled>
                            <i class="fas fa-eye"></i> مشاهده
                        </button>
                        <select name="work_month_id" id="work_month_id" class="form-select">
                            <option value="">انتخاب ماه</option>
                            <!-- ماه‌ها اینجا با AJAX بارگذاری می‌شن -->
                        </select>
                    </div>
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
                                <td><?= number_format($product['unit_price'], 0) ?> </td>
                                <td><?= $product['total_quantity'] ?></td>
                                <td><?= number_format($product['total_price'], 0) ?> </td>
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
        $(document).ready(function () {
            // تابع برای بارگذاری ماه‌ها بر اساس سال
            function loadMonths(year) {
                const selected_user_id = $('#user_id').val() || '<?= $current_user_id ?>';
                console.log('Loading months for year:', year, 'selected_user_id:', selected_user_id);
                if (!year) {
                    $('#work_month_id').html('<option value="">انتخاب ماه</option>');
                    $('#view-report-btn').prop('disabled', true);
                    return;
                }
                $.ajax({
                    url: 'get_months.php',
                    type: 'POST',
                    data: { year: year, user_id: selected_user_id },
                    success: function (response) {
                        console.log('Months response:', response);
                        $('#work_month_id').html(response);
                        $('#view-report-btn').prop('disabled', $('#work_month_id').val() === '');
                    },
                    error: function (xhr, status, error) {
                        console.error('Error loading months:', error, 'Status:', status, 'Response:', xhr.responseText);
                        $('#work_month_id').html('<option value="">خطا در بارگذاری ماه‌ها: ' + error + '</option>');
                        $('#view-report-btn').prop('disabled', true);
                    }
                });
            }

            // تابع برای بارگذاری محصولات
            function loadProducts() {
                console.log('Loading products...');
                const year = $('#year').val();
                const user_id = $('#user_id').val() || '<?= $current_user_id ?>';
                const work_month_id = $('#work_month_id').val();

                if (!work_month_id) {
                    $('#products-table').html('<table class="table table-light"><thead><tr><th>ردیف</th><th>اقلام</th><th>قیمت واحد</th><th>تعداد</th><th>قیمت کل</th></tr></thead><tbody><tr><td colspan="5" class="text-center">لطفاً ماه کاری را انتخاب کنید.</td></tr></tbody></table>');
                    $('#view-report-btn').prop('disabled', true);
                    return;
                }

                $.ajax({
                    url: 'get_products.php',
                    type: 'GET',
                    data: {
                        action: 'get_sales_report',
                        year: year,
                        user_id: user_id,
                        work_month_id: work_month_id
                    },
                    dataType: 'json',
                    success: function (response) {
                        console.log('Products response (raw):', response);
                        try {
                            if (response.success && typeof response.html === 'string' && response.html.trim().length > 0) {
                                console.log('Rendering HTML:', response.html);
                                $('#products-table').html(response.html);
                                // به‌روزرسانی جمع کل‌ها
                                $('#total-sales').text(response.total_sales ? new Intl.NumberFormat('fa-IR').format(response.total_sales) + ' ' : '0 ');
                                $('#total-discount').text(response.total_discount ? new Intl.NumberFormat('fa-IR').format(response.total_discount) + ' ' : '0 ');
                                $('#total-sessions').text(response.total_sessions ? response.total_sessions : 0);
                                $('#view-report-btn').prop('disabled', false);
                            } else {
                                throw new Error('HTML نامعتبر یا خالی است: ' + (response.message || 'داده‌ای برای نمایش وجود ندارد'));
                            }
                        } catch (e) {
                            console.error('Error rendering products:', e);
                            $('#products-table').html('<div class="alert alert-danger text-center">خطا در نمایش محصولات: ' + e.message + '</div>');
                            $('#view-report-btn').prop('disabled', true);
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('AJAX Error:', { status: status, error: error, response: xhr.responseText });
                        $('#products-table').html('<div class="alert alert-danger text-center">خطایی در بارگذاری محصولات رخ داد: ' + error + '</div>');
                        $('#view-report-btn').prop('disabled', true);
                    }
                });
            }

            // تابع برای بارگذاری همه فیلترها
            function loadFilters() {
                const year = $('#year').val();
                loadMonths(year);
                loadProducts();
            }

            // بارگذاری اولیه
            const initial_year = $('#year').val();
            if (initial_year) {
                loadMonths(initial_year);
            }
            loadProducts();

            // رویدادهای تغییر
            $('#year').on('change', function () {
                loadFilters();
            });

            $('#user_id').on('change', function () {
                const year = $('#year').val();
                if (year) {
                    loadMonths(year);
                }
                loadProducts();
            });

            $('#work_month_id').on('change', function () {
                loadProducts();
                $('#view-report-btn').prop('disabled', $('#work_month_id').val() === '');
            });

            // رویداد کلیک دکمه مشاهده
            $('#view-report-btn').on('click', function () {
                const work_month_id = $('#work_month_id').val();
                const user_id = $('#user_id').val() || '<?= $current_user_id ?>';
                if (work_month_id) {
                    window.location.href = 'print-report-sell.php?work_month_id=' + work_month_id + '&user_id=' + user_id;
                } else {
                    alert('ماه کاری به درستی انتخاب نشده است.');
                }
            });
        });
    </script>

    <?php require_once 'footer.php'; ?>