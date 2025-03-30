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
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

// تابع برای دریافت سال شمسی از تاریخ میلادی
function get_jalali_year($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    $gy = (int)$gy;
    $gm = (int)$gm;
    $gd = (int)$gd;
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return $jy;
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

// بررسی نقش کاربر
$current_user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user_role = $stmt->fetchColumn();

// دریافت همه ماه‌ها برای استخراج سال‌های شمسی
$stmt = $pdo->query("SELECT start_date FROM Work_Months ORDER BY start_date DESC");
$months = $stmt->fetchAll(PDO::FETCH_ASSOC);

$years_jalali = [];
$year_mapping = [];
foreach ($months as $month) {
    $jalali_year = get_jalali_year($month['start_date']);
    $gregorian_year = (int)date('Y', strtotime($month['start_date']));
    if (!in_array($jalali_year, $years_jalali)) {
        $years_jalali[] = $jalali_year;
        $year_mapping[$jalali_year] = [
            'start_date' => "$gregorian_year-03-21",
            'end_date' => ($gregorian_year + 1) . "-03-21"
        ];
        if ($jalali_year == 1404) {
            $year_mapping[$jalali_year]['start_date'] = "2025-03-21";
            $year_mapping[$jalali_year]['end_date'] = "2026-03-21";
        } elseif ($jalali_year == 1403) {
            $year_mapping[$jalali_year]['start_date'] = "2024-03-20";
            $year_mapping[$jalali_year]['end_date'] = "2025-03-21";
        }
    }
}
sort($years_jalali, SORT_NUMERIC);
$years_jalali = array_reverse($years_jalali);

error_log("report-bill.php: Available years (jalali): " . implode(", ", $years_jalali));

// تنظیم پیش‌فرض به جدیدترین سال شمسی
$current_jalali_year = get_jalali_year(date('Y-m-d'));
$selected_year_jalali = $_GET['year'] ?? (in_array($current_jalali_year, $years_jalali) ? $current_jalali_year : (!empty($years_jalali) ? $years_jalali[0] : null));

// دریافت پارامترهای انتخاب‌شده
$selected_month = $_GET['work_month_id'] ?? '';
$display_filter = $_GET['display_filter'] ?? 'all';
$partner_role_filter = $_GET['partner_role'] ?? 'all';

// متغیرهای جمع کل (برای نمایش اولیه)
$total_invoices = 0;
$total_payments = 0;
$total_debt = 0;
?>

<div class="container-fluid mt-5">
    <h5 class="card-title mb-4">گزارش بدهی</h5>

    <!-- فیلترها -->
    <div class="mb-4">
        <div class="row g-3">
            <div class="col-md-3">
                <label for="year" class="form-label">سال</label>
                <select name="year" id="year" class="form-select">
                    <option value="">همه</option>
                    <?php foreach ($years_jalali as $year): ?>
                        <option value="<?= $year ?>" <?= $selected_year_jalali == $year ? 'selected' : '' ?>>
                            <?= $year ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="work_month_id" class="form-label">ماه کاری</label>
                <div class="input-group">
                    <select name="work_month_id" id="work_month_id" class="form-select">
                        <option value="">انتخاب ماه</option>
                        <!-- ماه‌ها با AJAX بارگذاری می‌شن -->
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <label for="partner_role" class="form-label">نقش همکار</label>
                <select name="partner_role" id="partner_role" class="form-select">
                    <option value="all" <?= $partner_role_filter === 'all' ? 'selected' : '' ?>>همه</option>
                    <option value="partner1" <?= $partner_role_filter === 'partner1' ? 'selected' : '' ?>>سرگروه</option>
                    <option value="partner2" <?= $partner_role_filter === 'partner2' ? 'selected' : '' ?>>زیرگروه</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="display_filter" class="form-label">نمایش</label>
                <select name="display_filter" id="display_filter" class="form-select">
                    <option value="all" <?= $display_filter === 'all' ? 'selected' : '' ?>>همه</option>
                    <option value="debtors" <?= $display_filter === 'debtors' ? 'selected' : '' ?>>بدهکاران</option>
                </select>
            </div>
        </div>
    </div>

    <!-- اطلاعات اضافی -->
    <div id="summary" class="mb-4" style="display: <?= $selected_month ? 'block' : 'none' ?>;">
        <p>جمع کل فاکتورها: <strong id="total_invoices"><?= number_format($total_invoices, 0) ?> تومان</strong></p>
        <p>مجموع پرداختی‌ها: <strong id="total_payments"><?= number_format($total_payments, 0) ?> تومان</strong></p>
        <p>مانده بدهی‌ها: <strong id="total_debt"><?= number_format($total_debt, 0) ?> تومان</strong></p>
    </div>

    <!-- جدول فاکتورها (دیتاتیبل) -->
    <div class="table-responsive" id="bills-table">
        <table id="billsTable" class="table table-light table-hover display nowrap" style="width: 100%; min-width: 800px;">
            <thead>
                <tr>
                    <th>تاریخ</th>
                    <th>نام مشتری</th>
                    <?php if ($user_role === 'seller' || $user_role === 'admin'): ?>
                        <th>همکاران</th>
                    <?php endif; ?>
                    <th>مبلغ فاکتور</th>
                    <th>مانده بدهی</th>
                </tr>
            </thead>
            <tbody>
                <!-- داده‌ها توسط دیتاتیبل از طریق AJAX لود می‌شن -->
            </tbody>
        </table>
    </div>
</div>

<style>
    #billsTable {
        direction: rtl !important;
    }
    #billsTable_wrapper {
        width: 100%;
        overflow-x: auto;
    }
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    $(document).ready(function() {
        let billsTable = $('#billsTable').DataTable({
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
            ],
            "ajax": {
                url: 'get_bills.php',
                type: 'GET',
                data: function(d) {
                    d.action = 'get_bill_report';
                    d.year = $('#year').val();
                    d.work_month_id = $('#work_month_id').val();
                    d.display_filter = $('#display_filter').val();
                    d.partner_role = $('#partner_role').val();
                },
                dataSrc: function(json) {
                    if (json.success) {
                        $('#summary').show();
                        $('#total_invoices').text(json.total_invoices ? new Intl.NumberFormat('fa-IR').format(json.total_invoices) + ' تومان' : '0 تومان');
                        $('#total_payments').text(json.total_payments ? new Intl.NumberFormat('fa-IR').format(json.total_payments) + ' تومان' : '0 تومان');
                        $('#total_debt').text(json.total_debt ? new Intl.NumberFormat('fa-IR').format(json.total_debt) + ' تومان' : '0 تومان');
                        return json.data || [];
                    } else {
                        $('#summary').hide();
                        return [];
                    }
                }
            },
            "columns": [
                { "data": "order_date" },
                { "data": "customer_name" },
                <?php if ($user_role === 'seller' || $user_role === 'admin'): ?>
                { "data": "partners" },
                <?php endif; ?>
                { "data": "invoice_amount" },
                { "data": "remaining_debt" }
            ]
        });

        function loadMonths(year) {
            console.log('Loading months for year:', year);
            if (!year) {
                $('#work_month_id').html('<option value="">انتخاب ماه</option>');
                $('#summary').hide();
                billsTable.ajax.reload();
                return;
            }
            $.ajax({
                url: 'get_months_for_bills.php',
                type: 'POST',
                data: { year: year },
                success: function(response) {
                    console.log('Months response:', response);
                    $('#work_month_id').html(response);
                    $('#summary').hide();
                    billsTable.ajax.reload();
                },
                error: function(xhr, status, error) {
                    console.error('Error loading months:', error);
                    $('#work_month_id').html('<option value="">خطا در بارگذاری ماه‌ها</option>');
                    $('#summary').hide();
                    billsTable.ajax.reload();
                }
            });
        }

        const initial_year = $('#year').val();
        if (initial_year) {
            loadMonths(initial_year);
        } else {
            billsTable.ajax.reload();
        }

        $('#year').on('change', function() {
            loadMonths($(this).val());
        });

        $('#work_month_id').on('change', function() {
            billsTable.ajax.reload();
        });

        $('#partner_role').on('change', function() {
            billsTable.ajax.reload();
        });

        $('#display_filter').on('change', function() {
            billsTable.ajax.reload();
        });
    });
</script>

<?php require_once 'footer.php'; ?>