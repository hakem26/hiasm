<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

$response = ['success' => false, 'message' => ''];

$work_month_id = $_POST['work_month_id'] ?? null;
if (!$work_month_id) {
    $response['message'] = 'ماه کاری انتخاب نشده است.';
    echo json_encode($response);
    exit;
}

// تاریخ امروز
$today = date('Y-m-d');
$today_jalali = gregorian_to_jalali_format($today);

// دریافت اطلاعات ماه کاری
$stmt_month = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
$stmt_month->execute([$work_month_id]);
$month = $stmt_month->fetch(PDO::FETCH_ASSOC);
if (!$month) {
    $response['message'] = 'ماه کاری یافت نشد.';
    echo json_encode($response);
    exit;
}
$start_month = $month['start_date'];
$end_month = $month['end_date'];

try {
    // نفرات امروز (جفت‌های همکار)
    $stmt_partners = $pdo->prepare("
        SELECT p.partner_id, u1.full_name AS partner1_name, u2.full_name AS partner2_name
        FROM Work_Details wd
        JOIN Partners p ON wd.partner_id = p.partner_id
        JOIN Users u1 ON p.user_id1 = u1.user_id
        JOIN Users u2 ON p.user_id2 = u2.user_id
        WHERE wd.work_date = ?
    ");
    $stmt_partners->execute([$today]);
    $partners_today = $stmt_partners->fetchAll(PDO::FETCH_ASSOC);

    // فروش کلی (روزانه، هفتگی، ماهانه)
    // روزانه: فروش امروز
    $stmt_daily_sales = $pdo->prepare("
        SELECT COALESCE(SUM(o.final_amount), 0) AS daily_sales
        FROM Orders o
        JOIN Work_Details wd ON o.work_details_id = wd.id
        WHERE DATE(o.created_at) = ?
    ");
    $stmt_daily_sales->execute([$today]);
    $daily_sales = $stmt_daily_sales->fetchColumn();

    // هفتگی: جمع فروش در روزهایی که هم‌روز با امروز هستند در ماه کاری
    $day_of_week = date('w', strtotime($today));
    $stmt_weekly_sales = $pdo->prepare("
        SELECT COALESCE(SUM(o.final_amount), 0) AS weekly_sales
        FROM Orders o
        JOIN Work_Details wd ON o.work_details_id = wd.id
        WHERE wd.work_month_id = ?
        AND DAYOFWEEK(wd.work_date) = ?
    ");
    $stmt_weekly_sales->execute([$work_month_id, ($day_of_week + 1)]);
    $weekly_sales = $stmt_weekly_sales->fetchColumn();

    // ماهانه: جمع فروش کل در ماه کاری
    $stmt_monthly_sales = $pdo->prepare("
        SELECT COALESCE(SUM(o.final_amount), 0) AS monthly_sales
        FROM Orders o
        JOIN Work_Details wd ON o.work_details_id = wd.id
        WHERE wd.work_month_id = ?
    ");
    $stmt_monthly_sales->execute([$work_month_id]);
    $monthly_sales = $stmt_monthly_sales->fetchColumn();

    // محصولات پر فروش (ماهانه)
    $stmt_top_products = $pdo->prepare("
        SELECT oi.product_name, SUM(oi.quantity) AS total_quantity, SUM(oi.total_price) AS total_amount
        FROM Order_Items oi
        JOIN Orders o ON oi.order_id = o.order_id
        JOIN Work_Details wd ON o.work_details_id = wd.id
        WHERE wd.work_month_id = ?
        GROUP BY oi.product_name
        ORDER BY total_quantity DESC
        LIMIT 10
    ");
    $stmt_top_products->execute([$work_month_id]);
    $top_products = $stmt_top_products->fetchAll(PDO::FETCH_ASSOC);

    // فروشندگان برتر (نفرات)
    $stmt_top_sellers_individual = $pdo->prepare("
        SELECT u.user_id, u.full_name, SUM(o.final_amount) AS total_sales
        FROM Users u
        JOIN Partners p ON u.user_id IN (p.user_id1, p.user_id2)
        JOIN Work_Details wd ON p.partner_id = wd.partner_id
        JOIN Orders o ON o.work_details_id = wd.id
        WHERE wd.work_month_id = ?
        GROUP BY u.user_id, u.full_name
        ORDER BY total_sales DESC
    ");
    $stmt_top_sellers_individual->execute([$work_month_id]);
    $top_sellers_individual = $stmt_top_sellers_individual->fetchAll(PDO::FETCH_ASSOC);

    // فروشندگان برتر (همکاران)
    $stmt_top_sellers_partners = $pdo->prepare("
        SELECT p.partner_id, u1.full_name AS partner1_name, u2.full_name AS partner2_name, SUM(o.final_amount) AS total_sales
        FROM Partners p
        JOIN Users u1 ON p.user_id1 = u1.user_id
        JOIN Users u2 ON p.user_id2 = u2.user_id
        JOIN Work_Details wd ON p.partner_id = wd.partner_id
        JOIN Orders o ON o.work_details_id = wd.id
        WHERE wd.work_month_id = ?
        GROUP BY p.partner_id, u1.full_name, u2.full_name
        ORDER BY total_sales DESC
    ");
    $stmt_top_sellers_partners->execute([$work_month_id]);
    $top_sellers_partners = $stmt_top_sellers_partners->fetchAll(PDO::FETCH_ASSOC);

    // آمار بدهکاران (فقط همکار1)
    $stmt_debtors = $pdo->prepare("
        SELECT u.user_id, u.full_name, 
               COALESCE(SUM(o.final_amount), 0) AS total_amount,
               COALESCE(SUM(op.amount), 0) AS paid_amount,
               (COALESCE(SUM(o.final_amount), 0) - COALESCE(SUM(op.amount), 0)) AS debt
        FROM Users u
        JOIN Partners p ON u.user_id = p.user_id1
        JOIN Work_Details wd ON p.partner_id = wd.partner_id
        JOIN Orders o ON o.work_details_id = wd.id
        LEFT JOIN Order_Payments op ON o.order_id = op.order_id
        WHERE wd.work_month_id = ?
        GROUP BY u.user_id, u.full_name
        HAVING debt > 0
        ORDER BY debt ASC
    ");
    $stmt_debtors->execute([$work_month_id]);
    $debtors = $stmt_debtors->fetchAll(PDO::FETCH_ASSOC);

    // آژانس (ماهانه)
    $stmt_agency = $pdo->prepare("
        SELECT u.user_id, u.full_name, COUNT(*) AS agency_count
        FROM Work_Details wd
        JOIN Users u ON wd.agency_owner_id = u.user_id
        WHERE wd.work_month_id = ?
        GROUP BY u.user_id, u.full_name
        ORDER BY agency_count DESC
    ");
    $stmt_agency->execute([$work_month_id]);
    $agency_data = $stmt_agency->fetchAll(PDO::FETCH_ASSOC);

    // تولید پاسخ
    $response['success'] = true;
    $response['partners_today'] = $partners_today;
    $response['daily_sales'] = $daily_sales;
    $response['weekly_sales'] = $weekly_sales;
    $response['monthly_sales'] = $monthly_sales;
    $response['top_products'] = $top_products;
    $response['top_sellers_individual'] = $top_sellers_individual;
    $response['top_sellers_partners'] = $top_sellers_partners;
    $response['debtors'] = $debtors;
    $response['agency_data'] = $agency_data;
} catch (Exception $e) {
    $response['message'] = 'خطایی در سرور رخ داد: ' . $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;