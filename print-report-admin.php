<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

// دریافت پارامترها
$work_month_id = $_GET['work_month_id'] ?? '';
$user_id = $_GET['user_id'] ?? 'all';

if (!$work_month_id) {
    die("ماه کاری مشخص نشده است.");
}

// بررسی نقش کاربر
$current_user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user_role = $stmt->fetchColumn();

if ($user_role !== 'admin') {
    header("Location: print-report-sell.php?work_month_id=" . urlencode($work_month_id) . "&user_id=" . urlencode($current_user_id));
    exit;
}

// اطلاعات ماه
$stmt = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
$stmt->execute([$work_month_id]);
$month = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$month) {
    die("ماه کاری یافت نشد.");
}

list($sy, $sm, $sd) = explode('/', gregorian_to_jalali_format($month['start_date']));
list($ey, $em, $ed) = explode('/', gregorian_to_jalali_format($month['end_date']));

// جمع کل فروش و تخفیف
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(o.total_amount), 0) AS total_sales,
           COALESCE(SUM(o.discount), 0) AS total_discount
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE wd.work_month_id = ? " . ($user_id !== 'all' ? "AND p.user_id1 = ?" : "") . "
");
$params = [$work_month_id];
if ($user_id !== 'all') $params[] = $user_id;
$stmt->execute($params);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);
$total_sales = $summary['total_sales'] ?? 0;
$total_discount = $summary['total_discount'] ?? 0;

// تعداد جلسات
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT wd.work_date) AS total_sessions
    FROM Work_Details wd
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE wd.work_month_id = ? " . ($user_id !== 'all' ? "AND p.user_id1 = ?" : "") . "
");
$params = [$work_month_id];
if ($user_id !== 'all') $params[] = $user_id;
$stmt->execute($params);
$sessions = $stmt->fetch(PDO::FETCH_ASSOC);
$total_sessions = $sessions['total_sessions'] ?? 0;

// لیست محصولات
$stmt = $pdo->prepare("
    SELECT oi.product_name, oi.unit_price, SUM(oi.quantity) AS total_quantity, SUM(oi.total_price) AS total_price
    FROM Order_Items oi
    JOIN Orders o ON oi.order_id = o.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE wd.work_month_id = ? " . ($user_id !== 'all' ? "AND p.user_id1 = ?" : "") . "
    GROUP BY oi.product_name, oi.unit_price
    ORDER BY oi.product_name COLLATE utf8mb4_persian_ci
");
$params = [$work_month_id];
if ($user_id !== 'all') $params[] = $user_id;
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>چاپ گزارش فروش ادمین</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            .container-fluid {
                width: 100%;
                margin: 0;
                padding: 20px;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <h5 class="card-title mb-4">گزارش فروش ادمین</h5>
        <p>دوره: <?= "$sm/$sd/$sy تا $em/$ed/$ey" ?></p>

        <!-- جمع کل‌ها -->
        <div class="mb-4">
            <p>جمع کل فروش: <?= number_format($total_sales, 0) ?> تومان</p>
            <p>جمع کل تخفیفات: <?= number_format($total_discount, 0) ?> تومان</p>
            <p>مجموع جلسات آژانس: <?= $total_sessions ?> جلسه</p>
        </div>

        <!-- جدول محصولات -->
        <div class="table-responsive">
            <table class="table table-light">
                <thead>
                    <tr>
                        <th>ردیف</th>
                        <th>اقلام</th>
                        <th>قیمت واحد</th>
                        <th>تعداد</th>
                        <th>قیمت کل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="5" class="text-center">محصولی یافت نشد.</td>
                        </tr>
                    <?php else: ?>
                        <?php $row_number = 1; ?>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?= $row_number++ ?></td>
                                <td><?= htmlspecialchars($product['product_name']) ?></td>
                                <td><?= number_format($product['unit_price'], 0) ?> تومان</td>
                                <td><?= $product['total_quantity'] ?></td>
                                <td><?= number_format($product['total_price'], 0) ?> تومان</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="no-print mt-4">
            <button onclick="window.print()" class="btn btn-primary">چاپ</button>
            <a href="report-admin-sell.php" class="btn btn-secondary">بازگشت</a>
        </div>
    </div>

    <?php require_once 'footer.php'; ?>