<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'db.php'; // فایل اتصال به دیتابیس
require_once 'jdf.php'; // برای تبدیل تاریخ

function gregorian_to_jalali_format($date) {
    return jdate('Y/m/d', strtotime($date));
}

// دریافت لیست ماه‌های کاری
$months = $conn->query("SELECT * FROM Work_Months ORDER BY start_date DESC");

// دریافت اطلاعات بر اساس ماه کاری انتخاب شده
$work_details = [];
if (isset($_GET['work_month_id'])) {
    $work_month_id = (int)$_GET['work_month_id'];

    // دریافت تاریخ شروع و پایان ماه کاری
    $month_query = $conn->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
    $month_query->execute([$work_month_id]);
    $month = $month_query->fetch(PDO::FETCH_ASSOC);

    if ($month) {
        $start_date = new DateTime($month['start_date']);
        $end_date = new DateTime($month['end_date']);
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));

        foreach ($date_range as $date) {
            $work_date = $date->format('Y-m-d');
            $work_day = jdate('l', strtotime($work_date), '', '', 'gregorian', 'persian');

            // پیدا کردن جفت همکارانی که در این روز کار می‌کنند
            $partner_query = $conn->prepare("
                SELECT p.partner_id, u1.user_id AS user_id1, u1.full_name AS user1, 
                       COALESCE(u2.user_id, u1.user_id) AS user_id2, COALESCE(u2.full_name, u1.full_name) AS user2
                FROM Partners p
                JOIN Users u1 ON p.user_id1 = u1.user_id
                LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
                WHERE p.work_day = ?
            ");
            $partner_query->execute([$work_day]);
            $partners = $partner_query->fetchAll(PDO::FETCH_ASSOC);

            foreach ($partners as $partner) {
                // بررسی اینکه آیا اطلاعات قبلاً ثبت شده است؟
                $detail_query = $conn->prepare("
                    SELECT * FROM Work_Details WHERE work_date = ? AND work_month_id = ? AND partner_id = ?
                ");
                $detail_query->execute([$work_date, $work_month_id, $partner['partner_id']]);
                $existing_detail = $detail_query->fetch(PDO::FETCH_ASSOC);

                if (!$existing_detail) {
                    $insert_query = $conn->prepare("
                        INSERT INTO Work_Details (work_month_id, work_date, work_day, partner_id, agency_owner_id) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $insert_query->execute([$work_month_id, $work_date, $work_day, $partner['partner_id'], $partner['user_id1']]);
                }

                // دریافت اطلاعات نهایی برای نمایش
                $work_details[] = [
                    'work_date' => $work_date,
                    'work_day' => $work_day,
                    'partner_id' => $partner['partner_id'],
                    'user1' => $partner['user1'],
                    'user2' => $partner['user2'],
                    'user_id1' => $partner['user_id1'],
                    'user_id2' => $partner['user_id2'],
                    'agency_owner_id' => $existing_detail ? $existing_detail['agency_owner_id'] : $partner['user_id1']
                ];
            }
        }
    }
}

// فیلتر بر اساس همکار (اگه انتخاب شده باشه)
$filtered_work_details = $work_details;
if (isset($_GET['user_id'])) {
    $user_id = (int)$_GET['user_id'];
    $filtered_work_details = array_filter($work_details, function($detail) use ($user_id) {
        return $detail['user_id1'] == $user_id || $detail['user_id2'] == $user_id;
    });
}
?>

<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اطلاعات کاری</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body dir="rtl">

<div class="container mt-3">
    <h2>انتخاب ماه کاری</h2>
    <form method="GET" class="row g-3">
        <div class="col-auto">
            <select name="work_month_id" class="form-select" onchange="this.form.submit()">
                <option value="">انتخاب کنید</option>
                <?php foreach ($months as $month): ?>
                    <option value="<?= $month['work_month_id'] ?>" <?= isset($_GET['work_month_id']) && $_GET['work_month_id'] == $month['work_month_id'] ? 'selected' : '' ?>>
                        <?= gregorian_to_jalali_format($month['start_date']) ?> تا <?= gregorian_to_jalali_format($month['end_date']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <select name="user_id" class="form-select" onchange="this.form.submit()">
                <option value="">همه همکاران</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= $user['user_id'] ?>" <?= isset($_GET['user_id']) && $_GET['user_id'] == $user['user_id'] ? 'selected' : '' ?>>
                        <?= $user['full_name'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if (!empty($filtered_work_details)): ?>
        <h2 class="mt-4">جدول اطلاعات کاری</h2>
        <table class="table table-striped">
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
        <div class="alert alert-warning mt-3">اطلاعاتی وجود ندارد.</div>
    <?php endif; ?>
</div>

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

</body>
</html>