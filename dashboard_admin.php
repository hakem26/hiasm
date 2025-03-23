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

// تابع برای دریافت نام ماه شمسی
function get_jalali_month_name($month)
{
    $month_names = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
        4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
        10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    ];
    return $month_names[$month] ?? '';
}

// تاریخ امروز
$today = date('Y-m-d');
$today_jalali = gregorian_to_jalali_format($today);

// دریافت 12 ماه کاری آخر
$stmt_months = $pdo->query("SELECT work_month_id, start_date, end_date FROM Work_Months ORDER BY start_date DESC LIMIT 12");
$work_months = $stmt_months->fetchAll(PDO::FETCH_ASSOC);

// پیدا کردن ماه کاری که شامل تاریخ امروز است
$selected_work_month_id = null;
foreach ($work_months as $month) {
    if ($today >= $month['start_date'] && $today <= $month['end_date']) {
        $selected_work_month_id = $month['work_month_id'];
        $start_month = $month['start_date'];
        $end_month = $month['end_date'];
        break;
    }
}

// اگر ماه کاری برای تاریخ امروز پیدا نشد، آخرین ماه کاری را انتخاب کن
if (!$selected_work_month_id && !empty($work_months)) {
    $selected_work_month_id = $work_months[0]['work_month_id'];
    $start_month = $work_months[0]['start_date'];
    $end_month = $work_months[0]['end_date'];
}

// اگر هیچ ماه کاری‌ای وجود نداشت
$no_work_month_message = null;
if (empty($work_months)) {
    $no_work_month_message = "هیچ ماه کاری‌ای در دیتابیس ثبت نشده است.";
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">داشبورد مدیر</h2>
        <?php if ($no_work_month_message): ?>
            <div class="alert alert-warning mb-0"><?= $no_work_month_message ?></div>
        <?php else: ?>
            <div class="dropdown">
                <button class="btn btn-primary dropdown-toggle" type="button" id="workMonthDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    انتخاب ماه کاری
                </button>
                <ul class="dropdown-menu" aria-labelledby="workMonthDropdown">
                    <?php foreach ($work_months as $month): ?>
                        <?php
                        list($gy, $gm, $gd) = explode('-', $month['start_date']);
                        list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
                        $month_name = get_jalali_month_name($jm) . ' ' . $jy;
                        ?>
                        <li>
                            <a class="dropdown-item <?= $month['work_month_id'] == $selected_work_month_id ? 'active' : '' ?>" 
                               href="#" 
                               data-work-month-id="<?= $month['work_month_id'] ?>">
                                <?= $month_name ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$no_work_month_message): ?>
        <div class="row">
            <!-- نفرات امروز -->
            <div class="col-12 col-md-6 mb-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">نفرات امروز (<?= $today_jalali ?>)</h5>
                        <div id="partnersToday" class="d-flex flex-wrap gap-2"></div>
                    </div>
                </div>
            </div>

            <!-- آمار فروش کلی -->
            <div class="col-12 col-md-6 mb-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">آمار فروش کلی</h5>
                        <canvas id="salesChart"></canvas>
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
                                <tbody></tbody>
                            </table>
                        </div>
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
                    </div>
                </div>
            </div>

            <!-- آمار بدهکاران -->
            <div class="col-12 col-md-6 mb-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">آمار بدهکاران</h5>
                        <ul id="debtorsList" class="list-group"></ul>
                    </div>
                </div>
            </div>

            <!-- آژانس -->
            <div class="col-12 col-md-6 mb-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">آژانس (ماهانه)</h5>
                        <canvas id="agencyChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let salesChart, sellersChart, agencyChart, topProductsTable;

    $(document).ready(function () {
        // تنظیم دیتاتیبل برای محصولات پر فروش
        topProductsTable = $('#topProductsTable').DataTable({
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

        // تابع برای مرتب‌سازی محصولات
        window.sortTopProducts = function(type) {
            topProductsTable.order([type === 'quantity' ? 1 : 2, 'desc']).draw();
        };

        // لود اولیه داده‌ها
        loadDashboardData(<?= $selected_work_month_id ?? 'null' ?>);

        // رویداد تغییر ماه کاری
        $('.dropdown-item').on('click', function(e) {
            e.preventDefault();
            const workMonthId = $(this).data('work-month-id');
            $('.dropdown-item').removeClass('active');
            $(this).addClass('active');
            $('#workMonthDropdown').text($(this).text());
            loadDashboardData(workMonthId);
        });
    });

    function loadDashboardData(workMonthId) {
        if (!workMonthId) return;

        $.ajax({
            url: 'fetch_dashboard_data.php',
            type: 'POST',
            data: { work_month_id: workMonthId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // نفرات امروز
                    const partnersToday = $('#partnersToday');
                    partnersToday.empty();
                    if (response.partners_today.length === 0) {
                        partnersToday.append('<span class="badge bg-secondary">هیچ‌کس امروز فعال نیست.</span>');
                    } else {
                        response.partners_today.forEach(partner => {
                            partnersToday.append(
                                `<span class="badge bg-primary">${partner.partner1_name} - ${partner.partner2_name}</span>`
                            );
                        });
                    }

                    // آمار فروش کلی
                    if (salesChart) salesChart.destroy();
                    const ctxSales = document.getElementById('salesChart').getContext('2d');
                    salesChart = new Chart(ctxSales, {
                        type: 'bar',
                        data: {
                            labels: ['روزانه', 'هفتگی', 'ماهانه'],
                            datasets: [{
                                label: 'فروش (تومان)',
                                data: [response.daily_sales, response.weekly_sales, response.monthly_sales],
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

                    // محصولات پر فروش
                    topProductsTable.clear();
                    if (response.top_products.length === 0) {
                        topProductsTable.rows.add([{
                            0: 'محصولی یافت نشد.',
                            1: '',
                            2: ''
                        }]);
                    } else {
                        response.top_products.forEach(product => {
                            topProductsTable.rows.add([[
                                product.product_name,
                                product.total_quantity,
                                new Intl.NumberFormat('fa-IR').format(product.total_amount)
                            ]]);
                        });
                    }
                    topProductsTable.draw();

                    // فروشندگان برتر
                    if (sellersChart) sellersChart.destroy();
                    const ctxSellers = document.getElementById('sellersChart').getContext('2d');
                    const individualData = {
                        labels: response.top_sellers_individual.map(seller => seller.full_name),
                        datasets: [{
                            label: 'فروش (تومان)',
                            data: response.top_sellers_individual.map(seller => seller.total_sales),
                            backgroundColor: response.top_sellers_individual.map((_, index) => `hsl(${index * 360 / response.top_sellers_individual.length}, 70%, 50%)`),
                            borderWidth: 1
                        }]
                    };
                    const partnersData = {
                        labels: response.top_sellers_partners.map(partner => `${partner.partner1_name} - ${partner.partner2_name}`),
                        datasets: [{
                            label: 'فروش (تومان)',
                            data: response.top_sellers_partners.map(partner => partner.total_sales),
                            backgroundColor: response.top_sellers_partners.map((_, index) => `hsl(${index * 360 / response.top_sellers_partners.length}, 70%, 50%)`),
                            borderWidth: 1
                        }]
                    };
                    window.showSellersChart = function(type) {
                        if (sellersChart) sellersChart.destroy();
                        sellersChart = new Chart(ctxSellers, {
                            type: 'bar',
                            data: type === 'individual' ? individualData : partnersData,
                            options: {
                                indexAxis: 'y',
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
                    };
                    showSellersChart('individual');

                    // آمار بدهکاران
                    const debtorsList = $('#debtorsList');
                    debtorsList.empty();
                    if (response.debtors.length === 0) {
                        debtorsList.append('<li class="list-group-item">بدهی‌ای یافت نشد.</li>');
                    } else {
                        response.debtors.forEach(debtor => {
                            debtorsList.append(
                                `<li class="list-group-item">${debtor.full_name} - بدهی: ${new Intl.NumberFormat('fa-IR').format(debtor.debt)} تومان</li>`
                            );
                        });
                    }

                    // آژانس
                    if (agencyChart) agencyChart.destroy();
                    const ctxAgency = document.getElementById('agencyChart').getContext('2d');
                    agencyChart = new Chart(ctxAgency, {
                        type: 'bar',
                        data: {
                            labels: response.agency_data.map(agency => agency.full_name),
                            datasets: [{
                                label: 'تعداد آژانس',
                                data: response.agency_data.map(agency => agency.agency_count),
                                backgroundColor: response.agency_data.map((_, index) => `hsl(${index * 360 / response.agency_data.length}, 70%, 50%)`),
                                borderWidth: 1
                            }]
                        },
                        options: {
                            indexAxis: 'y',
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
                } else {
                    alert('خطا در بارگذاری داده‌ها: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', xhr.responseText, status, error);
                alert('خطایی در ارتباط با سرور رخ داد.');
            }
        });
    }
</script>

<?php
require_once 'footer.php';
?>