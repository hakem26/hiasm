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
function gregorian_to_jalali_format($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

// تابع تبدیل سال میلادی به سال شمسی
function gregorian_year_to_jalali($gregorian_year)
{
    list($jy, $jm, $jd) = gregorian_to_jalali($gregorian_year, 1, 1);
    return $jy;
}

// تابع تبدیل عدد روز به نام روز
function number_to_day($day_number)
{
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

// تابع تبدیل تاریخ شمسی به میلادی
function jalali_to_gregorian_format($jalali_date)
{
    list($jy, $jm, $jd) = explode('/', $jalali_date);
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    return "$gy-$gm-$gd";
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
    $work_month_id = (int) $_GET['work_month_id'];

    $month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
    $month_query->execute([$work_month_id]);
    $month = $month_query->fetch(PDO::FETCH_ASSOC);

    if ($month) {
        // مستقیماً از Work_Details داده‌ها رو بکشیم
        if ($is_admin) {
            $details_query = $pdo->prepare("
                SELECT wd.id, wd.work_date, wd.work_day, wd.partner_id, wd.agency_owner_id, wd.status, 
                       u1.user_id AS user_id1, u1.full_name AS user1,
                       COALESCE(u2.user_id, u1.user_id) AS user_id2, COALESCE(u2.full_name, u1.full_name) AS user2
                FROM Work_Details wd
                JOIN Partners p ON wd.partner_id = p.partner_id
                JOIN Users u1 ON p.user_id1 = u1.user_id
                LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
                WHERE wd.work_month_id = ? AND wd.status = 0
            ");
            $details_query->execute([$work_month_id]);
        } else {
            $details_query = $pdo->prepare("
                SELECT wd.id, wd.work_date, wd.work_day, wd.partner_id, wd.agency_owner_id, wd.status, 
                       u1.user_id AS user_id1, u1.full_name AS user1,
                       COALESCE(u2.user_id, u1.user_id) AS user_id2, COALESCE(u2.full_name, u1.full_name) AS user2
                FROM Work_Details wd
                JOIN Partners p ON wd.partner_id = p.partner_id
                JOIN Users u1 ON p.user_id1 = u1.user_id
                LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
                WHERE wd.work_month_id = ? AND wd.status = 0
                AND (p.user_id1 = ? OR p.user_id2 = ?)
            ");
            $details_query->execute([$work_month_id, $current_user_id, $current_user_id]);
        }
        $work_details_raw = $details_query->fetchAll(PDO::FETCH_ASSOC);

        foreach ($work_details_raw as $detail) {
            // محاسبه جمع کل فروش برای این روز کاری
            $sales_query = $pdo->prepare("
                SELECT SUM(total_amount) as total_sales 
                FROM Orders 
                WHERE work_details_id = ?
            ");
            $sales_query->execute([$detail['id']]);
            $total_sales = $sales_query->fetchColumn() ?: 0;

            $work_details[] = [
                'id' => $detail['id'],
                'work_date' => $detail['work_date'],
                'work_day' => number_to_day($detail['work_day']),
                'partner_id' => $detail['partner_id'],
                'user1' => $detail['user1'],
                'user2' => $detail['user2'],
                'user_id1' => $detail['user_id1'],
                'user_id2' => $detail['user_id2'],
                'agency_owner_id' => $detail['agency_owner_id'],
                'total_sales' => $total_sales,
                'status' => $detail['status']
            ];
        }
    }
}

// فیلتر بر اساس همکار
$selected_partner_id = $_GET['user_id'] ?? '';
$filtered_work_details = $work_details;
if (!empty($selected_partner_id)) {
    $user_id = (int) $selected_partner_id;
    $filtered_work_details = array_filter($work_details, function ($detail) use ($user_id) {
        return $detail['user_id1'] == $user_id || $detail['user_id2'] == $user_id;
    });
}

// مرتب‌سازی بر اساس work_date
usort($filtered_work_details, function ($a, $b) {
    return strcmp($a['work_date'], $b['work_date']);
});

// محاسبه مجموع فروش بر اساس فیلترها
$total_sales_all = 0;
if ($selected_year) {
    $conditions = [];
    $params = [];
    $base_query = "SELECT SUM(o.total_amount) as total_sales FROM Orders o JOIN Work_Details wd ON o.work_details_id = wd.id JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id WHERE wd.status = 0 AND 1=1";

    if (!$is_admin) {
        $conditions[] = "EXISTS (
            SELECT 1 FROM Partners p 
            WHERE p.partner_id = wd.partner_id 
            AND (p.user_id1 = ? OR p.user_id2 = ?)
        )";
        $params[] = $current_user_id;
        $params[] = $current_user_id;
    }

    if ($selected_year) {
        $conditions[] = "YEAR(wm.start_date) = ?";
        $params[] = $selected_year;
    }

    if (isset($_GET['work_month_id']) && $_GET['work_month_id']) {
        $conditions[] = "wd.work_month_id = ?";
        $params[] = (int) $_GET['work_month_id'];
    }

    if (!empty($selected_partner_id)) {
        $conditions[] = "EXISTS (
            SELECT 1 FROM Partners p 
            WHERE p.partner_id = wd.partner_id 
            AND (p.user_id1 = ? OR p.user_id2 = ?)
        )";
        $params[] = (int) $selected_partner_id;
        $params[] = (int) $selected_partner_id;
    }

    $final_query = $base_query . (empty($conditions) ? "" : " AND " . implode(" AND ", $conditions));
    $stmt_total = $pdo->prepare($final_query);
    $stmt_total->execute($params);
    $total_sales_all = $stmt_total->fetchColumn() ?: 0;
}
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
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center text-center mb-3">
            <h5 class="card-title">اطلاعات کاری</h5>
            <?php if ($is_admin): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWorkDetailModal">افزودن روز کاری</button>
            <?php endif; ?>
        </div>

        <!-- نمایش مجموع فروش -->
        <div class="mb-3">
            <p class="text-success fw-bold">مجموع فروش (بدون تخفیف): <?= number_format($total_sales_all, 0) ?> تومان</p>
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
                            <?= gregorian_to_jalali_format($month['start_date']) ?> تا
                            <?= gregorian_to_jalali_format($month['end_date']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-auto">
                <select name="user_id" class="form-select" onchange="this.form.submit()">
                    <option value="">همه همکاران</option>
                    <?php foreach ($partners as $partner): ?>
                        <option value="<?= htmlspecialchars($partner['user_id']) ?>"
                            <?= $selected_partner_id == $partner['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($partner['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if (!empty($filtered_work_details)): ?>
            <div class="table-responsive">
                <table class="table table-work-details table-light table-hover">
                    <thead>
                        <tr>
                            <th>تاریخ</th>
                            <th>روز هفته</th>
                            <th>همکاران</th>
                            <th>جمع کل فروش</th>
                            <th>آژانس</th>
                            <?php if ($is_admin): ?>
                                <th>وضعیت</th>
                            <?php endif; ?>
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
                                    <select class="select-wdt form-select agency-select" data-id="<?= $work['work_date'] ?>"
                                            data-partner-id="<?= $work['partner_id'] ?>">
                                        <option value="" <?= is_null($work['agency_owner_id']) ? 'selected' : '' ?>>انتخاب کنید</option>
                                        <option value="<?= $work['user_id1'] ?>" <?= $work['agency_owner_id'] == $work['user_id1'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($work['user1']) ?>
                                        </option>
                                        <option value="<?= $work['user_id2'] ?>" <?= $work['agency_owner_id'] == $work['user_id2'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($work['user2']) ?>
                                        </option>
                                    </select>
                                </td>
                                <?php if ($is_admin): ?>
                                    <td>
                                        <button class="btn btn-sm btn-<?= $work['status'] ? 'warning' : 'success' ?> toggle-status" data-id="<?= $work['id'] ?>">
                                            <?= $work['status'] ? 'غیر تعطیل' : 'تعطیل' ?>
                                        </button>
                                        <button class="btn btn-sm btn-danger delete-work" data-id="<?= $work['id'] ?>">حذف</button>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif (isset($_GET['work_month_id'])): ?>
            <div class="alert alert-warning text-center">اطلاعاتی وجود ندارد.</div>
        <?php endif; ?>
    </div>

    <!-- مودال افزودن روز کاری -->
    <?php if ($is_admin): ?>
        <div class="modal fade" id="addWorkDetailModal" tabindex="-1" aria-labelledby="addWorkDetailModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-light">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addWorkDetailModalLabel">افزودن روز کاری جدید</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form id="addWorkDetailForm" method="POST" action="add_work_detail.php">
                            <div class="mb-3">
                                <label for="work_month_id" class="form-label">ماه کاری</label>
                                <select name="work_month_id" class="form-select" required>
                                    <option value="">انتخاب ماه</option>
                                    <?php foreach ($work_months as $month): ?>
                                        <option value="<?= $month['work_month_id'] ?>">
                                            <?= gregorian_to_jalali_format($month['start_date']) ?> تا
                                            <?= gregorian_to_jalali_format($month['end_date']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="work_date" class="form-label">تاریخ (شمسی)</label>
                                <input type="text" class="form-control persian-date" id="work_date" name="work_date" required>
                            </div>
                            <div class="mb-3">
                                <label for="user_id1" class="form-label">همکار اول</label>
                                <select name="user_id1" class="form-select" required>
                                    <option value="">انتخاب همکار</option>
                                    <?php foreach ($partners as $partner): ?>
                                        <option value="<?= htmlspecialchars($partner['user_id']) ?>">
                                            <?= htmlspecialchars($partner['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="user_id2" class="form-label">همکار دوم</label>
                                <select name="user_id2" class="form-select">
                                    <option value="">انتخاب همکار (اختیاری)</option>
                                    <?php foreach ($partners as $partner): ?>
                                        <option value="<?= htmlspecialchars($partner['user_id']) ?>">
                                            <?= htmlspecialchars($partner['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">ثبت روز کاری</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        $(document).ready(function () {
            // Datepicker برای فیلد تاریخ
            $('.persian-date').persianDatepicker({
                format: 'YYYY/MM/DD',
                autoClose: true,
                calendar: {
                    persian: {
                        locale: 'fa',
                        digits: true
                    }
                }
            });

            // تغییر وضعیت تعطیل/غیر تعطیل
            $('.toggle-status').click(function () {
                const workDetailId = $(this).data('id');
                const button = $(this);
                
                $.post('toggle_work_status.php', { work_detail_id: workDetailId }, function (response) {
                    if (response.success) {
                        alert('وضعیت با موفقیت ذخیره شد');
                        if (response.status == 1) {
                            button.removeClass('btn-success').addClass('btn-warning').text('غیر تعطیل');
                        } else {
                            button.removeClass('btn-warning').addClass('btn-success').text('تعطیل');
                        }
                    } else {
                        alert('خطا در تغییر وضعیت: ' + response.message);
                    }
                }, 'json').fail(function () {
                    alert('خطا در اتصال به سرور!');
                });
            });

            // حذف روز کاری
            $('.delete-work').click(function () {
                if (!confirm('آیا مطمئن هستید که می‌خواهید این روز کاری را حذف کنید؟')) {
                    return;
                }

                const workDetailId = $(this).data('id');
                
                $.post('delete_work_detail.php', { work_detail_id: workDetailId }, function (response) {
                    if (response.success) {
                        alert('روز کاری با موفقیت حذف شد');
                        location.reload();
                    } else {
                        alert('خطا در حذف: ' + response.message);
                    }
                }, 'json').fail(function () {
                    alert('خطا در اتصال به سرور!');
                });
            });

            // تغییر آژانس
            $('.agency-select').change(function () {
                var work_date = $(this).data("id");
                var partner_id = $(this).data("partner-id");
                var agency_owner_id = $(this).val();

                $.post("update_agency.php", {
                    work_date: work_date,
                    partner_id: partner_id,
                    agency_owner_id: agency_owner_id
                }, function (response) {
                    alert(response);
                });
            });
        });
    </script>

    <?php require_once 'footer.php'; ?>