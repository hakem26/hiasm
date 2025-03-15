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
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
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

// دریافت سال‌های موجود از دیتابیس (میلادی)
$stmt = $pdo->query("SELECT DISTINCT YEAR(start_date) AS year FROM Work_Months ORDER BY year DESC");
$years_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
$years = array_column($years_db, 'year');

// دریافت سال جاری (میلادی) به‌عنوان پیش‌فرض
$current_year = date('Y');

// دریافت سال انتخاب‌شده (میلادی)
$selected_year = $_GET['year'] ?? (in_array($current_year, $years) ? $current_year : (!empty($years) ? $years[0] : null));

// متغیرهای جمع کل
$total_sales = 0;
$total_discount = 0;
$total_sessions = 0;

// محصولات
$products = [];
$selected_month = $_GET['work_month_id'] ?? '';

if ($selected_year && $selected_month) {
    // جمع کل فروش و تخفیف
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(o.total_amount), 0) AS total_sales,
               COALESCE(SUM(o.discount), 0) AS total_discount
        FROM Orders o
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        WHERE wd.work_month_id = ? AND p.user_id1 = ?
    ");
    $stmt->execute([$selected_month, $current_user_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_sales = $summary['total_sales'] ?? 0;
    $total_discount = $summary['total_discount'] ?? 0;

    // تعداد جلسات (روزهای کاری)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT wd.work_date) AS total_sessions
        FROM Work_Details wd
        WHERE wd.work_month_id = ?
    ");
    $stmt->execute([$selected_month]);
    $sessions = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_sessions = $sessions['total_sessions'] ?? 0;

    // لیست محصولات
    $stmt = $pdo->prepare("
        SELECT oi.product_name, oi.unit_price, SUM(oi.quantity) AS total_quantity, SUM(oi.total_price) AS total_price
        FROM Order_Items oi
        JOIN Orders o ON oi.order_id = o.order_id
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        WHERE wd.work_month_id = ? AND p.user_id1 = ?
        GROUP BY oi.product_name, oi.unit_price
        ORDER BY oi.product_name COLLATE utf8mb4_persian_ci
    ");
    $stmt->execute([$selected_month, $current_user_id]);
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
                        <?php foreach ($years as $year): ?>
                            <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                                <?= gregorian_year_to_jalali($year) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="work_month_id" class="form-label">ماه کاری</label>
                    <select name="work_month_id" id="work_month_id" class="form-select">
                        <option value="">انتخاب ماه</option>
                        <!-- ماه‌ها اینجا با AJAX بارگذاری می‌شن -->
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
                        <th>سود</th>
                        <th>مشاهده</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="7" class="text-center">محصولی یافت نشد.</td>
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
                                <td></td>
                                <td>
                                    <a href="print-report-sell.php?work_month_id=<?= $selected_month ?>" class="btn btn-info btn-sm">
                                        <i class="fas fa-eye"></i> مشاهده
                                    </a>
                                </td>
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
                console.log('Loading months for year:', year);
                if (!year) {
                    $('#work_month_id').html('<option value="">انتخاب ماه</option>');
                    return;
                }
                $.ajax({
                    url: 'get_months.php',
                    type: 'POST',
                    data: { year: year, user_id: <?= json_encode($current_user_id) ?> },
                    success: function(response) {
                        console.log('Months response:', response);
                        $('#work_month_id').html(response);
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading months:', error);
                        $('#work_month_id').html('<option value="">خطا در بارگذاری ماه‌ها</option>');
                    }
                });
            }

            // تابع برای بارگذاری محصولات
            function loadProducts() {
                console.log('Loading products...');
                const year = $('#year').val();
                const work_month_id = $('#work_month_id').val();

                if (!work_month_id) {
                    $('#products-table').html('<table class="table table-light"><thead><tr><th>ردیف</th><th>اقلام</th><th>قیمت واحد</th><th>تعداد</th><th>قیمت کل</th><th>سود</th><th>مشاهده</th></tr></thead><tbody><tr><td colspan="7" class="text-center">لطفاً ماه کاری را انتخاب کنید.</td></tr></tbody></table>');
                    return;
                }

                $.ajax({
                    url: 'get_products.php',
                    type: 'GET',
                    data: {
                        year: year,
                        work_month_id: work_month_id
                    },
                    dataType: 'json',
                    success: function(response) {
                        console.log('Products response (raw):', response);
                        try {
                            if (response.success && typeof response.html === 'string' && response.html.trim().length > 0) {
                                console.log('Rendering HTML:', response.html);
                                $('#products-table').html(response.html);
                                // به‌روزرسانی جمع کل‌ها
                                $('#total-sales').text(response.total_sales ? new Intl.NumberFormat('fa-IR').format(response.total_sales) + ' تومان' : '0 تومان');
                                $('#total-discount').text(response.total_discount ? new Intl.NumberFormat('fa-IR').format(response.total_discount) + ' تومان' : '0 تومان');
                                $('#total-sessions').text(response.total_sessions ? response.total_sessions + ' جلسه' : '0 جلسه');
                            } else {
                                throw new Error('HTML نامعتبر یا خالی است: ' + (response.message || 'داده‌ای برای نمایش وجود ندارد'));
                            }
                        } catch (e) {
                            console.error('Error rendering products:', e);
                            $('#products-table').html('<div class="alert alert-danger text-center">خطا در نمایش محصولات: ' + e.message + '</div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', { status: status, error: error, response: xhr.responseText });
                        $('#products-table').html('<div class="alert alert-danger text-center">خطایی در بارگذاری محصولات رخ داد: ' + error + '</div>');
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
            $('#year').on('change', function() {
                loadFilters();
            });

            $('#work_month_id').on('change', function() {
                loadProducts();
            });
        });
    </script>

    <?php require_once 'footer.php'; ?>