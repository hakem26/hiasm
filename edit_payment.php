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
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

// بررسی نقش کاربر
$is_admin = ($_SESSION['role'] === 'admin');
if ($is_admin) {
    header("Location: orders.php");
    exit;
}
$current_user_id = $_SESSION['user_id'];

// دریافت order_id و payment_id از GET
$order_id = $_GET['order_id'] ?? '';
$payment_id = $_GET['payment_id'] ?? '';

if (!$order_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>شناسه سفارش مشخص نشده است.</div></div>";
    require_once 'footer.php';
    exit;
}

// بررسی دسترسی کاربر به سفارش
$stmt = $pdo->prepare("
    SELECT o.order_id, o.customer_name, o.final_amount
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners pr ON wd.partner_id = pr.partner_id
    WHERE o.order_id = ? AND (pr.user_id1 = ? OR pr.user_id2 = ?)
");
$stmt->execute([$order_id, $current_user_id, $current_user_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>سفارش یافت نشد یا شما دسترسی ویرایش آن را ندارید.</div></div>";
    require_once 'footer.php';
    exit;
}

// اگه payment_id وجود داشت، اطلاعات پرداخت رو بگیر (حالت ویرایش)
$payment = null;
$mode = 'add'; // پیش‌فرض حالت اضافه کردن
if ($payment_id) {
    $stmt = $pdo->prepare("
        SELECT p.*
        FROM Payments p
        WHERE p.payment_id = ? AND p.order_id = ?
    ");
    $stmt->execute([$payment_id, $order_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($payment) {
        $mode = 'edit'; // تغییر به حالت ویرایش
    } else {
        echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>پرداخت یافت نشد.</div></div>";
        require_once 'footer.php';
        exit;
    }
}

// محاسبه مجموع پرداخت‌های قبلی (بدون پرداخت فعلی، برای نمایش مانده حساب)
$total_paid_without_current = 0;
if ($mode === 'edit') {
    $stmt = $pdo->prepare("
        SELECT SUM(amount) as total_paid
        FROM Payments
        WHERE order_id = ? AND payment_id != ?
    ");
    $stmt->execute([$order_id, $payment_id]);
    $total_paid_without_current = $stmt->fetchColumn() ?: 0;
} else {
    $stmt = $pdo->prepare("
        SELECT SUM(amount) as total_paid
        FROM Payments
        WHERE order_id = ?
    ");
    $stmt->execute([$order_id]);
    $total_paid_without_current = $stmt->fetchColumn() ?: 0;
}

// مدیریت ارسال فرم
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)($_POST['amount'] ?? 0);
    $jalali_payment_date = $_POST['payment_date'] ?? '';
    $payment_type = $_POST['payment_type'] ?? '';
    $payment_code = $_POST['payment_code'] ?? '';

    // اعتبارسنجی
    if ($amount <= 0 || empty($jalali_payment_date) || empty($payment_type)) {
        echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>لطفاً تمام فیلدهای الزامی را پر کنید.</div></div>";
    } else {
        // تبدیل تاریخ شمسی به میلادی
        list($jy, $jm, $jd) = explode('/', $jalali_payment_date);
        list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
        $payment_date = sprintf("%04d-%02d-%02d", $gy, $gm, $gd);

        $pdo->beginTransaction();
        try {
            if ($mode === 'edit') {
                // حالت ویرایش: به‌روزرسانی پرداخت موجود
                $stmt = $pdo->prepare("
                    UPDATE Payments 
                    SET amount = ?, payment_date = ?, payment_type = ?, payment_code = ?
                    WHERE payment_id = ?
                ");
                $stmt->execute([$amount, $payment_date, $payment_type, $payment_code, $payment_id]);
            } else {
                // حالت اضافه کردن: ایجاد پرداخت جدید
                $stmt = $pdo->prepare("
                    INSERT INTO Payments (order_id, amount, payment_date, payment_type, payment_code)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$order_id, $amount, $payment_date, $payment_type, $payment_code]);
            }

            $pdo->commit();
            $message = $mode === 'edit' ? 'پرداخت با موفقیت ویرایش شد' : 'پرداخت با موفقیت اضافه شد';
            echo "<div class='container-fluid mt-5'><div class='alert alert-success text-center'>$message. <a href='orders.php'>بازگشت به لیست سفارشات</a></div></div>";
            require_once 'footer.php';
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>خطا در " . ($mode === 'edit' ? 'ویرایش' : 'اضافه کردن') . " پرداخت: " . $e->getMessage() . "</div></div>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $mode === 'edit' ? 'ویرایش پرداخت' : 'اضافه کردن پرداخت' ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/persian-datepicker.min.css" />
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container-fluid mt-5">
        <h5 class="card-title mb-4"><?= $mode === 'edit' ? 'ویرایش پرداخت' : 'اضافه کردن پرداخت' ?></h5>

        <form method="POST">
            <div class="mb-3">
                <label for="order_id" class="form-label">شماره سفارش</label>
                <input type="text" class="form-control" id="order_id" value="<?= $order['order_id'] ?>" readonly>
            </div>
            <div class="mb-3">
                <label for="customer_name" class="form-label">نام مشتری</label>
                <input type="text" class="form-control" id="customer_name" value="<?= htmlspecialchars($order['customer_name']) ?>" readonly>
            </div>
            <div class="mb-3">
                <label for="final_amount" class="form-label">مبلغ نهایی فاکتور</label>
                <input type="text" class="form-control" id="final_amount" value="<?= number_format($order['final_amount'], 0) ?> تومان" readonly>
            </div>
            <div class="mb-3">
                <label for="remaining_balance" class="form-label">مانده حساب (بدون این پرداخت)</label>
                <input type="text" class="form-control" id="remaining_balance" value="<?= number_format($order['final_amount'] - $total_paid_without_current, 0) ?> تومان" readonly>
            </div>
            <div class="mb-3">
                <label for="amount" class="form-label">مبلغ پرداخت (تومان)</label>
                <input type="number" class="form-control" id="amount" name="amount" value="<?= $payment['amount'] ?? '' ?>" min="1" required>
            </div>
            <div class="mb-3">
                <label for="payment_date" class="form-label">تاریخ پرداخت</label>
                <input type="text" class="form-control" id="payment_date" name="payment_date" value="<?= $payment ? gregorian_to_jalali_format($payment['payment_date']) : '' ?>" required>
            </div>
            <div class="mb-3">
                <label for="payment_type" class="form-label">نوع پرداخت</label>
                <select class="form-select" id="payment_type" name="payment_type" required>
                    <option value="" <?= !$payment ? 'selected' : '' ?>>انتخاب کنید</option>
                    <option value="نقدی" <?= $payment && $payment['payment_type'] === 'نقدی' ? 'selected' : '' ?>>نقدی</option>
                    <option value="کارت به کارت" <?= $payment && $payment['payment_type'] === 'کارت به کارت' ? 'selected' : '' ?>>کارت به کارت</option>
                </select>
            </div>
            <div class="mb-3">
                <label for="payment_code" class="form-label">کد واریز (در صورت کارت به کارت)</label>
                <input type="text" class="form-control" id="payment_code" name="payment_code" value="<?= $payment ? htmlspecialchars($payment['payment_code']) : '' ?>">
            </div>
            <button type="submit" class="btn btn-success mt-3"><?= $mode === 'edit' ? 'ذخیره تغییرات' : 'ثبت پرداخت' ?></button>
            <a href="orders.php" class="btn btn-secondary mt-3">بازگشت</a>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/persian-date.min.js"></script>
    <script src="assets/js/persian-datepicker.min.js"></script>
    <script>
        $(document).ready(function() {
            $("#payment_date").persianDatepicker({
                format: "YYYY/MM/DD",
                autoClose: true,
                initialValue: <?= $payment ? 'true' : 'false' ?>,
                <?php if (!$payment): ?>
                initialValue: false,
                onSelect: function(unix) {
                    const d = new persianDate(unix);
                    $(this).val(d.format("YYYY/MM/DD"));
                }
                <?php endif; ?>
            });

            // محاسبه پویای مانده حساب هنگام تغییر مبلغ پرداخت
            $('#amount').on('input', function() {
                const finalAmount = <?= $order['final_amount'] ?>;
                const totalPaidWithoutCurrent = <?= $total_paid_without_current ?>;
                const currentAmount = parseFloat($(this).val()) || 0;
                const remainingBalance = finalAmount - (totalPaidWithoutCurrent + currentAmount);
                $('#remaining_balance').val(remainingBalance.toLocaleString('fa') + ' تومان');
            });
        });
    </script>

<?php require_once 'footer.php'; ?>