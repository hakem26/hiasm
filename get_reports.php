<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید.']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$year = $_GET['year'] ?? '';
$work_month_id = $_GET['work_month_id'] ?? '';
$user_id = $_GET['user_id'] ?? '';

$reports = [];
$conditions = [];
$params = [];

$conditions[] = "wm.work_month_id = wd.work_month_id";
$conditions[] = "wd.partner_id = p.partner_id";
$conditions[] = "(p.user_id1 = :user_id OR p.user_id2 = :user_id)";
$params[':user_id'] = $current_user_id;

if ($year) {
    $conditions[] = "YEAR(wm.start_date) = :year";
    $params[':year'] = $year;
}
if ($work_month_id) {
    $conditions[] = "wm.work_month_id = :work_month_id";
    $params[':work_month_id'] = $work_month_id;
}
if ($user_id) {
    $conditions[] = "(p.user_id1 = :partner_id OR p.user_id2 = :partner_id)";
    $params[':partner_id'] = $user_id;
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
    $start_date = gregorian_to_jalali_format($row['start_date']);
    $end_date = gregorian_to_jalali_format($row['end_date']);
    $partner_name = $row['user1_name'] . ' و ' . $row['user2_name'];
    $total_sales = $row['total_sales'] ?? 0;
    $status = ($row['days_worked'] == $row['total_days']) ? 'تکمیل' : 'ناقص';

    $reports[] = [
        'work_month_id' => $row['work_month_id'],
        'start_date' => $start_date,
        'end_date' => $end_date,
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
        $html .= '<td>' . htmlspecialchars($report['start_date']) . ' تا ' . htmlspecialchars($report['end_date']) . '</td>';
        $html .= '<td>' . htmlspecialchars($report['partner_name']) . '</td>';
        $html .= '<td>' . number_format($report['total_sales'], 0) . ' تومان</td>';
        $html .= '<td>' . $report['status'] . '</td>';
        $html .= '<td><a href="print-report-monthly.php?work_month_id=' . $report['work_month_id'] . '&partner_id=' . $report['partner_id'] . '" class="btn btn-info btn-sm"><i class="fas fa-eye"></i> مشاهده</a></td>';
        $html .= '</tr>';
    }
}
$html .= '</tbody></table>';

echo json_encode(['success' => true, 'html' => $html]);