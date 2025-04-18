<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

// تنظیم هدر برای JSON
header('Content-Type: application/json; charset=UTF-8');

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    $gy = (int)$gy;
    $gm = (int)$gm;
    $gd = (int)$gd;
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd); // تابع از jdf.php
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

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید.']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$year_jalali = isset($_GET['year']) ? (int) $_GET['year'] : null;
$work_month_id = isset($_GET['work_month_id']) ? (int) $_GET['work_month_id'] : null;
$user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
$report_type = $_GET['report_type'] ?? 'monthly';

// محاسبه بازه میلادی برای سال شمسی
$gregorian_start_year = $year_jalali - 579;
$gregorian_end_year = $gregorian_start_year + 1;
$start_date = "$gregorian_start_year-03-21";
$end_date = "$gregorian_end_year-03-21";

if ($year_jalali == 1404) {
    $start_date = "2025-03-21";
    $end_date = "2026-03-21";
} elseif ($year_jalali == 1403) {
    $start_date = "2024-03-20";
    $end_date = "2025-03-21";
}

$reports = [];
$conditions = [];
$params = [];

$conditions[] = "wm.work_month_id = wd.work_month_id";
$conditions[] = "wd.partner_id = p.partner_id";
$conditions[] = "(p.user_id1 = :user_id OR p.user_id2 = :user_id)";
$params[':user_id'] = $current_user_id;

if ($year_jalali) {
    $conditions[] = "wm.start_date >= :start_date AND wm.start_date < :end_date";
    $params[':start_date'] = $start_date;
    $params[':end_date'] = $end_date;
}
if ($work_month_id) {
    $conditions[] = "wm.work_month_id = :work_month_id";
    $params[':work_month_id'] = $work_month_id;
}
if ($user_id) {
    $conditions[] = "(p.user_id1 = :partner_id OR p.user_id2 = :partner_id)";
    $params[':partner_id'] = $user_id;
}

try {
    $sql = "
        SELECT wm.work_month_id, wm.start_date, wm.end_date, p.partner_id, u1.full_name AS user1_name, u2.full_name AS user2_name,
               COUNT(DISTINCT wd.work_date) AS days_worked,
               (SELECT COUNT(DISTINCT work_date) FROM Work_Details WHERE work_month_id = wm.work_month_id) AS total_days,
               COALESCE(SUM(o.total_amount), 0) AS total_sales
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
        $start_date = gregorian_to_jalali_format($row['start_date']);
        list($jy, $jm, $jd) = explode('/', $start_date);
        $month_name = get_jalali_month_name((int)$jm) . ' ' . $jy;

        $partner_name = ($row['user1_name'] ?? 'نامشخص') . ' و ' . ($row['user2_name'] ?? 'نامشخص');
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

    // دیباگ
    if (empty($reports)) {
        error_log("get_reports.php: No reports found for year_jalali $year_jalali, work_month_id " . ($work_month_id ?? 'none') . ", user_id " . ($user_id ?? 'none'));
    } else {
        foreach ($reports as $report) {
            error_log("get_reports.php: Found report: work_month_id = {$report['work_month_id']}, partner_name = {$report['partner_name']}, total_sales = {$report['total_sales']}");
        }
    }

    $html = '<table class="table table-light"><thead><tr><th>ماه کاری</th><th>نام همکار</th><th>مجموع فروش</th><th>وضعیت</th><th>مشاهده</th></tr></thead><tbody>';
    if (empty($reports)) {
        $html .= '<tr><td colspan="5" class="text-center">گزارشی یافت نشد.</td></tr>';
    } else {
        foreach ($reports as $report) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($report['month_name']) . '</td>';
            $html .= '<td>' . htmlspecialchars($report['partner_name']) . '</td>';
            $html .= '<td>' . number_format($report['total_sales'], 0) . '</td>';
            $html .= '<td>' . $report['status'] . '</td>';
            $link = $report_type === 'summary' ? 'print-report-summary.php' : 'print-report-monthly.php';
            $html .= '<td><a href="' . $link . '?work_month_id=' . $report['work_month_id'] . '&partner_id=' . $report['partner_id'] . '" class="btn btn-info btn-sm"><i class="fas fa-eye"></i> مشاهده</a></td>';
            $html .= '</tr>';
        }
    }
    $html .= '</tbody></table>';

    error_log('get_reports.php: Generated HTML: ' . $html);
    echo json_encode(['success' => true, 'html' => $html], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log("get_reports.php: Database error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'خطا در دیتابیس: لطفاً با پشتیبانی تماس بگیرید.'], JSON_UNESCAPED_UNICODE);
}
?>