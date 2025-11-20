<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'db.php';
require_once 'jdf.php';

// توابع تبدیل تاریخ (برای جلوگیری از خطای undefined)
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

function get_jalali_month_name($month) {
    $month_names = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    ];
    return $month_names[$month] ?? '';
}

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
$month_name = get_jalali_month_name((int)$jm);

// نام کاربر و همکار
$stmt = $pdo->prepare("SELECT full_name FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user_name = $stmt->fetchColumn() ?: 'نامشخص';

$partner_name = 'همه همکاران';
if ($selected_user_id !== 'all' && $user_role === 'admin') {
    $stmt->execute([$selected_user_id]);
    $partner_name = $stmt->fetchColumn() ?: 'نامشخص';
} elseif ($user_role !== 'admin') {
    $partner_name = $user_name;
}

// جمع کل فروش و تخفیف
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(o.total_amount), 0) AS total_sales,
           COALESCE(SUM(o.discount), 0) AS total_discount
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE wd.work_month_id = ? AND p.user_id1 = ?
");
$stmt->execute([$work_month_id, $selected_user_id]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);
$total_sales = $summary['total_sales'] ?? 0;
$total_discount = $summary['total_discount'] ?? 0;

// تعداد جلسات آژانس
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM Work_Details wd
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE wd.work_month_id = ? 
    AND ((p.user_id1 = ? AND wd.agency_owner_id = p.user_id1) 
      OR (p.user_id2 = ? AND wd.agency_owner_id = p.user_id2))
");
$stmt->execute([$work_month_id, $selected_user_id, $selected_user_id]);
$total_sessions = $stmt->fetchColumn() ?: 0;
$total_sessions = $total_sessions ? "$total_sessions جلسه" : "";

// لیست ثابت 157 محصول
$fixed_products = [
    'آبرسان نیتروژنار',
    'آکواژل لاروشه',
    'اسپری بریدگی/سوختگی',
    'اسکالپ اسکراب ریوولی',
    'اسکراب اسکین',
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
    'پلینگ ژل ریوولی',
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
    'قرص امگا 3 افتریو',
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
    'قرص هیالورونیک',
    'قرص هیرتامین',
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
    'ماس flashbackک موی یک کیلویی',
    'ماسک نیاسینامید ریوولی',
    'ماسک هیدراتراپی ریوولی',
    'مرطوب کننده لب ریوولی',
    'مولتی اکتیو (شب / روز)',
    'نمک بدن بیوومن',
    'کرم MED لاروشه',
    'کرم آبرسان سرامید ریوولی',
    'کرم آبرسان هیالورونیک ریوولی',
    'کرم آبرسان کاسه ای سراوی',
    'کرم پلی پپتاید ریوولی',
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
    'کرم کلاژن زرد YC'
];

// گرفتن فروش‌ها + قیمت بروز
$sales_query = "
    SELECT 
        p.product_name,
        COALESCE(h.unit_price, p.unit_price) AS current_unit_price,
        SUM(oi.quantity) AS total_quantity,
        SUM(oi.total_price) AS total_price
    FROM Products p
    LEFT JOIN Order_Items oi ON p.product_name = oi.product_name
    LEFT JOIN Orders o ON oi.order_id = o.order_id
    LEFT JOIN Work_Details wd ON o.work_details_id = wd.id
    LEFT JOIN Partners p2 ON wd.partner_id = p2.partner_id
    LEFT JOIN Product_Price_History h ON p.product_id = h.product_id 
        AND wd.work_date >= h.start_date 
        AND (h.end_date IS NULL OR wd.work_date <= h.end_date)
    WHERE wd.work_month_id = ? AND p2.user_id1 = ?
    GROUP BY p.product_name, h.unit_price, p.unit_price
    ORDER BY p.product_name COLLATE utf8mb4_persian_ci
";
$stmt_sales = $pdo->prepare($sales_query);
$stmt_sales->execute([$work_month_id, $selected_user_id]);
$sales_data = $stmt_sales->fetchAll(PDO::FETCH_ASSOC);

$sales_map = [];
foreach ($sales_data as $sale) {
    $sales_map[$sale['product_name']] = $sale;
}

// گزارش نهایی با ترتیب ثابت و قیمت بروز
$report = [];
foreach ($fixed_products as $product_name) {
    $sale = $sales_map[$product_name] ?? null;
    $unit_price = $sale['current_unit_price'] ?? 0;

    $report[] = [
        'product_name' => $product_name,
        'unit_price' => $unit_price,
        'quantity' => $sale['total_quantity'] ?? '',
        'total_price' => $sale ? $sale['total_price'] : ''
    ];
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>چاپ گزارش فروش</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        @font-face {font-family: 'BNaznnBd';src: url('./assets/fonts/BNaznnBd.ttf') format('truetype');}
        @font-face {font-family: 'BTitrBd';src: url('./assets/fonts/BTitrBd.ttf') format('truetype');font-weight: bold;}
        body {font-family: 'BNaznnBd';margin:0;padding:0;}
        th {font-family: 'BTitrBd';}
        .page-container {width:210mm;height:297mm;margin:0 auto;padding:0 5mm;box-sizing:border-box;border:1px solid #ccc;position:relative;overflow:hidden;page-break-after:always;}
        .page-container:last-child {page-break-after:auto;}
        table {width:100%;border-collapse:collapse;}
        th, td {border:1px solid #000;padding:3px;text-align:center;}
        th {background-color:#f0f0f0;}
        .print-btn {position:fixed;top:10px;right:10px;padding:10px 20px;background:#28a745;color:#fff;border:none;border-radius:5px;cursor:pointer;z-index:1000;}
        @media print {.page-container{border:none;}.print-btn{display:none;}@page{size:A4 portrait;margin:0;}body{margin:0;padding:0;}}
    </style>
</head>
<body>
    <button class="print-btn" onclick="saveReportAsPNG()">ذخیره به‌صورت PNG</button>

    <!-- صفحه اول: جمع کل‌ها -->
    <div class="page-container" id="page-1">
        <h4 style="text-align:center;font-size: 24px;">گزارش کاری - <?= htmlspecialchars($partner_name) ?> - از <?= $start_date ?> تا <?= $end_date ?></h4>
        <table style="width:50%;margin:20px auto;font-size: 24px;">
            <tr><td>جمع کل فروش</td><td><?= number_format($total_sales) ?> تومان</td></tr>
            <tr><td>تخفیف</td><td><?= number_format($total_discount) ?> تومان</td></tr>
            <tr><td>آژانس</td><td><?= $total_sessions ?></td></tr>
        </table>
    </div>

    <!-- صفحات جدول محصولات -->
    <?php
    $items_per_page = 30;
    $total_pages = ceil(count($report) / $items_per_page);
    for ($page = 0; $page < $total_pages; $page++) {
        $start = $page * $items_per_page;
        $page_items = array_slice($report, $start, $items_per_page);
        echo '<div class="page-container" id="page-' . ($page + 2) . '">';
        echo '<h4 style="text-align:center;">گزارش کاری - ' . htmlspecialchars($partner_name) . ' - از ' . $start_date . ' تا ' . $end_date . '</h4>';
        echo '<table class="products-table">';
        echo '<thead><tr><th>ردیف</th><th>اقلام</th><th>قیمت واحد</th><th>تعداد</th><th>قیمت کل</th><th>سود کلی</th><th>اضافه فروش-توضیحات</th></tr></thead><tbody>';
        foreach ($page_items as $i => $item) {
            $row_num = $start + $i + 1;
            $unit_price = $item['unit_price'] ? rtrim(rtrim(number_format($item['unit_price'], 0, '.', ','), '0'), ',') : '';
            $total_price = $item['total_price'] ? rtrim(rtrim(number_format($item['total_price'], 0, '.', ','), '0'), ',') : '';
            echo '<tr>';
            echo '<td>' . $row_num . '</td>';
            echo '<td>' . htmlspecialchars($item['product_name']) . '</td>';
            echo '<td>' . $unit_price . '</td>';
            echo '<td>' . ($item['quantity'] ?: '') . '</td>';
            echo '<td>' . $total_price . '</td>';
            echo '<td></td><td></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    ?>

    <script>
        function saveReportAsPNG() {
            const totalPages = <?= $total_pages + 1 ?>;
            for (let i = 1; i <= totalPages; i++) {
                html2canvas(document.getElementById('page-' + i), {scale: 4}).then(canvas => {
                    const a = document.createElement('a');
                    a.href = canvas.toDataURL('image/png');
                    a.download = 'گزارش فروش ماه <?= $month_name ?> صفحه ' + i + '.png';
                    a.click();
                });
            }
        }
    </script>
</body>
</html>