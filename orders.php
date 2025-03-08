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

// دریافت ماه‌ها بر اساس سال انتخاب‌شده
$work_months = [];
if ($selected_year) {
    $stmt_months = $pdo->prepare("SELECT * FROM Work_Months WHERE YEAR(start_date) = ? ORDER BY start_date DESC");
    $stmt_months->execute([$selected_year]);
    $work_months = $stmt_months->fetchAll(PDO::FETCH_ASSOC);
}

// بررسی نقش کاربر
$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];

// دریافت لیست همکارها
$partners = [];
if ($is_admin) {
    $partners_query = $pdo->query("SELECT user_id, full_name FROM Users WHERE role = 'seller' ORDER BY full_name");
    $partners = $partners_query->fetchAll(PDO::FETCH_ASSOC);
} else {
    $partners_query = $pdo->prepare("
        SELECT DISTINCT u.user_id, u.full_name 
        FROM Partners p
        JOIN Users u ON u.user_id IN (p.user_id1, p.user_id2)
        WHERE (p.user_id1 = ? OR p.user_id2 = ?) AND u.user_id != ? AND u.role = 'seller'
        ORDER BY u.full_name
    ");
    $partners_query->execute([$current_user_id, $current_user_id, $current_user_id]);
    $partners = $partners_query->fetchAll(PDO::FETCH_ASSOC);
}

// دریافت اطلاعات روزها و فاکتورها بر اساس فیلترها
$work_details = [];
$orders = [];
$selected_work_month_id = $_GET['work_month_id'] ?? '';
$selected_partner_id = $_GET['user_id'] ?? '';
$selected_work_day_id = $_GET['work_day_id'] ?? '';

if ($selected_work_month_id) {
    // دریافت اطلاعات ماه
    $month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
    $month_query->execute([$selected_work_month_id]);
    $month = $month_query->fetch(PDO::FETCH_ASSOC);

    if ($month) {
        $start_date = new DateTime($month['start_date']);
        $end_date = new DateTime($month['end_date']);
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));

        // دریافت جفت‌های همکار
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

        // همگام‌سازی و ذخیره‌سازی روزها
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
                        $detail_query->execute([$work_date, $selected_work_month_id, $partner_id]);
                        $existing_detail = $detail_query->fetch(PDO::FETCH_ASSOC);

                        if ($existing_detail) {
                            $agency_owner_id = $existing_detail['agency_owner_id'];
                            $work_details[] = [
                                'work_details_id' => $existing_detail['id'],
                                'work_date' => $work_date,
                                'work_day' => number_to_day($adjusted_day_number),
                                'partner_id' => $partner_id,
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
        }

        // فیلتر بر اساس همکار
        if (!empty($selected_partner_id)) {
            $filtered_work_details = array_filter($work_details, function($detail) use ($selected_partner_id) {
                return $detail['user_id1'] == $selected_partner_id || $detail['user_id2'] == $selected_partner_id;
            });
            $work_details = array_values($filtered_work_details);
        }

        // دریافت فاکتورها بر اساس روز انتخاب‌شده
        if ($selected_work_day_id) {
            $stmt_orders = $pdo->prepare("
                SELECT o.order_id, o.customer_name, o.total_amount, o.discount, o.final_amount,
                       SUM(p.amount) AS paid_amount,
                       (o.final_amount - COALESCE(SUM(p.amount), 0)) AS remaining_amount,
                       wd.work_date, 
                       CONCAT(u1.full_name, ' - ', u2.full_name) AS partner_names
                FROM Orders o
                LEFT JOIN Payments p ON o.order_id = p.order_id
                JOIN Work_Details wd ON o.work_details_id = wd.id
                JOIN Users u1 ON u1.user_id = wd.partner_id
                JOIN Users u2 ON u2.user_id = wd.agency_owner_id
                WHERE o.work_details_id = ?
                GROUP BY o.order_id, o.customer_name, o.total_amount, o.discount, o.final_amount, wd.work_date, partner_names
            ");
            $stmt_orders->execute([$selected_work_day_id]);
            $orders = $stmt_orders->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سفارشات</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css"
        integrity="sha384-dpuaG1suU0eT09tx5plTaGMLBsfDLzUCCUXOY2j/LSvXYuG6Bqs43ALlhIqAJVRb" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/persian-datepicker.min.css" />
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
                    <?php foreach ($work_months as $month): ?>
                        <option value="<?= $month['work_month_id'] ?>" <?= $selected_work_month_id == $month['work_month_id'] ? 'selected' : '' ?>>
                            <?= gregorian_to_jalali_format($month['start_date']) ?> تا <?= gregorian_to_jalali_format($month['end_date']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="user_id" class="form-select" onchange="this.form.submit()">
                    <option value="">انتخاب همکار</option>
                    <?php foreach ($partners as $partner): ?>
                        <option value="<?= htmlspecialchars($partner['user_id']) ?>" <?= $selected_partner_id == $partner['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($partner['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="work_day_id" class="form-select" onchange="this.form.submit()">
                    <option value="">انتخاب روز</option>
                    <?php foreach ($work_details as $day): ?>
                        <option value="<?= $day['work_details_id'] ?>" <?= $selected_work_day_id == $day['work_details_id'] ? 'selected' : '' ?>>
                            <?= gregorian_to_jalali_format($day['work_date']) ?> (<?= $day['user1'] ?> - <?= $day['user2'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if (!$is_admin && $selected_work_day_id): ?>
            <div class="mb-3">
                <a href="add_order.php?work_details_id=<?= $selected_work_day_id ?>" class="btn btn-primary">ثبت سفارش جدید</a>
            </div>
        <?php endif; ?>

        <?php if ($selected_work_day_id): ?>
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
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/js/persian-date.min.js"></script>
    <script src="assets/js/persian-datepicker.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            $('select[name="year"]').change(function() {
                this.form.submit();
            });

            $('select[name="work_month_id"]').change(function() {
                this.form.submit();
            });

            $('select[name="user_id"]').change(function() {
                this.form.submit();
            });

            $('select[name="work_day_id"]').change(function() {
                this.form.submit();
            });
        });
    </script>

<?php require_once 'footer.php'; ?>