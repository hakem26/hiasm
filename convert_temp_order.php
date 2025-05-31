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
    list($gy, $gm, $gd) = explode('-', explode(' ', $gregorian_date)[0]);
    $jalali = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jalali[0], $jalali[1], $jalali[2]);
}

$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];

$work_month_id = $_GET['work_month_id'] ?? null;
if (!$work_month_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>ماه کاری مشخص نشده است.</div></div>";
    require_once 'footer.php';
    exit;
}

// دریافت روزهای کاری
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
    $('.convert-temp-order').on('click', function () {
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
        }, 'json');
    });
});
</script>

<?php require_once 'footer.php'; ?>