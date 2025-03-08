<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

$current_user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

// دریافت فیلترها
$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? date('m');
$partner_id = $_GET['partner_id'] ?? 'all';
$work_details_id = $_GET['work_details_id'] ?? 'all';
$page = (int)($_GET['page'] ?? 1);
$per_page = 10;

// کوئری فاکتورها
$where = "WHERE 1=1";
if ($partner_id != 'all') {
    $where .= " AND o.work_details_id IN (SELECT id FROM Work_Details WHERE partner_id = ? OR agency_owner_id = ?)";
}
if ($work_details_id != 'all') {
    $where .= " AND o.work_details_id = ?";
}
if ($year != 'all' && $month != 'all') {
    $where .= " AND YEAR(o.created_at) = ? AND MONTH(o.created_at) = ?";
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM Orders o $where");
$params = [];
if ($partner_id != 'all') {
    $params = array_merge($params, [$partner_id, $partner_id]);
}
if ($work_details_id != 'all') {
    $params[] = $work_details_id;
}
if ($year != 'all' && $month != 'all') {
    $params = array_merge($params, [$year, $month]);
}
$stmt->execute($params);
$total_orders = $stmt->fetchColumn();
$total_pages = ceil($total_orders / $per_page);
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare("SELECT o.*, wd.work_date, u1.full_name AS partner_name, u2.full_name AS agency_owner_name 
                       FROM Orders o 
                       LEFT JOIN Work_Details wd ON o.work_details_id = wd.id 
                       LEFT JOIN Users u1 ON wd.partner_id = u1.user_id 
                       LEFT JOIN Users u2 ON wd.agency_owner_id = u2.user_id 
                       $where 
                       ORDER BY o.created_at DESC 
                       LIMIT ? OFFSET ?");
$params = array_merge($params, [$per_page, $offset]);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// دریافت لیست همکاران
$stmt_partners = $pdo->prepare("SELECT user_id, full_name FROM Users WHERE role = 'partner' OR user_id = ?");
$stmt_partners->execute([$current_user_id]);
$partners = $stmt_partners->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاکتورها</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container-fluid mt-5">
        <h5 class="card-title mb-4">فاکتورها</h5>

        <!-- فیلترها -->
        <form method="GET" class="row g-3 mb-3">
            <div class="col-auto">
                <label for="year" class="form-label">سال</label>
                <select name="year" id="year" class="form-select">
                    <option value="all">همه سال‌ها</option>
                    <?php for ($y = date('Y'); $y >= 2023; $y--): ?>
                        <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <label for="month" class="form-label">ماه</label>
                <select name="month" id="month" class="form-select">
                    <option value="all">همه ماه‌ها</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= sprintf('%02d', $m) ?>" <?= $month == sprintf('%02d', $m) ? 'selected' : '' ?>><?= jdate('F', mktime(0, 0, 0, $m, 1)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <label for="partner_id" class="form-label">همکار</label>
                <select name="partner_id" id="partner_id" class="form-select">
                    <option value="all">همه همکاران</option>
                    <?php foreach ($partners as $partner): ?>
                        <option value="<?= $partner['user_id'] ?>" <?= $partner_id == $partner['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($partner['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary mt-4">فیلتر</button>
            </div>
        </form>

        <!-- جدول فاکتورها -->
        <div class="table-responsive">
            <table id="ordersTable" class="table table-striped" style="width:100%">
                <thead>
                    <tr>
                        <th>تاریخ</th>
                        <th>نام مشتری</th>
                        <th>مبلغ کل</th>
                        <th>تخفیف</th>
                        <th>مبلغ نهایی</th>
                        <th>همکار</th>
                        <th>اقدامات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?= gregorian_to_jalali_format($order['created_at']) ?></td>
                            <td><?= htmlspecialchars($order['customer_name']) ?></td>
                            <td><?= number_format($order['total_amount'], 0) ?> تومان</td>
                            <td><?= number_format($order['discount'], 0) ?> تومان</td>
                            <td><?= number_format($order['final_amount'], 0) ?> تومان</td>
                            <td><?= htmlspecialchars($order['partner_name'] . ' - ' . $order['agency_owner_name']) ?></td>
                            <td><a href="edit_order.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-info">ویرایش</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- پیجینیشن -->
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center mt-3">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page - 1 ?>&year=<?= $year ?>&month=<?= $month ?>&partner_id=<?= $partner_id ?>&work_details_id=<?= $work_details_id ?>">قبلی</a>
                </li>
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&year=<?= $year ?>&month=<?= $month ?>&partner_id=<?= $partner_id ?>&work_details_id=<?= $work_details_id ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page + 1 ?>&year=<?= $year ?>&month=<?= $month ?>&partner_id=<?= $partner_id ?>&work_details_id=<?= $work_details_id ?>">بعدی</a>
                </li>
            </ul>
        </nav>

        <?php if ($total_orders > 10): ?>
            <div class="text-center mt-3">
                <button id="loadMoreBtn" class="btn btn-secondary">نمایش فاکتورهای بیشتر</button>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#ordersTable').DataTable({
                "pageLength": 10,
                "paging": true,
                "ordering": false,
                "info": true,
                "searching": false,
                "language": {
                    "paginate": {
                        "previous": "قبلی",
                        "next": "بعدی"
                    },
                    "info": "نمایش _START_ تا _END_ از _TOTAL_ فاکتور",
                    "infoEmpty": "هیچ فاکتوری یافت نشد",
                    "zeroRecords": "هیچ فاکتوری یافت نشد"
                }
            });

            $('#loadMoreBtn').on('click', function() {
                let table = $('#ordersTable').DataTable();
                table.page.len(50).draw(); // نمایش 50 فاکتور به‌جای 10
                $(this).hide();
            });
        });
    </script>

<?php require_once 'footer.php'; ?>