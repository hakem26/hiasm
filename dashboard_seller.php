<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'seller') {
    header("Location: index.php");
    exit;
}

require_once 'db.php';
require_once 'header.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

// تابع تبدیل ماه میلادی به نام ماه فارسی
function jalali_month_name($jalali_date)
{
    list($jy, $jm, $jd) = explode('/', $jalali_date);
    $month_names = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
        4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
        10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    ];
    return $month_names[(int)$jm] ?? 'نامشخص';
}

// تاریخ امروز
$today = date('Y-m-d');
$today_jalali = gregorian_to_jalali_format($today);

// روز هفته به فارسی
$day_of_week = jdate('l', strtotime($today));
$day_names = [
    'شنبه' => 'شنبه', 'یک‌شنبه' => 'یک‌شنبه', 'دوشنبه' => 'دوشنبه',
    'سه‌شنبه' => 'سه‌شنبه', 'چهارشنبه' => 'چهارشنبه', 'پنج‌شنبه' => 'پنج‌شنبه',
    'جمعه' => 'جمعه'
];
$persian_day = $day_names[$day_of_week] ?? 'نامشخص';

// کاربر فعلی
$current_user_id = $_SESSION['user_id'];
$stmt_user = $pdo->prepare("SELECT full_name FROM Users WHERE user_id = ?");
$stmt_user->execute([$current_user_id]);
$user = $stmt_user->fetch(PDO::FETCH_ASSOC);
$user_name = $user['full_name'] ?? 'کاربر ناشناس';

// دریافت اطلاعات روز کاری برای امروز
$stmt_work_details = $pdo->prepare("
    SELECT id AS work_details_id
    FROM Work_Details
    WHERE work_date = ? AND partner_id IN (
        SELECT partner_id FROM Partners WHERE user_id1 = ? OR user_id2 = ?
    )
    LIMIT 1
");
$stmt_work_details->execute([$today, $current_user_id, $current_user_id]);
$work_details = $stmt_work_details->fetch(PDO::FETCH_ASSOC);
$work_details_id = $work_details['work_details_id'] ?? null;

// نفرات امروز (همکار آن کاربر)
$stmt_partners = $pdo->prepare("
    SELECT p.partner_id, u2.full_name AS partner_name
    FROM Partners p
    LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
    WHERE (p.user_id1 = ? OR p.user_id2 = ?) 
    AND p.partner_id IN (
        SELECT partner_id 
        FROM Work_Details 
        WHERE work_date = ?
    )
");
$stmt_partners->execute([$current_user_id, $current_user_id, $today]);
$partners_today = $stmt_partners->fetchAll(PDO::FETCH_ASSOC);

// دریافت ماه کاری فعلی و 6 ماه قبلی
$stmt_months = $pdo->query("
    SELECT work_month_id, start_date, end_date
    FROM Work_Months
    WHERE end_date <= CURDATE()
    ORDER BY end_date DESC
    LIMIT 7
");
$work_months = $stmt_months->fetchAll(PDO::FETCH_ASSOC);
$current_work_month_id = $work_months[0]['work_month_id'] ?? null;
$current_start_month = $work_months[0]['start_date'] ?? $today;
$current_end_month = $work_months[0]['end_date'] ?? $today;
$previous_work_month_id = isset($work_months[1]) ? $work_months[1]['work_month_id'] : null;
$previous_start_month = isset($work_months[1]) ? $work_months[1]['start_date'] : date('Y-m-d', strtotime('-1 month', strtotime($current_start_month)));
$previous_end_month = isset($work_months[1]) ? $work_months[1]['end_date'] : date('Y-m-d', strtotime('-1 day', strtotime($current_start_month)));

// فروش روزانه (7 روز اخیر)
$days = [];
$sales_data = [];
for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $jalali_date = gregorian_to_jalali_format($date);
    $days[] = $jalali_date;
    $stmt_day_sales = $pdo->prepare("
        SELECT SUM(o.final_amount) AS day_sales
        FROM Orders o
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        WHERE wd.work_date = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
    ");
    $stmt_day_sales->execute([$date, $current_user_id, $current_user_id]);
    $sales_data[] = $stmt_day_sales->fetchColumn() ?? 0;
}

// فروش ماهانه (7 ماه کاری)
$month_sales_data = [];
foreach ($work_months as $month) {
    $month_name = jalali_month_name(gregorian_to_jalali_format($month['start_date']));
    $stmt_month_sales = $pdo->prepare("
        SELECT SUM(o.final_amount) AS month_sales
        FROM Orders o
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        WHERE wd.work_month_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
    ");
    $stmt_month_sales->execute([$month['work_month_id'], $current_user_id, $current_user_id]);
    $month_sales_data[$month_name] = $stmt_month_sales->fetchColumn() ?? 0;
}

// رشد امروز (مقایسه با هفته قبل)
$last_week_day = date('Y-m-d', strtotime('-7 days'));
$stmt_last_week_sales = $pdo->prepare("
    SELECT SUM(o.final_amount) AS last_week_sales
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE wd.work_date = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
");
$stmt_last_week_sales->execute([$last_week_day, $current_user_id, $current_user_id]);
$last_week_sales = $stmt_last_week_sales->fetchColumn() ?? 0;
$today_sales = $sales_data[0];
$growth_today = $today_sales - $last_week_sales;
$growth_today_color = $growth_today < 0 ? 'red' : ($growth_today > 0 ? 'green' : 'navy');
$growth_today_sign = $growth_today < 0 ? '-' : ($growth_today > 0 ? '+' : '');

// رشد این ماه (مقایسه با ماه قبل)
$stmt_previous_month_sales = $pdo->prepare("
    SELECT SUM(o.final_amount) AS previous_month_sales
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE wd.work_month_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
");
$stmt_previous_month_sales->execute([$previous_work_month_id, $current_user_id, $current_user_id]);
$previous_month_sales = $stmt_previous_month_sales->fetchColumn() ?? 0;
$current_month_sales = $month_sales_data[jalali_month_name(gregorian_to_jalali_format($current_start_month))] ?? 0;
$growth_month = $current_month_sales - $previous_month_sales;
$growth_month_color = $growth_month < 0 ? 'red' : ($growth_month > 0 ? 'green' : 'navy');
$growth_month_sign = $growth_month < 0 ? '-' : ($growth_month > 0 ? '+' : '');
?>

<h2 class="text-center mb-4">داشبورد فروشنده - <?= htmlspecialchars($user_name) ?></h2>

<!-- نفرات امروز -->
<div class="card">
    <div class="card-body">
        <h5 class="card-title">امروز <?= $persian_day ?> (<?= $today_jalali ?>)</h5>
        <ul class="list-group">
            <?php foreach ($partners_today as $partner): ?>
                <li class="list-group-item"><?= htmlspecialchars($partner['partner_name'] ?? 'همکار ناشناس') ?></li>
            <?php endforeach; ?>
            <?php if (empty($partners_today)): ?>
                <li class="list-group-item">هیچ همکاری امروز فعال نیست.</li>
            <?php endif; ?>
        </ul>
        <?php if (!empty($partners_today) && $work_details_id): ?>
            <div class="mt-3">
                <a href="https://hakemo26.persiasptool.com/add_order.php?work_details_id=<?= $work_details_id ?>" class="btn btn-primary me-2">ثبت سفارش</a>
                <a href="https://hakemo26.persiasptool.com/orders.php?year=2025&work_month_id=10&user_id=<?= $current_user_id ?>&work_day_id=<?= $work_details_id ?>" class="btn btn-secondary">لیست سفارشات</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- آمار فروش کلی -->
<div class="card">
    <div class="card-body">
        <h5 class="card-title">آمار فروش کلی</h5>
        <div class="btn-group mb-3" role="group">
            <button type="button" class="btn btn-primary" onclick="showDailyChart()">روزانه</button>
            <button type="button" class="btn btn-primary" onclick="showMonthlyChart()">ماهانه</button>
        </div>
        <canvas id="salesChart"></canvas>
        <div class="mt-3">
            <p>رشد امروز: <span style="color: <?= $growth_today_color ?>"><?= $growth_today_sign ?><?= number_format(abs($growth_today), 0) ?> تومان</span></p>
            <p>رشد این ماه: <span style="color: <?= $growth_month_color ?>"><?= $growth_month_sign ?><?= number_format(abs($growth_month), 0) ?> تومان</span></p>
        </div>
        <script>
            let salesChart;
            const ctx = document.getElementById('salesChart').getContext('2d');

            function showDailyChart() {
                if (salesChart) salesChart.destroy();
                salesChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode(array_reverse($days)) ?>,
                        datasets: [{
                            label: 'فروش (تومان)',
                            data: <?= json_encode(array_reverse($sales_data)) ?>,
                            backgroundColor: [
                                'rgba(255, 99, 132, 0.5)', 'rgba(54, 162, 235, 0.5)',
                                'rgba(255, 206, 86, 0.5)', 'rgba(75, 192, 192, 0.5)',
                                'rgba(153, 102, 255, 0.5)', 'rgba(255, 159, 64, 0.5)',
                                'rgba(199, 199, 199, 0.5)'
                            ],
                            borderColor: [
                                'rgba(255, 99, 132, 1)', 'rgba(54, 162, 235, 1)',
                                'rgba(255, 206, 86, 1)', 'rgba(75, 192, 192, 1)',
                                'rgba(153, 102, 255, 1)', 'rgba(255, 159, 64, 1)',
                                'rgba(199, 199, 199, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        scales: { y: { beginAtZero: true } },
                        responsive: true
                    }
                });
            }

            function showMonthlyChart() {
                if (salesChart) salesChart.destroy();
                salesChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode(array_keys($month_sales_data)) ?>,
                        datasets: [{
                            label: 'فروش (تومان)',
                            data: <?= json_encode(array_values($month_sales_data)) ?>,
                            backgroundColor: [
                                'rgba(255, 99, 132, 0.5)', 'rgba(54, 162, 235, 0.5)',
                                'rgba(255, 206, 86, 0.5)', 'rgba(75, 192, 192, 0.5)',
                                'rgba(153, 102, 255, 0.5)', 'rgba(255, 159, 64, 0.5)',
                                'rgba(199, 199, 199, 0.5)'
                            ],
                            borderColor: [
                                'rgba(255, 99, 132, 1)', 'rgba(54, 162, 235, 1)',
                                'rgba(255, 206, 86, 1)', 'rgba(75, 192, 192, 1)',
                                'rgba(153, 102, 255, 1)', 'rgba(255, 159, 64, 1)',
                                'rgba(199, 199, 199, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        scales: { y: { beginAtZero: true } },
                        responsive: true
                    }
                });
            }

            // نمایش چارت روزانه به صورت پیش‌فرض
            showDailyChart();
        </script>
    </div>
</div>

<?php
require_once 'footer.php';
?>