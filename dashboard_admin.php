<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

require_once 'db.php';
require_once 'header.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

// تاریخ امروز
$today = date('Y-m-d');
$today_jalali = gregorian_to_jalali_format($today);

// ماه کاری جاری
$stmt_month = $pdo->query("SELECT work_month_id, start_date, end_date FROM Work_Months WHERE end_date <= CURDATE() ORDER BY work_month_id DESC LIMIT 1");
$month = $stmt_month->fetch(PDO::FETCH_ASSOC);
$start_month = $month['start_date'];
$end_month = $month['end_date'];
$work_month_id = $month['work_month_id'];

// نفرات امروز (جفت‌های همکار)
$stmt_partners = $pdo->prepare("
    SELECT p.partner_id, u1.full_name AS partner1_name, u2.full_name AS partner2_name
    FROM Work_Details wd
    JOIN Partners p ON wd.partner_id = p.partner_id
    JOIN Users u1 ON p.user_id1 = u1.user_id
    JOIN Users u2 ON p.user_id2 = u2.user_id
    WHERE wd.work_date = ?
");
$stmt_partners->execute([$today]);
$partners_today = $stmt_partners->fetchAll(PDO::FETCH_ASSOC);

// فروش کلی (روزانه، هفتگی، ماهانه)
// روزانه: فروش امروز
$stmt_daily_sales = $pdo->prepare("
    SELECT COALESCE(SUM(o.final_amount), 0) AS daily_sales
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    WHERE DATE(o.created_at) = ?
");
$stmt_daily_sales->execute([$today]);
$daily_sales = $stmt_daily_sales->fetchColumn();

// هفتگی: جمع فروش در روزهایی که هم‌روز با امروز هستند در ماه کاری
$day_of_week = date('w', strtotime($today)); // 0 (یکشنبه) تا 6 (شنبه)
$stmt_weekly_sales = $pdo->prepare("
    SELECT COALESCE(SUM(o.final_amount), 0) AS weekly_sales
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    WHERE wd.work_month_id = ?
    AND DAYOFWEEK(wd.work_date) = ?
");
$stmt_weekly_sales->execute([$work_month_id, ($day_of_week + 1)]); // DAYOFWEEK: 1 (یکشنبه) تا 7 (شنبه)
$weekly_sales = $stmt_weekly_sales->fetchColumn();

// ماهانه: جمع فروش کل در ماه کاری
$stmt_monthly_sales = $pdo->prepare("
    SELECT COALESCE(SUM(o.final_amount), 0) AS monthly_sales
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    WHERE wd.work_month_id = ?
");
$stmt_monthly_sales->execute([$work_month_id]);
$monthly_sales = $stmt_monthly_sales->fetchColumn();

// محصولات پر فروش (ماهانه)
$stmt_top_products = $pdo->prepare("
    SELECT oi.product_name, SUM(oi.quantity) AS total_quantity, SUM(oi.total_price) AS total_amount
    FROM Order_Items oi
    JOIN Orders o ON oi.order_id = o.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    WHERE wd.work_month_id = ?
    GROUP BY oi.product_name
    ORDER BY total_quantity DESC
    LIMIT 10
");
$stmt_top_products->execute([$work_month_id]);
$top_products = $stmt_top_products->fetchAll(PDO::FETCH_ASSOC);

// فروشندگان برتر (نفرات)
$stmt_top_sellers_individual = $pdo->prepare("
    SELECT u.user_id, u.full_name, SUM(o.final_amount) AS total_sales
    FROM Users u
    JOIN Partners p ON u.user_id IN (p.user_id1, p.user_id2)
    JOIN Work_Details wd ON p.partner_id = wd.partner_id
    JOIN Orders o ON o.work_details_id = wd.id
    WHERE wd.work_month_id = ?
    GROUP BY u.user_id, u.full_name
    ORDER BY total_sales DESC
");
$stmt_top_sellers_individual->execute([$work_month_id]);
$top_sellers_individual = $stmt_top_sellers_individual->fetchAll(PDO::FETCH_ASSOC);

// فروشندگان برتر (همکاران)
$stmt_top_sellers_partners = $pdo->prepare("
    SELECT p.partner_id, u1.full_name AS partner1_name, u2.full_name AS partner2_name, SUM(o.final_amount) AS total_sales
    FROM Partners p
    JOIN Users u1 ON p.user_id1 = u1.user_id
    JOIN Users u2 ON p.user_id2 = u2.user_id
    JOIN Work_Details wd ON p.partner_id = wd.partner_id
    JOIN Orders o ON o.work_details_id = wd.id
    WHERE wd.work_month_id = ?
    GROUP BY p.partner_id, u1.full_name, u2.full_name
    ORDER BY total_sales DESC
");
$stmt_top_sellers_partners->execute([$work_month_id]);
$top_sellers_partners = $stmt_top_sellers_partners->fetchAll(PDO::FETCH_ASSOC);

// آمار بدهکاران (فقط همکار1)
$stmt_debtors = $pdo->prepare("
    SELECT u.user_id, u.full_name, 
           COALESCE(SUM(o.final_amount), 0) AS total_amount,
           COALESCE(SUM(op.amount), 0) AS paid_amount,
           (COALESCE(SUM(o.final_amount), 0) - COALESCE(SUM(op.amount), 0)) AS debt
    FROM Users u
    JOIN Partners p ON u.user_id = p.user_id1
    JOIN Work_Details wd ON p.partner_id = wd.partner_id
    JOIN Orders o ON o.work_details_id = wd.id
    LEFT JOIN Order_Payments op ON o.order_id = op.order_id
    WHERE wd.work_month_id = ?
    GROUP BY u.user_id, u.full_name
    HAVING debt > 0
    ORDER BY debt ASC
");
$stmt_debtors->execute([$work_month_id]);
$debtors = $stmt_debtors->fetchAll(PDO::FETCH_ASSOC);

// آژانس (ماهانه)
$stmt_agency = $pdo->prepare("
    SELECT u.user_id, u.full_name, COUNT(*) AS agency_count
    FROM Work_Details wd
    JOIN Users u ON wd.agency_owner_id = u.user_id
    WHERE wd.work_month_id = ?
    GROUP BY u.user_id, u.full_name
    ORDER BY agency_count DESC
");
$stmt_agency->execute([$work_month_id]);
$agency_data = $stmt_agency->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid">
    <h2 class="text-center mb-4">داشبورد مدیر</h2>

    <div class="row">
        <!-- نفرات امروز -->
        <div class="col-12 col-md-6 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">نفرات امروز (<?= $today_jalali ?>)</h5>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if (empty($partners_today)): ?>
                            <span class="badge bg-secondary">هیچ‌کس امروز فعال نیست.</span>
                        <?php else: ?>
                            <?php foreach ($partners_today as $partner): ?>
                                <span class="badge bg-primary">
                                    <?= htmlspecialchars($partner['partner1_name']) ?> - <?= htmlspecialchars($partner['partner2_name']) ?>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- آمار فروش کلی -->
        <div class="col-12 col-md-6 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">آمار فروش کلی</h5>
                    <canvas id="salesChart"></canvas>
                    <script>
                        const ctxSales = document.getElementById('salesChart').getContext('2d');
                        new Chart(ctxSales, {
                            type: 'bar',
                            data: {
                                labels: ['روزانه', 'هفتگی', 'ماهانه'],
                                datasets: [{
                                    label: 'فروش (تومان)',
                                    data: [<?= $daily_sales ?? 0 ?>, <?= $weekly_sales ?? 0 ?>, <?= $monthly_sales ?? 0 ?>],
                                    backgroundColor: ['#007bff', '#28a745', '#dc3545'],
                                    borderColor: ['#007bff', '#28a745', '#dc3545'],
                                    borderWidth: 1
                                }]
                            },
                            options: {
                                scales: { y: { beginAtZero: true } },
                                responsive: true
                            }
                        });
                    </script>
                </div>
            </div>
        </div>

        <!-- محصولات پر فروش -->
        <div class="col-12 col-md-6 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">محصولات پر فروش (ماهانه)</h5>
                    <div class="mb-3">
                        <button class="btn btn-primary btn-sm me-2" onclick="sortTopProducts('quantity')">تعداد</button>
                        <button class="btn btn-secondary btn-sm" onclick="sortTopProducts('amount')">قیمت</button>
                    </div>
                    <div class="table-responsive" style="overflow-x: auto; width: 100%;">
                        <table id="topProductsTable" class="table table-light table-hover display nowrap" style="width: 100%; min-width: 600px;">
                            <thead>
                                <tr>
                                    <th>محصول</th>
                                    <th>تعداد</th>
                                    <th>مبلغ (تومان)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_products as $product): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($product['product_name']) ?></td>
                                        <td><?= $product['total_quantity'] ?></td>
                                        <td><?= number_format($product['total_amount'], 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (empty($top_products)): ?>
                        <div class="alert alert-warning text-center">محصولی یافت نشد.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- فروشندگان برتر -->
        <div class="col-12 col-md-6 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">فروشندگان برتر (ماهانه)</h5>
                    <div class="mb-3">
                        <button class="btn btn-primary btn-sm me-2" onclick="showSellersChart('individual')">نفرات</button>
                        <button class="btn btn-secondary btn-sm" onclick="showSellersChart('partners')">همکاران</button>
                    </div>
                    <canvas id="sellersChart"></canvas>
                    <script>
                        let sellersChart;
                        const ctxSellers = document.getElementById('sellersChart').getContext('2d');
                        const individualData = {
                            labels: [<?php foreach ($top_sellers_individual as $seller) { echo "'" . htmlspecialchars($seller['full_name']) . "',"; } ?>],
                            datasets: [{
                                label: 'فروش (تومان)',
                                data: [<?php foreach ($top_sellers_individual as $seller) { echo $seller['total_sales'] . ","; } ?>],
                                backgroundColor: [<?php foreach ($top_sellers_individual as $index => $seller) { echo "'hsl(" . ($index * 360 / count($top_sellers_individual)) . ", 70%, 50%)',"; } ?>],
                                borderWidth: 1
                            }]
                        };
                        const partnersData = {
                            labels: [<?php foreach ($top_sellers_partners as $partner) { echo "'" . htmlspecialchars($partner['partner1_name'] . ' - ' . $partner['partner2_name']) . "',"; } ?>],
                            datasets: [{
                                label: 'فروش (تومان)',
                                data: [<?php foreach ($top_sellers_partners as $partner) { echo $partner['total_sales'] . ","; } ?>],
                                backgroundColor: [<?php foreach ($top_sellers_partners as $index => $partner) { echo "'hsl(" . ($index * 360 / count($top_sellers_partners)) . ", 70%, 50%)',"; } ?>],
                                borderWidth: 1
                            }]
                        };

                        function showSellersChart(type) {
                            if (sellersChart) sellersChart.destroy();
                            sellersChart = new Chart(ctxSellers, {
                                type: 'bar',
                                data: type === 'individual' ? individualData : partnersData,
                                options: {
                                    indexAxis: 'y', // نمودار افقی
                                    scales: { x: { beginAtZero: true } },
                                    responsive: true,
                                    plugins: {
                                        tooltip: {
                                            callbacks: {
                                                label: function(context) {
                                                    return context.dataset.label + ': ' + new Intl.NumberFormat('fa-IR').format(context.raw) + ' تومان';
                                                }
                                            }
                                        }
                                    }
                                }
                            });
                        }
                        // نمایش اولیه (نفرات)
                        showSellersChart('individual');
                    </script>
                </div>
            </div>
        </div>

        <!-- آمار بدهکاران -->
        <div class="col-12 col-md-6 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">آمار بدهکاران</h5>
                    <ul class="list-group">
                        <?php foreach ($debtors as $debtor): ?>
                            <li class="list-group-item">
                                <?= htmlspecialchars($debtor['full_name']) ?> - بدهی: <?= number_format($debtor['debt'], 0) ?> تومان
                            </li>
                        <?php endforeach; ?>
                        <?php if (empty($debtors)): ?>
                            <li class="list-group-item">بدهی‌ای یافت نشد.</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- آژانس -->
        <div class="col-12 col-md-6 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">آژانس (ماهانه)</h5>
                    <canvas id="agencyChart"></canvas>
                    <script>
                        const ctxAgency = document.getElementById('agencyChart').getContext('2d');
                        new Chart(ctxAgency, {
                            type: 'bar',
                            data: {
                                labels: [<?php foreach ($agency_data as $agency) { echo "'" . htmlspecialchars($agency['full_name']) . "',"; } ?>],
                                datasets: [{
                                    label: 'تعداد آژانس',
                                    data: [<?php foreach ($agency_data as $agency) { echo $agency['agency_count'] . ","; } ?>],
                                    backgroundColor: [<?php foreach ($agency_data as $index => $agency) { echo "'hsl(" . ($index * 360 / count($agency_data)) . ", 70%, 50%)',"; } ?>],
                                    borderWidth: 1
                                }]
                            },
                            options: {
                                indexAxis: 'y', // نمودار افقی
                                scales: { x: { beginAtZero: true } },
                                responsive: true,
                                plugins: {
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                return context.dataset.label + ': ' + context.raw;
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    </script>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* اطمینان از RTL بودن جدول */
    #topProductsTable {
        direction: rtl !important;
    }

    /* تنظیمات برای دیتاتیبل */
    #topProductsTable_wrapper {
        width: 100%;
        overflow-x: auto;
    }
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    $(document).ready(function () {
        const topProductsTable = $('#topProductsTable').DataTable({
            "pageLength": 10,
            "scrollX": true,
            "scrollCollapse": true,
            "paging": true,
            "autoWidth": true,
            "ordering": true,
            "responsive": false,
            "language": {
                "decimal": "",
                "emptyTable": "داده‌ای در جدول وجود ندارد",
                "info": "نمایش _START_ تا _END_ از _TOTAL_ ردیف",
                "infoEmpty": "نمایش 0 تا 0 از 0 ردیف",
                "infoFiltered": "(فیلتر شده از _MAX_ ردیف کل)",
                "lengthMenu": "نمایش _MENU_ ردیف",
                "loadingRecords": "در حال بارگذاری...",
                "processing": "در حال پردازش...",
                "search": "جستجو:",
                "zeroRecords": "هیچ ردیف منطبقی یافت نشد",
                "paginate": {
                    "first": "اولین",
                    "last": "آخرین",
                    "next": "بعدی",
                    "previous": "قبلی"
                }
            },
            "columnDefs": [
                { "targets": "_all", "className": "text-center" }
            ]
        });

        window.sortTopProducts = function(type) {
            topProductsTable.order([type === 'quantity' ? 1 : 2, 'desc']).draw();
        };
    });
</script>

<?php
require_once 'footer.php';
?>