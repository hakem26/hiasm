<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

$order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
if ($order_id <= 0) {
    error_log("Invalid order_id attempted: $order_id");
    header('HTTP/1.1 400 Bad Request');
    echo "<div style='text-align: center; font-family: Vazirmatn RD FD NL; direction: rtl;'>فاکتور نامعتبر است. لطفاً شماره فاکتور معتبر وارد کنید.</div>";
    exit;
}

$stmt = $pdo->prepare("
    SELECT o.order_id, o.customer_name, o.total_amount, o.discount, o.final_amount, wd.work_date,
           u1.full_name AS partner1_name, u1.phone_number AS partner1_phone,
           u2.full_name AS partner2_name, u2.phone_number AS partner2_phone
    FROM Orders o
    LEFT JOIN Work_Details wd ON o.work_details_id = wd.id
    LEFT JOIN Partners p ON wd.partner_id = p.partner_id
    LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
    LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
    WHERE o.order_id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    error_log("Order not found for order_id: $order_id");
    header('HTTP/1.1 404 Not Found');
    echo "<div style='text-align: center; font-family: Vazirmatn RD FD NL; direction: rtl;'>فاکتور یافت نشد. لطفاً با پشتیبانی تماس بگیرید.</div>";
    exit;
}

$stmt_items = $pdo->prepare("SELECT * FROM Order_Items WHERE order_id = ? ORDER BY item_id ASC");
$stmt_items->execute([$order_id]);
$items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

// دریافت قیمت‌های فاکتور و پست از جدول Invoice_Prices
$invoice_prices = [];
$postal_enabled = false;
$postal_price = 0;
$stmt_invoice = $pdo->prepare("SELECT item_index, invoice_price, is_postal, postal_price FROM Invoice_Prices WHERE order_id = ? ORDER BY id DESC");
$stmt_invoice->execute([$order_id]);
$invoice_data = $stmt_invoice->fetchAll(PDO::FETCH_ASSOC);
foreach ($invoice_data as $row) {
    if ($row['is_postal'] && $row['postal_price'] > 0) {
        $postal_enabled = true;
        $postal_price = $row['postal_price'];
    } elseif (!$row['is_postal']) {
        if (!isset($invoice_prices[$row['item_index']])) {
            $invoice_prices[$row['item_index']] = $row['invoice_price'];
        }
    }
}

$items_per_page = 14;
$total_items = count($items) + ($postal_enabled ? 1 : 0);
$total_pages = ceil($total_items / $items_per_page);
$pages = array_chunk($items, $items_per_page);
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاکتور فروش</title>
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

        .invoice-container {
            width: 148mm;
            height: 210mm;
            margin: 0 auto;
            padding: 0 5mm;
            box-sizing: border-box;
            border: 1px solid #ccc;
            position: relative;
            overflow: hidden;
            page-break-after: always;
        }

        .invoice-container:last-child {
            page-break-after: auto;
        }

        .invoice-header {
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

        .invoice-details {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5mm;
            font-size: 10pt;
        }

        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 3mm;
        }

        .invoice-table th,
        .invoice-table td {
            border: 1px solid #000;
            padding: 5px;
            text-align: center;
            font-size: 10pt;
        }

        .invoice-table th {
            background-color: #f0f0f0;
        }

        .invoice-summary {
            font-size: 10pt;
        }

        .invoice-footer {
            position: absolute;
            bottom: 2mm;
            left: 0;
            right: 0;
            font-size: 8pt;
            text-align: center;
        }

        /* استایل دکمه ذخیره PNG */
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
            .invoice-container {
                border: none;
            }

            .save-png-btn {
                display: none;
            }

            @page {
                size: A5 portrait;
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
    <button class="save-png-btn" onclick="saveInvoiceAsPNG()">ذخیره به‌صورت PNG</button>

    <?php for ($page = 0; $page < $total_pages; $page++): ?>
        <div class="invoice-container" id="invoice-page-<?= $page + 1 ?>">
            <div class="invoice-header">
                <h3>فاکتور فروش</h3>
                <div class="page-number">صفحه <?= ($page + 1) ?> از <?= $total_pages ?></div>
            </div>

            <div class="invoice-details">
                <div>صورتحساب: <?= htmlspecialchars($order['customer_name']) ?></div>
                <div>تاریخ: <?= gregorian_to_jalali_format($order['work_date']) ?></div>
                <div>شماره فاکتور: <?= $order['order_id'] ?></div>
            </div>

            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>ردیف</th>
                        <th>نام محصول</th>
                        <th>قیمت فاکتور</th>
                        <th>تعداد</th>
                        <th>قیمت کل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $invoice_total = 0;
                    $page_items = $pages[$page];
                    foreach ($page_items as $index => $item):
                        $global_index = $index + ($page * $items_per_page);
                        $item_invoice_price = $invoice_prices[$global_index] ?? $item['total_price'];
                        $invoice_total += $item_invoice_price;
                        ?>
                        <tr>
                            <td><?= $global_index + 1 ?></td>
                            <td><?= htmlspecialchars($item['product_name']) ?></td>
                            <td><?= number_format($item_invoice_price, 0) ?> تومان</td>
                            <td><?= $item['quantity'] ?></td>
                            <td><?= number_format($item_invoice_price, 0) ?> تومان</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($postal_enabled && $page == $total_pages - 1): ?>
                        <tr>
                            <td><?= count($items) + 1 ?></td>
                            <td>ارسال پستی</td>
                            <td><?= number_format($postal_price, 0) ?> تومان</td>
                            <td>-</td>
                            <td><?= number_format($postal_price, 0) ?> تومان</td>
                        </tr>
                        <?php $invoice_total += $postal_price; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($page == $total_pages - 1): ?>
                <div class="invoice-summary">
                    <p>مبلغ کل فاکتور: <?= number_format($invoice_total, 0) ?> تومان</p>
                    <p>تخفیف: <?= number_format($order['discount'], 0) ?> تومان</p>
                    <p>مبلغ قابل پرداخت: <?= number_format($invoice_total - $order['discount'], 0) ?> تومان</p>
                </div>
            <?php endif; ?>

            <div class="invoice-footer">
                <hr>
                <p>فروشندگان: </p>
                <p>
                    <?= htmlspecialchars($order['partner1_name']) ?> - شماره تماس:
                    <?= htmlspecialchars($order['partner1_phone'] ?? 'نامشخص') ?> |
                    <?= htmlspecialchars($order['partner2_name']) ?> - شماره تماس:
                    <?= htmlspecialchars($order['partner2_phone'] ?? 'نامشخص') ?>
                </p>
            </div>
        </div>
    <?php endfor; ?>

    <script>
        // اطمینان از لود کامل فونت‌ها قبل از رندر
        document.fonts.ready.then(function () {
            // تابع ذخیره فاکتور به‌صورت PNG
            function saveInvoiceAsPNG() {
                const totalPages = <?= $total_pages ?>;
                const orderId = <?= $order_id ?>;

                for (let page = 1; page <= totalPages; page++) {
                    const invoiceContainer = document.getElementById(`invoice-page-${page}`);
                    html2canvas(invoiceContainer, {
                        scale: 2, // برای کیفیت بالاتر
                        useCORS: true,
                        backgroundColor: '#ffffff'
                    }).then(canvas => {
                        const link = document.createElement('a');
                        link.href = canvas.toDataURL('image/png');
                        link.download = `فاکتور_شماره_${orderId}_صفحه_${page}.png`;
                        link.click();
                    }).catch(error => {
                        console.error('Error saving PNG:', error);
                        alert('خطا در ذخیره تصویر فاکتور. لطفاً دوباره تلاش کنید.');
                    });
                }
            }

            // همچنان پرینت خودکار اجرا می‌شه
            // window.print();
        });
    </script>
</body>

</html>