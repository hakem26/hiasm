<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'seller') {
    header("Location: index.php");
    exit;
}

require_once 'db.php';
require_once 'header.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date)
{
    if (!$gregorian_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $gregorian_date)) {
        return "تاریخ نامعتبر";
    }
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

function jalali_month_name($jalali_date)
{
    if (!$jalali_date || !preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $jalali_date)) {
        return "نامشخص";
    }
    list($jy, $jm, $jd) = explode('/', $jalali_date);
    $month_names = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
        4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
        10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    ];
    return $month_names[(int)$jm] ?? 'نامشخص';
}

$today = date('Y-m-d');
$today_jalali = gregorian_to_jalali_format($today);

$day_of_week = jdate('l', strtotime($today));
$day_names = [
    'شنبه' => 'شنبه', 
    'یکشنبه' => 'یک‌شنبه', 
    'دوشنبه' => 'دوشنبه',
    'سه‌شنبه' => 'سه‌شنبه',
    'چهارشنبه' => 'چهارشنبه', 
    'پنج‌شنبه' => 'پنج‌شنبه', 
    'جمعه' => 'جمعه'
];
$persian_day = $day_names[$day_of_week] ?? 'نامشخص';

$current_user_id = $_SESSION['user_id'];
$stmt_user = $pdo->prepare("SELECT full_name FROM Users WHERE user_id = ?");
$stmt_user->execute([$current_user_id]);
$user = $stmt_user->fetch(PDO::FETCH_ASSOC);
$user_name = $user['full_name'] ?? 'کاربر ناشناس';

$stmt_current_month = $pdo->query("
    SELECT work_month_id, start_date, end_date
    FROM Work_Months
    WHERE start_date <= CURDATE() AND end_date >= CURDATE()
    LIMIT 1
");
$current_month = $stmt_current_month->fetch(PDO::FETCH_ASSOC);

if ($current_month === false) {
    echo "<!-- دیباگ: هیچ ماه کاری‌ای برای تاریخ فعلی ($today) پیدا نشد. -->";
    $current_month = null;
    $current_work_month_id = null;
    $current_start_month = $today;
    $current_end_month = $today;
} else {
    echo "<!-- دیباگ: ماه کاری پیدا شد - work_month_id: {$current_month['work_month_id']}, start_date: {$current_month['start_date']}, end_date: {$current_month['end_date']} -->";
    $current_work_month_id = $current_month['work_month_id'];
    $current_start_month = $current_month['start_date'];
    $current_end_month = $current_month['end_date'];
}

$stmt_previous_months = $pdo->query("
    SELECT DISTINCT work_month_id, start_date, end_date
    FROM Work_Months
    WHERE end_date < CURDATE()
    ORDER BY end_date DESC
    LIMIT 3
");
$previous_months = $stmt_previous_months->fetchAll(PDO::FETCH_ASSOC);

$work_months = $current_month ? array_merge([$current_month], $previous_months) : $previous_months;

$selected_year = '';
if ($current_start_month && preg_match('/^\d{4}-\d{2}-\d{2}$/', $current_start_month)) {
    list($gy, $gm, $gd) = explode('-', $current_start_month);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    $selected_year = $jy;
}

$month_sales_data = [];
foreach ($work_months as $month) {
    if (!isset($month['start_date']) || empty($month['start_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $month['start_date'])) {
        continue;
    }

    $month_name = jalali_month_name(gregorian_to_jalali_format($month['start_date']));
    $conditions = [];
    $params = [];
    $base_query = "SELECT SUM(o.total_amount) AS month_sales FROM Orders o JOIN Work_Details wd ON o.work_details_id = wd.id JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id WHERE 1=1";

    $conditions[] = "wd.work_month_id = ?";
    $params[] = $month['work_month_id'];

    $conditions[] = "EXISTS (SELECT 1 FROM Partners p WHERE p.partner_id = wd.partner_id AND (p.user_id1 = ? OR p.user_id2 = ?))";
    $params[] = $current_user_id;
    $params[] = $current_user_id;

    if ($month['work_month_id'] == $current_work_month_id) {
        $conditions[] = "wd.work_date <= ?";
        $params[] = $today;
    }

    $final_query = $base_query . " AND " . implode(" AND ", $conditions);
    $stmt_month_sales = $pdo->prepare($final_query);
    $stmt_month_sales->execute($params);
    $sales = $stmt_month_sales->fetchColumn() ?? 0;

    if ($sales > 0) {
        $month_sales_data[$month_name] = $sales;
    }
}

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

$week_days = [];
$week_sales_data = [];
if ($current_start_month && $current_end_month && preg_match('/^\d{4}-\d{2}-\d{2}$/', $current_start_month) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $current_end_month)) {
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
}

$partner_sales = [];
if ($current_work_month_id) {
    $stmt_partner_sales = $pdo->prepare("
        SELECT p.partner_id, 
               CASE 
                   WHEN p.user_id1 = ? THEN COALESCE(u2.full_name, 'کاربر ناشناس')
                   WHEN p.user_id2 = ? THEN COALESCE(u1.full_name, 'کاربر ناشناس')
               END AS partner_name,
               SUM(o.total_amount) AS total_sales,
               CASE 
                   WHEN p.user_id1 = ? THEN 'member'
                   WHEN p.user_id2 = ? THEN 'leader'
               END AS role,
               p.user_id1,
               p.user_id2
        FROM Partners p
        LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
        LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
        JOIN Work_Details wd ON p.partner_id = wd.partner_id
        LEFT JOIN Orders o ON wd.id = o.work_details_id
        WHERE wd.work_month_id = ? 
        AND (p.user_id1 = ? OR p.user_id2 = ?)
        GROUP BY p.partner_id, partner_name, role
    ");
    $stmt_partner_sales->execute([
        $current_user_id, $current_user_id,
        $current_user_id, $current_user_id,
        $current_work_month_id,
        $current_user_id, $current_user_id
    ]);
    $partners_data = $stmt_partner_sales->fetchAll(PDO::FETCH_ASSOC);

    echo "<!-- دیباگ: تعداد همکاران پیدا شده: " . count($partners_data) . " -->";
    if (count($partners_data) > 0) {
        foreach ($partners_data as $partner) {
            echo "<!-- دیباگ: همکار - partner_id: {$partner['partner_id']}, partner_name: {$partner['partner_name']}, total_sales: " . ($partner['total_sales'] ?? '0') . ", role: {$partner['role']} -->";
        }
    }

    usort($partners_data, function($a, $b) {
        return ($b['total_sales'] ?? 0) <=> ($a['total_sales'] ?? 0);
    });

    $partner_labels = [];
    $partner_data = [];
    $partner_colors = [];
    foreach ($partners_data as $partner) {
        $partner_labels[] = $partner['partner_name'] ?? 'همکار ناشناس';
        $partner_data[] = $partner['total_sales'] ?? 0;
        $partner_colors[] = ($partner['role'] === 'leader') ? 'rgba(54, 162, 235, 1)' : 'rgba(153, 102, 255, 1)';
    }
} else {
    echo "<!-- دیباگ: current_work_month_id وجود ندارد -->";
    $partners_data = [];
    $partner_labels = [];
    $partner_data = [];
    $partner_colors = [];
}

$agency_data = [];
if ($current_work_month_id) {
    $stmt_agency = $pdo->prepare("
        SELECT 
            wd.agency_owner_id AS agency_id,
            u.full_name AS agency_name
        FROM Work_Details wd
        JOIN Partners p ON wd.partner_id = p.partner_id
        JOIN Users u ON wd.agency_owner_id = u.user_id
        WHERE wd.work_month_id = ?
        AND (p.user_id1 = ? OR p.user_id2 = ?)
        AND wd.agency_owner_id IS NOT NULL
    ");
    $stmt_agency->execute([$current_work_month_id, $current_user_id, $current_user_id]);
    $agency_records = $stmt_agency->fetchAll(PDO::FETCH_ASSOC);

    echo "<!-- دیباگ: تعداد رکوردهای آژانس پیدا شده: " . count($agency_records) . " -->";

    $agency_counts = [];
    foreach ($agency_records as $record) {
        $agency_id = $record['agency_id'];
        $agency_name = $record['agency_name'] ?? 'کاربر ناشناس';
        if (!isset($agency_counts[$agency_id])) {
            $agency_counts[$agency_id] = [
                'name' => $agency_name,
                'count' => 0
            ];
        }
        $agency_counts[$agency_id]['count']++;
    }

    $agency_labels = [];
    $agency_data_counts = [];
    $agency_colors = [];
    $color_index = 0;
    $colors = [
        'rgba(255, 99, 132, 1)', 'rgba(54, 162, 235, 1)',
        'rgba(255, 206, 86, 1)', 'rgba(75, 192, 192, 1)',
        'rgba(153, 102, 255, 1)', 'rgba(255, 159, 64, 1)',
        'rgba(199, 199, 199, 1)'
    ];

    foreach ($agency_counts as $agency_id => $data) {
        $agency_labels[] = $data['name'];
        $agency_data_counts[] = $data['count'];
        $agency_colors[] = $colors[$color_index % count($colors)];
        $color_index++;
    }

    echo "<!-- دیباگ: داده‌های آژانس - labels: " . json_encode($agency_labels) . ", counts: " . json_encode($agency_data_counts) . " -->";
} else {
    echo "<!-- دیباگ: current_work_month_id برای آژانس وجود ندارد -->";
    $agency_labels = [];
    $agency_data_counts = [];
    $agency_colors = [];
}

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

$debtors = [];
if ($current_work_month_id) {
    $stmt = $pdo->prepare("
        SELECT o.customer_name, o.total_amount, COALESCE(SUM(op.amount), 0) AS paid_amount
        FROM Orders o
        LEFT JOIN Order_Payments op ON o.order_id = op.order_id
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        WHERE wd.work_month_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
        GROUP BY o.order_id, o.customer_name, o.total_amount
        HAVING paid_amount < total_amount
    ");
    $stmt->execute([$current_work_month_id, $current_user_id, $current_user_id]);
    $debts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($debts as $debt) {
        $remaining = $debt['total_amount'] - $debt['paid_amount'];
        if ($remaining > 0) {
            $debtors[] = [
                'name' => $debt['customer_name'],
                'amount' => $remaining
            ];
        }
    }
}

$top_products = [];
if ($current_work_month_id) {
    $stmt = $pdo->prepare("
        SELECT oi.product_name, 
               SUM(oi.quantity) AS total_quantity, 
               SUM(oi.quantity * oi.unit_price) AS total_amount
        FROM Order_Items oi
        JOIN Orders o ON oi.order_id = o.order_id
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners pr ON wd.partner_id = pr.partner_id
        WHERE wd.work_month_id = ? AND (pr.user_id1 = ? OR pr.user_id2 = ?)
        GROUP BY oi.product_name
        ORDER BY total_quantity DESC
    ");
    $stmt->execute([$current_work_month_id, $current_user_id, $current_user_id]);
    $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="container-fluid">
    <h2 class="text-center mb-4">پیشخوان فروشنده - <?= htmlspecialchars($user_name) ?></h2>

    <div class="row">
        <div class="col-12 col-md-6 mb-4">
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
                            <a href="https://hakemo26.persiasptool.com/orders.php?year=<?= $selected_year ?>&work_month_id=<?= $current_work_month_id ?>&user_id=<?= $current_user_id ?>&work_day_id=<?= $work_details_id ?>" class="btn btn-secondary">لیست سفارشات</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">آمار بدهکاران</h5>
                    <ul class="list-group">
                        <?php if (empty($debtors)): ?>
                            <li class="list-group-item">بدهی‌ای یافت نشد.</li>
                        <?php else: ?>
                            <?php foreach ($debtors as $debtor): ?>
                                <li class="list-group-item">
                                    <?= htmlspecialchars($debtor['name']) ?> - بدهی: <?= number_format($debtor['amount'], 0) ?> تومان
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12 col-md-6 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">محصولات پر فروش (ماهانه)</h5>
                    <div class="mb-3" id="topProductsButtons">
                        <button class="btn btn-primary btn-sm me-2" onclick="sortTopProducts('quantity')">تعداد</button>
                        <button class="btn btn-secondary btn-sm" onclick="sortTopProducts('amount')">قیمت</button>
                    </div>
                    <div class="table-responsive" style="overflow-x: auto; width: 100%;">
                        <table id="topProductsTable" class="table table-light table-hover display nowrap" style="width: 100%; min-width: 600px;">
                            <thead>
                                <tr>
                                    <th>محصول</th>
                                    <th>تعداد</th>
                                    <th>مبلغ (تومان)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_products as $product): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($product['product_name']) ?></td>
                                        <td><?= $product['total_quantity'] ?></td>
                                        <td><?= number_format($product['total_amount'], 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 mb-4">
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
        </div>
    </div>

    <div class="row">
        <div class="col-12 col-md-6 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">فروش با همکاران در ماه 
                        <?php 
                        if ($current_start_month && preg_match('/^\d{4}-\d{2}-\d{2}$/', $current_start_month)) {
                            echo jalali_month_name(gregorian_to_jalali_format($current_start_month));
                        } else {
                            echo "نامشخص";
                        }
                        ?>
                    </h5>
                    <div class="btn-group mb-3" role="group">
                        <button style="display: none;" type="button" class="btn btn-primary active" id="allBtn" onclick="showAllPartners()"></button>
                    </div>
                    <?php if (empty($partner_data)): ?>
                        <div class="alert alert-warning text-center">داده‌ای برای نمایش وجود ندارد.</div>
                    <?php else: ?>
                        <canvas id="partnerChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">تعداد آژانس‌ها در ماه 
                        <?php 
                        if ($current_start_month && preg_match('/^\d{4}-\d{2}-\d{2}$/', $current_start_month)) {
                            echo jalali_month_name(gregorian_to_jalali_format($current_start_month));
                        } else {
                            echo "نامشخص";
                        }
                        ?>
                    </h5>
                    <?php if (empty($agency_data_counts)): ?>
                        <div class="alert alert-warning text-center">داده‌ای برای نمایش وجود ندارد.</div>
                    <?php else: ?>
                        <canvas id="agencyChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .btn-primary.active {
        background-color: #004085 !important;
        border-color: #003366 !important;
    }

    #topProductsTable {
        direction: rtl !important;
    }

    #topProductsTable_wrapper {
        width: 100%;
        overflow-x: auto;
    }
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    let salesChart;
    let partnerChart;
    let agencyChart;
    let topProductsTable;
    const ctxSales = document.getElementById('salesChart').getContext('2d');
    const ctxPartner = document.getElementById('partnerChart')?.getContext('2d');
    const ctxAgency = document.getElementById('agencyChart')?.getContext('2d');

    $(document).ready(function () {
        topProductsTable = $('#topProductsTable').DataTable({
            "pageLength": 10,
            "scrollX": true,
            "scrollCollapse": true,
            "paging": true,
            "autoWidth": true,
            "ordering": true,
            "responsive": false,
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
                { "targets": "_all", "className": "text-center" }
            ]
        });
    });

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
        console.log('Daily Chart Loaded', <?= json_encode(array_reverse($days)) ?>, <?= json_encode(array_reverse($sales_data)) ?>);
    }

    function showWeeklyChart() {
        setActiveButton('weeklyBtn', 'weeklyBtn');
        if (salesChart) salesChart.destroy();
        if (<?= json_encode($week_days) ?>.length === 0 || <?= json_encode($week_sales_data) ?>.length === 0) {
            console.error('No data for weekly chart');
            return;
        }
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
        console.log('Weekly Chart Loaded', <?= json_encode(array_reverse($week_days)) ?>, <?= json_encode(array_reverse($week_sales_data)) ?>);
    }

    function showMonthlyChart() {
        setActiveButton('monthlyBtn', 'monthlyBtn');
        if (salesChart) salesChart.destroy();
        if (Object.keys(<?= json_encode($month_sales_data) ?>).length === 0) {
            console.error('No data for monthly chart');
            return;
        }
        salesChart = new Chart(ctxSales, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_keys($month_sales_data)) ?>,
                datasets: [{
                    label: 'فروش (تومان)',
                    data: <?= json_encode(array_values($month_sales_data)) ?>,
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
        console.log('Monthly Chart Loaded', <?= json_encode(array_keys($month_sales_data)) ?>, <?= json_encode(array_values($month_sales_data)) ?>);
    }

    function showAllPartners() {
        setActiveButton('allBtn', 'allBtn');
        if (partnerChart) {
            partnerChart.destroy();
            partnerChart = null;
        }
        if (<?= json_encode($partner_labels) ?>.length === 0 || <?= json_encode($partner_data) ?>.length === 0) {
            console.error('No data for all partners chart');
            return;
        }
        partnerChart = new Chart(ctxPartner, {
            type: 'bar',
            data: {
                labels: <?= json_encode($partner_labels) ?>,
                datasets: [{
                    label: 'فروش (تومان)',
                    data: <?= json_encode($partner_data) ?>,
                    backgroundColor: <?= json_encode($partner_colors) ?>,
                    borderColor: <?= json_encode($partner_colors) ?>,
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                scales: {
                    x: { beginAtZero: true },
                    y: { barPercentage: 0.5 }
                },
                responsive: true,
                legend: { display: false }
            }
        });
        console.log('All Partners Chart Loaded', <?= json_encode($partner_labels) ?>, <?= json_encode($partner_data) ?>, <?= json_encode($partner_colors) ?>);
    }

    function showAgencyChart() {
        if (agencyChart) {
            agencyChart.destroy();
            agencyChart = null;
        }
        if (!ctxAgency || <?= json_encode($agency_labels) ?>.length === 0 || <?= json_encode($agency_data_counts) ?>.length === 0) {
            console.error('No data for agency chart');
            return;
        }
        agencyChart = new Chart(ctxAgency, {
            type: 'bar',
            data: {
                labels: <?= json_encode($agency_labels) ?>,
                datasets: [{
                    label: 'تعداد آژانس‌ها',
                    data: <?= json_encode($agency_data_counts) ?>,
                    backgroundColor: <?= json_encode($agency_colors) ?>,
                    borderColor: <?= json_encode($agency_colors) ?>,
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                scales: {
                    x: { beginAtZero: true, title: { display: true, text: 'تعداد دفعات' } },
                    y: { barPercentage: 0.5, title: { display: true, text: 'نام کاربر' } }
                },
                responsive: true,
                plugins: {
                    legend: { display: false }
                }
            }
        });
        console.log('Agency Chart Loaded', <?= json_encode($agency_labels) ?>, <?= json_encode($agency_data_counts) ?>, <?= json_encode($agency_colors) ?>);
    }

    function sortTopProducts(type) {
        console.log('Sorting top products by:', type);
        topProductsTable.order([type === 'quantity' ? 1 : 2, 'desc']).draw();
        $('#topProductsButtons .btn').removeClass('btn-primary').addClass('btn-secondary');
        $(`#topProductsButtons .btn[onclick="sortTopProducts('${type}')"]`).removeClass('btn-secondary').addClass('btn-primary');
    }

    document.addEventListener('DOMContentLoaded', function() {
        try {
            showDailyChart();
            showAllPartners();
            showAgencyChart();
        } catch (e) {
            console.error('Error loading default charts:', e);
        }
    });
</script>

<?php
require_once 'footer.php';
?>