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
    $start_date = $month['start_date'];
    list($gy, $gm, $gd) = explode('-', $start_date);
    $jalali_date = gregorian_to_jalali($gy, $gm, $gd);
    $jalali_year = $jalali_date[0]; // سال شمسی
    if (!in_array($jalali_year, $years)) {
        $years[] = $jalali_year;
    }
}
sort($years, SORT_NUMERIC);
$years = array_reverse($years); // مرتب‌سازی نزولی

// محاسبه سال جاری شمسی
$current_gregorian_year = date('Y'); // 2025
$current_jalali_year = gregorian_to_jalali($current_gregorian_year, 1, 1)[0]; // 1404

// تنظیم پیش‌فرض به جدیدترین سال
$selected_year = $_GET['year'] ?? null;
if (!$selected_year) {
    $selected_year = $years[0] ?? $current_jalali_year; // اولین سال توی لیست (جدیدترین سال)
}

// دریافت لیست ماه‌های کاری برای بارگذاری اولیه
$work_months = [];
if ($selected_year) {
    $gregorian_start_year = $selected_year - 579;
    $gregorian_end_year = $gregorian_start_year + 1;
    $start_date = "$gregorian_start_year-03-21";
    $end_date = "$gregorian_end_year-03-21";

    if ($selected_year == 1404) {
        $start_date = "2025-03-21";
        $end_date = "2026-03-21";
    } elseif ($selected_year == 1403) {
        $start_date = "2024-03-20";
        $end_date = "2025-03-21";
    }

    $query = "
        SELECT DISTINCT wm.work_month_id, wm.start_date, wm.end_date 
        FROM Work_Months wm
        JOIN Work_Details wd ON wm.work_month_id = wd.work_month_id
        JOIN Partners p ON wd.partner_id = p.partner_id
        WHERE wm.start_date >= ? AND wm.start_date < ?
        AND (p.user_id1 = ? OR p.user_id2 = ? OR wd.agency_owner_id = ?)
        ORDER BY wm.start_date DESC
    ";
    $params = [$start_date, $end_date, $current_user_id, $current_user_id, $current_user_id];
    if ($is_admin && isset($_GET['user_id']) && $_GET['user_id']) {
        $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
        $params[] = (int) $_GET['user_id'];
        $params[] = (int) $_GET['user_id'];
    }

    $stmt_months = $pdo->prepare($query);
    $stmt_months->execute($params);
    $work_months = $stmt_months->fetchAll(PDO::FETCH_ASSOC);

    // دیباگ
    if (empty($work_months)) {
        error_log("report-monthly.php: No months found for year $selected_year. Start date: $start_date, End date: $end_date");
    } else {
        foreach ($work_months as $month) {
            error_log("report-monthly.php: Found month: work_month_id = {$month['work_month_id']}, start_date = {$month['start_date']}, end_date = {$month['end_date']}");
        }
    }
}

// دریافت گزارش‌های ماهانه (برای بارگذاری اولیه)
$reports = [];
if ($selected_year) {
    $gregorian_start_year = $selected_year - 579;
    $gregorian_end_year = $gregorian_start_year + 1;
    $start_date = "$gregorian_start_year-03-21";
    $end_date = "$gregorian_end_year-03-21";

    if ($selected_year == 1404) {
        $start_date = "2025-03-21";
        $end_date = "2026-03-21";
    } elseif ($selected_year == 1403) {
        $start_date = "2024-03-20";
        $end_date = "2025-03-21";
    }

    $query = "
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
        WHERE wm.start_date >= ? AND wm.start_date < ?
        AND (p.user_id1 = ? OR p.user_id2 = ? OR wd.agency_owner_id = ?)
    ";
    $params = [$start_date, $end_date, $current_user_id, $current_user_id, $current_user_id];

    if (isset($_GET['work_month_id']) && $_GET['work_month_id']) {
        $query .= " AND wm.work_month_id = ?";
        $params[] = (int) $_GET['work_month_id'];
    }

    if (isset($_GET['user_id']) && $_GET['user_id']) {
        $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
        $params[] = (int) $_GET['user_id'];
        $params[] = (int) $_GET['user_id'];
    }

    $query .= " GROUP BY wm.work_month_id, p.partner_id ORDER BY wm.start_date DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $start_date_jalali = gregorian_to_jalali_format($row['start_date']);
        list($jy, $jm, $jd) = explode('/', $start_date_jalali);
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

    // دیباگ
    if (empty($reports)) {
        error_log("report-monthly.php: No reports found for year $selected_year, work_month_id " . ($_GET['work_month_id'] ?? 'none') . ", user_id " . ($_GET['user_id'] ?? 'none'));
    } else {
        foreach ($reports as $report) {
            error_log("report-monthly.php: Found report: work_month_id = {$report['work_month_id']}, partner_name = {$report['partner_name']}, total_sales = {$report['total_sales']}");
        }
    }
}
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
                        <?php foreach ($work_months as $month): ?>
                            <option value="<?= $month['work_month_id'] ?>" <?= isset($_GET['work_month_id']) && $_GET['work_month_id'] == $month['work_month_id'] ? 'selected' : '' ?>>
                                <?= gregorian_to_jalali_format($month['start_date']) ?> تا
                                <?= gregorian_to_jalali_format($month['end_date']) ?>
                            </option>
                        <?php endforeach; ?>
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