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
function gregorian_to_jalali($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return [$jy, $jm, $jd];
}

function gregorian_to_jalali_format($gregorian_date) {
    $jalali_date = gregorian_to_jalali($gregorian_date);
    return sprintf("%04d/%02d/%02d", $jalali_date[0], $jalali_date[1], $jalali_date[2]);
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
$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];

// دریافت همه ماه‌ها برای استخراج سال‌های شمسی
$stmt = $pdo->query("SELECT start_date FROM Work_Months ORDER BY start_date DESC");
$months = $stmt->fetchAll(PDO::FETCH_ASSOC);

$years = [];
foreach ($months as $month) {
    $jalali_date = gregorian_to_jalali($month['start_date']);
    $jalali_year = $jalali_date[0]; // سال شمسی
    if (!in_array($jalali_year, $years)) {
        $years[] = $jalali_year;
    }
}
sort($years, SORT_NUMERIC);
$years = array_reverse($years); // مرتب‌سازی نزولی

// دیباگ سال‌ها
error_log("report-monthly.php: Available years: " . implode(", ", $years));

// تنظیم پیش‌فرض به جدیدترین سال
$selected_year = $_GET['year'] ?? null;
if (!$selected_year) {
    $selected_year = $years[0] ?? gregorian_to_jalali(date('Y-m-d'))[0]; // جدیدترین سال یا سال جاری
}

error_log("report-monthly.php: Selected year: $selected_year");

// برای بارگذاری اولیه، گزارش‌ها رو فعلاً خالی می‌ذاریم تا بعد از اصلاح get_reports.php پر بشن
$reports = [];
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>گزارشات ماهانه</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <div class="container-fluid mt-5">
        <h5 class="card-title mb-4">گزارشات ماهانه</h5>

        <!-- فرم فیلترها -->
        <div class="mb-4">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="year" class="form-label">سال</label>
                    <select name="year" id="year" class="form-select">
                        <?php foreach ($years as $year): ?>
                            <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                                <?= $year ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="work_month_id" class="form-label">ماه کاری</label>
                    <select name="work_month_id" id="work_month_id" class="form-select">
                        <option value="">انتخاب ماه</option>
                        <!-- ماه‌ها با AJAX بارگذاری می‌شن -->
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="user_id" class="form-label">همکار</label>
                    <select name="user_id" id="user_id" class="form-select">
                        <option value="">انتخاب همکار</option>
                        <!-- همکاران با AJAX بارگذاری می‌شن -->
                    </select>
                </div>
            </div>
        </div>

        <!-- جدول گزارشات -->
        <div class="table-responsive" id="reports-table">
            <table class="table table-light">
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
                    <tr>
                        <td colspan="5" class="text-center">لطفاً فیلترها را انتخاب کنید.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // تابع برای بارگذاری ماه‌ها بر اساس سال
            function loadMonths(year) {
                console.log('Loading months for year:', year);
                if (!year) {
                    $('#work_month_id').html('<option value="">انتخاب ماه</option>');
                    $('#user_id').html('<option value="">انتخاب همکار</option>');
                    loadReports();
                    return;
                }
                $.ajax({
                    url: 'get_months.php',
                    type: 'POST',
                    data: { year: year, user_id: <?= json_encode($current_user_id) ?> },
                    success: function(response) {
                        console.log('Months response:', response);
                        $('#work_month_id').html(response);
                        const selectedMonth = $('#work_month_id').val();
                        if (selectedMonth) {
                            loadPartners(selectedMonth);
                        } else {
                            $('#user_id').html('<option value="">انتخاب همکار</option>');
                            loadReports();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading months:', error);
                        $('#work_month_id').html('<option value="">خطا در بارگذاری ماه‌ها</option>');
                        loadReports();
                    }
                });
            }

            // تابع برای بارگذاری همکاران بر اساس ماه
            function loadPartners(month_id) {
                console.log('Loading partners for month:', month_id);
                if (!month_id) {
                    $('#user_id').html('<option value="">انتخاب همکار</option>');
                    loadReports();
                    return;
                }
                $.ajax({
                    url: 'get_partners.php',
                    type: 'POST',
                    data: { month_id: month_id, user_id: <?= json_encode($current_user_id) ?> },
                    success: function(response) {
                        console.log('Partners response:', response);
                        $('#user_id').html(response);
                        loadReports();
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading partners:', error);
                        $('#user_id').html('<option value="">خطا در بارگذاری همکاران</option>');
                        loadReports();
                    }
                });
            }

            // تابع برای بارگذاری گزارش‌ها
            function loadReports() {
                console.log('Loading reports...');
                const year = $('#year').val();
                const work_month_id = $('#work_month_id').val();
                const user_id = $('#user_id').val();
                console.log('Report params:', { year: year, work_month_id: work_month_id, user_id: user_id });

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

            // بارگذاری اولیه
            const initial_year = $('#year').val();
            if (initial_year) {
                loadMonths(initial_year);
            } else {
                loadReports();
            }

            // رویدادهای تغییر
            $('#year').on('change', function() {
                const year = $(this).val();
                loadMonths(year);
            });

            $('#work_month_id').on('change', function() {
                const month_id = $(this).val();
                loadPartners(month_id);
            });

            $('#user_id').on('change', function() {
                loadReports();
            });
        });
    </script>

    <?php require_once 'footer.php'; ?>