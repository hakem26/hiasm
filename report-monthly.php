<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    if (!$gregorian_date) return "نامشخص";
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

// تابع برای دریافت نام ماه شمسی
function get_jalali_month_name($month) {
    $month_names = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
        4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
        10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    ];
    return $month_names[$month] ?? '';
}

// تابع تبدیل سال میلادی به سال شمسی
function gregorian_year_to_jalali($gregorian_year) {
    list($jy, $jm, $jd) = gregorian_to_jalali($gregorian_year, 1, 1);
    return $jy;
}

// بررسی نقش کاربر
$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];

// دریافت سال‌های موجود از دیتابیس (میلادی)
$stmt = $pdo->query("SELECT DISTINCT YEAR(start_date) AS year FROM Work_Months ORDER BY year DESC");
$years_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
$years = array_column($years_db, 'year');

// دریافت سال جاری (میلادی) به‌عنوان پیش‌فرض
$current_year = date('Y');

// دریافت سال انتخاب‌شده (میلادی)
$selected_year = $_GET['year'] ?? (in_array($current_year, $years) ? $current_year : (!empty($years) ? $years[0] : null));

// دریافت گزارش‌های ماهانه (برای بارگذاری اولیه)
$reports = [];
if ($selected_year) {
    $stmt = $pdo->prepare("
        SELECT wm.work_month_id, wm.start_date, wm.end_date, p.partner_id, u1.full_name AS user1_name, u2.full_name AS user2_name,
               COUNT(DISTINCT wd.work_date) AS days_worked,
               (SELECT COUNT(DISTINCT work_date) FROM Work_Details WHERE work_month_id = wm.work_month_id) AS total_days,
               COALESCE(SUM(o.total_amount), 0) AS total_sales
        FROM Work_Months wm
        JOIN Work_Details wd ON wm.work_month_id = wd.work_month_id
        JOIN Partners p ON wd.partner_id = p.partner_id
        LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
        LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
        LEFT JOIN Orders o ON o.work_details_id = wd.id
        WHERE YEAR(wm.start_date) = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
        GROUP BY wm.work_month_id, p.partner_id
        ORDER BY wm.start_date DESC
    ");
    $stmt->execute([$selected_year, $current_user_id, $current_user_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $start_date = gregorian_to_jalali_format($row['start_date']);
        list($jy, $jm, $jd) = explode('/', $start_date);
        $month_name = get_jalali_month_name((int)$jm) . ' ' . $jy;

        $partner_name = ($row['user1_name'] ?? 'نامشخص') . ' و ' . ($row['user2_name'] ?? 'نامشخص');
        $total_sales = $row['total_sales'] ?? 0;
        $status = ($row['days_worked'] == $row['total_days']) ? 'تکمیل' : 'ناقص';

        $reports[] = [
            'work_month_id' => $row['work_month_id'],
            'month_name' => $month_name,
            'partner_name' => $partner_name,
            'partner_id' => $row['partner_id'],
            'total_sales' => $total_sales,
            'status' => $status
        ];
    }
}
?>

<!-- فقط استایل اصلی DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">

<style>
/* جلوگیری از اسکرول افقی صفحه اصلی */
body, .container-fluid {
    overflow-x: hidden !important;
}

/* تنظیمات div اطراف جدول */
.table-responsive {
    width: 100%;
    overflow-x: auto !important; /* اسکرول افقی فقط برای جدول */
    overflow-y: visible; /* جلوگیری از اسکرول عمودی غیرضروری */
    -webkit-overflow-scrolling: touch; /* اسکرول روان در دستگاه‌های لمسی */
}

/* وسط‌چین کردن هدر جدول */
table.dataTable thead th,
table.dataTable thead td {
    text-align: center !important;
}

/* وسط‌چین کردن محتوای ستون‌ها */
table.dataTable tbody td {
    text-align: center !important;
    white-space: nowrap !important; /* جلوگیری از چند خطی شدن */
    overflow: hidden;
    text-overflow: ellipsis; /* نمایش ... برای متن طولانی */
}
</style>

<div class="container-fluid mt-5">
    <h5 class="card-title mb-4">گزارشات ماهانه</h5>

    <!-- فرم فیلترها -->
    <div class="mb-4">
        <div class="row g-3">
            <div class="col-md-3">
                <label for="year" class="form-label">سال</label>
                <select name="year" id="year" class="form-select">
                    <option value="">همه</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                            <?= gregorian_year_to_jalali($year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="work_month_id" class="form-label">ماه کاری</label>
                <select name="work_month_id" id="work_month_id" class="form-select">
                    <option value="">انتخاب ماه</option>
                    <!-- ماه‌ها اینجا با AJAX بارگذاری می‌شن -->
                </select>
            </div>
            <div class="col-md-3">
                <label for="user_id" class="form-label">همکار</label>
                <select name="user_id" id="user_id" class="form-select">
                    <option value="">انتخاب همکار</option>
                    <!-- همکاران اینجا با AJAX بارگذاری می‌شن -->
                </select>
            </div>
        </div>
    </div>

    <!-- جدول گزارشات -->
    <div class="table-responsive" id="reports-table">
        <table id="reportsTable" class="table table-light table-hover display" style="width:100%;">
            <thead>
                <tr>
                    <th>ماه کاری</th>
                    <th>نام همکار</th>
                    <th>مجموع فروش</th>
                    <th>وضعیت</th>
                    <th>مشاهده</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reports)): ?>
                    <tr>
                        <td colspan="5" class="text-center">گزارشی یافت نشد.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reports as $report): ?>
                        <tr>
                            <td><?= htmlspecialchars($report['month_name']) ?></td>
                            <td><?= htmlspecialchars($report['partner_name']) ?></td>
                            <td><?= number_format($report['total_sales'], 0) ?> تومان</td>
                            <td><?= $report['status'] ?></td>
                            <td>
                                <a href="print-report-monthly.php?work_month_id=<?= $report['work_month_id'] ?>&partner_id=<?= $report['partner_id'] ?>" class="btn btn-info btn-sm">
                                    <i class="fas fa-eye"></i> مشاهده
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    $(document).ready(function() {
        // تنظیم DataTables برای جدول گزارشات
        $('#reportsTable').DataTable({
            "pageLength": 10,           // 10 ردیف در هر صفحه
            "scrollX": true,            // فعال کردن اسکرول افقی
            "paging": true,             // فعال کردن صفحه‌بندی
            "autoWidth": false,         // غیرفعال کردن تنظیم خودکار عرض
            "ordering": true,           // فعال کردن مرتب‌سازی ستون‌ها
            "responsive": false,        // غیرفعال کردن حالت ریسپانسیو
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
                { "targets": "_all", "className": "text-center" }, // وسط‌چین کردن همه ستون‌ها
                { "targets": 0, "width": "160px" }, // ماه کاری
                { "targets": 1, "width": "160px" }, // نام همکار
                { "targets": 2, "width": "160px" }, // مجموع فروش
                { "targets": 3, "width": "160px" }, // وضعیت
                { "targets": 4, "width": "160px" }  // مشاهده
            ]
        });

        // تابع برای بارگذاری ماه‌ها بر اساس سال
        function loadMonths(year) {
            console.log('Loading months for year:', year);
            if (!year) {
                $('#work_month_id').html('<option value="">انتخاب ماه</option>');
                $('#user_id').html('<option value="">انتخاب همکار</option>');
                return;
            }
            $.ajax({
                url: 'get_months.php',
                type: 'POST',
                data: { year: year, user_id: <?= json_encode($current_user_id) ?> },
                success: function(response) {
                    console.log('Months response:', response);
                    $('#work_month_id').html(response);
                },
                error: function(xhr, status, error) {
                    console.error('Error loading months:', error);
                    $('#work_month_id').html('<option value="">خطا در بارگذاری ماه‌ها</option>');
                }
            });
        }

        // تابع برای بارگذاری همکاران بر اساس ماه
        function loadPartners(month_id) {
            console.log('Loading partners for month:', month_id);
            if (!month_id) {
                $('#user_id').html('<option value="">انتخاب همکار</option>');
                return;
            }
            $.ajax({
                url: 'get_partners.php',
                type: 'POST',
                data: { month_id: month_id, user_id: <?= json_encode($current_user_id) ?> },
                success: function(response) {
                    console.log('Partners response:', response);
                    $('#user_id').html(response);
                },
                error: function(xhr, status, error) {
                    console.error('Error loading partners:', error);
                    $('#user_id').html('<option value="">خطا در بارگذاری همکاران</option>');
                }
            });
        }

        // تابع برای بارگذاری گزارش‌ها
        function loadReports() {
            console.log('Loading reports...');
            const year = $('#year').val();
            const work_month_id = $('#work_month_id').val();
            const user_id = $('#user_id').val();

            $.ajax({
                url: 'get_reports.php',
                type: 'GET',
                data: {
                    year: year,
                    work_month_id: work_month_id,
                    user_id: user_id,
                    report_type: 'monthly'
                },
                dataType: 'json',
                success: function(response) {
                    console.log('Reports response (raw):', response);
                    try {
                        if (response.success && typeof response.html === 'string' && response.html.trim().length > 0) {
                            console.log('Rendering HTML:', response.html);
                            $('#reports-table').html(response.html);
                            // دوباره DataTable را روی جدول جدید اعمال کن
                            $('#reportsTable').DataTable({
                                "destroy": true, // تخریب جدول قبلی
                                "pageLength": 10,
                                "scrollX": true,
                                "paging": true,
                                "autoWidth": false,
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
                                    { "targets": "_all", "className": "text-center" },
                                    { "targets": 0, "width": "160px" },
                                    { "targets": 1, "width": "160px" },
                                    { "targets": 2, "width": "160px" },
                                    { "targets": 3, "width": "160px" },
                                    { "targets": 4, "width": "160px" }
                                ]
                            });
                        } else {
                            throw new Error('HTML نامعتبر یا خالی است: ' + (response.message || 'داده‌ای برای نمایش وجود ندارد'));
                        }
                    } catch (e) {
                        console.error('Error rendering reports:', e);
                        $('#reports-table').html('<div class="alert alert-danger text-center">خطا در نمایش گزارش‌ها: ' + e.message + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', { status: status, error: error, response: xhr.responseText });
                    $('#reports-table').html('<div class="alert alert-danger text-center">خطایی در بارگذاری گزارش‌ها رخ داد: ' + error + '</div>');
                }
            });
        }

        // تابع برای بارگذاری همه فیلترها
        function loadFilters() {
            const year = $('#year').val();
            loadMonths(year);
            loadReports();
        }

        // بارگذاری اولیه
        const initial_year = $('#year').val();
        if (initial_year) {
            loadMonths(initial_year);
        }
        loadReports();

        // رویدادهای تغییر
        $('#year').on('change', function() {
            loadFilters();
        });

        $('#work_month_id').on('change', function() {
            const month_id = $(this).val();
            loadPartners(month_id);
            loadReports();
        });

        $('#user_id').on('change', function() {
            loadReports();
        });
    });
</script>

<?php require_once 'footer.php'; ?>