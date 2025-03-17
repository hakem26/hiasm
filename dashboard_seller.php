<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'seller') {
    header("Location: index.php");
    exit;
}

require_once 'db.php';
require_once 'header.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

// تاریخ امروز
$today = date('Y-m-d');
$today_jalali = gregorian_to_jalali_format($today);

// روز هفته به فارسی
$day_of_week = jdate('l', strtotime($today));
$day_names = [
    'شنبه' => 'شنبه',
    'یک‌شنبه' => 'یک‌شنبه',
    'دوشنبه' => 'دوشنبه',
    'سه‌شنبه' => 'سه‌شنبه',
    'چهارشنبه' => 'چهارشنبه',
    'پنج‌شنبه' => 'پنج‌شنبه',
    'جمعه' => 'جمعه'
];
$persian_day = $day_names[$day_of_week] ?? 'نامشخص';

// کاربر فعلی
$current_user_id = $_SESSION['user_id'];
$stmt_user = $pdo->prepare("SELECT full_name FROM Users WHERE user_id = ?");
$stmt_user->execute([$current_user_id]);
$user = $stmt_user->fetch(PDO::FETCH_ASSOC);
$user_name = $user['full_name'] ?? 'کاربر ناشناس';

// دریافت اطلاعات روز کاری برای امروز
$stmt_work_details = $pdo->prepare("
    SELECT id AS work_details_id
    FROM Work_Details
    WHERE work_date = ? AND partner_id IN (
        SELECT partner_id FROM Partners WHERE user_id1 = ? OR user_id2 = ?
    )
    LIMIT 1
");
$stmt_work_details->execute([$today, $current_user_id, $current_user_id]);
$work_details = $stmt_work_details->fetch(PDO::FETCH_ASSOC);
$work_details_id = $work_details['work_details_id'] ?? null;

// نفرات امروز (همکار آن کاربر)
$stmt_partners = $pdo->prepare("
    SELECT p.partner_id, u2.full_name AS partner_name
    FROM Partners p
    LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
    WHERE (p.user_id1 = ? OR p.user_id2 = ?) 
    AND p.partner_id IN (
        SELECT partner_id 
        FROM Work_Details 
        WHERE work_date = ?
    )
");
$stmt_partners->execute([$current_user_id, $current_user_id, $today]);
$partners_today = $stmt_partners->fetchAll(PDO::FETCH_ASSOC);
?>

<h2 class="text-center mb-4">داشبورد فروشنده - <?= htmlspecialchars($user_name) ?></h2>

<!-- نفرات امروز -->
<div class="card">
    <div class="card-body">
        <h5 class="card-title">امروز <?= $persian_day ?> (<?= $today_jalali ?>)</h5>
        <ul class="list-group">
            <?php foreach ($partners_today as $partner): ?>
                <li class="list-group-item"><?= htmlspecialchars($partner['partner_name'] ?? 'همکار ناشناس') ?></li>
            <?php endforeach; ?>
            <?php if (empty($partners_today)): ?>
                <li class="list-group-item">هیچ همکاری امروز فعال نیست.</li>
            <?php endif; ?>
        </ul>
        <?php if (!empty($partners_today) && $work_details_id): ?>
            <div class="mt-3">
                <a href="https://hakemo26.persiasptool.com/add_order.php?work_details_id=<?= $work_details_id ?>" class="btn btn-primary me-2">ثبت سفارش</a>
                <a href="https://hakemo26.persiasptool.com/orders.php?year=2025&work_month_id=10&user_id=<?= $current_user_id ?>&work_day_id=<?= $work_details_id ?>" class="btn btn-secondary">لیست سفارشات</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
// بخش‌های دیگر (فروش، محصولات پر فروش، فروشندگان برتر، بدهکاران) فعلاً خالی می‌مونه تا مرحله به مرحله کامل بشه
require_once 'footer.php';
?>