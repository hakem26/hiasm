<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'db.php';
require_once 'jdf.php';

// دریافت پارامترها
$work_month_id = $_GET['work_month_id'] ?? '';
if (!$work_month_id) {
    die('ماه کاری انتخاب نشده است.');
}

$current_user_id = $_SESSION['user_id'];

// دریافت اطلاعات ماه کاری
$stmt = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
$stmt->execute([$work_month_id]);
$month = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$month) {
    die('ماه کاری یافت نشد.');
}

$start_date = gregorian_to_jalali_format($month['start_date']);
$end_date = gregorian_to_jalali_format($month['end_date']);
list($jy, $jm, $jd) = explode('/', $start_date);
$month_name = get_jalali_month_name((int)$jm) . ' ' . $jy;

// دریافت نام کاربر گزارش‌گیرنده
$stmt = $pdo->prepare("SELECT full_name FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$user_name = $user['full_name'] ?? 'نامشخص';

// جمع کل فروش و تخفیف
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(o.total_amount), 0) AS total_sales,
           COALESCE(SUM(o.discount), 0) AS total_discount
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE wd.work_month_id = ? AND p.user_id1 = ?
");
$stmt->execute([$work_month_id, $current_user_id]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);
$total_sales = $summary['total_sales'] ?? 0;
$total_discount = $summary['total_discount'] ?? 0;

// تعداد جلسات (روزهای کاری) فقط برای user_id1
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT wd.work_date) AS total_sessions
    FROM Work_Details wd
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE wd.work_month_id = ? AND p.user_id1 = ?
");
$stmt->execute([$work_month_id, $current_user_id]);
$sessions = $stmt->fetch(PDO::FETCH_ASSOC);
$total_sessions = $sessions['total_sessions'] ?? 0;

// لیست محصولات
$products = [];
$stmt = $pdo->prepare("
    SELECT oi.product_name, oi.unit_price, SUM(oi.quantity) AS total_quantity, SUM(oi.total_price) AS total_price
    FROM Order_Items oi
    JOIN Orders o ON oi.order_id = o.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE wd.work_month_id = ? AND p.user_id1 = ?
    GROUP BY oi.product_name, oi.unit_price
    ORDER BY oi.product_name COLLATE utf8mb4_persian_ci
");
$stmt->execute([$work_month_id, $current_user_id]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// تبدیل تاریخ به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

function get_jalali_month_name($month) {
    $month_names = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
        4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
        10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    ];
    return $month_names[$month] ?? '';
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>چاپ گزارش فروش</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
        }
        .summary-box {
            border: 2px solid #000;
            width: 50%;
            margin: 0 auto;
            padding: 10px;
        }
        .summary-box table {
            width: 100%;
            border-collapse: collapse;
        }
        .summary-box td {
            padding: 10px;
            border: 1px solid #ccc;
        }
        .products-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .products-table th, .products-table td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: center;
        }
        .total-row {
            background-color: #f0f0f0;
            font-weight: bold;
        }
        .grand-total-row {
            background-color: #fff3cd;
            font-weight: bold;
            font-size: 14px;
        }
        .page-break {
            page-break-before: always;
        }
    </style>
</head>

<body>
    <!-- صفحه اول: جمع کل‌ها -->
    <div class="summary-box">
        <table>
            <tr>
                <td>جمع کل فروش</td>
                <td><?= number_format($total_sales, 0) ?> تومان</td>
            </tr>
            <tr>
                <td>تخفیف</td>
                <td><?= number_format($total_discount, 0) ?> تومان</td>
            </tr>
            <tr>
                <td>آژانس</td>
                <td><?= $total_sessions ?> جلسه</td>
            </tr>
        </table>
    </div>

    <!-- صفحه دوم به بعد: لیست محصولات -->
    <?php
    $items_per_page = 30;
    $total_items = count($products);
    $total_pages = ceil($total_items / $items_per_page);

    for ($page = 0; $page < $total_pages; $page++) {
        if ($page > 0) {
            echo '<div class="page-break"></div>';
        }
        $start = $page * $items_per_page;
        $end = min(($page + 1) * $items_per_page, $total_items);
        $page_items = array_slice($products, $start, $items_per_page);

        // تیتر صفحه
        echo '<h4>گزارش کاری ' . $month_name . ' - ' . $user_name . ' - از ' . $start_date . ' تا ' . $end_date . '</h4>';

        // جدول محصولات (با ستون سود)
        echo '<table class="products-table">';
        echo '<thead><tr><th>ردیف</th><th>اقلام</th><th>قیمت واحد</th><th>تعداد</th><th>قیمت کل</th><th>سود</th></tr></thead>';
        echo '<tbody>';

        $page_total = 0;
        for ($i = 0; $i < count($page_items); $i++) {
            $item = $page_items[$i];
            $row_number = $start + $i + 1;
            $total_price = $item['total_price'];
            $page_total += $total_price;

            echo '<tr>';
            echo '<td>' . $row_number . '</td>';
            echo '<td>' . htmlspecialchars($item['product_name']) . '</td>';
            echo '<td>' . number_format($item['unit_price'], 0) . ' تومان</td>';
            echo '<td>' . $item['total_quantity'] . '</td>';
            echo '<td>' . number_format($total_price, 0) . ' تومان</td>';
            echo '<td></td>'; // ستون سود (فعلاً خالی)
            echo '</tr>';
        }

        // ردیف جمع کل
        echo '<tr class="total-row">';
        echo '<td colspan="4">جمع کل</td>';
        echo '<td>' . number_format($page_total, 0) . ' تومان</td>';
        echo '<td></td>'; // ستون سود برای جمع کل
        echo '</tr>';

        // اگر صفحه آخر باشه و تعداد محصولات ≤ 30 باشه، ردیف جمع کل فروش رو اضافه کن
        if ($end == $total_items && $total_items <= 30) {
            echo '<tr class="grand-total-row">';
            echo '<td colspan="4"><strong>جمع کل فروش</strong></td>';
            echo '<td>' . number_format($total_sales, 0) . ' تومان</td>';
            echo '<td></td>'; // ستون سود برای جمع کل فروش
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
    ?>

    <button class="btn btn-secondary mt-3" onclick="window.print()">چاپ</button>
</body>
</html>