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

// دریافت سال جاری (با بررسی خطا)
$current_gregorian_year = date('Y');
list($current_year) = gregorian_to_jalali($current_gregorian_year, 1, 1); // تبدیل سال میلادی به شمسی
if (!$current_year || $current_year < 1300) {
    $current_year = 1403; // پیش‌فرض
}
$years = range($current_year, $current_year - 40);

// دریافت سال انتخاب‌شده (پیش‌فرض سال جاری)
$selected_year = $_GET['year'] ?? $current_year;

// دریافت لیست ماه‌های کاری بر اساس سال انتخاب‌شده
$stmt = $pdo->prepare("SELECT * FROM Work_Months WHERE YEAR(start_date) = ? ORDER BY start_date DESC");
$stmt->execute([$selected_year]);
$work_months = $stmt->fetchAll(PDO::FETCH_ASSOC);

// پردازش فرم افزودن ماه کاری
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_work_month'])) {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];

    if ($start_date && $end_date) {
        // بررسی همپوشانی ماه‌های کاری
        $overlap_check = $pdo->prepare("
            SELECT * FROM Work_Months
            WHERE (
                (start_date <= ? AND end_date >= ?) OR
                (start_date <= ? AND end_date >= ?) OR
                (start_date >= ? AND end_date <= ?)
            )
        ");
        $overlap_check->execute([$end_date, $start_date, $end_date, $start_date, $start_date, $end_date]);
        $overlap = $overlap_check->fetch();

        if ($overlap) {
            echo "<script>alert('همپوشانی در ماه‌های کاری وجود دارد!');</script>";
        } else {
            $stmt = $pdo->prepare("INSERT INTO Work_Months (start_date, end_date) VALUES (?, ?)");
            $stmt->execute([$start_date, $end_date]);
            echo "<script>alert('ماه کاری با موفقیت ثبت شد!'); window.location.href='work_months.php';</script>";
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
                </tr>
            </thead>
            <tbody>
                <?php foreach ($work_months as $month): ?>
                    <tr>
                        <td><?= $month['work_month_id'] ?></td>
                        <td><?= gregorian_to_jalali_format($month['start_date']) ?></td>
                        <td><?= gregorian_to_jalali_format($month['end_date']) ?></td>
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