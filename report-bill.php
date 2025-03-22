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
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

// تابع برای دریافت نام ماه شمسی
function get_jalali_month_name($month) {
    $month_names = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
        4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
        10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    ];
    return $month_names[$month] ?? '';
}

// تابع تبدیل سال میلادی به سال شمسی
function gregorian_year_to_jalali($gregorian_year) {
    list($jy, $jm, $jd) = gregorian_to_jalali($gregorian_year, 1, 1);
    return $jy;
}

// بررسی نقش کاربر
$current_user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user_role = $stmt->fetchColumn();

// دریافت سال‌های موجود از دیتابیس (میلادی)
$stmt = $pdo->query("SELECT DISTINCT YEAR(start_date) AS year FROM Work_Months ORDER BY year DESC");
$years_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
$years = array_column($years_db, 'year');

// دریافت سال جاری (میلادی) به‌عنوان پیش‌فرض
$current_year = date('Y');

// دریافت پارامترهای انتخاب‌شده
$selected_year = $_GET['year'] ?? (in_array($current_year, $years) ? $current_year : (!empty($years) ? $years[0] : null));
$selected_month = $_GET['work_month_id'] ?? '';
$display_filter = $_GET['display_filter'] ?? 'all'; // پیش‌فرض "همه"
$partner_role_filter = $_GET['partner_role'] ?? 'all'; // پیش‌فرض "همه"

// متغیرهای جمع کل
$total_invoices = 0;    // جمع کل فاکتورها (فروش - تخفیف)
$total_payments = 0;    // مجموع پرداختی‌ها
$total_debt = 0;        // مانده بدهی
$bills = [];            // لیست فاکتورها برای جدول

if ($selected_year && $selected_month) {
    // جمع کل فاکتورها (فروش - تخفیف)
    $query = "
        SELECT COALESCE(SUM(o.total_amount - o.discount), 0) AS total_invoices
        FROM Orders o
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        WHERE wd.work_month_id = ?
    ";
    $params = [$selected_month];

    if ($user_role !== 'admin') {
        if ($partner_role_filter === 'all') {
            $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
            $params[] = $current_user_id;
            $params[] = $current_user_id;
        } elseif ($partner_role_filter === 'partner1') {
            $query .= " AND p.user_id1 = ?";
            $params[] = $current_user_id;
        } elseif ($partner_role_filter === 'partner2') {
            $query .= " AND p.user_id2 = ?";
            $params[] = $current_user_id;
        }
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $total_invoices = $stmt->fetchColumn() ?? 0;

    // مجموع پرداختی‌ها (از جدول Order_Payments)
    $query = "
        SELECT COALESCE(SUM(op.amount), 0) AS total_payments
        FROM Order_Payments op
        JOIN Orders o ON op.order_id = o.order_id
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        WHERE wd.work_month_id = ?
    ";
    $params = [$selected_month];

    if ($user_role !== 'admin') {
        if ($partner_role_filter === 'all') {
            $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
            $params[] = $current_user_id;
            $params[] = $current_user_id;
        } elseif ($partner_role_filter === 'partner1') {
            $query .= " AND p.user_id1 = ?";
            $params[] = $current_user_id;
        } elseif ($partner_role_filter === 'partner2') {
            $query .= " AND p.user_id2 = ?";
            $params[] = $current_user_id;
        }
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $total_payments = $stmt->fetchColumn() ?? 0;

    // مانده بدهی
    $total_debt = $total_invoices - $total_payments;

    // لیست فاکتورها برای جدول (با فیلتر بدهکاران)
    $query = "
        SELECT o.created_at AS order_date, o.customer_name, 
               (o.total_amount - o.discount) AS invoice_amount,
               (o.total_amount - o.discount - COALESCE((
                   SELECT SUM(op.amount) 
                   FROM Order_Payments op 
                   WHERE op.order_id = o.order_id
               ), 0)) AS remaining_debt,
               u1.full_name AS partner1_name,
               u2.full_name AS partner2_name
        FROM Orders o
        JOIN Work_Details wd ON o.work_details_id = wd.id
        JOIN Partners p ON wd.partner_id = p.partner_id
        JOIN Users u1 ON p.user_id1 = u1.user_id
        JOIN Users u2 ON p.user_id2 = u2.user_id
        WHERE wd.work_month_id = ?
    ";
    $params = [$selected_month];

    if ($user_role !== 'admin') {
        if ($partner_role_filter === 'all') {
            $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
            $params[] = $current_user_id;
            $params[] = $current_user_id;
        } elseif ($partner_role_filter === 'partner1') {
            $query .= " AND p.user_id1 = ?";
            $params[] = $current_user_id;
        } elseif ($partner_role_filter === 'partner2') {
            $query .= " AND p.user_id2 = ?";
            $params[] = $current_user_id;
        }
    }

    if ($display_filter === 'debtors') {
        $query .= " AND (o.total_amount - o.discount - COALESCE((
                   SELECT SUM(op.amount) 
                   FROM Order_Payments op 
                   WHERE op.order_id = o.order_id
               ), 0)) > 0";
    }
    $query .= " ORDER BY o.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="container-fluid mt-5">
        <h5 class="card-title mb-4">گزارش بدهی</h5>

        <!-- فیلترها -->
        <div class="mb-4">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="year" class="form-label">سال</label>
                    <select name="year" id="year" class="form-select">
                        <option value="">همه</option>
                        <?php foreach ($years as $year): ?>
                            <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                                <?= gregorian_year_to_jalali($year) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="work_month_id" class="form-label">ماه کاری</label>
                    <div class="input-group">
                        <select name="work_month_id" id="work_month_id" class="form-select">
                            <option value="">انتخاب ماه</option>
                            <!-- ماه‌ها اینجا با AJAX بارگذاری می‌شن -->
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <label for="partner_role" class="form-label">نقش همکار</label>
                    <select name="partner_role" id="partner_role" class="form-select">
                        <option value="all" <?= $partner_role_filter === 'all' ? 'selected' : '' ?>>همه</option>
                        <option value="partner1" <?= $partner_role_filter === 'partner1' ? 'selected' : '' ?>>همکار ۱</option>
                        <option value="partner2" <?= $partner_role_filter === 'partner2' ? 'selected' : '' ?>>همکار ۲</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="display_filter" class="form-label">نمایش</label>
                    <select name="display_filter" id="display_filter" class="form-select">
                        <option value="all" <?= $display_filter === 'all' ? 'selected' : '' ?>>همه</option>
                        <option value="debtors" <?= $display_filter === 'debtors' ? 'selected' : '' ?>>بدهکاران</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- اطلاعات اضافی (بعد از انتخاب ماه) -->
        <div id="summary" class="mb-4" style="display: <?= $selected_month ? 'block' : 'none' ?>;">
            <p>جمع کل فاکتورها: <strong><?= number_format($total_invoices, 0) ?> تومان</strong></p>
            <p>مجموع پرداختی‌ها: <strong><?= number_format($total_payments, 0) ?> تومان</strong></p>
            <p>مانده بدهی‌ها: <strong><?= number_format($total_debt, 0) ?> تومان</strong></p>
        </div>

        <!-- جدول فاکتورها -->
        <div class="table-responsive" id="bills-table">
            <table class="table table-light">
                <thead>
                    <tr>
                        <th>تاریخ</th>
                        <th>نام مشتری</th>
                        <?php if ($user_role === 'seller' || $user_role === 'admin'): ?>
                            <th>همکاران</th>
                        <?php endif; ?>
                        <th>مبلغ فاکتور</th>
                        <th>مانده بدهی</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bills)): ?>
                        <tr>
                            <td colspan="<?= $user_role === 'seller' || $user_role === 'admin' ? 5 : 4 ?>" class="text-center">فاکتوری یافت نشد.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($bills as $bill): ?>
                            <tr>
                                <td><?= gregorian_to_jalali_format($bill['order_date']) ?></td>
                                <td><?= htmlspecialchars($bill['customer_name']) ?></td>
                                <?php if ($user_role === 'seller' || $user_role === 'admin'): ?>
                                    <td><?= htmlspecialchars($bill['partner1_name']) . ' - ' . htmlspecialchars($bill['partner2_name']) ?></td>
                                <?php endif; ?>
                                <td><?= number_format($bill['invoice_amount'], 0) ?> تومان</td>
                                <td><?= number_format($bill['remaining_debt'], 0) ?> تومان</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // تابع برای بارگذاری ماه‌ها بر اساس سال
            function loadMonths(year) {
                console.log('Loading months for year:', year);
                if (!year) {
                    $('#work_month_id').html('<option value="">انتخاب ماه</option>');
                    $('#summary').hide();
                    return;
                }
                $.ajax({
                    url: 'get_months_for_bills.php',
                    type: 'POST',
                    data: { year: year },
                    success: function(response) {
                        console.log('Months response:', response);
                        $('#work_month_id').html(response);
                        $('#summary').hide();
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading months:', error);
                        $('#work_month_id').html('<option value="">خطا در بارگذاری ماه‌ها</option>');
                        $('#summary').hide();
                    }
                });
            }

            // تابع برای بارگذاری فاکتورها
            function loadBills() {
                console.log('Loading bills...');
                const year = $('#year').val();
                const work_month_id = $('#work_month_id').val();
                const display_filter = $('#display_filter').val();
                const partner_role = $('#partner_role').val();

                if (!work_month_id) {
                    $('#bills-table').html('<table class="table table-light"><thead><tr><th>تاریخ</th><th>نام مشتری</th><?= $user_role === 'seller' || $user_role === 'admin' ? '<th>همکاران</th>' : '' ?><th>مبلغ فاکتور</th><th>مانده بدهی</th></tr></thead><tbody><tr><td colspan="<?= $user_role === 'seller' || $user_role === 'admin' ? 5 : 4 ?>" class="text-center">لطفاً ماه کاری را انتخاب کنید.</td></tr></tbody></table>');
                    $('#summary').hide();
                    return;
                }

                $.ajax({
                    url: 'get_bills.php',
                    type: 'GET',
                    data: {
                        action: 'get_bill_report',
                        year: year,
                        work_month_id: work_month_id,
                        display_filter: display_filter,
                        partner_role: partner_role
                    },
                    dataType: 'json',
                    success: function(response) {
                        console.log('Bills response (raw):', response);
                        try {
                            if (response.success && typeof response.html === 'string' && response.html.trim().length > 0) {
                                console.log('Rendering HTML:', response.html);
                                $('#bills-table').html(response.html);
                                // به‌روزرسانی اطلاعات اضافی
                                $('#summary').show();
                                $('#summary').html(`
                                    <p>جمع کل فاکتورها: <strong>${response.total_invoices ? new Intl.NumberFormat('fa-IR').format(response.total_invoices) + ' تومان' : '0 تومان'}</strong></p>
                                    <p>مجموع پرداختی‌ها: <strong>${response.total_payments ? new Intl.NumberFormat('fa-IR').format(response.total_payments) + ' تومان' : '0 تومان'}</strong></p>
                                    <p>مانده بدهی‌ها: <strong>${response.total_debt ? new Intl.NumberFormat('fa-IR').format(response.total_debt) + ' تومان' : '0 تومان'}</strong></p>
                                `);
                            } else {
                                throw new Error('HTML نامعتبر یا خالی است: ' + (response.message || 'داده‌ای برای نمایش وجود ندارد'));
                            }
                        } catch (e) {
                            console.error('Error rendering bills:', e);
                            $('#bills-table').html('<div class="alert alert-danger text-center">خطا در نمایش فاکتورها: ' + e.message + '</div>');
                            $('#summary').hide();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', { status: status, error: error, response: xhr.responseText });
                        $('#bills-table').html('<div class="alert alert-danger text-center">خطایی در بارگذاری فاکتورها رخ داد: ' + error + '</div>');
                        $('#summary').hide();
                    }
                });
            }

            // تابع برای بارگذاری همه فیلترها
            function loadFilters() {
                const year = $('#year').val();
                loadMonths(year);
                loadBills();
            }

            // بارگذاری اولیه
            const initial_year = $('#year').val();
            if (initial_year) {
                loadMonths(initial_year);
            }
            loadBills();

            // رویدادهای تغییر
            $('#year').on('change', function() {
                loadFilters();
            });

            $('#work_month_id').on('change', function() {
                loadBills();
            });

            $('#partner_role').on('change', function() {
                loadBills();
            });

            $('#display_filter').on('change', function() {
                loadBills();
            });
        });
    </script>

    <?php require_once 'footer.php'; ?>