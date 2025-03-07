<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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

// تابع تبدیل عدد روز به نام روز (کامل با همه روزها)
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

// دریافت لیست ماه‌های کاری
$stmt = $pdo->query("SELECT * FROM Work_Months ORDER BY start_date DESC");
$work_months = $stmt->fetchAll(PDO::FETCH_ASSOC);

// دریافت کاربران برای فیلتر
$users = $pdo->query("SELECT user_id, full_name FROM Users WHERE role = 'seller'")->fetchAll(PDO::FETCH_ASSOC);
$selected_user_id = $_GET['user_id'] ?? null;

// دریافت اطلاعات بر اساس ماه کاری انتخاب شده
$work_details = [];
if (isset($_GET['work_month_id'])) {
    $work_month_id = (int)$_GET['work_month_id'];

    // دریافت تاریخ شروع و پایان ماه کاری
    $month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
    $month_query->execute([$work_month_id]);
    $month = $month_query->fetch(PDO::FETCH_ASSOC);

    if ($month) {
        $start_date = new DateTime($month['start_date']);
        $end_date = new DateTime($month['end_date']);
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));

        foreach ($date_range as $date) {
            $work_date = $date->format('Y-m-d');
            $day_number_php = (int)date('N', strtotime($work_date)); // 1 (دوشنبه) تا 7 (یکشنبه)
            // تبدیل به سیستم ما: 1=شنبه، 2=یکشنبه، 3=دوشنبه، 4=سه‌شنبه، 5=چهارشنبه، 6=پنجشنبه، 7=جمعه
            $adjusted_day_number = ($day_number_php + 5) % 7;
            if ($adjusted_day_number == 0) $adjusted_day_number = 7; // تنظیم برای روز جمعه
            $work_day_display = number_to_day($adjusted_day_number); // برای نمایش

            // پیدا کردن جفت همکارانی که در این روز کار می‌کنند
            $partner_query = $pdo->prepare("
                SELECT p.partner_id, p.work_day AS stored_day_number, u1.user_id AS user_id1, u1.full_name AS user1, 
                       COALESCE(u2.user_id, u1.user_id) AS user_id2, COALESCE(u2.full_name, u1.full_name) AS user2
                FROM Partners p
                JOIN Users u1 ON p.user_id1 = u1.user_id
                LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
                WHERE p.work_day = ?
            ");
            $partner_query->execute([$adjusted_day_number]);
            $partners = $partner_query->fetchAll(PDO::FETCH_ASSOC);

            if (empty($partners)) {
                error_log("No partners found for day_number: $adjusted_day_number (Display: $work_day_display) on date: $work_date");
            } else {
                error_log("Partners found for day_number: $adjusted_day_number (Display: $work_day_display) on date: $work_date - Count: " . count($partners) . " - Partner IDs: " . implode(', ', array_column($partners, 'partner_id')));
            }

            foreach ($partners as $partner) {
                // بررسی اینکه آیا اطلاعات قبلاً ثبت شده است؟
                $detail_query = $pdo->prepare("
                    SELECT * FROM Work_Details 
                    WHERE work_date = ? AND work_month_id = ? AND partner_id = ?
                ");
                $detail_query->execute([$work_date, $work_month_id, $partner['partner_id']]);
                $existing_detail = $detail_query->fetch(PDO::FETCH_ASSOC);

                if (!$existing_detail) {
                    $insert_query = $pdo->prepare("
                        INSERT INTO Work_Details (work_month_id, work_date, work_day, partner_id, agency_owner_id) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $insert_query->execute([$work_month_id, $work_date, $adjusted_day_number, $partner['partner_id'], $partner['user_id1']]);
                    error_log("Inserted new Work_Detail for date: $work_date, day_number: $adjusted_day_number, partner_id: {$partner['partner_id']}");
                }

                // دریافت اطلاعات نهایی برای نمایش
                $agency_owner_id = $existing_detail && isset($existing_detail['agency_owner_id']) ? $existing_detail['agency_owner_id'] : $partner['user_id1'];
                $work_details[] = [
                    'work_date' => $work_date,
                    'work_day' => $work_day_display,
                    'partner_id' => $partner['partner_id'],
                    'user1' => $partner['user1'],
                    'user2' => $partner['user2'],
                    'user_id1' => $partner['user_id1'],
                    'user_id2' => $partner['user_id2'],
                    'agency_owner_id' => $agency_owner_id
                ];
            }
        }
    }
}

// فیلتر بر اساس همکار
$filtered_work_details = $work_details;
if (isset($_GET['user_id']) && !empty($_GET['user_id'])) {
    $user_id = (int)$_GET['user_id'];
    $filtered_work_details = array_filter($work_details, function($detail) use ($user_id) {
        return $detail['user_id1'] == $user_id || $detail['user_id2'] == $user_id;
    });
} else {
    $filtered_work_details = $work_details; // همه همکاران
    if (empty($filtered_work_details) && isset($_GET['work_month_id'])) {
        error_log("No work details loaded for work_month_id: $work_month_id");
    }
}
?>

<div class="container-fluid mt-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="card-title">اطلاعات کاری</h5>
    </div>

    <form method="GET" class="row g-3 mb-3">
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
                <option value="" <?= !isset($_GET['user_id']) || empty($_GET['user_id']) ? 'selected' : '' ?>>همه همکاران</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= $user['user_id'] ?>" <?= isset($_GET['user_id']) && $_GET['user_id'] == $user['user_id'] ? 'selected' : '' ?>>
                        <?= $user['full_name'] ?>
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
                    <th>آژانس</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filtered_work_details as $work): ?>
                    <tr>
                        <td><?= gregorian_to_jalali_format($work['work_date']) ?></td>
                        <td><?= $work['work_day'] ?></td>
                        <td><?= $work['user1'] ?> - <?= $work['user2'] ?></td>
                        <td>
                            <select class="form-select agency-select" data-id="<?= $work['work_date'] ?>" data-partner-id="<?= $work['partner_id'] ?>">
                                <option value="<?= $work['user_id1'] ?>" <?= $work['agency_owner_id'] == $work['user_id1'] ? 'selected' : '' ?>>
                                    <?= $work['user1'] ?>
                                </option>
                                <option value="<?= $work['user_id2'] ?>" <?= $work['agency_owner_id'] == $work['user_id2'] ? 'selected' : '' ?>>
                                    <?= $work['user2'] ?>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    $(".agency-select").change(function() {
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