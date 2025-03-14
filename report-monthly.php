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
    return sprintf("%02d/%02d/%04d", $jd, $jm, $jy);
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

// دریافت مقادیر فیلتر از GET یا مقدار پیش‌فرض
$filter_year = $_GET['filter_year'] ?? (jalali_to_gregorian(date('Y'), date('m'), date('d'), '/')[0]); // سال شمسی فعلی
$filter_work_month = $_GET['filter_work_month'] ?? '';
$filter_partner = $_GET['filter_partner'] ?? '';

// تبدیل سال شمسی به میلادی برای کوئری
$gregorian_year = $filter_year - 621;

// دریافت لیست سال‌ها برای فیلتر
$years = [];
$stmt_years = $pdo->query("SELECT DISTINCT YEAR(start_date) as year FROM Work_Months ORDER BY year DESC");
while ($row = $stmt_years->fetch(PDO::FETCH_ASSOC)) {
    $jalali_year = $row['year'] + 621;
    $years[] = $jalali_year;
}

// دریافت لیست ماه‌های کاری برای فیلتر
$work_months = [];
$stmt_months = $pdo->prepare("SELECT work_month_id, start_date, end_date FROM Work_Months WHERE YEAR(start_date) = ? ORDER BY start_date DESC");
$stmt_months->execute([$gregorian_year]);
while ($row = $stmt_months->fetch(PDO::FETCH_ASSOC)) {
    $start_date = explode('-', $row['start_date']);
    $jalali_year = $start_date[0] + 621;
    $jalali_month = (int)$start_date[1];
    $month_name = get_jalali_month_name($jalali_month) . ' ' . $jalali_year;
    $work_months[] = [
        'work_month_id' => $row['work_month_id'],
        'month_name' => $month_name
    ];
}

// دریافت لیست همکاران برای فیلتر
$partners = [];
$stmt_partners = $pdo->prepare("
    SELECT DISTINCT p.partner_id, u1.full_name AS user1_name, u2.full_name AS user2_name
    FROM Partners p
    LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
    LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
    WHERE p.user_id1 = :user_id OR p.user_id2 = :user_id
");
$stmt_partners->execute([':user_id' => $current_user_id]);
while ($row = $stmt_partners->fetch(PDO::FETCH_ASSOC)) {
    $partner_name = $row['user1_name'] . ' و ' . $row['user2_name'];
    $partners[$row['partner_id']] = $partner_name;
}

// دریافت گزارش‌های ماهانه (برای بارگذاری اولیه)
$reports = [];
if ($filter_year || $filter_work_month || $filter_partner) {
    $conditions = [];
    $params = [];

    $conditions[] = "wm.work_month_id = wd.work_month_id";
    $conditions[] = "wd.partner_id = p.partner_id";
    $conditions[] = "(p.user_id1 = :user_id OR p.user_id2 = :user_id)";
    $params[':user_id'] = $current_user_id;

    if ($filter_year) {
        $conditions[] = "YEAR(wm.start_date) = :year";
        $params[':year'] = $gregorian_year;
    }
    if ($filter_work_month) {
        $conditions[] = "wm.work_month_id = :work_month_id";
        $params[':work_month_id'] = $filter_work_month;
    }
    if ($filter_partner) {
        $conditions[] = "p.partner_id = :partner_id";
        $params[':partner_id'] = $filter_partner;
    }

    $sql = "
        SELECT wm.work_month_id, wm.start_date, wm.end_date, p.partner_id, u1.full_name AS user1_name, u2.full_name AS user2_name,
               COUNT(DISTINCT wd.work_date) AS days_worked,
               (SELECT COUNT(DISTINCT work_date) FROM Work_Details WHERE work_month_id = wm.work_month_id) AS total_days,
               SUM(o.total_amount) AS total_sales
        FROM Work_Months wm
        JOIN Work_Details wd ON wm.work_month_id = wd.work_month_id
        JOIN Partners p ON wd.partner_id = p.partner_id
        LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
        LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
        LEFT JOIN Orders o ON o.work_details_id = wd.id
        WHERE " . implode(" AND ", $conditions) . "
        GROUP BY wm.work_month_id, p.partner_id
        ORDER BY wm.start_date DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $start_date = explode('-', $row['start_date']);
        $jalali_year = $start_date[0] + 621;
        $jalali_month = (int)$start_date[1];
        $month_name = get_jalali_month_name($jalali_month) . ' ' . $jalali_year;

        $partner_name = $row['user1_name'] . ' و ' . $row['user2_name'];
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
                    <label for="filter_year" class="form-label">سال</label>
                    <select name="filter_year" id="filter_year" class="form-select">
                        <option value="">همه</option>
                        <?php foreach ($years as $year): ?>
                            <option value="<?= $year ?>" <?= $filter_year == $year ? 'selected' : '' ?>><?= $year ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="filter_work_month" class="form-label">ماه کاری</label>
                    <select name="filter_work_month" id="filter_work_month" class="form-select">
                        <option value="">همه</option>
                        <?php foreach ($work_months as $month): ?>
                            <option value="<?= $month['work_month_id'] ?>" <?= $filter_work_month == $month['work_month_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($month['month_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="filter_partner" class="form-label">همکاران</label>
                    <select name="filter_partner" id="filter_partner" class="form-select">
                        <option value="">همه</option>
                        <?php foreach ($partners as $partner_id => $partner_name): ?>
                            <option value="<?= $partner_id ?>" <?= $filter_partner == $partner_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($partner_name) ?>
                            </option>
                        <?php endforeach; ?>
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
            // تابع برای بارگذاری جدول با فیلترها
            function loadReports() {
                const filter_year = $('#filter_year').val();
                const filter_work_month = $('#filter_work_month').val();
                const filter_partner = $('#filter_partner').val();

                $.ajax({
                    url: 'get_reports.php',
                    type: 'GET',
                    data: {
                        filter_year: filter_year,
                        filter_work_month: filter_work_month,
                        filter_partner: filter_partner
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#reports-table').html(response.html);
                        } else {
                            $('#reports-table').html('<div class="alert alert-danger text-center">' + response.message + '</div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', error);
                        $('#reports-table').html('<div class="alert alert-danger text-center">خطایی رخ داد.</div>');
                    }
                });
            }

            // به‌روزرسانی ماه‌ها با تغییر سال
            $('#filter_year').on('change', function() {
                const year = $(this).val();
                $.ajax({
                    url: 'get_work_months.php',
                    type: 'GET',
                    data: { year: year },
                    success: function(response) {
                        if (response.success) {
                            const $select = $('#filter_work_month');
                            $select.empty().append('<option value="">همه</option>');
                            response.months.forEach(month => {
                                $select.append(`<option value="${month.work_month_id}">${month.month_name}</option>`);
                            });
                            loadReports(); // به‌روزرسانی جدول
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', error);
                    }
                });
            });

            // به‌روزرسانی جدول با تغییر هر فیلتر
            $('#filter_year, #filter_work_month, #filter_partner').on('change', function() {
                loadReports();
            });

            // بارگذاری اولیه
            loadReports();
        });
    </script>

    <?php require_once 'footer.php'; ?>