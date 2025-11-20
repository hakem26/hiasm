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

// تعداد جلسات آژانس
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

// لیست ثابت محصولات (به ترتیب دلخواه)
$fixed_products = [
    'آبرسان نیتروژنار',
    'آکواژل لاروشه',
    'اسپری بریدگی/سوختگی',
    'اسکالپ اسکراب ریوولی',
    'اسکراب اسکین',
    'اسکراب اینگون',
    'اسکراب رشوبی',
    'اسکراب فوری',
    'انتی ملاسما',
    'انواع خوشبو کننده بدن',
    'انواع ژل ضد جوش',
    'انواع میسلار واتر ریوولی',
    'انواع ویال',
    'انواع کپسول ویتامین ای',
    'بادی ژل ریوولی',
    'بادی لوسیون / میلک ریوولی',
    'بادی واش انتی پیمپل',
    'بالم سیکاپلاست لاروشه',
    'بالم لب',
    'پچ چشم',
    'پچ چشم مایع ریوولی',
    'پچ لب',
    'پیلینگ ژل ریوولی',
    'پک اورال اورجینال',
    'پک دیور',
    'پک گرلن',
    'ترک پا ریوولی',
    'ترک پا هندی',
    'تقویت مژه ماوالا',
    'تقویت مژه ریوولی',
    'تقویت ناخن ریوولی',
    'تونر KA',
    'تونر آبی راشل',
    'تونر ریوولی',
    'تونر سبز راشل',
    'تونر طلا',
    'تونر مجیک ریوولی',
    'تونر کنترل چربی ریوولی',
    'تونیک عصاره سیکا ریوولی',
    'تونیک لیفت فرش ریوولی',
    'خمیر دندان ترمد',
    'خمیر دندان فرشکول',
    'خمیر دندان فرشکول کوچک',
    'خمیر دندان کاپیتانو',
    'دور چشم ریوولی',
    'دور چشم شتر مرغ اسکین',
    'ریمل اسنس',
    'ریمل اوربیوتی',
    'ژل شستشو ریوولی',
    'ژل ضد جوش سراوی',
    'ژل کرم نیاسینامید ریوولی',
    'ژل کرم کلاژن ریوولی',
    'ژل کلینسر سراوی',
    'ست ویال ویتامین E',
    'سرم اورال',
    'سرم پلی پپتاید ریوولی',
    'سرم سراوی',
    'سرم سنتلا ریوولی',
    'سرم ضد قرمزی ریوولی',
    'سرم لاروشه',
    'سرم نیاسینامید ریوولی',
    'سرم هیدراتراپی ریوولی',
    'سرم کراتین اسکین',
    'سرم کریستال موی ریوولی',
    'شامپو جنسینگ',
    'شامپو دکسی',
    'شامپو ضد زردی ریوولی',
    'شامپو های کویین',
    'شامپو کارلزاپ کاندیشنر',
    'شامپو کلاژن نایس فرش',
    'شاور کرم ریوولی',
    'شاورژل کویین',
    'شربت کلروفیل NOW',
    'صابون',
    'صابون ابرو',
    'KA SPF 50 ضد آفتاب',
    'ضد آفتاب استیلن',
    'ضد آفتاب اوسرین',
    'ضد آفتاب پیگمنت لاروشه',
    'ضد آفتاب ریوولی',
    'ضد آفتاب سراوی',
    'ضد آفتاب لاروشه',
    'ضد آفتاب KA 50 پلاس',
    'ضد آفتاب سلرانیکو کره ای',
    'ضد لک مردان EXPLORE',
    'فارم استی',
    'فوم پمپی ریوولی',
    'فوم ضد جوش سراوی',
    'فوم ویتامین E',
    'فوم کلاژن',
    'فیس واش / افتر شیو آقایان',
    'فیکساتور آرایش ریوولی',
    'قرص اسپرولینا افتریو',
    'قرص ال کارنتین',
    'قرص الفامن 240 عددی',
    'قرص بن بیس',
    'قرص تفویت چشم افتریو',
    'قرص تقویت قلب افتریو',
    'قرص تقویت مردان افتریو',
    'قرص تقویت کبد افتریو',
    'قرص دانه پسیلیوم افتریو',
    'قرص زنان افتریو',
    'قرص فروزن',
    'قرص فیتو انگلیس',
    'قرص گرویولا افتریو',
    'قرص مولتی فرست افترایو',
    'قرص ویت مث افترایو',
    'قرص کلاژن افتریو',
    'قرص کوکونات کلاژن',
    'لوسیون آبرسان سراوی',
    'لوسیون انتی پیمپل',
    'لوسیون اینگون',
    'لوسیون تاناكا',
    'لوسیون صورت سراوی',
    'لوسیون کلینسر ریوولی',
    'ماسک استیک',
    'ماسک خاک دریا',
    'ماسک صورت ورقه ای',
    'ماسک موی کلاژن نایس',
    'ماسک موی ریوولی',
    'ماسک موی یک کیلویی',
    'ماسک نیاسینامید ریوولی',
    'ماسک هیدراتراپی ریوولی',
    'مرطوب کننده لب ریوولی',
    'مولتی اکتیو (شب / روز)',
    'نمک بدن بیوومن',
    'کرم MED لاروشه',
    'کرم آبرسان سرامید ریوولی',
    'کرم آبرسان هیالورونیک ریوولی',
    'کرم آبرسان کاسه ای سراوی',
    'کرم پنتنول',
    'کرم پودر بورژوا',
    'کرم دست سراوی',
    'کرم دست لاروشه',
    'کرم دور چشم کلاژن ریوولی',
    'کرم رفع تیرگی YC',
    'کرم روز YC',
    'کرم روز انتی پیگمنت',
    'کرم سه چهره YC',
    'کرم شب انتی پیمپل',
    'کرم شب سراوی',
    'کرم شب / روز ضد قرمزی',
    'کرم شترمرغ اسکین',
    'کرم گردن ریوولی',
    'کرم لیزر ریوولی',
    'کرم هایدرم',
    'کرم هیدراتراپی ریوولی',
    'کرم هیدرو اسکین ریوولی',
    'کرم واريس',
    'کرم وایت KA',
    'کرم وایت YC',
    'کرم ویتامین ای KA',
    'کرم کلاژن حلزون',
    'کرم کلاژن زرد YC',
    'کره بدن'
];

// گرفتن فروش‌ها برای ماه جاری
$sales_query = "
    SELECT 
        oi.product_name,
        SUM(oi.quantity) as total_quantity,
        SUM(oi.total_price) as total_price,
        p.unit_price as current_price
    FROM Order_Items oi
    JOIN Orders o ON oi.order_id = o.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    JOIN Products p2 ON oi.product_name = p2.product_name
    WHERE wd.work_month_id = ? AND p.user_id1 = ?
    GROUP BY oi.product_name
";
$sales_stmt = $pdo->prepare($sales_query);
$sales_stmt->execute([$work_month_id, $selected_user_id]);
$sales_data = $sales_stmt->fetchAll(PDO::FETCH_ASSOC);

// تبدیل به نقشه برای دسترسی سریع
$sales_map = [];
foreach ($sales_data as $sale) {
    $sales_map[$sale['product_name']] = $sale;
}

// تولید گزارش با ترتیب ثابت
$report = [];
foreach ($fixed_products as $product_name) {
    $sale = $sales_map[$product_name] ?? null;
    if (!$sale) {
        // فقط محصولاتی که فروش یا تخصیص دارن
        continue;
    }

    $report[] = [
        'product_name' => $product_name,
        'unit_price' => $sale['current_price'] ?? 0,
        'quantity' => $sale['total_quantity'] ?? 0,
        'total_price' => $sale['total_price'] ?? 0
    ];
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
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: 'BTitrBd';
            src: url('./assets/fonts/BTitrBd.ttf') format('truetype');
            font-weight: bold;
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
            font-family: "BNaznnBd";
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

        .products-table th {
            font-family: 'BTitrBd';
            background-color: #f0f0f0;
        }

        .products-table th,
        .products-table td {
            border: 1px solid #000;
            text-align: center;
            font-family: 'BNaznnBd';
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
            margin-bottom: 1mm;
            font-family: 'BTitrBd';
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
            font-family: "BTitrBd";
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
            <h4>گزارش کاری - <?= $partner_name ?> - از <?= $start_date ?> تا <?= $end_date ?></h4>
        </div>
        <div class="summary-box">
            <table>
                <tr>
                    <td>جمع کل فروش</td>
                    <td><?= number_format($total_sales) ?> تومان</td>
                </tr>
                <tr>
                    <td>تخفیف</td>
                    <td><?= number_format($total_discount) ?> تومان</td>
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
    $total_items = count($report);
    $total_pages = ceil($total_items / $items_per_page);

    for ($page = 0; $page < $total_pages; $page++) {
        echo '<div class="page-container" id="page-' . ($page + 2) . '">';
        $start = $page * $items_per_page;
        $end = min(($page + 1) * $items_per_page, $total_items);
        $page_items = array_slice($report, $start, $end - $start);

        echo '<div class="page-header">';
        echo '<h4>گزارش کاری - ' . $partner_name . ' - از ' . $start_date . ' تا ' . $end_date . '</h4>';
        echo '</div>';

        echo '<table class="products-table">';
        echo '<thead><tr><th>ردیف</th><th>اقلام</th><th>قیمت واحد</th><th>تعداد</th><th>قیمت کل</th><th>سود کلی</th><th>اضافه فروش-توضیحات</th></tr></thead>';
        echo '<tbody>';

        $page_total = 0;
        foreach ($page_items as $i => $item) {
            $row_number = $start + $i + 1;
            $unit_price = $item['unit_price'];
            $quantity = $item['quantity'];
            $total_price = $item['total_price'];
            $page_total += $total_price;

            // حذف 000 آخر
            $unit_price_display = rtrim(number_format($unit_price, 0, '.', ','), '0');
            $unit_price_display = rtrim($unit_price_display, ',');
            $total_price_display = rtrim(number_format($total_price, 0, '.', ','), '0');
            $total_price_display = rtrim($total_price_display, ',');

            echo '<tr>';
            echo '<td>' . $row_number . '</td>';
            echo '<td>' . htmlspecialchars($item['product_name']) . '</td>';
            echo '<td>' . $unit_price_display . '</td>';
            echo '<td>' . $quantity . '</td>';
            echo '<td>' . $total_price_display . '</td>';
            echo '<td></td>'; // سود کلی
            echo '<td></td>'; // اضافه فروش-توضیحات
            echo '</tr>';
        }

        // حذف جمع کل در هر صفحه
        // فقط در آخرین صفحه جمع کل فروش نمایش داده میشه
        if ($page == $total_pages - 1) {
            $total_sales_display = rtrim(number_format($total_sales, 0, '.', ','), '0');
            $total_sales_display = rtrim($total_sales_display, ',');
            echo '<tr class="grand-total-row">';
            echo '<td colspan="4"><strong>جمع کل فروش</strong></td>';
            echo '<td>' . $total_sales_display . ' تومان</td>';
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
                html2canvas(element, { scale: 4, useCORS: true }).then(canvas => {
                    const link = document.createElement('a');
                    link.download = 'گزارش فروش ماه <?= $month_name ?> صفحه ' + page + '.png';
                    link.href = canvas.toDataURL();
                    link.click();
                });
            }
        }
    </script>
</body>

</html>