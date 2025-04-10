<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_full($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', date('Y-m-d', strtotime($gregorian_date)));
    return gregorian_to_jalali($gy, $gm, $gd);
}

// تابع تبدیل تاریخ میلادی به شمسی (تاریخ کامل بدون ساعت و دقیقه)
function gregorian_to_jalali_full_date($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', date('Y-m-d', strtotime($gregorian_date)));
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd); // مثلاً 1404/01/17
}

// محاسبه سال‌های شمسی (شروع از 21 مارچ)
$years = [];
$current_gregorian_year = date('Y');
$current_jalali = gregorian_to_jalali_full(date('Y-m-d'));
$current_jalali_year = $current_jalali[0];
for ($i = $current_jalali_year - 5; $i <= $current_jalali_year + 1; $i++) {
    $years[] = $i;
}
$selected_year = $_GET['year'] ?? $current_jalali_year;

// گرفتن ماه‌های کاری برای سال انتخاب‌شده
$gregorian_start = jalali_to_gregorian($selected_year, 1, 1);
$gregorian_end = jalali_to_gregorian($selected_year + 1, 1, 1);
$start_date = sprintf("%04d-%02d-%02d", $gregorian_start[0], $gregorian_start[1], $gregorian_start[2]);
$end_date = sprintf("%04d-%02d-%02d", $gregorian_end[0], $gregorian_end[1], $gregorian_end[2]);

$stmt_months = $pdo->prepare("SELECT * FROM Work_Months WHERE start_date >= ? AND end_date < ? ORDER BY start_date DESC");
$stmt_months->execute([$start_date, $end_date]);
$work_months = $stmt_months->fetchAll(PDO::FETCH_ASSOC);

$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];
$work_month_id = isset($_GET['work_month_id']) ? (int) $_GET['work_month_id'] : ($work_months ? $work_months[0]['work_month_id'] : null);

$product_id = $_GET['product_id'] ?? null;

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
        if ($product_id) {
            $query .= " AND it.product_id = ?";
            $params[] = $product_id;
        }

        $query .= " ORDER BY it.transaction_date ASC";
        $stmt_transactions = $pdo->prepare($query);
        $stmt_transactions->execute($params);
        $transactions_raw = $stmt_transactions->fetchAll(PDO::FETCH_ASSOC);

        foreach ($transactions_raw as $transaction) {
            $stmt_before = $pdo->prepare("
                SELECT SUM(quantity) as total_before
                FROM Inventory_Transactions
                WHERE user_id = ? AND product_id = ? AND transaction_date < ?
            ");
            $stmt_before->execute([$transaction['user_id'], $transaction['product_id'], $transaction['transaction_date']]);
            $total_before = $stmt_before->fetchColumn() ?: 0;

            $transactions[] = [
                'date' => gregorian_to_jalali_full_date($transaction['transaction_date']),
                'product_name' => $transaction['product_name'],
                'quantity' => abs($transaction['quantity']),
                'status' => $transaction['quantity'] > 0 ? 'درخواست' : 'بازگشت',
                'previous_inventory' => $total_before
            ];
        }
    }
}
?>
<div class="container-fluid mt-5">
    <h5 class="card-title mb-4">گزارش تخصیص موجودی فروشنده</h5>

    <form method="GET" class="row g-3 mb-4">
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
                <?php foreach ($work_months as $month):
                    $start_j = gregorian_to_jalali_full($month['start_date']);
                    $end_j = gregorian_to_jalali_full($month['end_date']);
                    ?>
                    <option value="<?= $month['work_month_id'] ?>" <?= $work_month_id == $month['work_month_id'] ? 'selected' : '' ?>>
                        <?= $start_j[2] . ' ' . jdate('F', strtotime($month['start_date'])) ?> تا
                        <?= $end_j[2] . ' ' . jdate('F', strtotime($month['end_date'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label for="product_search" class="form-label">جستجوی محصول</label>
            <input type="text" id="product_search" name="product_name" class="form-control"
                placeholder="نام محصول را وارد کنید"
                value="<?= isset($_GET['product_name']) ? htmlspecialchars($_GET['product_name']) : '' ?>">
            <input type="hidden" id="product_id" name="product_id"
                value="<?= isset($_GET['product_id']) ? $_GET['product_id'] : '' ?>">
        </div>
        <div class="col-auto align-self-end">
            <button type="submit" id="filter_product" class="btn btn-primary" disabled>فیلتر محصول</button>
        </div>
    </form>

    <div class="row g-3 mb-4">
        <div class="col-auto">
            <a href="inventory_monthly_report.php?work_month_id=<?= $work_month_id ?>&product_id=<?= $product_id ?? '' ?>"
                target="_blank" class="btn btn-success <?= !$work_month_id ? 'disabled' : '' ?>">گزارش ماهانه</a>
        </div>
        <div class="col-auto">
            <a href="inventory_time_report.php?work_month_id=<?= $work_month_id ?>&product_id=<?= $product_id ?? '' ?>"
                target="_blank" class="btn btn-info <?= !$work_month_id ? 'disabled' : '' ?>">گزارش زمانی</a>
        </div>
    </div>

    <?php if ($work_month_id && !empty($transactions)): ?>
        <div class="table-container">
            <table id="inventoryTable" class="table table-light table-inventory table-hover">
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
<script>
    $(document).ready(function () {
        $("#product_search").autocomplete({
            source: function (request, response) {
                $.ajax({
                    url: "get_products.php",
                    method: "POST",
                    data: { query: request.term },
                    dataType: "html",
                    success: function (data) {
                        console.log("Raw response:", data); // برای دیباگ
                        var suggestions = [];
                        $(data).each(function () {
                            try {
                                var product = JSON.parse($(this).attr('data-product'));
                                suggestions.push({
                                    label: product.product_name,
                                    value: product.product_name,
                                    id: product.product_id
                                });
                            } catch (e) {
                                console.error("Error parsing product:", e);
                            }
                        });
                        console.log("Suggestions:", suggestions); // برای دیباگ
                        response(suggestions);
                    },
                    error: function (xhr, status, error) {
                        console.error("AJAX error:", status, error);
                    }
                });
            },
            minLength: 2,
            select: function (event, ui) {
                $("#product_id").val(ui.item.id);
                $("#filter_product").prop("disabled", false);
                console.log("Selected product ID:", ui.item.id); // برای دیباگ
            }
        });

        // غیرفعال کردن دکمه اگر محصول انتخاب نشده
        if (!$("#product_id").val()) {
            $("#filter_product").prop("disabled", true);
        }
    });
</script>
<script>
    $(document).ready(function () {
        $('#inventoryTable').DataTable({
            "language": {
                "url": "//cdn.datatables.net/plug-ins/1.13.1/i18n/fa.json"
            },
            "paging": true,
            "searching": false, // حذف جستجو
            "ordering": true,
            "info": true,
            "lengthMenu": [10, 25, 50, 100]
        });
    });
</script>

<?php require_once 'footer.php'; ?>