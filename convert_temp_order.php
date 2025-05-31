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
    $temp = gregorian_to_jalali($gy, $gm, $gd); // سال، ماه، روز
    return "$temp[0]/$temp[1]/$temp[2]";
}

$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];

$work_month_id = $_GET['work_month_id'] ?? null;
if (!$work_month_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>ماه کاری مشخص نشده است.</div></div>";
    require_once 'footer.php';
    exit;
}

// دریافت سفارشات موقت برای همکار1
$temp_orders_query = $pdo->prepare("
    SELECT to.*, u.full_name
    FROM Temp_Orders to
    JOIN Users u ON to.user_id = u.user_id
    WHERE to.user_id = ? AND EXISTS (
        SELECT 1 FROM Work_Details wd
        JOIN Partners p ON wd.partner_id = p.partner_id
        WHERE wd.work_month_id = ? AND p.user_id1 = to.user_id
    )
");
$temp_orders_query->execute([$current_user_id, $work_month_id]);
$temp_orders = $temp_orders_query->fetchAll(PDO::FETCH_ASSOC);

// دریافت روزهای کاری برای ماه انتخاب‌شده
$work_details_query = $pdo->prepare("
    SELECT wd.id, wd.work_date, u1.full_name AS user1, u2.full_name AS user2
    FROM Work_Details wd
    JOIN Partners p ON wd.partner_id = p.partner_id
    JOIN Users u1 ON p.user_id1 = u1.user_id
    LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
    WHERE wd.work_month_id = ? AND p.user_id1 = ?
");
$work_details_query->execute([$work_month_id, $current_user_id]);
$work_details = $work_details_query->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid mt-5">
    <h5 class="card-title mb-4">تبدیل سفارشات موقت</h5>

    <?php if (empty($temp_orders)): ?>
        <div class="alert alert-warning text-center">هیچ سفارش موقتی یافت نشد.</div>
    <?php else: ?>
        <table class="table table-light table-hover">
            <thead>
                <tr>
                    <th>شناسه سفارش</th>
                    <th>نام مشتری</th>
                    <th>مبلغ کل</th>
                    <th>تخفیف</th>
                    <th>مبلغ نهایی</th>
                    <th>تاریخ ثبت</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($temp_orders as $order): ?>
                    <tr>
                        <td><?= $order['temp_order_id'] ?></td>
                        <td><?= htmlspecialchars($order['customer_name']) ?></td>
                        <td><?= number_format($order['total_amount'], 0) ?> تومان</td>
                        <td><?= number_format($order['discount'], 0) ?> تومان</td>
                        <td><?= number_format($order['final_amount'], 0) ?> تومان</td>
                        <td><?= gregorian_to_jalali_format($order['created_at']) ?></td>
                        <td>
                            <button type="button" class="btn btn-primary btn-sm convert-order" data-temp-order-id="<?= $order['temp_order_id'] ?>">تبدیل به سفارش دائمی</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <a href="orders.php?work_month_id=<?= htmlspecialchars($work_month_id) ?>" class="btn btn-secondary mt-3">بازگشت</a>
</div>

<div class="modal fade" id="convertOrderModal" tabindex="-1" aria-labelledby="convertOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="convertOrderModalLabel">تبدیل سفارش موقت</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="work_details_id" class="form-label">انتخاب روز کاری</label>
                    <select class="form-select" id="work_details_id" name="work_details_id" required>
                        <option value="">انتخاب کنید</option>
                        <?php foreach ($work_details as $detail): ?>
                            <option value="<?= $detail['id'] ?>">
                                <?= gregorian_to_jalali_format($detail['work_date']) ?> (<?= htmlspecialchars($detail['user1']) ?> - <?= htmlspecialchars($detail['user2'] ?: 'نامشخص') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" id="temp_order_id" name="temp_order_id">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="save_conversion">ذخیره</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function () {
    $('.convert-order').on('click', function () {
        const tempOrderId = $(this).data('temp-order-id');
        $('#temp_order_id').val(tempOrderId);
        $('#convertOrderModal').modal('show');
    });

    $('#save_conversion').on('click', function () {
        const temp_order_id = $('#temp_order_id').val();
        const work_details_id = $('#work_details_id').val();

        if (!work_details_id) {
            alert('لطفاً یک روز کاری انتخاب کنید.');
            return;
        }

        $.post('ajax_handler.php', {
            action: 'convert_temp_order',
            temp_order_id: temp_order_id,
            work_details_id: work_details_id,
            partner1_id: '<?= $current_user_id ?>'
        }, function (response) {
            if (response.success) {
                alert(response.message);
                window.location.href = response.data.redirect;
            } else {
                alert(response.message);
            }
        }, functionjson');
    });
});
</script>

<?php require_once 'footer.php'; ?>