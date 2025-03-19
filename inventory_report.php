<?php
session_start();
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

function gregorian_to_jalali_format($gregorian_date)
{
    // چک کردن اینکه تاریخ خالی یا null نباشه
    if (empty($gregorian_date) || $gregorian_date === '0000-00-00 00:00:00') {
        return "نامشخص";
    }

    // جدا کردن تاریخ و زمان (چون transaction_date یه TIMESTAMP هست)
    $date_parts = explode(' ', $gregorian_date);
    $date = $date_parts[0]; // فقط تاریخ (مثلاً 2025-03-20)

    // جدا کردن سال، ماه، روز
    list($gy, $gm, $gd) = explode('-', $date);

    // چک کردن اینکه مقادیر عددی و معتبر باشن
    if (!is_numeric($gy) || !is_numeric($gm) || !is_numeric($gd) || $gy < 1000 || $gm < 1 || $gm > 12 || $gd < 1 || $gd > 31) {
        return "نامشخص";
    }

    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

// دریافت فیلترها
$selected_work_month_id = $_GET['work_month_id'] ?? 'all';
$selected_user_id = $_GET['user_id'] ?? 'all';
$selected_product_id = $_GET['product_id'] ?? 'all';

// دریافت ماه‌های کاری
$work_months_query = $pdo->query("SELECT work_month_id, start_date, end_date FROM Work_Months ORDER BY start_date DESC");
$work_months = $work_months_query->fetchAll(PDO::FETCH_ASSOC);

// دریافت کاربران همکار 1
$users_query = $pdo->query("SELECT DISTINCT u.user_id, u.full_name 
                            FROM Users u 
                            JOIN Partners p ON u.user_id = p.user_id1 
                            WHERE u.role = 'seller'");
$users = $users_query->fetchAll(PDO::FETCH_ASSOC);

// دریافت محصولات
$products_query = $pdo->query("SELECT product_id, product_name FROM Products ORDER BY product_name");
$products = $products_query->fetchAll(PDO::FETCH_ASSOC);

// دریافت تراکنش‌ها
$transactions_query = "
    SELECT it.*, p.product_name, u.full_name, wm.start_date, wm.end_date 
    FROM Inventory_Transactions it
    JOIN Products p ON it.product_id = p.product_id
    JOIN Users u ON it.user_id = u.user_id
    JOIN Work_Months wm ON it.work_month_id = wm.work_month_id";

$conditions = [];
$params = [];

if ($selected_work_month_id && $selected_work_month_id != 'all') {
    $conditions[] = "it.work_month_id = ?";
    $params[] = $selected_work_month_id;
}

if ($selected_user_id && $selected_user_id != 'all') {
    $conditions[] = "it.user_id = ?";
    $params[] = $selected_user_id;
}

if ($selected_product_id && $selected_product_id != 'all') {
    $conditions[] = "it.product_id = ?";
    $params[] = $selected_product_id;
}

if (!empty($conditions)) {
    $transactions_query .= " WHERE " . implode(" AND ", $conditions);
}

$transactions_query .= " ORDER BY it.transaction_date DESC";
$stmt_transactions = $pdo->prepare($transactions_query);
$stmt_transactions->execute($params);
$transactions = $stmt_transactions->fetchAll(PDO::FETCH_ASSOC);
?>

    <div class="container-fluid">
        <h5 class="card-title mb-4">گزارش انبارگردانی</h5>

        <form method="GET" class="row g-3 mb-3">
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
                    <option value="all" <?= $selected_user_id == 'all' ? 'selected' : '' ?>>همه کاربران</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['user_id'] ?>" <?= $selected_user_id == $user['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="product_id" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= $selected_product_id == 'all' ? 'selected' : '' ?>>همه محصولات</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= $product['product_id'] ?>" <?= $selected_product_id == $product['product_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($product['product_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if (!empty($transactions)): ?>
            <div class="table-responsive">
                <table id="transactionsTable" class="table table-light table-hover">
                    <thead>
                        <tr>
                            <th>تاریخ</th>
                            <th>ماه کاری</th>
                            <th>کاربر</th>
                            <th>محصول</th>
                            <th>تعداد</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><?= gregorian_to_jalali_format($transaction['transaction_date']) ?></td>
                                <td>
                                    <?= gregorian_to_jalali_format($transaction['start_date']) ?> تا
                                    <?= gregorian_to_jalali_format($transaction['end_date']) ?>
                                </td>
                                <td><?= htmlspecialchars($transaction['full_name']) ?></td>
                                <td><?= htmlspecialchars($transaction['product_name']) ?></td>
                                <td><?= $transaction['quantity'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-warning text-center">تراکنشی ثبت نشده است.</div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function () {
            $('#transactionsTable').DataTable({
                "scrollX": true,
                "paging": true,
                "ordering": true,
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
                }
            });
        });
    </script>

    <?php require_once 'footer.php'; ?>