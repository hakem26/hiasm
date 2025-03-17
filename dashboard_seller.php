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

// کاربر فعلی
$current_user_id = $_SESSION['user_id'];
$stmt_user = $pdo->prepare("SELECT full_name FROM Users WHERE user_id = ?");
$stmt_user->execute([$current_user_id]);
$user = $stmt_user->fetch(PDO::FETCH_ASSOC);
$user_name = $user['full_name'] ?? 'کاربر ناشناس';

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

// فروش کلی (روزانه، هفتگی، ماهانه)
$start_week = date('Y-m-d', strtotime('monday this week'));
$end_week = date('Y-m-d', strtotime('sunday this week'));
$stmt_month = $pdo->query("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = (SELECT MAX(work_month_id) FROM Work_Months WHERE end_date <= CURDATE())");
$month = $stmt_month->fetch(PDO::FETCH_ASSOC);
$start_month = $month['start_date'];
$end_month = $month['end_date'];

$stmt_sales = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN o.created_at >= ? AND o.created_at <= ? THEN o.final_amount ELSE 0 END) AS daily_sales,
        SUM(CASE WHEN o.created_at >= ? AND o.created_at <= ? THEN o.final_amount ELSE 0 END) AS weekly_sales,
        SUM(CASE WHEN o.created_at >= ? AND o.created_at <= ? THEN o.final_amount ELSE 0 END) AS monthly_sales
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE (p.user_id1 = ? OR p.user_id2 = ?)
");
$stmt_sales->execute([$today, $today, $start_week, $end_week, $start_month, $end_month, $current_user_id, $current_user_id]);
$sales = $stmt_sales->fetch(PDO::FETCH_ASSOC);

// محصولات پر فروش (ماهانه)
$stmt_top_products = $pdo->prepare("
    SELECT oi.product_name, SUM(oi.quantity) AS total_quantity, SUM(oi.total_price) AS total_amount
    FROM Order_Items oi
    JOIN Orders o ON oi.order_id = o.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE (p.user_id1 = ? OR p.user_id2 = ?) 
    AND o.created_at >= ? AND o.created_at <= ?
    GROUP BY oi.product_name
    ORDER BY total_quantity DESC
    LIMIT 5
");
$stmt_top_products->execute([$current_user_id, $current_user_id, $start_month, $end_month]);
$top_products = $stmt_top_products->fetchAll(PDO::FETCH_ASSOC);

// فروشندگان برتر (همکار 2 های آن کاربر)
$stmt_top_sellers = $pdo->prepare("
    SELECT u2.full_name, SUM(o.final_amount) AS total_sales
    FROM Partners p
    JOIN Users u2 ON p.user_id2 = u2.user_id
    JOIN Work_Details wd ON p.partner_id = wd.partner_id
    JOIN Orders o ON o.work_details_id = wd.id
    WHERE p.user_id1 = ? 
    AND o.created_at >= ? AND o.created_at <= ?
    GROUP BY u2.user_id, u2.full_name
    ORDER BY total_sales DESC
    LIMIT 5
");
$stmt_top_sellers->execute([$current_user_id, $start_month, $end_month]);
$top_sellers = $stmt_top_sellers->fetchAll(PDO::FETCH_ASSOC);

// آمار بدهکاران (تا 10 ردیف)
$stmt_debtors = $pdo->prepare("
    SELECT o.customer_name, o.final_amount, 
           COALESCE(SUM(op.amount), 0) AS paid_amount,
           (o.final_amount - COALESCE(SUM(op.amount), 0)) AS debt
    FROM Orders o
    LEFT JOIN Order_Payments op ON o.order_id = op.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE (p.user_id1 = ? OR p.user_id2 = ?) 
    AND o.created_at >= ? AND o.created_at <= ?
    GROUP BY o.order_id, o.customer_name, o.final_amount
    HAVING debt > 0
    ORDER BY debt DESC
    LIMIT 10
");
$stmt_debtors->execute([$current_user_id, $current_user_id, $start_month, $end_month]);
$debtors = $stmt_debtors->fetchAll(PDO::FETCH_ASSOC);
?>

<h2 class="text-center mb-4">داشبورد فروشنده - <?= htmlspecialchars($user_name) ?></h2>

<!-- نفرات امروز -->
<div class="card">
    <div class="card-body">
        <h5 class="card-title">نفرات امروز (<?= $today_jalali ?>)</h5>
        <ul class="list-group">
            <?php foreach ($partners_today as $partner): ?>
                <li class="list-group-item"><?= htmlspecialchars($partner['partner_name'] ?? 'همکار ناشناس') ?></li>
            <?php endforeach; ?>
            <?php if (empty($partners_today)): ?>
                <li class="list-group-item">هیچ همکاری امروز فعال نیست.</li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<!-- نمودار فروش کلی -->
<div class="card">
    <div class="card-body">
        <h5 class="card-title">آمار فروش کلی</h5>
        <canvas id="salesChart"></canvas>
        <script>
            const ctx = document.getElementById('salesChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['روزانه', 'هفتگی', 'ماهانه'],
                    datasets: [{
                        label: 'فروش (تومان)',
                        data: [<?= $sales['daily_sales'] ?? 0 ?>, <?= $sales['weekly_sales'] ?? 0 ?>, <?= $sales['monthly_sales'] ?? 0 ?>],
                        backgroundColor: 'rgba(54, 162, 235, 0.5)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    scales: { y: { beginAtZero: true } },
                    responsive: true
                }
            });
        </script>
    </div>
</div>

<!-- محصولات پر فروش -->
<div class="card">
    <div class="card-body">
        <h5 class="card-title">محصولات پر فروش (ماهانه)</h5>
        <ul class="list-group">
            <?php foreach ($top_products as $product): ?>
                <li class="list-group-item">
                    <?= htmlspecialchars($product['product_name']) ?> - تعداد: <?= $product['total_quantity'] ?> - مبلغ:
                    <?= number_format($product['total_amount'], 0) ?> تومان
                </li>
            <?php endforeach; ?>
            <?php if (empty($top_products)): ?>
                <li class="list-group-item">محصولی یافت نشد.</li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<!-- فروشندگان برتر -->
<div class="card">
    <div class="card-body">
        <h5 class="card-title">فروشندگان برتر (ماهانه)</h5>
        <ul class="list-group">
            <?php foreach ($top_sellers as $seller): ?>
                <li class="list-group-item">
                    <?= htmlspecialchars($seller['full_name']) ?> - فروش: <?= number_format($seller['total_sales'], 0) ?>
                    تومان
                </li>
            <?php endforeach; ?>
            <?php if (empty($top_sellers)): ?>
                <li class="list-group-item">فروشنده‌ای یافت نشد.</li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<!-- آمار بدهکاران -->
<div class="card">
    <div class="card-body">
        <h5 class="card-title">آمار بدهکاران</h5>
        <ul class="list-group">
            <?php foreach ($debtors as $debtor): ?>
                <li class="list-group-item">
                    <?= htmlspecialchars($debtor['customer_name']) ?> - بدهی: <?= number_format($debtor['debt'], 0) ?> تومان
                </li>
            <?php endforeach; ?>
            <?php if (empty($debtors)): ?>
                <li class="list-group-item">بدهی‌ای یافت نشد.</li>
            <?php endif; ?>
        </ul>
    </div>
</div>
</div>

<?php
require_once 'footer.php';
?>