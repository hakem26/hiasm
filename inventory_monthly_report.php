<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

$work_month_id = $_GET['work_month_id'] ?? null;
$product_id = $_GET['product_id'] ?? null;
if (!$work_month_id) {
    exit;
}

$month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
$month_query->execute([$work_month_id]);
$month = $month_query->fetch(PDO::FETCH_ASSOC);
$start_date = $month['start_date'];
$end_date = $month['end_date'];

// گرفتن همه محصولات و تراکنش‌ها
$query = "
    SELECT 
        p.product_id,
        p.product_name,
        GROUP_CONCAT(
            CASE 
                WHEN it.quantity > 0 THEN it.quantity 
                WHEN it.quantity < 0 THEN CONCAT('(', it.quantity, ')')
            END 
            ORDER BY it.transaction_date DESC SEPARATOR '+'
        ) as requested,
        SUM(CASE WHEN it.quantity > 0 THEN it.quantity ELSE 0 END) as total_requested,
        SUM(CASE WHEN it.quantity < 0 THEN ABS(it.quantity) ELSE 0 END) as returned,
        (SELECT SUM(quantity) FROM Inventory_Transactions WHERE user_id = ? AND product_id = p.product_id AND transaction_date < ?) as initial_inventory
    FROM Products p
    LEFT JOIN Inventory_Transactions it ON p.product_id = it.product_id 
        AND it.transaction_date >= ? 
        AND it.transaction_date <= ? 
        AND it.user_id = ?
";
$params = [$_SESSION['user_id'], $start_date, $start_date, $end_date . ' 23:59:59', $_SESSION['user_id']];
if ($product_id) {
    $query .= " WHERE p.product_id = ?";
    $params[] = $product_id;
}
$query .= " GROUP BY p.product_id, p.product_name ORDER BY p.product_name ASC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$inventory_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// گرفتن تعداد فروش‌ها از فاکتورها
$sales_query = "
    SELECT 
        oi.product_name,
        SUM(oi.quantity) as total_sold
    FROM Order_Items oi
    JOIN Orders o ON oi.order_id = o.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    WHERE wd.work_date >= ? AND wd.work_date <= ? 
    AND EXISTS (
        SELECT 1 FROM Partners p 
        WHERE p.partner_id = wd.partner_id 
        AND (p.user_id1 = ? OR p.user_id2 = ?)
    )
";
$sales_params = [$start_date, $end_date, $_SESSION['user_id'], $_SESSION['user_id']];
if ($product_id) {
    $sales_query .= " AND EXISTS (SELECT 1 FROM Products p WHERE p.product_name = oi.product_name AND p.product_id = ?)";
    $sales_params[] = $product_id;
}
$sales_query .= " GROUP BY oi.product_name";
$stmt_sales = $pdo->prepare($sales_query);
$stmt_sales->execute($sales_params);
$sales_data = $stmt_sales->fetchAll(PDO::FETCH_ASSOC);

// ترکیب داده‌ها
$report = [];
foreach ($inventory_data as $item) {
    $initial_inventory = $item['initial_inventory'] ? (int)$item['initial_inventory'] : 0;
    $total_requested = $item['total_requested'] ? (int)$item['total_requested'] : 0;
    $returned = $item['returned'] ? (int)$item['returned'] : 0;

    // پیدا کردن فروش برای این محصول
    $total_sold = 0;
    foreach ($sales_data as $sale) {
        if ($sale['product_name'] === $item['product_name']) {
            $total_sold = (int)$sale['total_sold'];
            break;
        }
    }

    // محاسبه موجودی فعلی (برگشت از فروش)
    $current_inventory = $initial_inventory + $total_requested - $total_sold;

    // اصلاح رشته تخصیص‌ها با اضافه کردن موجودی اولیه
    $requested_display = $item['requested'] ? $item['requested'] : '';
    if ($initial_inventory != 0) {
        $initial_display = $initial_inventory < 0 ? "($initial_inventory)" : $initial_inventory;
        $requested_display = $requested_display ? $requested_display . "+<u>$initial_display</u>" : "<u>$initial_display</u>";
    } elseif ($requested_display === '') {
        $requested_display = '-';
    }

    $report[] = [
        'product_name' => $item['product_name'],
        'requested' => $requested_display,
        'total_requested' => $total_requested + $initial_inventory,
        'returned' => $returned,
        'current_inventory' => $current_inventory,
        'total_sold' => $total_sold
    ];
}
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

        /* برای راست‌چین کردن متن در ستون تخصیص‌ها */
        .requested-column {
            direction: rtl;
            text-align: center;
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
                        <th>ردیف</th>
                        <th>نام کالا</th>
                        <th>تعداد اجناس برده شده طی یک ماه</th>
                        <th>جمع</th>
                        <th>بازگشت به شرکت</th>
                        <th>برگشت از فروش</th>
                        <th>تعداد فروش نهایی</th>
                    </tr>
                </thead>
                <tbody>
                <?php endif; ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                    <td class="requested-column"><?= $item['requested'] ?></td>
                    <td><?= $item['total_requested'] ?></td>
                    <td><?= $item['returned'] ?: '-' ?></td>
                    <td><?= $item['current_inventory'] >= 0 ? $item['current_inventory'] : 0 ?></td>
                    <td><?= $item['total_sold'] ?></td>
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