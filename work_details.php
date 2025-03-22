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

// تابع محاسبه روز هفته به صورت دستی
function calculate_day_of_week($work_date)
{
    $reference_date = '2025-03-01'; // 1403/12/1 که شنبه است
    $reference_timestamp = strtotime($reference_date);
    $current_timestamp = strtotime($work_date);
    $days_diff = ($current_timestamp - $reference_timestamp) / (60 * 60 * 24);
    $adjusted_day_number = ($days_diff % 7 + 1);
    if ($adjusted_day_number <= 0) {
        $adjusted_day_number += 7;
    }
    $days = [
        1 => 'شنبه',
        2 => 'یک‌شنبه',
        3 => 'دوشنبه',
        4 => 'سه‌شنبه',
        5 => 'چهارشنبه',
        6 => 'پنج‌شنبه',
        7 => 'جمعه'
    ];
    return $days[$adjusted_day_number];
}

// تابع تبدیل تاریخ شمسی به میلادی
function jalali_to_gregorian_format($jalali_date)
{
    list($jy, $jm, $jd) = explode('/', $jalali_date);
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    return "$gy-$gm-$gd";
}

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

    $stmt_months = $pdo->prepare("SELECT * FROM Work_Months WHERE start_date >= ? AND start_date < ? ORDER BY start_date DESC");
    $stmt_months->execute([$start_date, $end_date]);
    $work_months = $stmt_months->fetchAll(PDO::FETCH_ASSOC);
}

$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];

$partners = [];
$work_details = [];
$work_month_id = isset($_GET['work_month_id']) ? (int) $_GET['work_month_id'] : null;
$selected_partner_id = $_GET['user_id'] ?? '';

// فقط اگه ماه کاری انتخاب شده باشه، همکاران و روزها رو بگیریم
if ($work_month_id) {
    $month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
    $month_query->execute([$work_month_id]);
    $month = $month_query->fetch(PDO::FETCH_ASSOC);

    if ($month) {
        // دریافت همکاران برای ماه کاری انتخاب‌شده
        if ($is_admin) {
            $partners_query = $pdo->prepare("
                SELECT DISTINCT u.user_id, u.full_name 
                FROM Work_Details wd
                JOIN Partners p ON wd.partner_id = p.partner_id
                JOIN Users u ON u.user_id IN (p.user_id1, p.user_id2)
                WHERE wd.work_month_id = ? AND u.role = 'seller'
                ORDER BY u.full_name
            ");
            $partners_query->execute([$work_month_id]);
        } else {
            $partners_query = $pdo->prepare("
                SELECT DISTINCT u.user_id, u.full_name 
                FROM Work_Details wd
                JOIN Partners p ON wd.partner_id = p.partner_id
                JOIN Users u ON u.user_id IN (p.user_id1, p.user_id2)
                WHERE wd.work_month_id = ? 
                AND (p.user_id1 = ? OR p.user_id2 = ?) 
                AND u.user_id != ? 
                AND u.role = 'seller'
                ORDER BY u.full_name
            ");
            $partners_query->execute([$work_month_id, $current_user_id, $current_user_id, $current_user_id]);
        }
        $partners = $partners_query->fetchAll(PDO::FETCH_ASSOC);

        // دریافت اطلاعات کاری
        $query = "
            SELECT wd.*, p.*, u1.user_id AS user_id1, u1.full_name AS user1,
                   COALESCE(u2.user_id, u1.user_id) AS user_id2, COALESCE(u2.full_name, u1.full_name) AS user2
            FROM Work_Details wd
            LEFT JOIN Partners p ON wd.partner_id = p.partner_id
            LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
            LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
            WHERE wd.work_month_id = ?
        ";
        $params = [$work_month_id];

        if ($selected_partner_id) {
            $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
            $params[] = (int) $selected_partner_id;
            $params[] = (int) $selected_partner_id;
        }

        if (!$is_admin) {
            $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
            $params[] = $current_user_id;
            $params[] = $current_user_id;
        }

        $details_query = $pdo->prepare($query);
        $details_query->execute($params);
        $work_details_raw = $details_query->fetchAll(PDO::FETCH_ASSOC);

        foreach ($work_details_raw as $detail) {
            $sales_query = $pdo->prepare("
                SELECT SUM(total_amount) as total_sales 
                FROM Orders 
                WHERE work_details_id = ?
            ");
            $sales_query->execute([$detail['id']]);
            $total_sales = $sales_query->fetchColumn() ?: 0;

            $work_day = calculate_day_of_week($detail['work_date']);

            $work_details[] = [
                'id' => $detail['id'],
                'work_date' => $detail['work_date'],
                'work_day' => $work_day,
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

// مرتب‌سازی بر اساس work_date
usort($work_details, function ($a, $b) {
    return strcmp($a['work_date'], $b['work_date']);
});

// محاسبه مجموع فروش بر اساس فیلترها
$total_sales_all = 0;
if ($selected_year) {
    $conditions = [];
    $params = [];
    $base_query = "SELECT SUM(o.total_amount) as total_sales FROM Orders o JOIN Work_Details wd ON o.work_details_id = wd.id JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id WHERE 1=1";

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
        $conditions[] = "wm.start_date >= ? AND wm.start_date < ?";
        $params[] = $start_date;
        $params[] = $end_date;
    }

    if ($work_month_id) {
        $conditions[] = "wd.work_month_id = ?";
        $params[] = $work_month_id;
    }

    if ($selected_partner_id) {
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

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center text-center mb-3">
        <h5 class="card-title">اطلاعات کاری</h5>
        <div>
            <?php if ($is_admin): ?>
                <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#addWorkDetailModal">افزودن
                    روز کاری</button>
                <?php if ($work_month_id): ?>
                    <button class="btn btn-success auto-update-work-details"
                        data-work-month-id="<?= $work_month_id ?>">بروزرسانی اتومات روز کاری</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
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
                            <?= $year ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="col-auto">
            <select name="work_month_id" class="form-select" onchange="this.form.submit()">
                <option value="" <?= !$work_month_id ? 'selected' : '' ?>>انتخاب ماه</option>
                <?php foreach ($work_months as $month): ?>
                    <option value="<?= $month['work_month_id'] ?>" <?= $work_month_id == $month['work_month_id'] ? 'selected' : '' ?>>
                        <?= gregorian_to_jalali_format($month['start_date']) ?> تا
                        <?= gregorian_to_jalali_format($month['end_date']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-auto">
            <select name="user_id" class="form-select" onchange="this.form.submit()">
                <option value="" <?= !$selected_partner_id ? 'selected' : '' ?>>همه همکاران</option>
                <?php foreach ($partners as $partner): ?>
                    <option value="<?= htmlspecialchars($partner['user_id']) ?>"
                        <?= $selected_partner_id == $partner['user_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($partner['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if (!empty($work_details)): ?>
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
                    <?php foreach ($work_details as $work): ?>
                        <tr>
                            <td><?= gregorian_to_jalali_format($work['work_date']) ?></td>
                            <td><?= $work['work_day'] ?></td>
                            <td><?= htmlspecialchars($work['user1']) ?> - <?= htmlspecialchars($work['user2']) ?></td>
                            <td><?= number_format($work['total_sales'], 0) ?></td>
                            <td>
                                <select class="select-wdt form-select agency-select" data-id="<?= $work['work_date'] ?>"
                                    data-partner-id="<?= $work['partner_id'] ?>">
                                    <option value="" <?= is_null($work['agency_owner_id']) ? 'selected' : '' ?>>انتخاب کنید
                                    </option>
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
                                    <button class="btn btn-sm btn-<?= $work['status'] ? 'warning' : 'success' ?> toggle-status"
                                        data-id="<?= $work['id'] ?>">
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
    <?php elseif ($work_month_id): ?>
        <div class="alert alert-warning text-center">اطلاعاتی وجود ندارد.</div>
    <?php endif; ?>
</div>

<!-- مودال افزودن روز کاری -->
<?php if ($is_admin): ?>
    <div class="modal fade" id="addWorkDetailModal" tabindex="-1" aria-labelledby="addWorkDetailModalLabel"
        aria-hidden="true">
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
                            <input type="text" class="form-control persian-date" id="work_date" name="work_date"
                                required>
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
                    alert('خطا: ' + response.message);
                }
            }, 'json').fail(function () {
                alert('خطا در اتصال به سرور!');
            });
        });

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
                    alert('خطا: ' + response.message);
                }
            }, 'json').fail(function () {
                alert('خطا در اتصال به سرور!');
            });
        });

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

        $('.auto-update-work-details').click(function () {
            const workMonthId = $(this).data('work-month-id');

            $.post('auto_update_work_details.php', { work_month_id: workMonthId }, function (response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('خطا: ' + response.message);
                }
            }, 'json').fail(function () {
                alert('خطا در اتصال به سرور!');
            });
        });
    });
</script>

<?php require_once 'footer.php'; ?>