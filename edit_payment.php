<?php
ob_start(); // شروع بافر خروجی برای جلوگیری از ارور هدر
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
    if (empty($gregorian_date) || strpos($gregorian_date, '-') === false) return '';
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

// تابع تبدیل تاریخ شمسی به میلادی با اعتبارسنجی
function jalali_to_gregorian_safe($jy, $jm, $jd) {
    if (!is_numeric($jy) || !is_numeric($jm) || !is_numeric($jd) || $jy < 1300 || $jy > 1500 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
        return null; // بازگرداندن null در صورت نامعتبر بودن
    }
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    return sprintf("%04d-%02d-%02d", $gy, $gm, $gd);
}

// بررسی نقش کاربر
$is_admin = ($_SESSION['role'] === 'admin');
if ($is_admin) {
    header("Location: orders.php");
    exit;
}
$current_user_id = $_SESSION['user_id'];

// دریافت order_id از GET
$order_id = $_GET['order_id'] ?? '';
if (!$order_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>شناسه سفارش مشخص نشده است.</div></div>";
    require_once 'footer.php';
    ob_end_flush();
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
    ob_end_flush();
    exit;
}

// دریافت پرداخت‌های موجود
$stmt = $pdo->prepare("SELECT * FROM Order_Payments WHERE order_id = ?");
$stmt->execute([$order_id]);
$existing_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// محاسبه مجموع پرداخت‌ها برای نمایش مانده حساب
$total_paid = 0;
foreach ($existing_payments as $payment) {
    $total_paid += $payment['amount'];
}

// مدیریت حذف پرداخت
if (isset($_GET['delete_payment_id'])) {
    $delete_payment_id = $_GET['delete_payment_id'];
    $stmt = $pdo->prepare("DELETE FROM Order_Payments WHERE order_payment_id = ? AND order_id = ?");
    if ($stmt->execute([$delete_payment_id, $order_id])) {
        header("Location: edit_payment.php?order_id=$order_id");
        ob_end_flush();
        exit;
    } else {
        echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>خطا در حذف پرداخت.</div></div>";
    }
}

// مدیریت ارسال فرم
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payments = $_POST['payments'] ?? [];
    if (empty($payments)) {
        echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>هیچ پرداختی وارد نشده است.</div></div>";
    } else {
        $pdo->beginTransaction();
        try {
            // حذف همه پرداخت‌های قبلی برای این سفارش (اختیاری، بسته به منطق)
            // $stmt = $pdo->prepare("DELETE FROM Order_Payments WHERE order_id = ?");
            // $stmt->execute([$order_id]);

            // ذخیره یا به‌روزرسانی پرداخت‌ها
            foreach ($payments as $index => $payment_data) {
                $payment_id = $payment_data['payment_id'] ?? '';
                $amount = (float)($payment_data['amount'] ?? 0);
                $jalali_payment_date = trim($payment_data['payment_date'] ?? '');
                $payment_type = $payment_data['payment_type'] ?? '';
                $payment_code = $payment_data['payment_code'] ?? '';

                // اعتبارسنجی
                if ($amount <= 0 || empty($jalali_payment_date) || empty($payment_type)) {
                    throw new Exception("فیلدهای الزامی برای پرداخت شماره " . ($index + 1) . " پر نشده است.");
                }

                // تبدیل تاریخ شمسی به میلادی با اعتبارسنجی
                list($jy, $jm, $jd) = explode('/', $jalali_payment_date);
                $payment_date = jalali_to_gregorian_safe($jy, $jm, $jd);
                if ($payment_date === null) {
                    throw new Exception("تاریخ نامعتبر برای پرداخت شماره " . ($index + 1) . " است.");
                }

                if ($payment_id) {
                    // به‌روزرسانی پرداخت موجود
                    $stmt = $pdo->prepare("
                        UPDATE Order_Payments 
                        SET amount = ?, payment_date = ?, payment_type = ?, payment_code = ?
                        WHERE order_payment_id = ? AND order_id = ?
                    ");
                    $stmt->execute([$amount, $payment_date, $payment_type, $payment_code, $payment_id, $order_id]);
                } else {
                    // اضافه کردن پرداخت جدید
                    $stmt = $pdo->prepare("
                        INSERT INTO Order_Payments (order_id, amount, payment_date, payment_type, payment_code)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$order_id, $amount, $payment_date, $payment_type, $payment_code]);
                }
            }

            $pdo->commit();
            echo "<div class='container-fluid mt-5'><div class='alert alert-success text-center'>پرداخت‌ها با موفقیت ثبت شدند. <a href='orders.php'>بازگشت به لیست سفارشات</a></div></div>";
            require_once 'footer.php';
            ob_end_flush();
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>خطا در ثبت پرداخت‌ها: " . $e->getMessage() . "</div></div>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت پرداخت‌ها</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/persian-datepicker.min.css" />
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container-fluid mt-5">
        <h5 class="card-title mb-4">مدیریت پرداخت‌ها</h5>

        <div class="mb-3">
            <label class="form-label">شماره سفارش</label>
            <input type="text" class="form-control" value="<?= $order['order_id'] ?>" readonly>
        </div>
        <div class="mb-3">
            <label class="form-label">نام مشتری</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($order['customer_name']) ?>" readonly>
        </div>
        <div class="mb-3">
            <label class="form-label">مبلغ نهایی فاکتور</label>
            <input type="text" class="form-control" value="<?= number_format($order['final_amount'], 0) ?> تومان" readonly>
        </div>
        <div class="mb-3">
            <label class="form-label">مانده حساب</label>
            <input type="text" class="form-control" id="remaining_balance" value="<?= number_format($order['final_amount'] - $total_paid, 0) ?> تومان" readonly>
        </div>

        <form method="POST" id="payment_form">
            <div id="payment_list">
                <?php foreach ($existing_payments as $index => $payment): ?>
                    <div class="payment-row mb-3 border p-3 rounded position-relative">
                        <input type="hidden" name="payments[<?= $index ?>][payment_id]" value="<?= $payment['order_payment_id'] ?>">
                        <div class="mb-2">
                            <label class="form-label">مبلغ پرداخت (تومان)</label>
                            <input type="number" class="form-control payment-amount" name="payments[<?= $index ?>][amount]" value="<?= $payment['amount'] ?>" min="1" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">تاریخ پرداخت</label>
                            <input type="text" class="form-control payment-date" name="payments[<?= $index ?>][payment_date]" value="<?= gregorian_to_jalali_format($payment['payment_date']) ?>" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">نوع پرداخت</label>
                            <select class="form-select" name="payments[<?= $index ?>][payment_type]" required>
                                <option value="نقدی" <?= $payment['payment_type'] === 'نقدی' ? 'selected' : '' ?>>نقدی</option>
                                <option value="کارت به کارت" <?= $payment['payment_type'] === 'کارت به کارت' ? 'selected' : '' ?>>کارت به کارت</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">کد واریز (در صورت کارت به کارت)</label>
                            <input type="text" class="form-control" name="payments[<?= $index ?>][payment_code]" value="<?= htmlspecialchars($payment['payment_code']) ?>">
                        </div>
                        <a href="edit_payment.php?order_id=<?= $order_id ?>&delete_payment_id=<?= $payment['order_payment_id'] ?>" class="btn btn-danger btn-sm position-absolute" style="top: 10px; left: 10px;" onclick="return confirm('آیا مطمئن هستید که می‌خواهید این پرداخت را حذف کنید؟')">حذف</a>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-primary mb-3" id="add_payment">افزودن پرداخت</button>
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-success me-2">ذخیره همه پرداخت‌ها</button>
                <a href="orders.php" class="btn btn-secondary">بازگشت</a>
            </div>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/persian-date.min.js"></script>
    <script src="assets/js/persian-datepicker.min.js"></script>
    <script>
        $(document).ready(function() {
            let paymentIndex = <?= count($existing_payments) ?>;

            // افزودن ردیف جدید برای پرداخت
            $("#add_payment").click(function() {
                const paymentRow = `
                    <div class="payment-row mb-3 border p-3 rounded position-relative">
                        <div class="mb-2">
                            <label class="form-label">مبلغ پرداخت (تومان)</label>
                            <input type="number" class="form-control payment-amount" name="payments[${paymentIndex}][amount]" min="1" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">تاریخ پرداخت</label>
                            <input type="text" class="form-control payment-date" name="payments[${paymentIndex}][payment_date]" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">نوع پرداخت</label>
                            <select class="form-select" name="payments[${paymentIndex}][payment_type]" required>
                                <option value="نقدی">نقدی</option>
                                <option value="کارت به کارت">کارت به کارت</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">کد واریز (در صورت کارت به کارت)</label>
                            <input type="text" class="form-control" name="payments[${paymentIndex}][payment_code]">
                        </div>
                        <button type="button" class="btn btn-danger btn-sm position-absolute remove-payment" style="top: 10px; left: 10px;">حذف</button>
                    </div>`;
                $("#payment_list").append(paymentRow);

                // فعال کردن Persian Datepicker برای ردیف جدید
                $(".payment-date").last().persianDatepicker({
                    format: "YYYY/MM/DD",
                    autoClose: true,
                    initialValue: true, // تنظیم تاریخ پیش‌فرض
                    onSelect: function(unix) {
                        const d = new persianDate(unix);
                        $(this).val(d.format("YYYY/MM/DD"));
                    }
                });

                paymentIndex++;
                updateRemainingBalance();
            });

            // حذف ردیف پرداخت (فقط ردیف‌های جدید)
            $(document).on("click", ".remove-payment", function() {
                $(this).closest(".payment-row").remove();
                updateRemainingBalance();
            });

            // محاسبه پویای مانده حساب
            function updateRemainingBalance() {
                const finalAmount = <?= $order['final_amount'] ?>;
                let totalPaid = 0;
                $(".payment-amount").each(function() {
                    const amount = parseFloat($(this).val()) || 0;
                    totalPaid += amount;
                });
                const remainingBalance = finalAmount - totalPaid;
                $("#remaining_balance").val(remainingBalance.toLocaleString('fa') + ' تومان');
            }

            // فعال کردن Persian Datepicker برای ردیف‌های موجود
            $(".payment-date").each(function() {
                $(this).persianDatepicker({
                    format: "YYYY/MM/DD",
                    autoClose: true,
                    initialValue: false,
                    onSelect: function(unix) {
                        const d = new persianDate(unix);
                        $(this).val(d.format("YYYY/MM/DD"));
                    }
                });
            });

            // به‌روزرسانی مانده حساب هنگام تغییر مبلغ
            $(document).on("input", ".payment-amount", updateRemainingBalance);
        });
    </script>

<?php require_once 'footer.php';
ob_end_flush(); // پایان بافر و ارسال خروجی
?>