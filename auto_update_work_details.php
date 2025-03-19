<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
    exit;
}

require_once 'db.php';
require_once 'jdf.php';

// فعال کردن لاگ خطاها
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'error.log');

if (!isset($_POST['work_month_id'])) {
    echo json_encode(['success' => false, 'message' => 'شناسه ماه کاری مشخص نشده است']);
    exit;
}

$work_month_id = (int) $_POST['work_month_id'];

try {
    // دریافت اطلاعات ماه کاری
    $month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
    $month_query->execute([$work_month_id]);
    $month = $month_query->fetch(PDO::FETCH_ASSOC);

    if (!$month) {
        echo json_encode(['success' => false, 'message' => 'ماه کاری یافت نشد']);
        exit;
    }

    $start_date = new DateTime($month['start_date']);
    $end_date = new DateTime($month['end_date']);
    $interval = new DateInterval('P1D');
    // اطمینان از اینکه end_date هم شامل شود
    $date_range = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));

    // دریافت همه جفت‌های همکار از Partners
    $partner_query = $pdo->prepare("
        SELECT p.partner_id, u1.user_id AS user_id1, u1.full_name AS user1, 
               COALESCE(u2.user_id, u1.user_id) AS user_id2, COALESCE(u2.full_name, u1.full_name) AS user2
        FROM Partners p
        JOIN Users u1 ON p.user_id1 = u1.user_id
        LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
        GROUP BY p.partner_id
    ");
    $partner_query->execute();
    $partners_in_work = $partner_query->fetchAll(PDO::FETCH_ASSOC);

    // تعریف تخصیص همکارها به روزها (به جای ستون work_day)
    $partner_days = [
        1 => [1], // روز شنبه: partner_id = 1 (مثلاً "مهری طارمی - شیدا جوهری")
        2 => [2], // روز یک‌شنبه: partner_id = 2 (مثلاً "سهیلا قاسمی - شیدا جوهری")
        3 => [],  // روز دوشنبه: بدون همکار
        4 => [],  // روز سه‌شنبه: بدون همکار
        5 => [3], // روز چهارشنبه: partner_id = 3 (مثلاً "شیدا جوهری - مرضیه عبادی")
        6 => [],  // روز پنج‌شنبه: بدون همکار
        7 => []   // روز جمعه: بدون همکار
    ];

    // همگام‌سازی و ذخیره‌سازی داده‌ها
    foreach ($date_range as $date) {
        $work_date = $date->format('Y-m-d');
        // محاسبه روز هفته با date('N') و تبدیل به تقویم ایرانی
        $timestamp = strtotime($work_date);
        $day_number_miladi = (int) date('N', $timestamp); // 1 (دوشنبه) تا 7 (یک‌شنبه)
        // تبدیل به تقویم ایرانی (1: شنبه تا 7: جمعه)
        $adjusted_day_number = ($day_number_miladi + 5) % 7;
        if ($adjusted_day_number == 0) {
            $adjusted_day_number = 7; // اگر 0 شد، 7 (جمعه) بشه
        }

        // دیباگ
        error_log("Date: $work_date, Miladi Day: $day_number_miladi, Adjusted Day: $adjusted_day_number\n", 3, "debug.log");

        // تخصیص همکارها بر اساس روز هفته
        if (isset($partner_days[$adjusted_day_number]) && !empty($partner_days[$adjusted_day_number])) {
            foreach ($partner_days[$adjusted_day_number] as $partner_id) {
                // چک کردن وجود ردیف برای این تاریخ و partner_id
                $detail_query = $pdo->prepare("
                    SELECT * FROM Work_Details 
                    WHERE work_date = ? AND work_month_id = ? AND partner_id = ?
                ");
                $detail_query->execute([$work_date, $work_month_id, $partner_id]);
                $existing_detail = $detail_query->fetch(PDO::FETCH_ASSOC);

                if (!$existing_detail) {
                    $insert_query = $pdo->prepare("
                        INSERT INTO Work_Details (work_month_id, work_date, partner_id, agency_owner_id, status) 
                        VALUES (?, ?, ?, ?, 0)
                    ");
                    $insert_query->execute([$work_month_id, $work_date, $partner_id, null]);
                }
            }
        }
    }

    echo json_encode(['success' => true, 'message' => 'روز کاری‌ها با موفقیت به‌روزرسانی شدند']);
    exit;
} catch (Exception $e) {
    error_log("Error in auto_update_work_details.php: " . $e->getMessage() . "\n", 3, "error.log");
    echo json_encode(['success' => false, 'message' => 'خطا در به‌روزرسانی: ' . $e->getMessage()]);
    exit;
}
?>