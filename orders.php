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
    if (!$gregorian_date) return 'نامشخص';
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

function gregorian_year_to_jalali($gregorian_year)
{
    list($jy, $jm, $jd) = gregorian_to_jalali($gregorian_year, 1, 1);
    return $jy;
}

function calculate_day_of_week($work_date)
{
    $reference_date = '2025-03-01';
    $reference_timestamp = strtotime($reference_date);
    $current_timestamp = strtotime($work_date);
    $days_diff = ($current_timestamp - $reference_timestamp) / (60 * 60 * 24);
    $adjusted_day_number = ($days_diff % 7 + 1);
    if ($adjusted_day_number <= 0) {
        $adjusted_day_number += 7;
    }
    return $adjusted_day_number;
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

$stmt = $pdo->query("SELECT start_date FROM Work_Months ORDER BY start_date DESC");
$months = $stmt->fetchAll(PDO::FETCH_ASSOC);

$years = [];
foreach ($months as $month) {
    $month_start_date = $month['start_date'];
    list($gy, $gm, $gd) = explode('-', $month_start_date);
    $jalali_date = gregorian_to_jalali($gy, $gm, $gd);
    $jalali_year = $jalali_date[0];
    if (!in_array($jalali_year, $years)) {
        $years[] = $jalali_year;
    }
}
sort($years, SORT_NUMERIC);
$years = array_reverse($years);

$current_gregorian_year = date('Y');
$current_jalali_year = gregorian_year_to_jalali($current_gregorian_year);

$selected_year = $_GET['year'] ?? ($years[0] ?? $current_jalali_year);

$work_months = [];
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
$show_sub_orders = isset($_GET['show_sub_orders']) && $_GET['show_sub_orders'] == '1';
$page = (int) ($_GET['page'] ?? 1);
$per_page = 10;

$is_partner1 = false;
$has_sub_orders = false;
if ($selected_work_month_id) {
    $month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
    $month_query->execute([$selected_work_month_id]);
    $month = $month_query->fetch(PDO::FETCH_ASSOC);

    if ($month) {
        // چک کردن وجود پیش‌فاکتور برای کاربر
        $sub_order_check = $pdo->prepare("
            SELECT 1 
            FROM Orders o
            JOIN Work_Details wd ON o.work_details_id = wd.id
            JOIN Partners p ON wd.partner_id = p.partner_id
            WHERE o.is_main_order = 0 AND wd.work_month_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
            LIMIT 1
        ");
        $sub_order_check->execute([$selected_work_month_id, $current_user_id, $current_user_id]);
        $has_sub_orders = $sub_order_check->fetchColumn();

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

            $partner1_check = $pdo->prepare("
                SELECT 1 
                FROM Work_Details wd
                JOIN Partners p ON wd.partner_id = p.partner_id
                WHERE wd.work_month_id = ? AND p.user_id1 = ?
                LIMIT 1
            ");
            $partner1_check->execute([$selected_work_month_id, $current_user_id]);
            $is_partner1 = $partner1_check->fetchColumn();
        }
        $partners = $partners_query->fetchAll(PDO::FETCH_ASSOC);

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

$orders_query = "
    SELECT o.order_id, o.customer_name, o.total_amount, o.discount, o.final_amount, o.is_main_order,
           COALESCE(wd.work_date, o.created_at) AS order_date,
           SUM(op.amount) AS paid_amount,
           (o.final_amount - COALESCE(SUM(op.amount), 0)) AS remaining_amount,";

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
           wd.id AS work_details_id,
           o.created_at
    FROM Orders o
    LEFT JOIN Order_Payments op ON o.order_id = op.order_id
    LEFT JOIN Work_Details wd ON o.work_details_id = wd.id";

$conditions = [];
$params = [];
if (!$is_admin) {
    $params[] = $current_user_id;
    $params[] = $current_user_id;
    $conditions[] = "EXISTS (
        SELECT 1
        FROM Partners p 
        WHERE p.partner_id = wd.partner_id
        AND (p.user_id1 = ? OR p.user_id2 = ?)
    )";
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
    $conditions[] = "COALESCE(wd.work_date, o.created_at) >= ? AND COALESCE(wd.work_date, o.created_at) < ?";
    $params[] = $start_date;
    $params[] = $end_date;
}

if ($selected_work_month_id) {
    $conditions[] = "wd.work_month_id = ?";
    $params[] = $selected_work_month_id;
}

if ($selected_partner_id) {
    $conditions[] = "EXISTS (
        SELECT 1
        FROM Partners p 
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

if ($show_sub_orders) {
    $conditions[] = "o.is_main_order = 0";
} else {
    $conditions[] = "(o.is_main_order = 1 OR o.is_main_order IS NULL)";
}

if (!empty($conditions)) {
    $orders_query .= " WHERE " . implode(" AND ", $conditions);
}

$orders_query .= "
    GROUP BY o.order_id, o.customer_name, o.total_amount, o.discount, o.final_amount, o.is_main_order, wd.work_date, o.created_at";
if ($is_admin) {
    $orders_query .= ", partners_names";
} else {
    $orders_query .= ", partner_name";
}

$orders_query .= " ORDER BY o.created_at DESC";

$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM ($orders_query) AS subquery");
$stmt_count->execute($params);
$total_orders = $stmt_count->fetchColumn();
$total_pages = ceil($total_orders / $per_page);
$offset = ($page - 1) * $per_page;

$orders_query .= " LIMIT " . (int) $per_page . " OFFSET " . (int) $offset;
$stmt_orders = $pdo->prepare($orders_query);
$stmt_orders->execute($params);
$orders = $stmt_orders->fetchAll(PDO::FETCH_ASSOC);

error_log("Orders Query: $orders_query, params=" . json_encode($params));
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
        <?php if ($has_sub_orders): ?>
            <div class="col-auto align-self-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="show_sub_orders" id="show_sub_orders" value="1" <?= $show_sub_orders ? 'checked' : '' ?> onchange="this.form.submit()">
                    <label class="form-check-label" for="show_sub_orders">
                        نمایش فقط پیش‌فاکتورها
                    </label>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!$is_admin && $is_partner1): ?>
            <div class="col-auto align-self-end">
                <a href="add_sub_order.php?work_month_id=<?= $selected_work_month_id ?>" class="btn btn-info">ایجاد پیش‌فاکتور</a>
            </div>
        <?php endif; ?>
    </form>

    <?php if (!$is_admin && $selected_work_day_id): ?>
        <div class="mb-3">
            <a href="add_order.php?work_details_id=<?= $selected_work_day_id ?>" class="btn btn-primary">ثبت سفارش جدید</a>
        </div>
    <?php endif; ?>

    <?php if (!empty($orders)): ?>
        <div>
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
                        <th>نوع فاکتور</th>
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
                            <td><?= gregorian_to_jalali_format($order['order_date']) ?></td>
                            <td><?= htmlspecialchars($is_admin ? $order['partners_names'] : $order['partner_name']) ?></td>
                            <td><?= $order['order_id'] ?></td>
                            <td><?= htmlspecialchars($order['customer_name']) ?></td>
                            <td><?= number_format($order['total_amount'], 0) ?></td>
                            <td><?= number_format($order['paid_amount'] ?? 0, 0) ?></td>
                            <td><?= number_format($order['remaining_amount'], 0) ?></td>
                            <td><?= $order['is_main_order'] === '0' ? 'پیش‌فاکتور' : 'فاکتور اصلی' ?></td>
                            <?php if (!$is_admin): ?>
                                <td>
                                    <?php if ($order['is_main_order'] === '0'): ?>
                                        <a href="edit_sub_order.php?order_id=<?= $order['order_id'] ?>"
                                           class="btn btn-primary btn-sm me-2"><i class="fas fa-edit"></i></a>
                                    <?php else: ?>
                                        <a href="edit_order.php?order_id=<?= $order['order_id'] ?>"
                                           class="btn btn-primary btn-sm me-2"><i class="fas fa-edit"></i></a>
                                    <?php endif; ?>
                                    <a href="delete_order.php?order_id=<?= $order['order_id'] ?>" class="btn btn-danger btn-sm"
                                       onclick="return confirm('حذف؟');"><i class="fas fa-trash"></i></a>
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

        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center mt-3">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link"
                       href="?page=<?= $page - 1 ?>&work_month_id=<?= $selected_work_month_id ?>&user_id=<?= $selected_partner_id ?>&work_day_id=<?= $selected_work_day_id ?>&year=<?= $selected_year ?>&show_sub_orders=<?= $show_sub_orders ? '1' : '' ?>">قبلی</a>
                </li>
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link"
                           href="?page=<?= $i ?>&work_month_id=<?= $selected_work_month_id ?>&user_id=<?= $selected_partner_id ?>&work_day_id=<?= $selected_work_day_id ?>&year=<?= $selected_year ?>&show_sub_orders=<?= $show_sub_orders ? '1' : '' ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link"
                       href="?page=<?= $page + 1 ?>&work_month_id=<?= $selected_work_month_id ?>&user_id=<?= $selected_partner_id ?>&work_day_id=<?= $selected_work_day_id ?>&year=<?= $selected_year ?>&show_sub_orders=<?= $show_sub_orders ? '1' : '' ?>">بعدی</a>
                </li>
            </ul>
        </nav>
    <?php else: ?>
        <div class="alert alert-warning text-center mt-3">سفارشی ثبت نشده است.</div>
    <?php endif; ?>
</div>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function () {
        $('#ordersTable').DataTable({
            responsive: false,
            scrollX: true,
            autoWidth: false,
            paging: false,
            ordering: true,
            info: true,
            searching: true,
            "language": {
                "info": "نمایش _START_ تا _END_ از _TOTAL_ فاکتور",
                "infoEmpty": "هیچ فاکتوری یافت نشد",
                "zeroRecords": "هیچ فاکتوری یافت نشد",
                "lengthMenu": "نمایش _MENU_ ردیف",
                "search": "جستجو:",
                "paginate": {
                    "previous": "قبلی",
                    "next": "بعدی"
                }
            }
        });

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