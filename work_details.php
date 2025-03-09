<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

// تابع تبدیل سال میلادی به سال شمسی
function gregorian_year_to_jalali($gregorian_year) {
    list($jy, $jm, $jd) = gregorian_to_jalali($gregorian_year, 1, 1);
    return $jy;
}

// تابع تبدیل عدد روز به نام روز
function number_to_day($day_number) {
    $days = [
        1 => 'شنبه',
        2 => 'یکشنبه',
        3 => 'دوشنبه',
        4 => 'سه‌شنبه',
        5 => 'چهارشنبه',
        6 => 'پنجشنبه',
        7 => 'جمعه'
    ];
    return $days[$day_number] ?? 'نامشخص';
}

// دریافت سال‌های موجود از دیتابیس (میلادی)
$stmt = $pdo->query("SELECT DISTINCT YEAR(start_date) AS year FROM Work_Months ORDER BY year DESC");
$years_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
$years = array_column($years_db, 'year');

// دریافت سال جاری (میلادی) به‌عنوان پیش‌فرض
$current_year = date('Y');

// دریافت سال انتخاب‌شده (میلادی)
$selected_year = $_GET['year'] ?? (in_array($current_year, $years) ? $current_year : (!empty($years) ? $years[0] : null));

// تبدیل سال انتخاب‌شده به شمسی برای نمایش
$selected_jalali_year = $selected_year ? gregorian_year_to_jalali($selected_year) : null;

// اگر سال انتخاب‌شده وجود نداشت، اولین سال موجود رو انتخاب کن
if ($selected_year && !in_array($selected_year, $years)) {
    $selected_year = !empty($years) ? $years[0] : null;
    $selected_jalali_year = $selected_year ? gregorian_year_to_jalali($selected_year) : null;
}

// دریافت لیست ماه‌های کاری بر اساس سال میلادی
if ($selected_year) {
    $work_months_query = $pdo->prepare("SELECT * FROM Work_Months WHERE YEAR(start_date) = ? ORDER BY start_date DESC");
    $work_months_query->execute([$selected_year]);
    $work_months = $work_months_query->fetchAll(PDO::FETCH_ASSOC);
} else {
    $work_months = [];
}

// تعریف متغیرها
$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];

// دریافت لیست همکاران
if ($is_admin) {
    $partners_query = $pdo->query("SELECT user_id, full_name FROM Users WHERE role = 'seller' ORDER BY full_name");
    $partners = $partners_query->fetchAll(PDO::FETCH_ASSOC);
} else {
    $partners_query = $pdo->prepare("
        SELECT DISTINCT u.user_id, u.full_name 
        FROM Partners p
        JOIN Users u ON u.user_id IN (p.user_id1, p.user_id2)
        WHERE (p.user_id1 = ? OR p.user_id2 = ?) AND u.role = 'seller'
        ORDER BY u.full_name
    ");
    $partners_query->execute([$current_user_id, $current_user_id]);
    $partners = $partners_query->fetchAll(PDO::FETCH_ASSOC);
}

// دریافت اطلاعات بر اساس ماه کاری انتخاب‌شده
$work_details = [];
if (isset($_GET['work_month_id'])) {
    $work_month_id = (int)$_GET['work_month_id'];

    $month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
    $month_query->execute([$work_month_id]);
    $month = $month_query->fetch(PDO::FETCH_ASSOC);

    if ($month) {
        $start_date = new DateTime($month['start_date']);
        $end_date = new DateTime($month['end_date']);
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));

        // دریافت همه جفت‌های همکار از Partners
        if ($is_admin) {
            $partner_query = $pdo->prepare("
                SELECT p.partner_id, p.work_day AS stored_day_number, u1.user_id AS user_id1, u1.full_name AS user1, 
                       COALESCE(u2.user_id, u1.user_id) AS user_id2, COALESCE(u2.full_name, u1.full_name) AS user2
                FROM Partners p
                JOIN Users u1 ON p.user_id1 = u1.user_id
                LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
                GROUP BY p.partner_id
            ");
            $partner_query->execute();
        } else {
            $partner_query = $pdo->prepare("
                SELECT p.partner_id, p.work_day AS stored_day_number, u1.user_id AS user_id1, u1.full_name AS user1, 
                       COALESCE(u2.user_id, u1.user_id) AS user_id2, COALESCE(u2.full_name, u1.full_name) AS user2
                FROM Partners p
                JOIN Users u1 ON p.user_id1 = u1.user_id
                LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
                WHERE (p.user_id1 = ? OR p.user_id2 = ?) AND u1.role = 'seller'
                GROUP BY p.partner_id
            ");
            $partner_query->execute([$current_user_id, $current_user_id]);
        }
        $partners_in_work = $partner_query->fetchAll(PDO::FETCH_ASSOC);

        // همگام‌سازی و ذخیره‌سازی داده‌ها
        $processed_partners = [];
        foreach ($partners_in_work as $partner) {
            $partner_id = $partner['partner_id'];
            if (!in_array($partner_id, $processed_partners)) {
                $processed_partners[] = $partner_id;

                foreach ($date_range as $date) {
                    $work_date = $date->format('Y-m-d');
                    $day_number_php = (int)date('N', strtotime($work_date));
                    $adjusted_day_number = ($day_number_php + 5) % 7;
                    if ($adjusted_day_number == 0) $adjusted_day_number = 7;

                    if ($partner['stored_day_number'] == $adjusted_day_number) {
                        $detail_query = $pdo->prepare("
                            SELECT * FROM Work_Details 
                            WHERE work_date = ? AND work_month_id = ? AND partner_id = ?
                        ");
                        $detail_query->execute([$work_date, $work_month_id, $partner_id]);
                        $existing_detail = $detail_query->fetch(PDO::FETCH_ASSOC);

                        if (!$existing_detail) {
                            $insert_query = $pdo->prepare("
                                INSERT INTO Work_Details (work_month_id, work_date, work_day, partner_id, agency_owner_id) 
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $insert_query->execute([$work_month_id, $work_date, $adjusted_day_number, $partner_id, $partner['user_id1']]);
                            $work_details_id = $pdo->lastInsertId();
                        } else {
                            $work_details_id = $existing_detail['id'];
                        }

                        // محاسبه جمع کل فروش برای این روز کاری
                        $sales_query = $pdo->prepare("
                            SELECT SUM(total_amount) as total_sales 
                            FROM Orders 
                            WHERE work_details_id = ?
                        ");
                        $sales_query->execute([$work_details_id]);
                        $total_sales = $sales_query->fetchColumn() ?: 0;

                        $agency_owner_id = $existing_detail && isset($existing_detail['agency_owner_id']) ? $existing_detail['agency_owner_id'] : $partner['user_id1'];
                        $work_details[] = [
                            'work_date' => $work_date,
                            'work_day' => number_to_day($adjusted_day_number),
                            'partner_id' => $partner_id,
                            'user1' => $partner['user1'],
                            'user2' => $partner['user2'],
                            'user_id1' => $partner['user_id1'],
                            'user_id2' => $partner['user_id2'],
                            'agency_owner_id' => $agency_owner_id,
                            'total_sales' => $total_sales // جمع کل فروش
                        ];
                    }
                }
            }
        }
    }
}

// فیلتر بر اساس همکار
$selected_partner_id = $_GET['user_id'] ?? '';
$filtered_work_details = $work_details;
if (!empty($selected_partner_id)) {
    $user_id = (int)$selected_partner_id;
    $filtered_work_details = array_filter($work_details, function($detail) use ($user_id) {
        return $detail['user_id1'] == $user_id || $detail['user_id2'] == $user_id;
    });
}

// مرتب‌سازی بر اساس work_date
usort($filtered_work_details, function($a, $b) {
    return strcmp($a['work_date'], $b['work_date']);
});
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اطلاعات کاری</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css"
        integrity="sha384-dpuaG1suU0eT09tx5plTaGMLBsfDLzUCCUXOY2j/LSvXYuG6Bqs43ALlhIqAJVRb" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/persian-datepicker.min.css" />
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container-fluid mt-5">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="card-title">اطلاعات کاری</h5>
        </div>

        <form method="GET" class="row g-3 mb-3">
            <?php if (!empty($years)): ?>
            <div class="col-auto">
                <select name="year" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($years as $year): ?>
                        <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                            <?= gregorian_year_to_jalali($year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="col-auto">
                <select name="work_month_id" class="form-select" onchange="this.form.submit()">
                    <option value="">انتخاب ماه</option>
                    <?php foreach ($work_months as $month): ?>
                        <option value="<?= $month['work_month_id'] ?>" <?= isset($_GET['work_month_id']) && $_GET['work_month_id'] == $month['work_month_id'] ? 'selected' : '' ?>>
                            <?= gregorian_to_jalali_format($month['start_date']) ?> تا <?= gregorian_to_jalali_format($month['end_date']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-auto">
                <select name="user_id" class="form-select" onchange="this.form.submit()">
                    <option value="">همه همکاران</option>
                    <?php foreach ($partners as $partner): ?>
                        <option value="<?= htmlspecialchars($partner['user_id']) ?>" <?= $selected_partner_id == $partner['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($partner['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if (!empty($filtered_work_details)): ?>
            <table class="table table-light table-hover">
                <thead>
                    <tr>
                        <th>تاریخ</th>
                        <th>روز هفته</th>
                        <th>همکاران</th>
                        <th>جمع کل فروش</th>
                        <th>آژانس</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filtered_work_details as $work): ?>
                        <tr>
                            <td><?= gregorian_to_jalali_format($work['work_date']) ?></td>
                            <td><?= $work['work_day'] ?></td>
                            <td><?= htmlspecialchars($work['user1']) ?> - <?= htmlspecialchars($work['user2']) ?></td>
                            <td><?= number_format($work['total_sales'], 0) ?></td>
                            <td>
                                <select class="form-select agency-select" data-id="<?= $work['work_date'] ?>" data-partner-id="<?= $work['partner_id'] ?>">
                                    <option value="<?= $work['user_id1'] ?>" <?= $work['agency_owner_id'] == $work['user_id1'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($work['user1']) ?>
                                    </option>
                                    <option value="<?= $work['user_id2'] ?>" <?= $work['agency_owner_id'] == $work['user_id2'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($work['user2']) ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif (isset($_GET['work_month_id'])): ?>
            <div class="alert alert-warning text-center">اطلاعاتی وجود ندارد.</div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"
        integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="assets/js/persian-date.min.js"></script>
    <script src="assets/js/persian-datepicker.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.agency-select').change(function() {
                var work_date = $(this).data("id");
                var partner_id = $(this).data("partner-id");
                var agency_owner_id = $(this).val();

                $.post("update_agency.php", {
                    work_date: work_date,
                    partner_id: partner_id,
                    agency_owner_id: agency_owner_id
                }, function(response) {
                    alert(response);
                });
            });
        });
    </script>

<?php require_once 'footer.php'; ?>