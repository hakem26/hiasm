<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date, $short = false)
{
    if (!$gregorian_date || $gregorian_date == '0000-00-00')
        return 'نامشخص';
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return $short ? sprintf("%02d/%02d", $jd, $jm) : "$jy/$jm/$jd";
}

function gregorian_year_to_jalali($gregorian_year)
{
    list($jy, $jm, $jd) = gregorian_to_jalali($gregorian_year, 1, 1);
    return $jy;
}

// دریافت همه ماه‌ها برای استخراج سال‌های شمسی
$stmt = $pdo->query("SELECT start_date FROM Work_Months ORDER BY start_date DESC");
$months = $stmt->fetchAll(PDO::FETCH_ASSOC);

$years = [];
foreach ($months as $month) {
    $start_date = $month['start_date'];
    if ($start_date == '0000-00-00')
        continue;
    list($gy, $gm, $gd) = explode('-', $start_date);
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

    $stmt_months = $pdo->prepare("SELECT * FROM Work_Months WHERE start_date >= ? AND start_date < ? AND start_date != '0000-00-00' ORDER BY start_date DESC");
    $stmt_months->execute([$start_date, $end_date]);
    $work_months = $stmt_months->fetchAll(PDO::FETCH_ASSOC);
}

$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];
$selected_work_month_id = $_GET['work_month_id'] ?? null;
$page = (int) ($_GET['page'] ?? 1);
$per_page = 10;

$is_partner1 = false;
if (!$is_admin) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM Work_Details wd
        JOIN Partners p ON wd.partner_id = p.partner_id
        WHERE p.user_id1 = ?
    ");
    $stmt->execute([$current_user_id]);
    $is_partner1 = $stmt->fetchColumn() > 0;
}

$orders_query = "
    SELECT tord.temp_order_id, tord.customer_name, tord.total_amount, tord.discount, tord.final_amount,
           SUM(op.amount) AS paid_amount,
           (tord.final_amount - COALESCE(SUM(op.amount), 0)) AS remaining_amount,
           tord.created_at, tord.work_month_id
    FROM Temp_Orders tord
    LEFT JOIN Order_Payments op ON tord.temp_order_id = op.order_id
    WHERE tord.user_id = ?";

$params = [$current_user_id];
$conditions = [];

if ($selected_work_month_id) {
    $month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
    $month_query->execute([$selected_work_month_id]);
    $month = $month_query->fetch(PDO::FETCH_ASSOC);
    if ($month) {
        $conditions[] = "tord.work_month_id = ?";
        $params[] = $selected_work_month_id;
    }
}

if (!empty($conditions)) {
    $orders_query .= " AND " . implode(" AND ", $conditions);
}

$orders_query .= " GROUP BY tord.temp_order_id, tord.customer_name, tord.total_amount, tord.discount, tord.final_amount, tord.created_at, tord.work_month_id";

$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM ($orders_query) AS subquery");
$stmt_count->execute($params);
$total_orders = $stmt_count->fetchColumn();
$total_pages = ceil($total_orders / $per_page);
$offset = ($page - 1) * $per_page;

$orders_query .= " LIMIT " . (int) $per_page . " OFFSET " . (int) $offset;
$stmt_orders = $pdo->prepare($orders_query);
$stmt_orders->execute($params);
$orders = $stmt_orders->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-<?= $_SESSION['message']['type'] ?> text-center">
            <?= htmlspecialchars($_SESSION['message']['text']) ?>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>
    <h5 class="card-title mb-4">لیست سفارشات بدون تاریخ</h5>
    <form method="GET" class="row g-3 mb-3">
        <div class="col-auto">
            <select name="year" class="form-select" onchange="this.form.submit()">
                <option value="">همه سال‌ها</option>
                <?php foreach ($years as $year): ?>
                    <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                        <?= $year ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <select name="work_month_id" class="form-select" onchange="this.form.submit()">
                <option value="" <?= !$selected_work_month_id ? 'selected' : '' ?>>همه ماه‌ها</option>
                <?php foreach ($work_months as $month): ?>
                    <option value="<?= $month['work_month_id'] ?>" <?= $selected_work_month_id == $month['work_month_id'] ? 'selected' : '' ?>>
                        <?= gregorian_to_jalali_format($month['start_date']) ?> تا
                        <?= gregorian_to_jalali_format($month['end_date']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if (!$is_admin && $is_partner1): ?>
        <div class="mb-3">
            <a href="add_temp_order.php" class="btn btn-primary">افزودن فاکتور بدون تاریخ</a>
        </div>
    <?php endif; ?>

    <?php if (!empty($orders)): ?>
        <div>
            <table id="tempOrdersTable" class="table table-light table-hover">
                <thead>
                    <tr>
                        <th>شماره فاکتور</th>
                        <th>نام مشتری</th>
                        <th>مبلغ کل فاکتور</th>
                        <th>مبلغ پرداختی</th>
                        <th>مانده حساب</th>
                        <th>فاکتور</th>
                        <th>پرینت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?= $order['temp_order_id'] ?></td>
                            <td><?= htmlspecialchars($order['customer_name']) ?></td>
                            <td><?= number_format($order['total_amount'], 0) ?></td>
                            <td><?= number_format($order['paid_amount'] ?? 0, 0) ?></td>
                            <td><?= number_format($order['remaining_amount'], 0) ?></td>
                            <td>
                                <a href="edit_temp_order.php?temp_order_id=<?= $order['temp_order_id'] ?>"
                                    class="btn btn-primary btn-sm me-2">ویرایش</a>
                                <a href="delete_temp_order.php?temp_order_id=<?= $order['temp_order_id'] ?>"
                                    class="btn btn-danger btn-sm me-2" onclick="return confirm('حذف؟');">حذف</a>
                                <button class="btn btn-warning btn-sm convert-order"
                                    data-temp-order-id="<?= $order['temp_order_id'] ?>"
                                    data-work-month-id="<?= $order['work_month_id'] ?>">تبدیل</button>
                            </td>
                            <td>
                                <a href="print_temp_invoice.php?order_id=<?= $order['temp_order_id'] ?>"
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
                        href="?page=<?= $page - 1 ?>&work_month_id=<?= $selected_work_month_id ?>&year=<?= $selected_year ?>">قبلی</a>
                </li>
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link"
                            href="?page=<?= $i ?>&work_month_id=<?= $selected_work_month_id ?>&year=<?= $selected_year ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link"
                        href="?page=<?= $page + 1 ?>&work_month_id=<?= $selected_work_month_id ?>&year=<?= $selected_year ?>">بعدی</a>
                </li>
            </ul>
        </nav>
    <?php else: ?>
        <div class="alert alert-warning text-center">سفارش بدون تاریخی ثبت نشده است.</div>
    <?php endif; ?>
</div>

<!-- مودال تبدیل سفارش -->
<div class="modal fade" id="convertOrderModal" tabindex="-1" aria-labelledby="convertOrderModalLabel"
    aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="convertOrderModalLabel">تبدیل سفارش بدون تاریخ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="work_details_id" class="form-label">انتخاب تاریخ کاری</label>
                    <select class="form-select" id="work_details_id" name="work_details_id" required>
                        <option value="">انتخاب کنید</option>
                    </select>
                    <input type="hidden" id="temp_order_id" name="temp_order_id">
                    <input type="hidden" id="work_month_id" name="work_month_id">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="convert_order_btn">تبدیل</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function () {
        $('#tempOrdersTable').DataTable({
            responsive: false,
            scrollX: true,
            autoWidth: false,
            paging: false,
            ordering: true,
            info: true,
            searching: false,
            "language": {
                "info": "نمایش _START_ تا _END_ از _TOTAL_ فاکتور",
                "infoEmpty": "هیچ فاکتوری یافت نشد",
                "zeroRecords": "هیچ فاکتوری یافت نشد",
                "lengthMenu": "نمایش _MENU_ ردیف",
                "paginate": {
                    "previous": "قبلی",
                    "next": "بعدی"
                }
            }
        });

        $('.convert-order').on('click', function () {
            const tempOrderId = $(this).data('temp-order-id');
            const workMonthId = $(this).data('work-month-id');
            $('#temp_order_id').val(tempOrderId);
            $('#work_month_id').val(workMonthId);

            // لود تاریخ‌های کاری با AJAX
            $.ajax({
                url: 'ajax_temp_order_handler.php',
                method: 'POST',
                data: {
                    action: 'get_work_days',
                    work_month_id: workMonthId,
                    user_id: <?= $current_user_id ?>
                },
                dataType: 'json',
                success: function (response) {
                    console.log('Raw get_work_days response:', response); // لاگ پاسخ خام
                    if (response.success && Array.isArray(response.data.work_days)) {
                        $('#work_details_id').empty().append('<option value="">انتخاب کنید</option>');
                        response.data.work_days.forEach(function (day) {
                            $('#work_details_id').append(
                                `<option value="${day.id}">${day.display}</option>`
                            );
                        });
                    } else {
                        alert('خطا در بارگذاری تاریخ‌های کاری: ' + (response.message || 'پاسخ نامعتبر'));
                    }
                },
                error: function (xhr, status, error) {
                    console.error('AJAX error:', status, error, xhr.responseText);
                    alert('خطا در ارتباط با سرور: ' + error);
                }
            });

            $('#convertOrderModal').modal('show');
        });

        $('#convert_order_btn').on('click', async function () {
            const temp_order_id = $('#temp_order_id').val();
            const work_details_id = $('#work_details_id').val();
            if (!work_details_id) {
                alert('لطفاً یک تاریخ کاری انتخاب کنید.');
                return;
            }

            const data = {
                action: 'convert_temp_order',
                temp_order_id: temp_order_id,
                work_details_id: work_details_id
            };

            try {
                const response = await fetch('ajax_temp_order_handler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(data)
                });
                const result = await response.json();
                console.log('convert_temp_order response:', result); // لاگ پاسخ
                if (result.success) {
                    alert(result.message);
                    window.location.reload();
                } else {
                    alert('خطا: ' + result.message);
                }
            } catch (error) {
                console.error('Convert order error:', error);
                alert('خطا در ارتباط با سرور: ' + error.message);
            }
            $('#convertOrderModal').modal('hide');
        });

        $('select[name="year"]').change(function () {
            this.form.submit();
        });

        $('select[name="work_month_id"]').change(function () {
            this.form.submit();
        });
    });
</script>

<?php require_once 'footer.php'; ?>