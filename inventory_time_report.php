<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_full_date($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', date('Y-m-d', strtotime($gregorian_date)));
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

// بررسی ورود کاربر
if (!isset($_SESSION['user_id'])) {
    // اگر کاربر وارد نشده، می‌توانیم او را به صفحه ورود هدایت کنیم
    header("Location: index.php");
    exit;
}

$work_month_id = $_GET['work_month_id'] ?? null;
$product_id = $_GET['product_id'] ?? null;
if (!$work_month_id)
    exit;

$month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
$month_query->execute([$work_month_id]);
$month = $month_query->fetch(PDO::FETCH_ASSOC);
$start_date = $month['start_date'];
$end_date = $month['end_date'];

$query = "
    SELECT it.transaction_date, p.product_name, it.quantity,
           SUM(CASE WHEN it2.quantity IS NOT NULL THEN it2.quantity ELSE 0 END) as total_before
    FROM Inventory_Transactions it
    JOIN Products p ON it.product_id = p.product_id
    LEFT JOIN Inventory_Transactions it2 ON it2.user_id = it.user_id AND it2.product_id = it.product_id AND it2.transaction_date < it.transaction_date
    WHERE it.transaction_date >= ? AND it.transaction_date <= ? AND it.user_id = ?
";
$params = [$start_date, $end_date . ' 23:59:59', $_SESSION['user_id']];
if ($product_id) {
    $query .= " AND it.product_id = ?";
    $params[] = $product_id;
}
$query .= " GROUP BY it.id, it.transaction_date, p.product_name, it.quantity ORDER BY it.transaction_date ASC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$report = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>گزارش زمانی</title>
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

        @page {
            size: A4 portrait;
            margin: 10mm;
        }

        body {
            font-family: 'Vazirmatn RD FD NL';
            font-size: 13px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            page-break-inside: auto;
        }

        th,
        td {
            border: 1px solid black;
            padding: 5px;
            text-align: center;
        }

        tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }

        thead {
            display: table-header-group;
        }

        .print-btn {
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

        .print-btn:hover {
            background-color: #218838;
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm;
            }

            body {
                margin: 0;
                padding: 0;
            }

            .print-btn {
                display: none;
            }
        }
    </style>
</head>

<body>
    <button class="print-btn" onclick="window.print()">چاپ گزارش</button>

    <?php
    $rows_per_page = 32;
    $row_count = 0;
    foreach ($report as $index => $item):
        if ($row_count % $rows_per_page == 0):
            if ($row_count > 0)
                echo '</table>';
            ?>
            <table>
                <thead>
                    <tr>
                        <th>تاریخ</th>
                        <th>نام کالا</th>
                        <th>تعداد</th>
                        <th>وضعیت تخصیص</th>
                        <th>موجودی قبل</th>
                    </tr>
                </thead>
                <tbody>
                <?php endif; ?>
                <tr>
                    <td><?= gregorian_to_jalali_full_date($item['transaction_date']) ?></td>
                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                    <td><?= abs($item['quantity']) ?></td>
                    <td><?= $item['quantity'] > 0 ? 'درخواست' : 'بازگشت' ?></td>
                    <td><?= $item['total_before'] ?></td>
                </tr>
                <?php
                $row_count++;
                if ($row_count % $rows_per_page == 0)
                    echo '</tbody></table>';
    endforeach;
    if ($row_count % $rows_per_page != 0)
        echo '</tbody></table>';
    ?>
</body>

</html>