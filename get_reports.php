<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%02d/%02d/%04d", $jd, $jm, $jy);
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

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید.']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$filter_year = $_GET['filter_year'] ?? '';
$filter_work_month = $_GET['filter_work_month'] ?? '';
$filter_partner = $_GET['filter_partner'] ?? '';

$gregorian_year = $filter_year ? ($filter_year - 621) : '';

$reports = [];
$conditions = [];
$params = [];

$conditions[] = "wm.work_month_id = wd.work_month_id";
$conditions[] = "wd.partner_id = p.partner_id";
$conditions[] = "(p.user_id1 = :user_id OR p.user_id2 = :user_id)";
$params[':user_id'] = $current_user_id;

if ($filter_year) {
    $conditions[] = "YEAR(wm.start_date) = :year";
    $params[':year'] = $gregorian_year;
}
if ($filter_work_month) {
    $conditions[] = "wm.work_month_id = :work_month_id";
    $params[':work_month_id'] = $filter_work_month;
}
if ($filter_partner) {
    $conditions[] = "p.partner_id = :partner_id";
    $params[':partner_id'] = $filter_partner;
}

$sql = "
    SELECT wm.work_month_id, wm.start_date, wm.end_date, p.partner_id, u1.full_name AS user1_name, u2.full_name AS user2_name,
           COUNT(DISTINCT wd.work_date) AS days_worked,
           (SELECT COUNT(DISTINCT work_date) FROM Work_Details WHERE work_month_id = wm.work_month_id) AS total_days,
           SUM(o.total_amount) AS total_sales
    FROM Work_Months wm
    JOIN Work_Details wd ON wm.work_month_id = wd.work_month_id
    JOIN Partners p ON wd.partner_id = p.partner_id
    LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
    LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
    LEFT JOIN Orders o ON o.work_details_id = wd.id
    WHERE " . implode(" AND ", $conditions) . "
    GROUP BY wm.work_month_id, p.partner_id
    ORDER BY wm.start_date DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $start_date = explode('-', $row['start_date']);
    $jalali_year = $start_date[0] + 621;
    $jalali_month = (int)$start_date[1];
    $month_name = get_jalali_month_name($jalali_month) . ' ' . $jalali_year;

    $partner_name = $row['user1_name'] . ' و ' . $row['user2_name'];
    $total_sales = $row['total_sales'] ?? 0;
    $status = ($row['days_worked'] == $row['total_days']) ? 'تکمیل' : 'ناقص';

    $reports[] = [
        'work_month_id' => $row['work_month_id'],
        'month_name' => $month_name,
        'partner_name' => $partner_name,
        'partner_id' => $row['partner_id'],
        'total_sales' => $total_sales,
        'status' => $status
    ];
}

$html = '<table class="table table-light"><thead><tr><th>ماه کاری</th><th>نام همکار</th><th>مجموع فروش</th><th>وضعیت</th><th>مشاهده</th></tr></thead><tbody>';
if (empty($reports)) {
    $html .= '<tr><td colspan="5" class="text-center">گزارشی یافت نشد.</td></tr>';
} else {
    foreach ($reports as $report) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($report['month_name']) . '</td>';
        $html .= '<td>' . htmlspecialchars($report['partner_name']) . '</td>';
        $html .= '<td>' . number_format($report['total_sales'], 0) . ' تومان</td>';
        $html .= '<td>' . $report['status'] . '</td>';
        $html .= '<td><a href="print-report-monthly.php?work_month_id=' . $report['work_month_id'] . '&partner_id=' . $report['partner_id'] . '" class="btn btn-info btn-sm"><i class="fas fa-eye"></i> مشاهده</a></td>';
        $html .= '</tr>';
    }
}
$html .= '</tbody></table>';

echo json_encode(['success' => true, 'html' => $html]);