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

// تابع تبدیل میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd"; // خروجی: YYYY/MM/DD
}

// دریافت سال‌های موجود از دیتابیس (میلادی)
$stmt = $pdo->query("SELECT DISTINCT YEAR(start_date) AS year FROM Work_Months ORDER BY year DESC");
$years_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
$years = array_column($years_db, 'year'); // سال‌ها به‌صورت میلادی

// دریافت سال جاری (میلادی) به‌عنوان پیش‌فرض
$current_year = date('Y'); // سال میلادی فعلی (مثلاً 2025)

// دریافت سال انتخاب‌شده (میلادی)
$selected_year = $_GET['year'] ?? (in_array($current_year, $years) ? $current_year : (!empty($years) ? $years[0] : null));

// اگر سال انتخاب‌شده وجود نداشت، اولین سال موجود رو انتخاب کن (اگر سالی وجود داشت)
if ($selected_year && !in_array($selected_year, $years)) {
    $selected_year = !empty($years) ? $years[0] : null;
}

// کوئری برای دریافت ماه‌های کاری بر اساس سال میلادی (اگر سال انتخاب‌شده وجود داشته باشه)
if ($selected_year) {
    $stmt = $pdo->prepare("SELECT * FROM Work_Months WHERE YEAR(start_date) = ? ORDER BY start_date DESC");
    $stmt->execute([$selected_year]);
    $work_months = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $work_months = []; // اگر هیچ سالی انتخاب نشده یا وجود نداره
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ماه‌های کاری</title>
    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css"
        integrity="sha384-dpuaG1suU0eT09tx5plTaGMLBsfDLzUCCUXOY2j/LSvXYuG6Bqs43ALlhIqAJVRb" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Persian Datepicker CSS -->
    <link rel="stylesheet" href="assets/css/persian-datepicker.min.css" />
    <!-- Custom CSS -->
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <!-- [BLOCK-WORK-MONTHS-002] -->
    <div class="container-fluid mt-5">
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
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"
        integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="assets/js/persian-date.min.js"></script>
    <script src="assets/js/persian-datepicker.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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

            // Datepicker برای فیلدهای تاریخ (شمسی)
            $('.persian-date').persianDatepicker({
                format: 'YYYY/MM/DD',
                autoClose: true,
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