<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
    exit;
}

// تابع محاسبه روز هفته (شمسی)
function calculate_day_of_week($work_date)
{
    $reference_date = '2025-03-01'; // 1403/12/1 که شنبه است
    $reference_timestamp = strtotime($reference_date);
    $current_timestamp = strtotime($work_date);
    $days_diff = ($current_timestamp - $reference_timestamp) / (60 * 60 * 24);
    $adjusted_day_number = ($days_diff % 7 + 1);
    if ($adjusted_day_number <= 0) {
        $adjusted_day_number += 7;
    }
    return $adjusted_day_number; // 1 (شنبه) تا 7 (جمعه)
}

if (isset($_POST['work_month_id'])) {
    $work_month_id = (int) $_POST['work_month_id'];

    // دیباگ: بررسی work_month_id
    error_log("Debug: auto_update_work_details - work_month_id = $work_month_id\n", 3, "debug.log");

    // دریافت محدوده تاریخ ماه کاری
    $month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
    $month_query->execute([$work_month_id]);
    $month = $month_query->fetch(PDO::FETCH_ASSOC);

    if ($month) {
        $start_date = $month['start_date'];
        $end_date = $month['end_date'];

        // دیباگ: بررسی تاریخ‌ها
        error_log("Debug: start_date = $start_date, end_date = $end_date\n", 3, "debug.log");

        // دریافت برنامه کاری همکارها
        $schedules_query = $pdo->query("SELECT ps.partner_id, ps.day_of_week, p.user_id1, p.user_id2, u1.full_name AS user1, u2.full_name AS user2
                                        FROM Partner_Schedule ps
                                        JOIN Partners p ON ps.partner_id = p.partner_id
                                        LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
                                        LEFT JOIN Users u2 ON p.user_id2 = u2.user_id");
        $schedules = $schedules_query->fetchAll(PDO::FETCH_ASSOC);

        // دیباگ: بررسی برنامه‌ها
        error_log("Debug: Schedules = " . json_encode($schedules) . "\n", 3, "debug.log");

        if (empty($schedules)) {
            echo json_encode(['success' => false, 'message' => 'هیچ برنامه کاری برای همکارها تعریف نشده است']);
            exit;
        }

        // دریافت روزهای کاری موجود برای این ماه
        $existing_dates_query = $pdo->prepare("SELECT work_date FROM Work_Details WHERE work_month_id = ?");
        $existing_dates_query->execute([$work_month_id]);
        $existing_dates = array_column($existing_dates_query->fetchAll(PDO::FETCH_ASSOC), 'work_date');

        // دیباگ: بررسی روزهای موجود
        error_log("Debug: Existing dates = " . json_encode($existing_dates) . "\n", 3, "debug.log");

        // محاسبه و ذخیره روزها (فقط برای روزهایی که وجود ندارن)
        $current_date = $start_date;
        $new_records_added = 0;
        while (strtotime($current_date) <= strtotime($end_date)) {
            // اگه این تاریخ قبلاً ثبت شده، ردش کن
            if (in_array($current_date, $existing_dates)) {
                error_log("Debug: Skipping $current_date (already exists)\n", 3, "debug.log");
                $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
                continue;
            }

            // محاسبه روز هفته
            $day_of_week = calculate_day_of_week($current_date);

            // دیباگ: بررسی روز هفته
            $days_of_week = [1 => 'شنبه', 2 => 'یک‌شنبه', 3 => 'دوشنبه', 4 => 'سه‌شنبه', 5 => 'چهارشنبه', 6 => 'پنج‌شنبه', 7 => 'جمعه'];
            error_log("Debug: Date $current_date is " . $days_of_week[$day_of_week] . "\n", 3, "debug.log");

            // پیدا کردن جفت‌های همکار برای این روز
            $partners_for_day = array_filter($schedules, function($schedule) use ($day_of_week) {
                return $schedule['day_of_week'] == $day_of_week;
            });

            foreach ($partners_for_day as $partner) {
                $partner_id = $partner['partner_id'];
                $user1 = $partner['user1'] ?: 'نامشخص';
                $user2 = $partner['user2'] ?: 'نامشخص';

                // دیباگ: نمایش جفت‌های همکار
                error_log("Debug: Assigning $user1 and $user2 for $current_date\n", 3, "debug.log");

                // ثبت روز کاری
                $insert_query = $pdo->prepare("INSERT INTO Work_Details (work_month_id, work_date, partner_id, status) VALUES (?, ?, ?, 0)");
                $insert_query->execute([$work_month_id, $current_date, $partner_id]);
                $new_records_added++;
            }

            // روز بعد
            $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
        }

        if ($new_records_added > 0) {
            echo json_encode(['success' => true, 'message' => "$new_records_added روز کاری جدید با موفقیت اضافه شدند"]);
        } else {
            echo json_encode(['success' => true, 'message' => 'هیچ روز کاری جدیدی برای اضافه کردن یافت نشد']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'ماه کاری نامعتبر است']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'شناسه ماه کاری ارسال نشده است']);
}
?>