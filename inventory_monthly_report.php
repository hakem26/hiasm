<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

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
    SELECT it.product_id, p.product_name,
           GROUP_CONCAT(CASE WHEN it.quantity > 0 THEN it.quantity END SEPARATOR '-') as requested,
           SUM(CASE WHEN it.quantity > 0 THEN it.quantity ELSE 0 END) as total_requested,
           SUM(CASE WHEN it.quantity < 0 THEN ABS(it.quantity) ELSE 0 END) as returned
    FROM Inventory_Transactions it
    JOIN Products p ON it.product_id = p.product_id
    WHERE it.transaction_date >= ? AND it.transaction_date <= ? AND it.user_id = ?
";
$params = [$start_date, $end_date . ' 23:59:59', $_SESSION['user_id']];
if ($product_id) {
    $query .= " AND it.product_id = ?";
    $params[] = $product_id;
}
$query .= " GROUP BY it.product_id, p.product_name ORDER BY it.product_name ASC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$report = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>گزارش ماهانه</title>
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
            font-size: 12px;
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
    </style>
</head>

<body>
    <?php
    $rows_per_page = 32; // تغییر از 17 به 32
    $row_count = 0;
    foreach ($report as $index => $item):
        if ($row_count % $rows_per_page == 0):
            if ($row_count > 0)
                echo '</table>';
            ?>
            <table>
                <thead>
                    <tr>
                        <th>ردیف</th>
                        <th>نام کالا</th>
                        <th>تعداد اجناس برده شده طی یک ماه</th>
                        <th>جمع</th>
                        <th>تعداد برگشتی</th>
                        <th>تعداد نهایی</th>
                    </tr>
                </thead>
                <tbody>
                <?php endif; ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                    <td><?= $item['requested'] ?: '-' ?></td> <!-- نمایش 2-1-4 -->
                    <td><?= $item['total_requested'] ?></td>
                    <td><?= $item['returned'] ? $item['returned'] : '-' ?></td>
                    <td><?= $item['total_requested'] - ($item['returned'] ?: 0) ?></td>
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