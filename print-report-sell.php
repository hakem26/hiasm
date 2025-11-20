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
$month_name = get_jalali_month_name((int) $jm);

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

// محاسبه تعداد جلسات آژانس
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
$total_sessions = $total_sessions > 0 ? "$total_sessions جلسه" : "";

// لیست همه محصولات با قیمت بروز + فروش
$stmt = $pdo->prepare("
    SELECT p.product_name, 
           p.unit_price,
           COALESCE(SUM(oi.quantity), 0) AS total_quantity,
           COALESCE(SUM(oi.total_price), 0) AS total_price
    FROM Products p
    LEFT JOIN Order_Items oi ON p.product_name = oi.product_name
    LEFT JOIN Orders o ON oi.order_id = o.order_id
    LEFT JOIN Work_Details wd ON o.work_details_id = wd.id
    LEFT JOIN Partners p2 ON wd.partner_id = p2.partner_id
    WHERE wd.work_month_id = ? AND p2.user_id1 = ?
    GROUP BY p.product_name, p.unit_price
    ORDER BY p.product_name COLLATE utf8mb4_persian_ci
");
$stmt->execute([$work_month_id, $selected_user_id]);
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
            font-family: 'BNaznnBd';
            src: url('./assets/fonts/BNaznnBd.ttf') format('truetype');
        }
        @font-face {
            font-family: 'BTitrBd';
            src: url('./assets/fonts/BTitrBd.ttf') format('truetype');
            font-weight: bold;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'BNaznnBd', sans-serif;
            direction: rtl;
            text-align: right;
        }

        th {
            font-family: 'BTitrBd', sans-serif;
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
            font-family: 'BNaznnBd', sans-serif;
        }

        .products-table th {
            background-color: #f0f0f0;
            font-family: 'BTitrBd', sans-serif;
        }

        .page-header {
            text-align: center;
            margin-bottom: 1mm;
            font-family: 'BTitrBd', sans-serif;
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
            font-family: "BTitrBd", sans-serif;
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
            <h4>گزارش کاری - <?= htmlspecialchars($partner_name) ?> - از <?= $start_date ?> تا <?= $end_date ?></h4>
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

    <!-- صفحات لیست محصولات -->
    <?php
    $items_per_page = 30; // تغییر به 30 ردیف
    $total_items = count($products);
    $total_pages = ceil($total_items / $items_per_page);

    for ($page = 0; $page < $total_pages; $page++) {
        echo '<div class="page-container" id="page-' . ($page + 2) . '">';
        $start = $page * $items_per_page;
        $end = min(($page + 1) * $items_per_page, $total_items);
        $page_items = array_slice($products, $start, $end - $start);

        echo '<div class="page-header">';
        echo '<h4>گزارش کاری - ' . htmlspecialchars($partner_name) . ' - از ' . $start_date . ' تا ' . $end_date . '</h4>';
        echo '</div>';

        echo '<table class="products-table">';
        echo '<thead><tr><th>ردیف</th><th>اقلام</th><th>قیمت واحد</th><th>تعداد</th><th>قیمت کل</th><th>سود کلی</th><th>اضافه فروش-توضیحات</th></tr></thead>';
        echo '<tbody>';

        foreach ($page_items as $i => $item) {
            $row_number = $start + $i + 1;

            // حذف 000 آخر
            $unit_price = rtrim(rtrim(number_format($item['unit_price'], 0, '.', ','), '0'), ',');
            $total_price = rtrim(rtrim(number_format($item['total_price'], 0, '.', ','), '0'), ',');

            echo '<tr>';
            echo '<td>' . $row_number . '</td>';
            echo '<td>' . htmlspecialchars($item['product_name']) . '</td>';
            echo '<td>' . $unit_price . '</td>';
            echo '<td>' . $item['total_quantity'] . '</td>';
            echo '<td>' . $total_price . '</td>';
            echo '<td></td>';
            echo '<td></td>';
            echo '</tr>';
        }

        // جمع کل در هر صفحه حذف شد
        if ($page == $total_pages - 1) {
            $total_sales_clean = rtrim(rtrim(number_format($total_sales, 0, '.', ','), '0'), ',');
            echo '<tr class="grand-total-row">';
            echo '<td colspan="4"><strong>جمع کل فروش</strong></td>';
            echo '<td>' . $total_sales_clean . ' تومان</td>';
            echo '<td></td><td></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
    ?>

    <script>
        function saveReportAsPNG() {
            const totalPages = <?= $total_pages + 1 ?>;
            for (let page = 1; page <= totalPages; page++) {
                const element = document.getElementById('page-' + page);
                if (element) {
                    html2canvas(element, {scale: 4, useCORS: true, backgroundColor: '#ffffff'}).then(canvas => {
                        const link = document.createElement('a');
                        link.download = 'گزارش فروش ماه <?= $month_name ?> صفحه ' + page + '.png';
                        link.href = canvas.toDataURL();
                        link.click();
                    });
                }
            }
        }
    </script>
</body>

</html>