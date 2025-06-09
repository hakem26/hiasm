<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

function gregorian_year_to_jalali($gregorian_year)
{
    list($jy, $jm, $jd) = gregorian_to_jalali($gregorian_year, 1, 1);
    return $jy;
}

// تابع محاسبه روز هفته (شمسی)
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
    return $adjusted_day_number; // 1 (شنبه) تا 7 (جمعه)
}

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
    // محاسبه بازه میلادی برای سال شمسی انتخاب‌شده
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
$selected_work_month_id = $_GET['work_month_id'] ?? null;
$selected_partner_id = $_GET['user_id'] ?? null;
$selected_work_day_id = $_GET['work_day_id'] ?? null;
$page = (int) ($_GET['page'] ?? 1);
$per_page = 10;

// فقط اگه ماه کاری انتخاب شده باشه، همکاران و روزها رو بگیریم
if ($selected_work_month_id) {
    $month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
    $month_query->execute([$selected_work_month_id]);
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
            $partners_query->execute([$selected_work_month_id]);
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
            $partners_query->execute([$selected_work_month_id, $current_user_id, $current_user_id, $current_user_id]);
        }
        $partners = $partners_query->fetchAll(PDO::FETCH_ASSOC);

        // دریافت روزهای کاری برای ماه و همکار انتخاب‌شده
        $details_query_params = [$selected_work_month_id];
        $details_query = "
            SELECT wd.id, wd.work_date, wd.partner_id, 
                   u1.full_name AS user1, u2.full_name AS user2,
                   u1.user_id AS user_id1, u2.user_id AS user_id2
            FROM Work_Details wd
            JOIN Partners p ON wd.partner_id = p.partner_id
            JOIN Users u1 ON p.user_id1 = u1.user_id
            LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
            WHERE wd.work_month_id = ?
        ";

        if ($selected_partner_id) {
            $details_query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
            $details_query_params[] = $selected_partner_id;
            $details_query_params[] = $selected_partner_id;
        }

        if (!$is_admin) {
            $details_query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
            $details_query_params[] = $current_user_id;
            $details_query_params[] = $current_user_id;
        }

        $details_query = $pdo->prepare($details_query);
        $details_query->execute($details_query_params);
        $work_details_raw = $details_query->fetchAll(PDO::FETCH_ASSOC);

        foreach ($work_details_raw as $detail) {
            $work_date = $detail['work_date'];
            $day_number = calculate_day_of_week($work_date);

            $work_details[] = [
                'work_details_id' => $detail['id'],
                'work_date' => $work_date,
                'work_day' => number_to_day($day_number),
                'partner_id' => $detail['partner_id'],
                'user1' => $detail['user1'],
                'user2' => $detail['user2'] ?: 'نامشخص',
                'user_id1' => $detail['user_id1'],
                'user_id2' => $detail['user_id2']
            ];
        }
    }
}

// دریافت سفارش‌ها با نام همکار
$orders_query = "
    SELECT o.order_id, o.customer_name, o.total_amount, o.discount, o.final_amount,
           SUM(op.amount) AS paid_amount,
           (o.final_amount - COALESCE(SUM(op.amount), 0)) AS remaining_amount,
           wd.work_date, ";

if ($is_admin) {
    $orders_query .= "
        (SELECT CONCAT(u1.full_name, ' - ', COALESCE(u2.full_name, u1.full_name))
         FROM Partners p
         LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
         LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
         WHERE p.partner_id = wd.partner_id) AS partners_names, ";
} else {
    $orders_query .= "
        COALESCE(
            (SELECT CASE 
                WHEN p.user_id1 = ? THEN u2.full_name 
                WHEN p.user_id2 = ? THEN u1.full_name 
                ELSE 'نامشخص' 
            END
            FROM Partners p
            LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
            LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
            WHERE p.partner_id = wd.partner_id),
            'نامشخص'
        ) AS partner_name, ";
}

$orders_query .= "
           wd.id AS work_details_id
    FROM Orders o
    LEFT JOIN Order_Payments op ON o.order_id = op.order_id
    LEFT JOIN Work_Details wd ON o.work_details_id = wd.id";

$conditions = [];
$params = [];
if (!$is_admin) {
    $params[] = $current_user_id;
    $params[] = $current_user_id;
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
    $conditions[] = "wd.work_date >= ? AND wd.work_date < ?";
    $params[] = $start_date;
    $params[] = $end_date;
}

if ($selected_work_month_id) {
    $conditions[] = "wd.work_month_id = ?";
    $params[] = $selected_work_month_id;
}

if ($selected_partner_id) {
    $conditions[] = "EXISTS (
        SELECT 1 FROM Partners p 
        WHERE p.partner_id = wd.partner_id 
        AND (p.user_id1 = ? OR p.user_id2 = ?)
    )";
    $params[] = $selected_partner_id;
    $params[] = $selected_partner_id;
}

if ($selected_work_day_id) {
    $conditions[] = "wd.id = ?";
    $params[] = $selected_work_day_id;
}

if (!empty($conditions)) {
    $orders_query .= " WHERE " . implode(" AND ", $conditions);
}

$orders_query .= "
    GROUP BY o.order_id, o.customer_name, o.total_amount, o.discount, o.final_amount, wd.work_date";
if ($is_admin) {
    $orders_query .= ", partners_names";
} else {
    $orders_query .= ", partner_name";
}

// تعداد کل فاکتورها
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM ($orders_query) AS subquery");
$stmt_count->execute($params);
$total_orders = $stmt_count->fetchColumn();
$total_pages = ceil($total_orders / $per_page);
$offset = ($page - 1) * $per_page;

// حذف LIMIT و OFFSET از کوئری
// $orders_query .= " LIMIT " . (int) $per_page . " OFFSET " . (int) $offset; // این خط رو حذف کن

// اجرای کوئری بدون LIMIT
$stmt_orders = $pdo->prepare($orders_query);
$stmt_orders->execute($params);
$orders = $stmt_orders->fetchAll(PDO::FETCH_ASSOC);

// تعداد کل فاکتورها (برای info توی DataTables)
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM ($orders_query) AS subquery");
$stmt_count->execute($params);
$total_orders = $stmt_count->fetchColumn();
?>

<div class="container-fluid">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-<?= $_SESSION['message']['type'] ?> text-center">
            <?= htmlspecialchars($_SESSION['message']['text']) ?>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>
    <h5 class="card-title mb-4">لیست سفارشات</h5>

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
        <div class="col-auto">
            <select name="work_month_id" class="form-select" onchange="this.form.submit()">
                <option value="" <?= !$selected_work_month_id ? 'selected' : '' ?>>انتخاب ماه</option>
                <?php foreach ($work_months as $month): ?>
                    <option value="<?= $month['work_month_id'] ?>" <?= $selected_work_month_id == $month['work_month_id'] ? 'selected' : '' ?>>
                        <?= gregorian_to_jalali_format($month['start_date']) ?> تا
                        <?= gregorian_to_jalali_format($month['end_date']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <select name="user_id" class="form-select" onchange="this.form.submit()">
                <option value="" <?= !$selected_partner_id ? 'selected' : '' ?>>انتخاب همکار</option>
                <?php foreach ($partners as $partner): ?>
                    <option value="<?= htmlspecialchars($partner['user_id']) ?>"
                        <?= $selected_partner_id == $partner['user_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($partner['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <select name="work_day_id" class="form-select" onchange="this.form.submit()">
                <option value="" <?= !$selected_work_day_id ? 'selected' : '' ?>>انتخاب روز</option>
                <?php foreach ($work_details as $day): ?>
                    <option value="<?= $day['work_details_id'] ?>" <?= $selected_work_day_id == $day['work_details_id'] ? 'selected' : '' ?>>
                        <?= gregorian_to_jalali_format($day['work_date']) ?> (<?= $day['user1'] ?> -
                        <?= $day['user2'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if (!$is_admin && $selected_work_day_id): ?>
        <div class="mb-3">
            <a href="add_order.php?work_details_id=<?= $selected_work_day_id ?>" class="btn btn-primary">ثبت سفارش
                جدید</a>
        </div>
    <?php endif; ?>

    <?php if (!empty($orders)): ?>
        <div class="table-responsive" style="overflow-x: auto; width: 100%;">
            <table id="ordersTable" class="table table-light table-hover display nowrap"
                style="width: 100%; min-width: 800px; table-layout: fixed;">
                <thead>
                    <tr>
                        <th>شماره</th>
                        <th>تاریخ</th>
                        <th><?= $is_admin ? 'همکاران' : 'نام همکار' ?></th>
                        <th>نام مشتری</th>
                        <th>مبلغ کل فاکتور</th>
                        <th>مبلغ پرداختی</th>
                        <th>مانده حساب</th>
                        <?php if (!$is_admin): ?>
                            <th>فاکتور</th>
                            <th>اطلاعات پرداخت</th>
                        <?php endif; ?>
                        <th>پرینت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?= $order['order_id'] ?></td>
                            <td><?= $order['work_date'] ? gregorian_to_jalali_format($order['work_date']) : 'نامشخص' ?></td>
                            <td><?= htmlspecialchars($is_admin ? $order['partners_names'] : $order['partner_name']) ?></td>
                            <td><?= htmlspecialchars($order['customer_name']) ?></td>
                            <td><?= number_format($order['total_amount'], 0) ?></td>
                            <td><?= number_format($order['paid_amount'] ?? 0, 0) ?></td>
                            <td><?= number_format($order['remaining_amount'], 0) ?></td>
                            <?php if (!$is_admin): ?>
                                <td>
                                    <a href="edit_order.php?order_id=<?= $order['order_id'] ?>"
                                        class="btn btn-warning btn-sm me-2"><i class="fas fa-edit"></i></a>
                                    <a href="delete_order.php?order_id=<?= $order['order_id'] ?>"
                                        class="btn btn-danger btn-sm" onclick="return confirm('حذف؟');"><i
                                            class="fas fa-trash"></i></a>
                                </td>
                                <td>
                                    <a href="edit_payment.php?order_id=<?= $order['order_id'] ?>"
                                        class="btn btn-primary btn-sm me-2"><i class="fas fa-edit"></i></a>
                                </td>
                            <?php endif; ?>
                            <td>
                                <a href="print_invoice.php?order_id=<?= $order['order_id'] ?>"
                                    class="btn btn-success btn-sm"><i class="fas fa-eye"></i> مشاهده</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="alert alert-warning text-center">سفارشی ثبت نشده است.</div>
    <?php endif; ?>
</div>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function () {
        $('#ordersTable').DataTable({
            "pageLength": 10,
            "scrollX": true,
            "scrollCollapse": true,
            "paging": true,
            "autoWidth": true, // فعال کردن تنظیم خودکار عرض
            "ordering": true,
            "order": [[0, 'desc']], // مرتب‌سازی پیش‌فرض ستون اول (شماره) از زیاد به کم
            "responsive": false,
            "language": {
                "decimal": "",
                "emptyTable": "داده‌ای در جدول وجود ندارد",
                "info": "نمایش _START_ تا _END_ از _TOTAL_ ردیف",
                "infoEmpty": "نمایش 0 تا 0 از 0 ردیف",
                "infoFiltered": "(فیلتر شده از _MAX_ ردیف کل)",
                "lengthMenu": "نمایش _MENU_ ردیف",
                "loadingRecords": "در حال بارگذاری...",
                "processing": "در حال پردازش...",
                "search": "جستجو:",
                "zeroRecords": "هیچ ردیف منطبقی یافت نشد",
                "paginate": {
                    "first": "اولین",
                    "last": "آخرین",
                    "next": "بعدی",
                    "previous": "قبلی"
                }
            },
            "columnDefs": [
                { "targets": "_all", "className": "text-center" },
            ],
            data: <?= json_encode($orders) ?>, // کل داده‌ها
            columns: [
                { data: 'order_id' },
                { data: 'work_date' },
                { data: <?= $is_admin ? "'partners_names'" : "'partner_name'" ?> },
                { data: 'customer_name' },
                { data: 'total_amount' },
                { data: 'paid_amount' },
                { data: 'remaining_amount' },
                <?php if (!$is_admin): ?>
                {
                    data: null, render: function (data) {
                        return '<a href="edit_order.php?order_id=' + data.order_id + '" class="btn btn-warning btn-sm me-2"><i class="fas fa-edit"></i></a>' +
                            '<a href="delete_order.php?order_id=' + data.order_id + '" class="btn btn-danger btn-sm" onclick="return confirm(\'حذف؟\');"><i class="fas fa-trash"></i></a>';
                    }
                },
                {
                    data: null, render: function (data) {
                        return '<a href="edit_payment.php?order_id=' + data.order_id + '" class="btn btn-primary btn-sm me-2"><i class="fas fa-edit"></i></a>';
                    }
                },
                <?php endif; ?>
                {
                    data: null, render: function (data) {
                        return '<a href="print_invoice.php?order_id=' + data.order_id + '" class="btn btn-success btn-sm"><i class="fas fa-eye"></i> مشاهده</a>';
                    }
                }
            ]
        });

        // حذف دکمه "بارگذاری بیشتر" (در صورت وجود)
        $('#loadMoreBtn').remove();

        // ارسال فرم هنگام تغییر سلکت‌ها
        $('select[name="year"]').change(function () {
            this.form.submit();
        });

        $('select[name="work_month_id"]').change(function () {
            this.form.submit();
        });

        $('select[name="user_id"]').change(function () {
            this.form.submit();
        });

        $('select[name="work_day_id"]').change(function () {
            this.form.submit();
        });
    });
</script>

<?php require_once 'footer.php'; ?>