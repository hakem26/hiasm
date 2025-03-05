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

$gregorian_date = date('Y-m-d');
$jalali_date = jdate('Y/m/d', strtotime($gregorian_date));

// کوئری برای دریافت ماه‌های کاری
$stmt = $pdo->query("SELECT * FROM Work_Months ORDER BY start_date DESC");
$work_months = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- [BLOCK-WORK-MONTHS-002] -->
<div class="container-fluid mt-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="card-title">لیست ماه‌های کاری</h5>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWorkMonthModal">افزودن ماه کاری</button>
    </div>

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
                <td><?php echo jdate('Y/m/d', strtotime($month['start_date'])); ?></td>
                <td><?php echo jdate('Y/m/d', strtotime($month['end_date'])); ?></td>
                <td>
                    <a href="#" class="text-primary me-2" data-bs-toggle="modal" data-bs-target="#editWorkMonthModal" data-month-id="<?php echo $month['work_month_id']; ?>" data-start-date="<?php echo $month['start_date']; ?>" data-end-date="<?php echo $month['end_date']; ?>">
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
                        <input type="text" class="form-control" id="start_date" name="start_date" required>
                    </div>
                    <div class="mb-3">
                        <label for="end_date" class="form-label">تاریخ پایان (شمسی)</label>
                        <input type="text" class="form-control" id="end_date" name="end_date" required>
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
                        <input type="text" class="form-control" id="edit_start_date" name="start_date" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_end_date" class="form-label">تاریخ پایان (شمسی)</label>
                        <input type="text" class="form-control" id="edit_end_date" name="end_date" required>
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
                const monthId = e.target.getAttribute('data-month-id');
                const startDate = e.target.getAttribute('data-start-date');
                const endDate = e.target.getAttribute('data-end-date');

                document.getElementById('edit_month_id').value = monthId;
                document.getElementById('edit_start_date').value = jdate('Y/m/d', strtotime(startDate));
                document.getElementById('edit_end_date').value = jdate('Y/m/d', strtotime(endDate));
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

        // Datepicker برای فیلدهای تاریخ (شمسی) با jQuery
        $('#start_date, #end_date, #edit_start_date, #edit_end_date').persianDatepicker({
            format: 'YYYY/MM/DD',
            observer: true,
            calendar: {
                persian: {
                    locale: 'fa'
                }
            }
        });
    });
</script>

<?php
// [BLOCK-WORK-MONTHS-004]
require_once 'footer.php';
?>