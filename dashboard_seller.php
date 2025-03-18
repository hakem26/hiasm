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
    'شنبه' => 'شنبه', 
    'یکشنبه' => 'یک‌شنبه', 
    'دوشنبه' => 'دوشنبه',
    'سه شنبه' => 'سه‌شنبه',
    'چهارشنبه' => 'چهارشنبه', 
    'پنجشنبه' => 'پنج‌شنبه', 
    'جمعه' => 'جمعه'
];
$persian_day = $day_names[$day_of_week] ?? 'نامشخص';

// کاربر فعلی
$current_user_id = $_SESSION['user_id'];
$stmt_user = $pdo->prepare("SELECT full_name FROM Users WHERE user_id = ?");
$stmt_user->execute([$current_user_id]);
$user = $stmt_user->fetch(PDO::FETCH_ASSOC);
$user_name = $user['full_name'] ?? 'کاربر ناشناس';

// دریافت ماه کاری فعلی
$stmt_current_month = $pdo->query("
    SELECT work_month_id, start_date, end_date
    FROM Work_Months
    WHERE start_date <= CURDATE() AND end_date >= CURDATE()
    LIMIT 1
");
$current_month = $stmt_current_month->fetch(PDO::FETCH_ASSOC);
$current_work_month_id = $current_month['work_month_id'] ?? 10;
$current_start_month = $current_month['start_date'] ?? $today;
$current_end_month = $current_month['end_date'] ?? $today;

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
    SELECT p.partner_id, COALESCE(u2.full_name, u1.full_name) AS partner_name
    FROM Partners p
    LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
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

// فروش روزانه (7 روز اخیر)
$days = [];
$sales_data = [];
for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $jalali_date = gregorian_to_jalali_format($date);
    $days[] = $jalali_date;
    $stmt_day_sales = $pdo->prepare("
        SELECT SUM(o.total_amount) AS day_sales
        FROM Orders o
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        WHERE wd.work_date = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
    ");
    $stmt_day_sales->execute([$date, $current_user_id, $current_user_id]);
    $sales_data[] = $stmt_day_sales->fetchColumn() ?? 0;
}

// فروش هفتگی (همه روزهایی که با امروز هم‌نام هستند در ماه کاری فعلی)
$week_days = [];
$week_sales_data = [];
$start_date = new DateTime($current_start_month);
$end_date = new DateTime($current_end_month);
$interval = new DateInterval('P1D');
$date_range = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));

foreach ($date_range as $date) {
    $work_date = $date->format('Y-m-d');
    $day_name = jdate('l', strtotime($work_date));
    if ($day_name === $day_of_week) {
        $jalali_date = gregorian_to_jalali_format($work_date);
        $week_days[] = $jalali_date;
        $stmt_week_sales = $pdo->prepare("
            SELECT SUM(o.total_amount) AS week_sales
            FROM Orders o
            JOIN Work_Details wd ON o.work_details_id = wd.id
            JOIN Partners p ON wd.partner_id = p.partner_id
            WHERE wd.work_date = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
        ");
        $stmt_week_sales->execute([$work_date, $current_user_id, $current_user_id]);
        $week_sales_data[] = $stmt_week_sales->fetchColumn() ?? 0;
    }
}

// فروش ماهانه با همکاران (فقط ماه جاری)
$partner_sales = [];
$stmt_partner_sales = $pdo->prepare("
    SELECT p.partner_id, COALESCE(u2.full_name, u1.full_name) AS partner_name,
           SUM(o.total_amount) AS total_sales,
           CASE WHEN p.user_id1 = ? THEN 'leader' ELSE 'member' END AS role
    FROM Partners p
    LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
    LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
    JOIN Work_Details wd ON p.partner_id = wd.partner_id
    JOIN Orders o ON wd.id = o.work_details_id
    WHERE wd.work_month_id = ? AND wd.work_date <= ?
    GROUP BY p.partner_id, partner_name, role
    HAVING total_sales IS NOT NULL
");
$stmt_partner_sales->execute([$current_user_id, $current_work_month_id, $today]);
$partners_data = $stmt_partner_sales->fetchAll(PDO::FETCH_ASSOC);

// مرتب‌سازی بر اساس فروش نزولی
usort($partners_data, function($a, $b) {
    return $b['total_sales'] <=> $a['total_sales'];
});

// محاسبه حداکثر فروش برای مقیاس 100%
$max_sales = max(array_column($partners_data, 'total_sales')) ?: 1; // جلوگیری از تقسیم بر صفر

// آماده‌سازی داده‌ها برای نمودار
$partner_labels = [];
$partner_data = [];
$partner_colors = [];
foreach ($partners_data as $partner) {
    $partner_labels[] = $partner['partner_name'] ?? 'همکار ناشناس';
    $partner_data[] = ($partner['total_sales'] / $max_sales) * 100; // درصد نسبت به حداکثر
    $partner_colors[] = ($partner['role'] === 'leader') ? 'rgba(54, 162, 235, 1)' : 'rgba(153, 102, 255, 1)';
}

// رشد امروز (مقایسه با هفته قبل)
$last_week_day = date('Y-m-d', strtotime('-7 days'));
$stmt_last_week_sales = $pdo->prepare("
    SELECT SUM(o.total_amount) AS last_week_sales
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
$previous_months = $pdo->query("
    SELECT DISTINCT work_month_id, start_date, end_date
    FROM Work_Months
    WHERE end_date < CURDATE()
    ORDER BY end_date DESC
    LIMIT 1
")->fetchAll(PDO::FETCH_ASSOC);
$previous_work_month_id = isset($previous_months[0]['work_month_id']) ? $previous_months[0]['work_month_id'] : null;
$stmt_previous_month_sales = $pdo->prepare("
    SELECT SUM(o.total_amount) AS previous_month_sales
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
    WHERE wd.work_month_id = ? AND EXISTS (SELECT 1 FROM Partners p WHERE p.partner_id = wd.partner_id AND (p.user_id1 = ? OR p.user_id2 = ?))
");
$stmt_previous_month_sales->execute([$previous_work_month_id, $current_user_id, $current_user_id]);
$previous_month_sales = $stmt_previous_month_sales->fetchColumn() ?? 0;
$current_month_sales = array_sum(array_column($partners_data, 'total_sales'));
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
            <button type="button" class="btn btn-primary active" id="dailyBtn" onclick="showDailyChart()">روزانه</button>
            <button type="button" class="btn btn-primary" id="weeklyBtn" onclick="showWeeklyChart()">هفتگی</button>
            <button type="button" class="btn btn-primary" id="monthlyBtn" onclick="showMonthlyChart()">ماهانه</button>
        </div>
        <canvas id="salesChart"></canvas>
        <div class="mt-3">
            <p>رشد امروز نسبت به <?= $persian_day ?> قبلی: <span style="color: <?= $growth_today_color ?>"><?= $growth_today_sign ?><?= number_format(abs($growth_today), 0) ?> تومان</span></p>
            <p>رشد این ماه نسبت به ماه کاری قبلی: <span style="color: <?= $growth_month_color ?>"><?= $growth_month_sign ?><?= number_format(abs($growth_month), 0) ?> تومان</span></p>
        </div>
    </div>
</div>

<!-- فروش با همکاران -->
<div class="card mt-4">
    <div class="card-body">
        <h5 class="card-title">فروش با همکاران در ماه <?= jalali_month_name(gregorian_to_jalali_format($current_start_month)) ?></h5>
        <div class="btn-group mb-3" role="group">
            <button type="button" class="btn btn-primary active" id="allBtn" onclick="showAllPartners()">همه</button>
            <button type="button" class="btn btn-primary" id="leaderBtn" onclick="showLeaders()">سرگروه</button>
            <button type="button" class="btn btn-primary" id="memberBtn" onclick="showMembers()">زیرگروه</button>
        </div>
        <canvas id="partnerChart"></canvas>
    </div>
</div>

<style>
    .btn-primary.active {
        background-color: #004085 !important;
        border-color: #003366 !important;
    }
</style>

<script>
    let salesChart;
    let partnerChart;
    const ctxSales = document.getElementById('salesChart').getContext('2d');
    const ctxPartner = document.getElementById('partnerChart').getContext('2d');

    function setActiveButton(groupId, buttonId) {
        document.querySelectorAll(`#${groupId} button`).forEach(btn => {
            btn.classList.remove('active');
        });
        document.getElementById(buttonId).classList.add('active');
    }

    function showDailyChart() {
        setActiveButton('dailyBtn', 'dailyBtn');
        if (salesChart) salesChart.destroy();
        salesChart = new Chart(ctxSales, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_reverse($days)) ?>,
                datasets: [{
                    label: 'فروش (تومان)',
                    data: <?= json_encode(array_reverse($sales_data)) ?>,
                    backgroundColor: [
                        'rgba(255, 99, 132, 1)', 'rgba(54, 162, 235, 1)',
                        'rgba(255, 206, 86, 1)', 'rgba(75, 192, 192, 1)',
                        'rgba(153, 102, 255, 1)', 'rgba(255, 159, 64, 1)',
                        'rgba(199, 199, 199, 1)'
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

    function showWeeklyChart() {
        setActiveButton('weeklyBtn', 'weeklyBtn');
        if (salesChart) salesChart.destroy();
        salesChart = new Chart(ctxSales, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_reverse($week_days)) ?>,
                datasets: [{
                    label: 'فروش (تومان)',
                    data: <?= json_encode(array_reverse($week_sales_data)) ?>,
                    backgroundColor: [
                        'rgba(255, 99, 132, 1)', 'rgba(54, 162, 235, 1)',
                        'rgba(255, 206, 86, 1)', 'rgba(75, 192, 192, 1)',
                        'rgba(153, 102, 255, 1)', 'rgba(255, 159, 64, 1)',
                        'rgba(199, 199, 199, 1)'
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
        setActiveButton('monthlyBtn', 'monthlyBtn');
        if (salesChart) salesChart.destroy();
        salesChart = new Chart(ctxSales, {
            type: 'bar',
            data: {
                labels: [],
                datasets: [{
                    label: 'فروش (تومان)',
                    data: [],
                    backgroundColor: [
                        'rgba(255, 99, 132, 1)', 'rgba(54, 162, 235, 1)',
                        'rgba(255, 206, 86, 1)', 'rgba(75, 192, 192, 1)',
                        'rgba(153, 102, 255, 1)', 'rgba(255, 159, 64, 1)',
                        'rgba(199, 199, 199, 1)'
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

    function showAllPartners() {
        setActiveButton('allBtn', 'allBtn');
        if (partnerChart) partnerChart.destroy();
        partnerChart = new Chart(ctxPartner, {
            type: 'horizontalBar',
            data: {
                labels: <?= json_encode($partner_labels) ?>,
                datasets: [{
                    label: 'فروش (درصد)',
                    data: <?= json_encode($partner_data) ?>,
                    backgroundColor: <?= json_encode($partner_colors) ?>,
                    borderColor: <?= json_encode($partner_colors) ?>,
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    x: { beginAtZero: true, max: 100 },
                    y: { barPercentage: 0.5 }
                },
                responsive: true,
                legend: { display: false }
            }
        });
    }

    function showLeaders() {
        setActiveButton('leaderBtn', 'leaderBtn');
        const leaders = <?= json_encode(array_filter($partners_data, fn($p) => $p['role'] === 'leader')) ?>;
        const leaderLabels = leaders.map(p => p.partner_name ?? 'همکار ناشناس');
        const leaderData = leaders.map(p => (p.total_sales / <?= $max_sales ?>) * 100);
        const leaderColors = leaders.map(p => 'rgba(54, 162, 235, 1)');
        if (partnerChart) partnerChart.destroy();
        partnerChart = new Chart(ctxPartner, {
            type: 'horizontalBar',
            data: {
                labels: leaderLabels,
                datasets: [{
                    label: 'فروش (درصد)',
                    data: leaderData,
                    backgroundColor: leaderColors,
                    borderColor: leaderColors,
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    x: { beginAtZero: true, max: 100 },
                    y: { barPercentage: 0.5 }
                },
                responsive: true,
                legend: { display: false }
            }
        });
    }

    function showMembers() {
        setActiveButton('memberBtn', 'memberBtn');
        const members = <?= json_encode(array_filter($partners_data, fn($p) => $p['role'] === 'member')) ?>;
        const memberLabels = members.map(p => p.partner_name ?? 'همکار ناشناس');
        const memberData = members.map(p => (p.total_sales / <?= $max_sales ?>) * 100);
        const memberColors = members.map(p => 'rgba(153, 102, 255, 1)');
        if (partnerChart) partnerChart.destroy();
        partnerChart = new Chart(ctxPartner, {
            type: 'horizontalBar',
            data: {
                labels: memberLabels,
                datasets: [{
                    label: 'فروش (درصد)',
                    data: memberData,
                    backgroundColor: memberColors,
                    borderColor: memberColors,
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    x: { beginAtZero: true, max: 100 },
                    y: { barPercentage: 0.5 }
                },
                responsive: true,
                legend: { display: false }
            }
        });
    }

    // نمایش چارت‌های پیش‌فرض
    document.addEventListener('DOMContentLoaded', function() {
        showDailyChart();
        showAllPartners();
    });
</script>

<?php
require_once 'footer.php';
?>