<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
    exit;
}

require_once 'db.php';
require_once 'jdf.php';

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
        SELECT p.partner_id, p.work_day AS stored_day_number, u1.user_id AS user_id1, u1.full_name AS user1, 
               COALESCE(u2.user_id, u1.user_id) AS user_id2, COALESCE(u2.full_name, u1.full_name) AS user2
        FROM Partners p
        JOIN Users u1 ON p.user_id1 = u1.user_id
        LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
        GROUP BY p.partner_id
    ");
    $partner_query->execute();
    $partners_in_work = $partner_query->fetchAll(PDO::FETCH_ASSOC);

    // همگام‌سازی و ذخیره‌سازی داده‌ها
    $processed_partners = [];
    foreach ($date_range as $date) {
        $work_date = $date->format('Y-m-d');
        // تبدیل تاریخ میلادی به شمسی و محاسبه روز هفته
        list($gy, $gm, $gd) = explode('-', $work_date);
        $adjusted_day_number = (int) jdate('w', mktime(0, 0, 0, $gm, $gd, $gy)) + 1; // 1 (شنبه) تا 7 (جمعه)

        // بررسی وجود روز کاری برای این تاریخ
        $detail_query = $pdo->prepare("
            SELECT * FROM Work_Details 
            WHERE work_date = ? AND work_month_id = ?
        ");
        $detail_query->execute([$work_date, $work_month_id]);
        $existing_details = $detail_query->fetchAll(PDO::FETCH_ASSOC);

        if (empty($existing_details)) {
            // اگه هنوز روز کاری برای این تاریخ ساخته نشده، برای همه جفت‌ها چک کن
            foreach ($partners_in_work as $partner) {
                $partner_id = $partner['partner_id'];
                if (!in_array($partner_id, $processed_partners)) {
                    $processed_partners[] = $partner_id;

                    if ($partner['stored_day_number'] == $adjusted_day_number) {
                        $insert_query = $pdo->prepare("
                            INSERT INTO Work_Details (work_month_id, work_date, work_day, partner_id, agency_owner_id, status) 
                            VALUES (?, ?, ?, ?, ?, 0)
                        ");
                        $insert_query->execute([$work_month_id, $work_date, $adjusted_day_number, $partner_id, null]);
                    }
                }
            }
        }
    }

    echo json_encode(['success' => true, 'message' => 'روز کاری‌ها با موفقیت به‌روزرسانی شدند']);
    exit;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'خطا در به‌روزرسانی: ' . $e->getMessage()]);
    exit;
}
?>