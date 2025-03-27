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

    // دریافت محدوده تاریخ ماه کاری
    $month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
    $month_query->execute([$work_month_id]);
    $month = $month_query->fetch(PDO::FETCH_ASSOC);

    if (!$month) {
        echo json_encode(['success' => false, 'message' => 'ماه کاری نامعتبر است']);
        exit;
    }

    $start_date = $month['start_date'];
    $end_date = $month['end_date'];

    // دریافت همه همکاری‌ها
    $partners_query = $pdo->prepare("SELECT partner_id, user_id1, user_id2 FROM Partners");
    $partners_query->execute();
    $all_partners = $partners_query->fetchAll(PDO::FETCH_ASSOC);

    if (empty($all_partners)) {
        echo json_encode(['success' => false, 'message' => 'هیچ همکاری پیدا نشد']);
        exit;
    }

    // دریافت برنامه کاری همکارها
    $schedules_query = $pdo->prepare("SELECT partner_id, day_of_week FROM Partner_Schedule");
    $schedules_query->execute();
    $schedules = $schedules_query->fetchAll(PDO::FETCH_ASSOC);

    // تبدیل برنامه‌ها به آرایه برای دسترسی سریع
    $schedule_map = [];
    foreach ($schedules as $schedule) {
        $schedule_map[$schedule['partner_id']][] = $schedule['day_of_week'];
    }

    // دریافت روزهای کاری موجود برای این ماه
    $existing_dates_query = $pdo->prepare("SELECT work_date, partner_id FROM Work_Details WHERE work_month_id = ?");
    $existing_dates_query->execute([$work_month_id]);
    $existing_details = $existing_dates_query->fetchAll(PDO::FETCH_ASSOC);

    // تبدیل به آرایه برای چک سریع
    $existing_map = [];
    foreach ($existing_details as $detail) {
        $existing_map[$detail['work_date'] . '|' . $detail['partner_id']] = true;
    }

    // محاسبه و ذخیره روزها
    $current_date = $start_date;
    $new_records_added = 0;
    $insert_query = $pdo->prepare("INSERT INTO Work_Details (work_month_id, work_date, partner_id, status) VALUES (?, ?, ?, 0)");

    while (strtotime($current_date) <= strtotime($end_date)) {
        $day_of_week = calculate_day_of_week($current_date);

        foreach ($all_partners as $partner) {
            $partner_id = $partner['partner_id'];
            $key = $current_date . '|' . $partner_id;

            // اگه این روز برای این همکار قبلاً ثبت شده، ردش کن
            if (isset($existing_map[$key])) {
                $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
                continue;
            }

            // چک کن اگه برنامه‌ای برای این همکار تعریف شده
            $partner_days = $schedule_map[$partner_id] ?? [];
            $should_insert = empty($partner_days) || in_array($day_of_week, $partner_days);

            if ($should_insert) {
                try {
                    $insert_query->execute([$work_month_id, $current_date, $partner_id]);
                    $new_records_added++;
                } catch (PDOException $e) {
                    error_log("Error inserting work detail for $current_date, partner $partner_id: " . $e->getMessage());
                }
            }
        }

        $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
    }

    if ($new_records_added > 0) {
        echo json_encode(['success' => true, 'message' => "$new_records_added روز کاری جدید با موفقیت اضافه شدند"]);
    } else {
        echo json_encode(['success' => true, 'message' => 'هیچ روز کاری جدیدی برای اضافه کردن یافت نشد']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'شناسه ماه کاری ارسال نشده است']);
}
?>