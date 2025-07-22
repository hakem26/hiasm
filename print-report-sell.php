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
$selected_user_id = $_GET['user_id'] ?? $_SESSION['user_id'];
if (!$work_month_id) {
    die('ماه کاری انتخاب نشده است.');
}

$current_user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user_role = $stmt->fetchColumn();

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
$month_name = get_jalali_month_name((int) $jm) . ' ' . $jy;

// دریافت نام کاربر گزارش‌گیرنده
$stmt = $pdo->prepare("SELECT full_name FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$user_name = $user['full_name'] ?? 'نامشخص';

// دریافت نام همکار (یا "همه همکاران")
$partner_name = 'همه همکاران';
if ($selected_user_id !== 'all' && $user_role === 'admin') {
    $stmt = $pdo->prepare("SELECT full_name FROM Users WHERE user_id = ?");
    $stmt->execute([$selected_user_id]);
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);
    $partner_name = $partner['full_name'] ?? 'نامشخص';
} elseif ($user_role !== 'admin') {
    $partner_name = $user_name;
}

// جمع کل فروش و تخفیف (فقط برای user_id1)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(o.total_amount), 0) AS total_sales,
           COALESCE(SUM(o.discount), 0) AS total_discount
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE wd.work_month_id = ? AND p.user_id1 = ?
");
$params = [$work_month_id, $selected_user_id];
$stmt->execute($params);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);
$total_sales = $summary['total_sales'] ?? 0;
$total_discount = $summary['total_discount'] ?? 0;

// محاسبه تعداد جلسات آژانس که کاربر خودش رو ثبت کرده (بررسی user_id1 و user_id2)
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS session_count
    FROM Work_Details wd
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE wd.work_month_id = ? 
    AND (
        (p.user_id1 = ? AND wd.agency_owner_id = p.user_id1) 
        OR (p.user_id2 = ? AND wd.agency_owner_id = p.user_id2)
    )
");
$params = [$work_month_id, $selected_user_id, $selected_user_id];
$stmt->execute($params);
$total_sessions = $stmt->fetchColumn() ?: 0;
error_log("Calculated total_sessions for user_id $selected_user_id, work_month_id $work_month_id: $total_sessions");
$total_sessions = $total_sessions > 0 ? "$total_sessions جلسه" : "";

// لیست همه محصولات از Products با مقداردهی صفر برای محصولات بدون فروش
$products = [];
$stmt = $pdo->prepare("
    SELECT p.product_name, p.unit_price, 
           COALESCE(SUM(CASE WHEN wd.work_month_id = ? AND p2.user_id1 = ? THEN oi.quantity ELSE 0 END), 0) AS total_quantity,
           COALESCE(SUM(CASE WHEN wd.work_month_id = ? AND p2.user_id1 = ? THEN oi.total_price ELSE 0 END), 0) AS total_price
    FROM Products p
    LEFT JOIN Order_Items oi ON p.product_name = oi.product_name
    LEFT JOIN Orders o ON oi.order_id = o.order_id
    LEFT JOIN Work_Details wd ON o.work_details_id = wd.id
    LEFT JOIN Partners p2 ON wd.partner_id = p2.partner_id
    GROUP BY p.product_name, p.unit_price
    ORDER BY p.product_name COLLATE utf8mb4_persian_ci
");
$params = [$work_month_id, $selected_user_id, $work_month_id, $selected_user_id];
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// تبدیل تاریخ به شمسی
function gregorian_to_jalali_format($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

function get_jalali_month_name($month)
{
    $month_names = [
        1 => 'فروردین',
        2 => 'اردیبهشت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند'
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
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-Thin.woff2') format('woff2');
            font-weight: 100;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-ExtraLight.woff2') format('woff2');
            font-weight: 200;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-Light.woff2') format('woff2');
            font-weight: 300;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-Regular.woff2') format('woff2');
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-Medium.woff2') format('woff2');
            font-weight: 500;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-SemiBold.woff2') format('woff2');
            font-weight: 600;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-Bold.woff2') format('woff2');
            font-weight: 700;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-ExtraBold.woff2') format('woff2');
            font-weight: 800;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-Black.woff2') format('woff2');
            font-weight: 900;
            font-style: normal;
            font-display: swap;
        }

        * {
            font-feature-settings: "lnum" 0;
            font-variant-numeric: normal;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: "Vazirmatn RD FD NL";
            unicode-range: U+06F0-06F9;
            direction: rtl;
            text-align: right;
        }

        .page-container {
            width: 210mm;
            height: 297mm;
            margin: 0 auto;
            padding: 0 5mm;
            box-sizing: border-box;
            border: 1px solid #ccc;
            position: relative;
            overflow: hidden;
            page-break-after: always;
        }

        .page-container:last-child {
            page-break-after: auto;
        }

        .summary-box {
            width: 50%;
            margin: 2mm auto;
        }

        .summary-box table {
            width: 100%;
            border-collapse: collapse;
        }

        .summary-box td {
            padding: 10px;
            font-size: 24px;
            font-weight: bold;
            border: 1px solid #ccc;
        }

        .products-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 3mm;
        }

        .products-table th,
        .products-table td {
            border: 1px solid #000;
            text-align: center;
            padding: 5px;
            font-size: 10pt;
        }

        .products-table th {
            background-color: #f0f0f0;
        }

        .total-row {
            background-color: #f0f0f0;
            font-weight: bold;
        }

        .grand-total-row {
            background-color: #fff3cd;
            font-weight: bold;
            font-size: 16px;
        }

        .page-header {
            text-align: center;
            margin-bottom: 5mm;
            position: relative;
        }

        .page-number {
            position: absolute;
            top: 2mm;
            right: 5mm;
            font-size: 10pt;
        }

        .save-png-btn {
            position: fixed;
            top: 10px;
            right: 10px;
            padding: 10px 20px;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-family: "Vazirmatn RD FD NL";
            font-size: 12pt;
            z-index: 1000;
        }

        .save-png-btn:hover {
            background-color: #218838;
        }

        @media print {
            .page-container {
                border: none;
            }

            .save-png-btn {
                display: none;
            }

            @page {
                size: A4 portrait;
                margin: 0;
            }

            body {
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>

<body>
    <button class="save-png-btn" onclick="saveReportAsPNG()">ذخیره به‌صورت PNG</button>

    <!-- صفحه اول: جمع کل‌ها -->
    <div class="page-container" id="page-1">
        <div class="page-header">
            <h5>گزارش کاری <?= $month_name ?> - <?= $partner_name ?> - از <?= $start_date ?> تا <?= $end_date ?></h5>
            <div class="page-number">صفحه 1 از <?= ceil(count($products) / 32 + 1) ?></div>
        </div>
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
                    <td><?= $total_sessions ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- صفحه دوم به بعد: لیست محصولات -->
    <?php
    $items_per_page = 32;
    $total_items = count($products);
    $total_pages = ceil($total_items / $items_per_page);

    for ($page = 0; $page < $total_pages; $page++) {
        echo '<div class="page-container" id="page-' . ($page + 2) . '">';
        $start = $page * $items_per_page;
        $end = min(($page + 1) * $items_per_page, $total_items);
        $page_items = array_slice($products, $start, $items_per_page);

        echo '<div class="page-header">';
        echo '<h6>گزارش کاری ' . $month_name . ' - ' . $partner_name . ' - از ' . $start_date . ' تا ' . $end_date . '</h6>';
        echo '<div class="page-number">صفحه ' . ($page + 2) . ' از ' . (ceil(count($products) / 32 + 1)) . '</div>';
        echo '</div>';

        echo '<table class="products-table">';
        echo '<thead><tr><th>ردیف</th><th>اقلام</th><th>قیمت واحد</th><th>تعداد</th><th>قیمت کل</th><th>سود کلی</th><th>اضافه فروش-توضیحات</th></tr></thead>';
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
            echo '<td>' . number_format($item['unit_price'], 0) . ' </td>';
            echo '<td>' . $item['total_quantity'] . '</td>';
            echo '<td>' . number_format($total_price, 0) . ' </td>';
            echo '<td></td>'; // ستون سود (فعلاً خالی)
            echo '<td></td>'; // ستون اضافه فروش-توضیحات (خالی)
            echo '</tr>';
        }

        echo '<tr class="total-row">';
        echo '<td colspan="4">جمع کل</td>';
        echo '<td>' . number_format($page_total, 0) . ' تومان</td>';
        echo '<td></td>'; // ستون سود برای جمع کل
        echo '<td></td>'; // ستون اضافه فروش-توضیحات برای جمع کل
        echo '</tr>';

        if ($page == $total_pages - 1) {
            echo '<tr class="grand-total-row">';
            echo '<td colspan="4"><strong>جمع کل فروش</strong></td>';
            echo '<td>' . number_format($total_sales, 0) . ' تومان</td>';
            echo '<td></td>'; // ستون سود برای جمع کل فروش
            echo '<td></td>'; // ستون اضافه فروش-توضیحات برای جمع کل فروش
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
    ?>

    <script>
        console.log('Script loaded');

        function saveReportAsPNG() {
            if (typeof html2canvas === 'undefined') {
                console.error('html2canvas is not loaded');
                alert('خطا: کتابخانه html2canvas لود نشده است. لطفاً اتصال اینترنت را بررسی کنید.');
                return;
            }

            const totalPages = <?= ceil(count($products) / 32 + 1) ?>;
            const workMonthId = <?= $work_month_id ?>;
            const userId = <?= $selected_user_id ?>;

            for (let page = 1; page <= totalPages; page++) {
                const pageContainer = document.getElementById(`page-${page}`);
                if (!pageContainer) {
                    console.error(`Page container for page ${page} not found`);
                    continue;
                }

                html2canvas(pageContainer, {
                    scale: 4, // کیفیت بالاتر با scale 4
                    useCORS: true,
                    backgroundColor: '#ffffff',
                    logging: true
                }).then(canvas => {
                    const link = document.createElement('a');
                    link.href = canvas.toDataURL('image/png', 1.0); // کیفیت 100%
                    link.download = `گزارش_فروش_ماه_${workMonthId}_کاربر_${userId}_صفحه_${page}.png`;
                    link.click();
                }).catch(error => {
                    console.error('Error saving PNG:', error);
                    alert('خطا در ذخیره تصویر گزارش. لطفاً دوباره تلاش کنید.');
                });
            }
        }

        document.fonts.ready.then(function () {
            console.log('Fonts loaded');
        }).catch(error => {
            console.error('Error loading fonts:', error);
        });
    </script>
</body>

</html>