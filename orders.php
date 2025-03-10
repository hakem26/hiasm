<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

function gregorian_year_to_jalali($gregorian_year) {
    list($jy, $jm, $jd) = gregorian_to_jalali($gregorian_year, 1, 1);
    return $jy;
}

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

$stmt = $pdo->query("SELECT DISTINCT YEAR(start_date) AS year FROM Work_Months ORDER BY year DESC");
$years_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
$years = array_column($years_db, 'year');
$current_year = date('Y');
$selected_year = $_GET['year'] ?? 'all';

$work_months = [];
if ($selected_year && $selected_year != 'all') {
    $stmt_months = $pdo->prepare("SELECT * FROM Work_Months WHERE YEAR(start_date) = ? ORDER BY start_date DESC");
    $stmt_months->execute([$selected_year]);
    $work_months = $stmt_months->fetchAll(PDO::FETCH_ASSOC);
}

$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];

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

$work_details = [];
$orders = [];
$selected_work_month_id = $_GET['work_month_id'] ?? 'all';
$selected_partner_id = $_GET['user_id'] ?? 'all';
$selected_work_day_id = $_GET['work_day_id'] ?? 'all';
$page = (int)($_GET['page'] ?? 1);
$per_page = 10;

if ($selected_work_month_id == 'all' || !$selected_work_month_id) {
    $stmt_months = $pdo->query("SELECT * FROM Work_Months ORDER BY start_date DESC");
    $work_months = $stmt_months->fetchAll(PDO::FETCH_ASSOC);
}

if ($selected_work_month_id && $selected_work_month_id != 'all') {
    $month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
    $month_query->execute([$selected_work_month_id]);
    $month = $month_query->fetch(PDO::FETCH_ASSOC);

    if ($month) {
        $start_date = new DateTime($month['start_date']);
        $end_date = new DateTime($month['end_date']);
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));

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
                            $work_details[] = [
                                'work_details_id' => $existing_detail['id'],
                                'work_date' => $work_date,
                                'work_day' => number_to_day($adjusted_day_number),
                                'partner_id' => $partner_id,
                                'user1' => $partner['user1'],
                                'user2' => $partner['user2'],
                                'user_id1' => $partner['user_id1'],
                                'user_id2' => $partner['user_id2']
                            ];
                        }
                    }
                }
            }
        }

        if ($selected_partner_id && $selected_partner_id != 'all') {
            $filtered_work_details = array_filter($work_details, function($detail) use ($selected_partner_id) {
                return $detail['user_id1'] == $selected_partner_id || $detail['user_id2'] == $selected_partner_id;
            });
            $work_details = array_values($filtered_work_details);
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
    // برای ادمین: نمایش نام هر دو همکار
    $orders_query .= "
        (SELECT CONCAT(u1.full_name, ' - ', COALESCE(u2.full_name, u1.full_name))
         FROM Partners p
         LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
         LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
         WHERE p.partner_id = wd.partner_id) AS partners_names, ";
} else {
    // برای فروشنده: نمایش نام همکار مقابل
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
    // پارامترها فقط برای فروشنده نیازه
    $params[] = $current_user_id; // برای user_id1 توی partner_name
    $params[] = $current_user_id; // برای user_id2 توی partner_name

    // محدود کردن دسترسی برای کاربران فروشنده
    $conditions[] = "EXISTS (
        SELECT 1 FROM Partners p 
        WHERE p.partner_id = wd.partner_id 
        AND (p.user_id1 = ? OR p.user_id2 = ?)
    )";
    $params[] = $current_user_id;
    $params[] = $current_user_id;
}

if ($selected_year && $selected_year != 'all') {
    $conditions[] = "YEAR(wd.work_date) = ?";
    $params[] = $selected_year;
}

if ($selected_work_month_id && $selected_work_month_id != 'all') {
    $conditions[] = "wd.work_month_id = ?";
    $params[] = $selected_work_month_id;
}

if ($selected_partner_id && $selected_partner_id != 'all') {
    $conditions[] = "EXISTS (
        SELECT 1 FROM Partners p 
        WHERE p.partner_id = wd.partner_id 
        AND (p.user_id1 = ? OR p.user_id2 = ?)
    )";
    $params[] = $selected_partner_id;
    $params[] = $selected_partner_id;
}

if ($selected_work_day_id && $selected_work_day_id != 'all') {
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

$orders_query .= " LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$stmt_orders = $pdo->prepare($orders_query);
$stmt_orders->execute($params);
$orders = $stmt_orders->fetchAll(PDO::FETCH_ASSOC);
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <style>
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        table {
            min-width: 800px;
            table-layout: fixed;
            width: 100%;
        }
        th, td {
            white-space: nowrap;
            padding: 8px 5px;
            text-align: center;
            vertical-align: middle;
        }
        th {
            padding-bottom: 1rem;
        }
        .pagination {
            justify-content: center;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <h5 class="card-title mb-4">لیست سفارشات</h5>

        <form method="GET" class="row g-3 mb-3">
            <div class="col-auto">
                <select name="year" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= $selected_year == 'all' ? 'selected' : '' ?>>همه سال‌ها</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                            <?= gregorian_year_to_jalali($year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="work_month_id" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= $selected_work_month_id == 'all' ? 'selected' : '' ?>>همه ماه‌ها</option>
                    <?php foreach ($work_months as $month): ?>
                        <option value="<?= $month['work_month_id'] ?>" <?= $selected_work_month_id == $month['work_month_id'] ? 'selected' : '' ?>>
                            <?= gregorian_to_jalali_format($month['start_date']) ?> تا <?= gregorian_to_jalali_format($month['end_date']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="user_id" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= $selected_partner_id == 'all' ? 'selected' : '' ?>>همه همکاران</option>
                    <?php foreach ($partners as $partner): ?>
                        <option value="<?= htmlspecialchars($partner['user_id']) ?>" <?= $selected_partner_id == $partner['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($partner['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="work_day_id" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= $selected_work_day_id == 'all' ? 'selected' : '' ?>>همه روزها</option>
                    <?php foreach ($work_details as $day): ?>
                        <option value="<?= $day['work_details_id'] ?>" <?= $selected_work_day_id == $day['work_details_id'] ? 'selected' : '' ?>>
                            <?= gregorian_to_jalali_format($day['work_date']) ?> (<?= $day['user1'] ?> - <?= $day['user2'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if (!$is_admin && $selected_work_day_id && $selected_work_day_id != 'all'): ?>
            <div class="mb-3">
                <a href="add_order.php?work_details_id=<?= $selected_work_day_id ?>" class="btn btn-primary">ثبت سفارش جدید</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($orders)): ?>
            <div class="table-wrapper">
                <table id="ordersTable" class="table table-light table-hover">
                    <thead>
                        <tr>
                            <th>تاریخ</th>
                            <th><?= $is_admin ? 'همکاران' : 'نام همکار' ?></th>
                            <th>شماره فاکتور</th>
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
                                <td><?= $order['work_date'] ? gregorian_to_jalali_format($order['work_date']) : 'نامشخص' ?></td>
                                <td><?= htmlspecialchars($is_admin ? $order['partners_names'] : $order['partner_name']) ?></td>
                                <td><?= $order['order_id'] ?></td>
                                <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                <td><?= number_format($order['total_amount'], 0) ?></td>
                                <td><?= number_format($order['paid_amount'] ?? 0, 0) ?></td>
                                <td><?= number_format($order['remaining_amount'], 0) ?></td>
                                <?php if (!$is_admin): ?>
                                    <td>
                                        <a href="edit_order.php?order_id=<?= $order['order_id'] ?>" class="btn btn-primary btn-sm me-2"><i class="fas fa-edit"></i></a>
                                        <a href="delete_order.php?order_id=<?= $order['order_id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('حذف؟');"><i class="fas fa-trash"></i></a>
                                    </td>
                                    <td>
                                        <a href="edit_payment.php?order_id=<?= $order['order_id'] ?>" class="btn btn-primary btn-sm me-2"><i class="fas fa-edit"></i></a>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <a href="print_invoice.php?order_id=<?= $order['order_id'] ?>" class="btn btn-success btn-sm"><i class="fas fa-eye"></i> مشاهده</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <nav aria-label="Page navigation">
                <ul class="pagination justify-content-center mt-3">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page - 1 ?>&work_month_id=<?= $selected_work_month_id ?>&user_id=<?= $selected_partner_id ?>&work_day_id=<?= $selected_work_day_id ?>&year=<?= $selected_year ?>">قبلی</a>
                    </li>
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>&work_month_id=<?= $selected_work_month_id ?>&user_id=<?= $selected_partner_id ?>&work_day_id=<?= $selected_work_day_id ?>&year=<?= $selected_year ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page + 1 ?>&work_month_id=<?= $selected_work_month_id ?>&user_id=<?= $selected_partner_id ?>&work_day_id=<?= $selected_work_day_id ?>&year=<?= $selected_year ?>">بعدی</a>
                    </li>
                </ul>
            </nav>

            <?php if ($total_orders > $per_page): ?>
                <div class="text-center mt-3">
                    <button id="loadMoreBtn" class="btn btn-secondary">نمایش فاکتورهای بیشتر</button>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="alert alert-warning text-center">سفارشی ثبت نشده است.</div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#ordersTable').DataTable({
                "pageLength": <?= $per_page ?>,
                "paging": false,
                "ordering": false,
                "info": true,
                "searching": false,
                "language": {
                    "info": "نمایش _START_ تا _END_ از _TOTAL_ فاکتور",
                    "infoEmpty": "هیچ فاکتوری یافت نشد",
                    "zeroRecords": "هیچ فاکتوری یافت نشد"
                }
            });

            $('#loadMoreBtn').on('click', function() {
                let table = $('#ordersTable').DataTable();
                table.page.len(50).draw();
                $(this).hide();
            });

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

            // تنظیم عرض ستون‌ها بر اساس بزرگ‌ترین محتوا
            function adjustColumnWidths() {
                const table = $('#ordersTable');
                const headers = table.find('thead th');
                const rows = table.find('tbody tr');

                headers.each(function(index) {
                    let maxWidth = $(this).width();
                    rows.each(function() {
                        const cell = $(this).find('td').eq(index);
                        const cellWidth = cell.width();
                        if (cellWidth > maxWidth) {
                            maxWidth = cellWidth;
                        }
                    });
                    headers.eq(index).css('width', (maxWidth + 10) + 'px');
                });
            }

            adjustColumnWidths();
        });
    </script>

<?php require_once 'footer.php'; ?>