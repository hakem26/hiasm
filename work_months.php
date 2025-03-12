<?php
// [BLOCK-WORK-MONTHS-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';
require_once 'persian_year.php';

// تابع تبدیل میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd"; // خروجی: YYYY/MM/DD
}

// تابع تبدیل سال میلادی به سال شمسی
function gregorian_year_to_jalali($gregorian_year) {
    list($jy, $jm, $jd) = gregorian_to_jalali($gregorian_year, 1, 1); // فقط سال رو می‌گیریم
    return $jy;
}

// دریافت سال‌های موجود از دیتابیس (بر اساس سال شمسی)
$stmt = $pdo->query("SELECT DISTINCT start_date FROM Work_Months ORDER BY start_date DESC");
$work_months_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
$years = [];
$all_work_months = [];

foreach ($work_months_data as $month) {
    list($gy, $gm, $gd) = explode('-', $month['start_date']);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    $years[] = $jy;
}
$years = array_unique($years);
rsort($years);

// دریافت سال جاری (شمسی) به‌عنوان پیش‌فرض
$current_persian_year = get_persian_current_year();
$selected_year = $_GET['year'] ?? $current_persian_year;

// دریافت همه ماه‌های کاری و فیلتر بر اساس سال شمسی
$stmt = $pdo->query("SELECT * FROM Work_Months ORDER BY start_date DESC");
$all_work_months = $stmt->fetchAll(PDO::FETCH_ASSOC);

$work_months = [];
foreach ($all_work_months as $month) {
    list($gy, $gm, $gd) = explode('-', $month['start_date']);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    if ($jy == $selected_year) {
        $work_months[] = $month;
    }
}
?>
    <!-- [BLOCK-WORK-MONTHS-002] -->
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="card-title">لیست ماه‌های کاری</h5>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWorkMonthModal">افزودن ماه کاری</button>
        </div>

        <!-- فیلتر سال -->
        <?php if (!empty($years)): ?>
        <form method="GET" class="row g-3 mb-3">
            <div class="col-auto">
                <select name="year" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($years as $year): ?>
                        <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                            <?= $year ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
        <?php endif; ?>

        <?php if (empty($work_months)): ?>
        <div class="alert alert-warning text-center">ماه کاری‌ای ساخته نشده است.</div>
        <?php else: ?>
        <table class="table table-light table-hover">
            <thead>
                <tr>
                    <th>ردیف</th>
                    <th>تاریخ شروع</th>
                    <th>تاریخ پایان</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php $row = 1; foreach ($work_months as $month): ?>
                <tr>
                    <td><?php echo $row++; ?></td>
                    <td><?php echo gregorian_to_jalali_format($month['start_date']); ?></td>
                    <td><?php echo gregorian_to_jalali_format($month['end_date']); ?></td>
                    <td>
                        <a href="#" class="text-primary me-2" data-bs-toggle="modal" data-bs-target="#editWorkMonthModal" data-month-id="<?php echo $month['work_month_id']; ?>" data-start-date="<?php echo gregorian_to_jalali_format($month['start_date']); ?>" data-end-date="<?php echo gregorian_to_jalali_format($month['end_date']); ?>">
                            <i class="fas fa-edit"></i>
                        </a>
                        <a href="#" class="text-danger" onclick="confirmDeleteMonth(<?php echo $month['work_month_id']; ?>)">
                            <i class="fas fa-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- مودال افزودن ماه کاری -->
    <div class="modal fade" id="addWorkMonthModal" tabindex="-1" aria-labelledby="addWorkMonthModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-light">
                <div class="modal-header">
                    <h5 class="modal-title" id="addWorkMonthModalLabel">افزودن ماه کاری جدید</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addWorkMonthForm" method="POST" action="add_work_month.php">
                        <div class="mb-3">
                            <label for="start_date" class="form-label">تاریخ شروع (شمسی)</label>
                            <input type="text" class="form-control persian-date" id="start_date" name="start_date" required>
                        </div>
                        <div class="mb-3">
                            <label for="end_date" class="form-label">تاریخ پایان (شمسی)</label>
                            <input type="text" class="form-control persian-date" id="end_date" name="end_date" required>
                        </div>
                        <button type="submit" class="btn btn-primary">ثبت ماه کاری</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال ویرایش ماه کاری -->
    <div class="modal fade" id="editWorkMonthModal" tabindex="-1" aria-labelledby="editWorkMonthModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-light">
                <div class="modal-header">
                    <h5 class="modal-title" id="editWorkMonthModalLabel">ویرایش ماه کاری</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editWorkMonthForm" method="POST" action="edit_work_month.php">
                        <input type="hidden" id="edit_month_id" name="month_id">
                        <div class="mb-3">
                            <label for="edit_start_date" class="form-label">تاریخ شروع (شمسی)</label>
                            <input type="text" class="form-control persian-date" id="edit_start_date" name="start_date" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_end_date" class="form-label">تاریخ پایان (شمسی)</label>
                            <input type="text" class="form-control persian-date" id="edit_end_date" name="end_date" required>
                        </div>
                        <button type="submit" class="btn btn-primary">بروزرسانی ماه کاری</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- اسکریپت‌ها -->
    <script>
        // [BLOCK-WORK-MONTHS-003]
        document.addEventListener('DOMContentLoaded', () => {
            // پر کردن اطلاعات در مودال ویرایش
            document.querySelectorAll('[data-bs-target="#editWorkMonthModal"]').forEach(button => {
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    const monthId = button.getAttribute('data-month-id');
                    const startDate = button.getAttribute('data-start-date');
                    const endDate = button.getAttribute('data-end-date');

                    document.getElementById('edit_month_id').value = monthId;
                    document.getElementById('edit_start_date').value = startDate;
                    document.getElementById('edit_end_date').value = endDate;
                });
            });

            // حذف ماه کاری
            window.confirmDeleteMonth = function(monthId) {
                if (confirm('آیا مطمئن هستید که می‌خواهید این ماه کاری را حذف کنید؟')) {
                    fetch('delete_work_month.php?month_id=' + monthId, {
                        method: 'GET'
                    })
                    .then(response => {
                        if (response.ok) {
                            window.location.reload(); // رفرش صفحه پس از حذف
                        } else {
                            alert('خطا در حذف ماه کاری!');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('خطا در اتصال به سرور!');
                    });
                }
                return false;
            }

            // Datepicker برای فیلدهای تاریخ (شمسی) با اعداد انگلیسی
            $('.persian-date').persianDatepicker({
                format: 'YYYY/MM/DD',
                autoClose: true,
                calendar: {
                    persian: {
                        locale: 'fa',
                        digits: true // استفاده از اعداد انگلیسی
                    }
                }
            });
        });
    </script>

<?php
// [BLOCK-WORK-MONTHS-004]
require_once 'footer.php';
?>