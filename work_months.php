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

// دریافت لیست ماه‌های کاری
$work_months_query = $pdo->query("SELECT * FROM Work_Months ORDER BY start_date DESC");
$work_months = $work_months_query->fetchAll(PDO::FETCH_ASSOC);

// اگر فرم ثبت ماه کاری ارسال شده باشد
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';

    if (!empty($start_date) && !empty($end_date)) {
        $start_date = date('Y-m-d', strtotime($start_date));
        $end_date = date('Y-m-d', strtotime($end_date));

        if ($start_date && $end_date && $start_date <= $end_date) {
            $stmt = $pdo->prepare("INSERT INTO Work_Months (start_date, end_date) VALUES (?, ?)");
            $stmt->execute([$start_date, $end_date]);
            header("Location: work_months.php");
            exit;
        } else {
            $error = "لطفاً تاریخ‌های معتبر وارد کنید و مطمئن شوید تاریخ شروع قبل از تاریخ پایان باشد.";
        }
    } else {
        $error = "لطفاً هر دو تاریخ را وارد کنید.";
    }
}

// حذف ماه کاری
if (isset($_GET['delete'])) {
    $work_month_id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM Work_Months WHERE work_month_id = ?");
    $stmt->execute([$work_month_id]);
    header("Location: work_months.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت ماه‌های کاری</title>
    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css"
        integrity="sha384-dpuaG1suU0eT09tx5plTaGMLBsfDLzUCCUXOY2j/LSvXYuG6Bqs43ALlhIqAJVRb" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Persian Datepicker CSS -->
    <link rel="stylesheet" href="assets/css/persian-datepicker.min.css" />
    <!-- Custom CSS -->
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container-fluid mt-5">
        <h5 class="card-title mb-4">مدیریت ماه‌های کاری</h5>

        <!-- فرم ثبت ماه کاری -->
        <form method="POST" class="row g-3 mb-4">
            <div class="col-md-3">
                <label for="start_date" class="form-label">تاریخ شروع</label>
                <input type="text" class="form-control persian-date" id="start_date" name="start_date" required>
            </div>
            <div class="col-md-3">
                <label for="end_date" class="form-label">تاریخ پایان</label>
                <input type="text" class="form-control persian-date" id="end_date" name="end_date" required>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary">ثبت ماه کاری</button>
            </div>
        </form>

        <!-- نمایش خطا -->
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- لیست ماه‌های کاری -->
        <?php if (!empty($work_months)): ?>
            <table class="table table-light table-hover">
                <thead>
                    <tr>
                        <th>تاریخ شروع</th>
                        <th>تاریخ پایان</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($work_months as $month): ?>
                        <tr>
                            <td><?= gregorian_to_jalali_format($month['start_date']) ?></td>
                            <td><?= gregorian_to_jalali_format($month['end_date']) ?></td>
                            <td>
                                <a href="?delete=<?= $month['work_month_id'] ?>" class="btn btn-danger btn-sm"
                                   onclick="return confirm('آیا از حذف این ماه کاری مطمئن هستید؟');">
                                    <i class="fas fa-trash"></i> حذف
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="alert alert-warning text-center">ماه کاری‌ای ثبت نشده است.</div>
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"
        integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="assets/js/persian-date.min.js"></script>
    <script src="assets/js/persian-datepicker.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            $(".persian-date").persianDatepicker({
                format: "YYYY/MM/DD",
                autoClose: true,
                initialValue: false,
                onSelect: function(unix) {
                    let date = new persianDate(unix).toCalendar("gregorian").format("YYYY-MM-DD");
                    $(this).val(date);
                }
            });
        });
    </script>

<?php require_once 'footer.php'; ?>