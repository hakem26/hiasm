<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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
    return "$jy/$jm/$jd";
}

// دریافت سال‌های موجود از Work_Months
$current_year = (int)jdate('Y'); // تبدیل به عدد
$years = range($current_year, $current_year - 40);

// دریافت سال انتخاب‌شده (پیش‌فرض سال جاری)
$selected_year = $_GET['year'] ?? $current_year;

// دریافت لیست ماه‌های کاری بر اساس سال انتخاب‌شده
$stmt = $pdo->prepare("SELECT * FROM Work_Months WHERE YEAR(start_date) = ? ORDER BY start_date DESC");
$stmt->execute([$selected_year]);
$work_months = $stmt->fetchAll(PDO::FETCH_ASSOC);

// دریافت لیست کاربران برای انتخاب همکاران
$users = $pdo->query("SELECT user_id, full_name FROM Users WHERE role = 'seller'")->fetchAll(PDO::FETCH_ASSOC);

// پردازش فرم افزودن ماه کاری
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_work_month'])) {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $user_id1 = $_POST['user_id1'];
    $user_id2 = $_POST['user_id2'] ?: null;

    if ($start_date && $end_date && $user_id1) {
        // بررسی همپوشانی ماه‌های کاری برای کاربر
        $overlap_check = $pdo->prepare("
            SELECT * FROM Work_Months wm
            JOIN Partners p ON wm.work_month_id = p.work_month_id
            WHERE (p.user_id1 = ? OR p.user_id2 = ? OR p.user_id1 = ? OR p.user_id2 = ?)
            AND (
                (start_date <= ? AND end_date >= ?) OR
                (start_date <= ? AND end_date >= ?) OR
                (start_date >= ? AND end_date <= ?)
            )
        ");
        $overlap_check->execute([$user_id1, $user_id1, $user_id2, $user_id2, $end_date, $start_date, $end_date, $start_date, $start_date, $end_date]);
        $overlap = $overlap_check->fetch();

        if ($overlap) {
            echo "<script>alert('همپوشانی در ماه‌های کاری برای این همکار وجود دارد!');</script>";
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO Work_Months (start_date, end_date) VALUES (?, ?)");
                $stmt->execute([$start_date, $end_date]);
                $work_month_id = $pdo->lastInsertId();

                $partner_stmt = $pdo->prepare("INSERT INTO Partners (work_month_id, user_id1, user_id2, work_day) VALUES (?, ?, ?, ?)");
                for ($day = 1; $day <= 7; $day++) {
                    $partner_stmt->execute([$work_month_id, $user_id1, $user_id2, $day]);
                }

                $pdo->commit();
                echo "<script>alert('ماه کاری با موفقیت ثبت شد!'); window.location.href='work_months.php';</script>";
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<script>alert('خطا در ثبت ماه کاری: " . $e->getMessage() . "');</script>";
            }
        }
    } else {
        echo "<script>alert('لطفاً همه فیلدها را پر کنید!');</script>";
    }
}
?>

<div class="container-fluid mt-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="card-title">ماه‌های کاری</h5>
    </div>

    <!-- فیلتر سال -->
    <form method="GET" class="row g-3 mb-3">
        <div class="col-auto">
            <select name="year" class="form-select" onchange="this.form.submit()">
                <?php foreach ($years as $year): ?>
                    <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                        <?= $year ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <!-- فرم افزودن ماه کاری -->
    <form method="POST" class="row g-3 mb-3">
        <div class="col-auto">
            <input type="text" name="start_date" class="form-control persian-date" placeholder="تاریخ شروع (میلادی)" required>
        </div>
        <div class="col-auto">
            <input type="text" name="end_date" class="form-control persian-date" placeholder="تاریخ پایان (میلادی)" required>
        </div>
        <div class="col-auto">
            <select name="user_id1" class="form-select" required>
                <option value="">انتخاب همکار 1</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <select name="user_id2" class="form-select">
                <option value="">انتخاب همکار 2</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" name="add_work_month" class="btn btn-primary">افزودن ماه کاری</button>
        </div>
    </form>

    <!-- جدول ماه‌های کاری -->
    <?php if (!empty($work_months)): ?>
        <table class="table table-light table-hover">
            <thead>
                <tr>
                    <th>شناسه</th>
                    <th>تاریخ شروع</th>
                    <th>تاریخ پایان</th>
                    <th>همکاران</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($work_months as $month): ?>
                    <?php
                    $partner_query = $pdo->prepare("
                        SELECT p.*, u1.full_name AS user1, u2.full_name AS user2
                        FROM Partners p
                        JOIN Users u1 ON p.user_id1 = u1.user_id
                        LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
                        WHERE p.work_month_id = ?
                        LIMIT 1
                    ");
                    $partner_query->execute([$month['work_month_id']]);
                    $partner = $partner_query->fetch(PDO::FETCH_ASSOC);
                    ?>
                    <tr>
                        <td><?= $month['work_month_id'] ?></td>
                        <td><?= gregorian_to_jalali_format($month['start_date']) ?></td>
                        <td><?= gregorian_to_jalali_format($month['end_date']) ?></td>
                        <td>
                            <?= htmlspecialchars($partner['user1']) ?>
                            <?= $partner['user2'] ? ' - ' . htmlspecialchars($partner['user2']) : '' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="alert alert-warning text-center">ماه کاری‌ای وجود ندارد.</div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    $(".persian-date").persianDatepicker({
        format: 'YYYY-MM-DD',
        autoClose: true,
        initialValue: false
    });
});
</script>

<?php require_once 'footer.php'; ?>