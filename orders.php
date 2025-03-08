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

// بررسی نقش کاربر
$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];

// دریافت سال‌های موجود بر اساس Work_Details
$stmt_years = $pdo->prepare("
    SELECT DISTINCT YEAR(work_date) AS year 
    FROM Work_Details 
    WHERE user_id1 = ? OR user_id2 = ?
    ORDER BY year DESC
");
$stmt_years->execute([$current_user_id, $current_user_id]);
$years_db = $stmt_years->fetchAll(PDO::FETCH_ASSOC);
$years = array_column($years_db, 'year');

// دریافت سال جاری (میلادی) به‌عنوان پیش‌فرض
$current_year = date('Y');
$selected_year = $_GET['year'] ?? (in_array($current_year, $years) ? $current_year : (!empty($years) ? $years[0] : null));

// دریافت ماه‌ها بر اساس سال انتخاب‌شده
$months = [];
if ($selected_year) {
    $stmt_months = $pdo->prepare("
        SELECT DISTINCT wm.work_month_id, wm.start_date, wm.end_date 
        FROM Work_Months wm
        JOIN Work_Details wd ON wm.work_month_id = wd.work_month_id
        WHERE YEAR(wm.start_date) = ? AND (wd.user_id1 = ? OR wd.user_id2 = ?)
        ORDER BY wm.start_date DESC
    ");
    $stmt_months->execute([$selected_year, $current_user_id, $current_user_id]);
    $months = $stmt_months->fetchAll(PDO::FETCH_ASSOC);
}

// دریافت روزهای کاری بر اساس ماه انتخاب‌شده
$work_days = [];
$selected_work_month_id = $_GET['work_month_id'] ?? '';
if ($selected_work_month_id) {
    $stmt_days = $pdo->prepare("
        SELECT work_details_id, work_date, user1, user2 
        FROM Work_Details 
        WHERE work_month_id = ? AND (user_id1 = ? OR user_id2 = ?)
        ORDER BY work_date ASC
    ");
    $stmt_days->execute([$selected_work_month_id, $current_user_id, $current_user_id]);
    $work_days = $stmt_days->fetchAll(PDO::FETCH_ASSOC);
}

// دریافت سفارشات بر اساس فیلترها
$orders = [];
$selected_work_day_id = $_GET['work_day_id'] ?? '';
if ($selected_work_day_id) {
    $stmt_orders = $pdo->prepare("
        SELECT o.order_id, o.customer_name, o.total_amount, o.discount, o.final_amount,
               SUM(p.amount) AS paid_amount,
               (o.final_amount - COALESCE(SUM(p.amount), 0)) AS remaining_amount,
               wd.work_date, CONCAT(wd.user1, ' - ', wd.user2) AS partner_names
        FROM Orders o
        LEFT JOIN Payments p ON o.order_id = p.order_id
        JOIN Work_Details wd ON o.work_details_id = wd.work_details_id
        WHERE o.work_details_id = ?
        GROUP BY o.order_id, o.customer_name, o.total_amount, o.discount, o.final_amount, wd.work_date, partner_names
    ");
    $stmt_orders->execute([$selected_work_day_id]);
    $orders = $stmt_orders->fetchAll(PDO::FETCH_ASSOC);
} elseif ($selected_work_month_id) {
    $stmt_orders = $pdo->prepare("
        SELECT o.order_id, o.customer_name, o.total_amount, o.discount, o.final_amount,
               SUM(p.amount) AS paid_amount,
               (o.final_amount - COALESCE(SUM(p.amount), 0)) AS remaining_amount,
               wd.work_date, CONCAT(wd.user1, ' - ', wd.user2) AS partner_names
        FROM Orders o
        LEFT JOIN Payments p ON o.order_id = p.order_id
        JOIN Work_Details wd ON o.work_details_id = wd.work_details_id
        WHERE wd.work_month_id = ? AND (wd.user_id1 = ? OR wd.user_id2 = ?)
        GROUP BY o.order_id, o.customer_name, o.total_amount, o.discount, o.final_amount, wd.work_date, partner_names
    ");
    $stmt_orders->execute([$selected_work_month_id, $current_user_id, $current_user_id]);
    $orders = $stmt_orders->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سفارشات</title>
    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css"
        integrity="sha384-dpuaG1suU0eT09tx5plTaGMLBsfDLzUCCUXOY2j/LSvXYuG6Bqs43ALlhIqAJVRb" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Persian Datepicker CSS -->
    <link rel="stylesheet" href="assets/css/persian-datepicker.min.css" />
    <!-- Custom CSS -->
    <link rel="stylesheet" href="style.css">
    <style>
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        table {
            min-width: 800px;
            table-layout: auto;
        }
        th, td {
            white-space: nowrap;
            padding: 8px 12px;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-5">
        <h5 class="card-title mb-4">لیست سفارشات</h5>

        <!-- فیلترها -->
        <form method="GET" class="row g-3 mb-3">
            <div class="col-auto">
                <select name="year" class="form-select" onchange="this.form.submit()">
                    <option value="">انتخاب سال</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                            <?= gregorian_year_to_jalali($year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="work_month_id" class="form-select" onchange="this.form.submit()">
                    <option value="">انتخاب ماه</option>
                    <?php foreach ($months as $month): ?>
                        <option value="<?= $month['work_month_id'] ?>" <?= $selected_work_month_id == $month['work_month_id'] ? 'selected' : '' ?>>
                            <?= gregorian_to_jalali_format($month['start_date']) ?> تا <?= gregorian_to_jalali_format($month['end_date']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="work_day_id" class="form-select" onchange="this.form.submit()">
                    <option value="">همه روزها</option>
                    <?php foreach ($work_days as $day): ?>
                        <option value="<?= $day['work_details_id'] ?>" <?= $selected_work_day_id == $day['work_details_id'] ? 'selected' : '' ?>>
                            <?= gregorian_to_jalali_format($day['work_date']) ?> (<?= $day['user1'] ?> - <?= $day['user2'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!$is_admin): ?>
                <div class="col-auto">
                    <a href="add_order.php" class="btn btn-primary">ثبت سفارش جدید</a>
                </div>
            <?php endif; ?>
        </form>

        <!-- لیست سفارشات -->
        <?php if (empty($orders)): ?>
            <div class="alert alert-warning text-center">سفارشی ثبت نشده است.</div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="table table-light table-hover">
                    <thead>
                        <tr>
                            <th>تاریخ</th>
                            <th>نام همکار</th>
                            <th>شماره فاکتور</th>
                            <th>نام مشتری</th>
                            <th>مبلغ کل فاکتور</th>
                            <th>مبلغ پرداختی</th>
                            <th>مانده حساب</th>
                            <th>فاکتور</th>
                            <th>اطلاعات پرداخت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><?= gregorian_to_jalali_format($order['work_date']) ?></td>
                                <td><?= $order['partner_names'] ?></td>
                                <td><?= $order['order_id'] ?></td>
                                <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                <td><?= number_format($order['final_amount'], 0) ?> تومان</td>
                                <td><?= number_format($order['paid_amount'] ?? 0, 0) ?> تومان</td>
                                <td><?= number_format($order['remaining_amount'], 0) ?> تومان</td>
                                <td>
                                    <a href="edit_order.php?order_id=<?= $order['order_id'] ?>" class="btn btn-primary btn-sm me-2"><i class="fas fa-edit"></i></a>
                                    <a href="delete_order.php?order_id=<?= $order['order_id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('حذف؟');"><i class="fas fa-trash"></i></a>
                                </td>
                                <td>
                                    <a href="edit_payment.php?order_id=<?= $order['order_id'] ?>" class="btn btn-primary btn-sm me-2"><i class="fas fa-edit"></i></a>
                                    <a href="delete_payment.php?order_id=<?= $order['order_id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('حذف؟');"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/js/persian-date.min.js"></script>
    <script src="assets/js/persian-datepicker.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // AJAX برای به‌روز‌رسانی ماه‌ها بر اساس سال
            $('select[name="year"]').change(function() {
                let year = $(this).val();
                if (year) {
                    $.ajax({
                        url: 'get_months.php',
                        type: 'POST',
                        data: { year: year, user_id: <?= $current_user_id ?> },
                        success: function(response) {
                            $('select[name="work_month_id"]').html(response);
                        }
                    });
                } else {
                    $('select[name="work_month_id"]').html('<option value="">انتخاب ماه</option>');
                }
            });

            // AJAX برای به‌روز‌رسانی روزهای کاری بر اساس ماه
            $('select[name="work_month_id"]').change(function() {
                let month_id = $(this).val();
                if (month_id) {
                    $.ajax({
                        url: 'get_work_days.php',
                        type: 'POST',
                        data: { month_id: month_id, user_id: <?= $current_user_id ?> },
                        success: function(response) {
                            $('select[name="work_day_id"]').html(response);
                        }
                    });
                } else {
                    $('select[name="work_day_id"]').html('<option value="">همه روزها</option>');
                }
            });
        });
    </script>

<?php require_once 'footer.php'; ?>