<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

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

header('Content-Type: application/json');
$current_user_id = $_SESSION['user_id'];

// دریافت پارامترها
$year = $_GET['year'] ?? '';
$work_month_id = $_GET['work_month_id'] ?? '';
$user_id = $_GET['user_id'] ?? '';
$report_type = $_GET['report_type'] ?? '';

$reports = [];
try {
    // ساخت شرط‌های WHERE
    $conditions = [];
    $params = [];

    if ($year) {
        $conditions[] = "YEAR(wm.start_date) = ?";
        $params[] = $year;
    }

    if ($work_month_id) {
        $conditions[] = "wm.work_month_id = ?";
        $params[] = $work_month_id;
    }

    if ($user_id) {
        $conditions[] = "(p.user_id1 = ? OR p.user_id2 = ?)";
        $params[] = $user_id;
        $params[] = $user_id;
    }

    $conditions[] = "(p.user_id1 = ? OR p.user_id2 = ?)";
    $params[] = $current_user_id;
    $params[] = $current_user_id;

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // کوئری برای گزارش‌های ماهانه
    if ($report_type === 'monthly') {
        $query = "
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
            $where_clause
            GROUP BY wm.work_month_id, p.partner_id
            ORDER BY wm.start_date DESC
        ";

        $stmt = $pdo->prepare($query);
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
    }

    echo json_encode([
        'success' => true,
        'reports' => $reports
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'خطا در دریافت گزارش‌ها: ' . $e->getMessage()
    ]);
}
exit;