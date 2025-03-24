<?php
session_start();

// فقط ادمین می‌تونه به این صفحه دسترسی داشته باشه
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

require_once 'db.php';
require_once 'header.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date)
{
    if (!$gregorian_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $gregorian_date)) {
        return "نامشخص";
    }
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

// دریافت سال‌های شمسی موجود
$years_query = $pdo->query("SELECT DISTINCT YEAR(start_date) AS gregorian_year FROM Work_Months ORDER BY gregorian_year DESC");
$gregorian_years = $years_query->fetchAll(PDO::FETCH_COLUMN);
$jalali_years = [];
foreach ($gregorian_years as $gy) {
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, 1, 1);
    $jalali_years[$jy] = $jy;
}
$selected_year = isset($_POST['year']) ? (int)$_POST['year'] : null;

// دریافت ماه‌های کاری برای سال انتخاب‌شده
$work_months = [];
if ($selected_year) {
    $gregorian_year = jdate('Y', strtotime("$selected_year/01/01"), '', 'Asia/Tehran', 'en');
    $stmt = $pdo->prepare("
        SELECT work_month_id, start_date, end_date
        FROM Work_Months
        WHERE YEAR(start_date) = ?
        ORDER BY start_date DESC
    ");
    $stmt->execute([$gregorian_year]);
    $work_months = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$selected_work_month_id = isset($_POST['work_month_id']) ? (int)$_POST['work_month_id'] : null;

// دریافت همکاران (فقط همکار 1)
$partners = [];
if ($selected_work_month_id) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.user_id, u.full_name
        FROM Users u
        JOIN Partners p ON u.user_id = p.user_id1
        WHERE u.role = 'seller'
        ORDER BY u.full_name
    ");
    $stmt->execute();
    $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$selected_partner_id = isset($_POST['partner_id']) ? (int)$_POST['partner_id'] : null;

// دریافت موجودی محصولات برای همکار انتخاب‌شده
$inventory_data = [];
if ($selected_year && $selected_work_month_id && $selected_partner_id) {
    $stmt = $pdo->prepare("
        SELECT p.product_id, p.product_name, p.unit_price, COALESCE(i.quantity, 0) AS quantity
        FROM Products p
        LEFT JOIN Inventory i ON p.product_id = i.product_id AND i.user_id = ?
        ORDER BY p.product_name
    ");
    $stmt->execute([$selected_partner_id]);
    $inventory_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // مرتب‌سازی بر اساس الفبای فارسی
    $collator = new Collator('fa_IR');
    usort($inventory_data, function ($a, $b) use ($collator) {
        return $collator->compare($a['product_name'], $b['product_name']);
    });

    // دریافت آخرین قیمت برای هر محصول
    foreach ($inventory_data as &$item) {
        $stmt = $pdo->prepare("
            SELECT unit_price
            FROM Product_Price_History
            WHERE product_id = ? AND start_date <= (
                SELECT end_date
                FROM Work_Months
                WHERE work_month_id = ?
            )
            ORDER BY start_date DESC LIMIT 1
        ");
        $stmt->execute([$item['product_id'], $selected_work_month_id]);
        $latest_price = $stmt->fetch(PDO::FETCH_ASSOC);
        $item['unit_price'] = $latest_price ? $latest_price['unit_price'] : $item['unit_price'];
    }
    unset($item);
}
?>

<div class="container-fluid">
    <h2 class="text-center mb-4">مدیریت موجودی محصولات همکاران</h2>

    <!-- فرم فیلترها -->
    <form method="POST" class="row g-3 mb-4">
        <!-- فیلتر سال -->
        <div class="col-md-4">
            <label for="year" class="form-label">سال</label>
            <select class="form-select" id="year" name="year" onchange="this.form.submit()" required>
                <option value="">انتخاب کنید</option>
                <?php foreach ($jalali_years as $jy): ?>
                    <option value="<?= $jy ?>" <?= $selected_year == $jy ? 'selected' : '' ?>>
                        <?= $jy ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- فیلتر ماه کاری -->
        <div class="col-md-4">
            <label for="work_month_id" class="form-label">ماه کاری</label>
            <select class="form-select" id="work_month_id" name="work_month_id" onchange="this.form.submit()" required>
                <option value="">انتخاب کنید</option>
                <?php if ($selected_year): ?>
                    <?php foreach ($work_months as $month): ?>
                        <option value="<?= $month['work_month_id'] ?>" <?= $selected_work_month_id == $month['work_month_id'] ? 'selected' : '' ?>>
                            <?= gregorian_to_jalali_format($month['start_date']) ?> تا <?= gregorian_to_jalali_format($month['end_date']) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>

        <!-- فیلتر همکار -->
        <div class="col-md-4">
            <label for="partner_id" class="form-label">همکار</label>
            <select class="form-select" id="partner_id" name="partner_id" onchange="this.form.submit()" required>
                <option value="">انتخاب کنید</option>
                <?php if ($selected_work_month_id): ?>
                    <?php foreach ($partners as $partner): ?>
                        <option value="<?= $partner['user_id'] ?>" <?= $selected_partner_id == $partner['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($partner['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
    </form>

    <!-- جدول موجودی محصولات -->
    <?php if ($selected_year && $selected_work_month_id && $selected_partner_id): ?>
        <?php if (!empty($inventory_data)): ?>
            <div class="table-responsive" style="overflow-x: auto; width: 100%;">
                <table id="inventoryTable" class="table table-light table-hover display nowrap" style="width: 100%; min-width: 800px;">
                    <thead>
                        <tr>
                            <th>شناسه</th>
                            <th>نام محصول</th>
                            <th>قیمت واحد (تومان)</th>
                            <th>موجودی همکار</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inventory_data as $item): ?>
                            <tr>
                                <td><?= $item['product_id'] ?></td>
                                <td><?= htmlspecialchars($item['product_name']) ?></td>
                                <td><?= number_format($item['unit_price'], 0, '', ',') ?></td>
                                <td><?= $item['quantity'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-warning text-center">محصولی برای این همکار یافت نشد.</div>
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert-info text-center">لطفاً همه فیلترها را انتخاب کنید تا جدول نمایش داده شود.</div>
    <?php endif; ?>
</div>

<!-- جاوااسکریپت DataTables -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    $(document).ready(function () {
        // فقط وقتی جدول وجود داره، DataTables رو مقداردهی کن
        if ($('#inventoryTable').length) {
            $('#inventoryTable').DataTable({
                "pageLength": 10,
                "scrollX": true,
                "scrollCollapse": true,
                "paging": true,
                "autoWidth": true,
                "ordering": true,
                "responsive": false,
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
                },
                "columnDefs": [
                    { "targets": "_all", "className": "text-center" }
                ]
            });
        }
    });
</script>

<style>
    /* اطمینان از RTL بودن جدول */
    #inventoryTable {
        direction: rtl !important;
    }

    /* تنظیمات برای دیتاتیبل */
    #inventoryTable_wrapper {
        width: 100%;
        overflow-x: auto;
    }
</style>

<?php require_once 'footer.php'; ?>