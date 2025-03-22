<?php
session_start();
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

// فقط کاربران واردشده (ادمین یا فروشنده) می‌تونن وارد بشن
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'seller'])) {
    header("Location: index.php");
    exit;
}

$current_user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

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

// تابع برای دریافت سال شمسی از تاریخ میلادی
function get_jalali_year($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    $gy = (int)$gy;
    $gm = (int)$gm;
    $gd = (int)$gd;
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return $jy;
}

// دریافت همه ماه‌ها برای استخراج سال‌های شمسی
$stmt = $pdo->query("SELECT start_date FROM Work_Months ORDER BY start_date DESC");
$months = $stmt->fetchAll(PDO::FETCH_ASSOC);

$years_jalali = [];
$year_mapping = []; // برای نگاشت سال شمسی به بازه تاریخ
foreach ($months as $month) {
    $jalali_year = get_jalali_year($month['start_date']);
    $gregorian_year = (int)date('Y', strtotime($month['start_date']));
    if (!in_array($jalali_year, $years_jalali)) {
        $years_jalali[] = $jalali_year;
        $year_mapping[$jalali_year] = [
            'start_date' => "$gregorian_year-03-21",
            'end_date' => ($gregorian_year + 1) . "-03-21"
        ];
        if ($jalali_year == 1404) {
            $year_mapping[$jalali_year]['start_date'] = "2025-03-21";
            $year_mapping[$jalali_year]['end_date'] = "2026-03-21";
        } elseif ($jalali_year == 1403) {
            $year_mapping[$jalali_year]['start_date'] = "2024-03-20";
            $year_mapping[$jalali_year]['end_date'] = "2025-03-21";
        }
    }
}
sort($years_jalali, SORT_NUMERIC);
$years_jalali = array_reverse($years_jalali); // مرتب‌سازی نزولی

// دیباگ سال‌ها
error_log("inventory_report.php: Available years (jalali): " . implode(", ", $years_jalali));

// تنظیم پیش‌فرض به جدیدترین سال شمسی
$current_jalali_year = get_jalali_year(date('Y-m-d'));
$selected_year_jalali = $_GET['year'] ?? (in_array($current_jalali_year, $years_jalali) ? $current_jalali_year : (!empty($years_jalali) ? $years_jalali[0] : null));

// محاسبه بازه تاریخ برای سال انتخاب‌شده
$start_date = null;
$end_date = null;
if ($selected_year_jalali && isset($year_mapping[$selected_year_jalali])) {
    $start_date = $year_mapping[$selected_year_jalali]['start_date'];
    $end_date = $year_mapping[$selected_year_jalali]['end_date'];
}

// دریافت فیلترها
$selected_work_month_id = $_GET['work_month_id'] ?? 'all';
$selected_user_id = ($user_role === 'admin') ? ($_GET['user_id'] ?? 'all') : $current_user_id; // برای فروشنده، فقط خودش

// دریافت ماه‌های کاری (فیلتر شده بر اساس سال)
$work_months_query = "SELECT work_month_id, start_date, end_date FROM Work_Months";
if ($start_date && $end_date) {
    $work_months_query .= " WHERE start_date >= ? AND start_date < ?";
    $stmt = $pdo->prepare($work_months_query);
    $stmt->execute([$start_date, $end_date]);
} else {
    $stmt = $pdo->query($work_months_query);
}
$work_months = $stmt->fetchAll(PDO::FETCH_ASSOC);

// دریافت کاربران همکار 1 (فقط برای ادمین)
$users = [];
if ($user_role === 'admin') {
    $users_query = $pdo->query("SELECT DISTINCT u.user_id, u.full_name 
                                FROM Users u 
                                JOIN Partners p ON u.user_id = p.user_id1 
                                WHERE u.role = 'seller'");
    $users = $users_query->fetchAll(PDO::FETCH_ASSOC);
}

// دریافت تراکنش‌ها
$transactions_query = "
    SELECT it.*, p.product_name, u.full_name, wm.start_date, wm.end_date 
    FROM Inventory_Transactions it
    JOIN Products p ON it.product_id = p.product_id
    JOIN Users u ON it.user_id = u.user_id
    JOIN Work_Months wm ON it.work_month_id = wm.work_month_id";

$conditions = [];
$params = [];

if ($start_date && $end_date) {
    $conditions[] = "wm.start_date >= ? AND wm.start_date < ?";
    $params[] = $start_date;
    $params[] = $end_date;
}

if ($selected_work_month_id && $selected_work_month_id != 'all') {
    $conditions[] = "it.work_month_id = ?";
    $params[] = $selected_work_month_id;
}

if ($selected_user_id && $selected_user_id != 'all') {
    $conditions[] = "it.user_id = ?";
    $params[] = $selected_user_id;
}

if (!empty($conditions)) {
    $transactions_query .= " WHERE " . implode(" AND ", $conditions);
}

$transactions_query .= " ORDER BY it.transaction_date DESC";
$stmt_transactions = $pdo->prepare($transactions_query);
$stmt_transactions->execute($params);
$transactions = $stmt_transactions->fetchAll(PDO::FETCH_ASSOC);

// دیباگ
error_log("inventory_report.php: Transactions fetched: " . count($transactions));
?>

<div class="container-fluid">
    <h5 class="card-title mb-4">گزارش انبارگردانی</h5>

    <form method="GET" class="row g-3 mb-3">
        <div class="col-auto">
            <select name="year" class="form-select" onchange="this.form.submit()">
                <option value="">همه سال‌ها</option>
                <?php foreach ($years_jalali as $year): ?>
                    <option value="<?= $year ?>" <?= $selected_year_jalali == $year ? 'selected' : '' ?>>
                        <?= $year ?>
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
        <?php if ($user_role === 'admin'): ?>
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
        <?php endif; ?>
    </form>

    <?php if (!empty($transactions)): ?>
        <div class="table-responsive">
            <table id="transactionsTable" class="table table-light table-hover">
                <thead>
                    <tr>
                        <th>تاریخ</th>
                        <th>ماه کاری</th>
                        <?php if ($user_role === 'admin'): ?>
                            <th>کاربر</th>
                        <?php endif; ?>
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
                            <?php if ($user_role === 'admin'): ?>
                                <td><?= htmlspecialchars($transaction['full_name']) ?></td>
                            <?php endif; ?>
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