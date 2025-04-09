<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ میلادی به شمسی (فقط روز)
function gregorian_to_jalali_day($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', date('Y-m-d', strtotime($gregorian_date)));
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return $jd; // فقط روز
}

// گرفتن سال‌ها از Work_Months
$stmt = $pdo->query("SELECT DISTINCT YEAR(start_date) as year FROM Work_Months ORDER BY year DESC");
$years = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($years)) {
    $years = [gregorian_to_jalali(date('Y'), 1, 1)[0]]; // سال جاری شمسی
}

// سال جاری شمسی
$current_jalali_year = gregorian_to_jalali(date('Y'), 1, 1)[0]; // مثلاً 1404
$selected_year = $_GET['year'] ?? $current_jalali_year;

// گرفتن ماه‌های کاری برای سال انتخاب‌شده
$gregorian_start_year = $selected_year - 579;
$gregorian_end_year = $gregorian_start_year + 1;
$start_date = "$gregorian_start_year-03-21";
$end_date = "$gregorian_end_year-03-20";

$stmt_months = $pdo->prepare("SELECT * FROM Work_Months WHERE start_date >= ? AND end_date <= ? ORDER BY start_date DESC");
$stmt_months->execute([$start_date, $end_date]);
$work_months = $stmt_months->fetchAll(PDO::FETCH_ASSOC);

$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];
$work_month_id = isset($_GET['work_month_id']) ? (int)$_GET['work_month_id'] : ($work_months ? $work_months[0]['work_month_id'] : null);

// گرفتن گزارش تراکنش‌ها
$transactions = [];
if ($work_month_id) {
    $month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
    $month_query->execute([$work_month_id]);
    $month = $month_query->fetch(PDO::FETCH_ASSOC);

    if ($month) {
        $start_date = $month['start_date'];
        $end_date = $month['end_date'];

        $query = "
            SELECT it.id, it.product_id, it.user_id, it.quantity, it.transaction_date, p.product_name
            FROM Inventory_Transactions it
            JOIN Products p ON it.product_id = p.product_id
            WHERE it.transaction_date >= ? AND it.transaction_date <= ?
        ";
        $params = [$start_date, $end_date . ' 23:59:59'];

        if (!$is_admin) {
            $query .= " AND it.user_id = ?";
            $params[] = $current_user_id;
        }

        $query .= " ORDER BY it.transaction_date ASC";
        $stmt_transactions = $pdo->prepare($query);
        $stmt_transactions->execute($params);
        $transactions_raw = $stmt_transactions->fetchAll(PDO::FETCH_ASSOC);

        foreach ($transactions_raw as $transaction) {
            // محاسبه موجودی قبل
            $stmt_before = $pdo->prepare("
                SELECT SUM(quantity) as total_before
                FROM Inventory_Transactions
                WHERE user_id = ? AND product_id = ? AND transaction_date < ?
            ");
            $stmt_before->execute([$transaction['user_id'], $transaction['product_id'], $transaction['transaction_date']]);
            $total_before = $stmt_before->fetchColumn() ?: 0;

            $transactions[] = [
                'date' => gregorian_to_jalali_day($transaction['transaction_date']),
                'product_name' => $transaction['product_name'],
                'quantity' => abs($transaction['quantity']),
                'status' => $transaction['quantity'] > 0 ? 'درخواست' : 'بازگشت',
                'previous_inventory' => $total_before
            ];
        }
    }
}
?>

<style>
    .table-responsive {
        overflow-x: auto;
        width: 100%;
    }
    .table-inventory {
        width: 100%;
        min-width: 600px;
        border-collapse: collapse;
    }
    .table-inventory th, .table-inventory td {
        vertical-align: middle;
        white-space: nowrap;
        padding: 8px;
        text-align: center;
    }
</style>

<div class="container-fluid mt-5">
    <h5 class="card-title mb-4">گزارش تخصیص موجودی فروشنده</h5>

    <form method="GET" class="row g-3 mb-3">
        <div class="col-auto">
            <label for="year" class="form-label">سال</label>
            <select name="year" id="year" class="form-select" onchange="this.form.submit()">
                <?php foreach ($years as $year): ?>
                    <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                        <?= $year ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-auto">
            <label for="work_month_id" class="form-label">ماه کاری</label>
            <select name="work_month_id" id="work_month_id" class="form-select" onchange="this.form.submit()">
                <option value="" <?= !$work_month_id ? 'selected' : '' ?>>انتخاب ماه</option>
                <?php foreach ($work_months as $month): ?>
                    <option value="<?= $month['work_month_id'] ?>" <?= $work_month_id == $month['work_month_id'] ? 'selected' : '' ?>>
                        <?= gregorian_to_jalali_day($month['start_date']) . ' ' . jdate('F', strtotime($month['start_date'])) ?> تا
                        <?= gregorian_to_jalali_day($month['end_date']) . ' ' . jdate('F', strtotime($month['end_date'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if ($work_month_id && !empty($transactions)): ?>
        <div class="table-responsive">
            <table class="table table-light table-inventory table-hover">
                <thead>
                    <tr>
                        <th>تاریخ</th>
                        <th>نام محصول</th>
                        <th>تعداد</th>
                        <th>وضعیت تخصیص</th>
                        <th>موجودی قبل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td><?= $transaction['date'] ?></td>
                            <td><?= htmlspecialchars($transaction['product_name']) ?></td>
                            <td><?= $transaction['quantity'] ?></td>
                            <td><?= $transaction['status'] ?></td>
                            <td><?= $transaction['previous_inventory'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($work_month_id): ?>
        <div class="alert alert-warning text-center">تراکنشی برای این ماه یافت نشد.</div>
    <?php else: ?>
        <div class="alert alert-info text-center">لطفاً یک ماه کاری انتخاب کنید.</div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>