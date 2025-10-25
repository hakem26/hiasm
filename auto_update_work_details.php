<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
    exit;
}

// تابع محاسبه روز هفته (شمسی)
function calculate_day_of_week($work_date) {
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

if (!isset($_POST['work_month_id'])) {
    echo json_encode(['success' => false, 'message' => 'شناسه ماه کاری ارسال نشده است']);
    exit;
}

$work_month_id = (int) $_POST['work_month_id'];

// دریافت بازه ماه کاری
$month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
$month_query->execute([$work_month_id]);
$month = $month_query->fetch(PDO::FETCH_ASSOC);

if (!$month) {
    echo json_encode(['success' => false, 'message' => 'ماه کاری نامعتبر است']);
    exit;
}

$start_date = $month['start_date'];
$end_date = $month['end_date'];

// دیباگ: لاگ کردن بازه تاریخ
error_log("Processing work_month_id: $work_month_id, start_date: $start_date, end_date: $end_date");

// دریافت همه همکاری‌ها
// فقط همکاران فعال
$partners_query = $pdo->prepare("SELECT partner_id, user_id1, user_id2 FROM Partners WHERE active = 1");
$partners_query->execute();
$all_partners = $partners_query->fetchAll(PDO::FETCH_ASSOC);

if (empty($all_partners)) {
    echo json_encode(['success' => false, 'message' => 'هیچ همکاری پیدا نشد']);
    exit;
}

// دریافت برنامه کاری
$schedules_query = $pdo->prepare("SELECT partner_id, day_of_week FROM Partner_Schedule");
$schedules_query->execute();
$schedules = $schedules_query->fetchAll(PDO::FETCH_ASSOC);

// نقشه برنامه‌ها
$schedule_map = [];
foreach ($schedules as $schedule) {
    $schedule_map[$schedule['partner_id']][] = (int) $schedule['day_of_week'];
}
error_log("Schedule map: " . json_encode($schedule_map));

// دریافت روزهای موجود
$existing_query = $pdo->prepare("SELECT work_date, partner_id FROM Work_Details WHERE work_month_id = ?");
$existing_query->execute([$work_month_id]);
$existing_details = $existing_query->fetchAll(PDO::FETCH_ASSOC);

$existing_map = [];
foreach ($existing_details as $detail) {
    $key = $detail['work_date'] . '|' . $detail['partner_id'];
    $existing_map[$key] = true;
}
error_log("Existing entries: " . count($existing_map));

// آماده‌سازی درج
$insert_query = $pdo->prepare("INSERT INTO Work_Details (work_month_id, work_date, partner_id, status) VALUES (?, ?, ?, 0)");
$new_records_added = 0;

$current_date = $start_date;
while (strtotime($current_date) <= strtotime($end_date)) {
    $day_of_week = calculate_day_of_week($current_date);
    error_log("Processing date: $current_date, day_of_week: $day_of_week");

    foreach ($all_partners as $partner) {
        $partner_id = $partner['partner_id'];
        $key = $current_date . '|' . $partner_id;

        if (isset($existing_map[$key])) {
            error_log("Skipping $key - already exists");
            continue;
        }

        $partner_days = $schedule_map[$partner_id] ?? [];
        $should_insert = empty($partner_days) || in_array($day_of_week, $partner_days);

        if ($should_insert) {
            try {
                $insert_query->execute([$work_month_id, $current_date, $partner_id]);
                $new_records_added++;
                error_log("Inserted: $key");
            } catch (PDOException $e) {
                error_log("Error inserting $key: " . $e->getMessage());
            }
        } else {
            error_log("Skipped $key - not in schedule");
        }
    }

    $current_date = date('Y-m-d', strtotime("$current_date +1 day"));
}

echo json_encode([
    'success' => true,
    'message' => "$new_records_added روز کاری جدید با موفقیت اضافه شدند"
]);
?>