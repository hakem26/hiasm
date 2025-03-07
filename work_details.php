<?php
require 'db.php'; // فایل اتصال به دیتابیس

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
        $interval = new DateInterval('P1D'); // افزایش یک روزه
        $date_range = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));

        foreach ($date_range as $date) {
            $work_date = $date->format('Y-m-d');
            $work_day = $date->format('l'); // دریافت نام روز هفته

            // پیدا کردن جفت همکارانی که در این روز کار می‌کنند
            $partner_query = $conn->prepare("
                SELECT p.partner_id, u1.full_name AS user1, u2.full_name AS user2, p.user_id1, p.user_id2
                FROM Partners p
                JOIN Users u1 ON p.user_id1 = u1.user_id
                JOIN Users u2 ON p.user_id2 = u2.user_id
                WHERE p.work_day = ?
            ");
            $partner_query->execute([$work_day]);
            $partner = $partner_query->fetch(PDO::FETCH_ASSOC);

            if ($partner) {
                // بررسی اینکه آیا اطلاعات قبلاً ثبت شده است؟
                $detail_query = $conn->prepare("
                    SELECT * FROM Work_Details WHERE work_date = ? AND work_month_id = ?
                ");
                $detail_query->execute([$work_date, $work_month_id]);
                $existing_detail = $detail_query->fetch(PDO::FETCH_ASSOC);

                if (!$existing_detail) {
                    // ثبت اطلاعات جدید در صورت عدم وجود
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
?>

<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اطلاعات کاری</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>

<h2>انتخاب ماه کاری</h2>
<form method="GET">
    <select name="work_month_id" onchange="this.form.submit()">
        <option value="">انتخاب کنید</option>
        <?php foreach ($months as $month): ?>
            <option value="<?= $month['work_month_id'] ?>" <?= isset($_GET['work_month_id']) && $_GET['work_month_id'] == $month['work_month_id'] ? 'selected' : '' ?>>
                <?= $month['start_date'] ?> تا <?= $month['end_date'] ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>

<?php if (!empty($work_details)): ?>
    <h2>جدول اطلاعات کاری</h2>
    <table border="1">
        <tr>
            <th>تاریخ</th>
            <th>روز هفته</th>
            <th>همکاران</th>
            <th>آژانس</th>
        </tr>
        <?php foreach ($work_details as $work): ?>
            <tr>
                <td><?= $work['work_date'] ?></td>
                <td><?= $work['work_day'] ?></td>
                <td><?= $work['user1'] ?> - <?= $work['user2'] ?></td>
                <td>
                    <select class="agency-select" data-id="<?= $work['work_date'] ?>">
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
    </table>
<?php endif; ?>

<script>
$(document).ready(function () {
    $(".agency-select").change(function () {
        var work_date = $(this).data("id");
        var agency_owner_id = $(this).val();

        $.post("update_agency.php", { work_date: work_date, agency_owner_id: agency_owner_id }, function (response) {
            alert(response);
        });
    });
});
</script>

</body>
</html>
