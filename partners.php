<?php
// [BLOCK-PARTNERS-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

// دریافت لیست همکاران
$stmt = $pdo->query("SELECT p.*, u1.full_name AS full_name1, u2.full_name AS full_name2 
                    FROM Partners p 
                    LEFT JOIN Users u1 ON p.user_id1 = u1.user_id 
                    LEFT JOIN Users u2 ON p.user_id2 = u2.user_id 
                    ORDER BY p.partner_id DESC");
$partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

// دریافت برنامه کاری همکارها
$schedule_stmt = $pdo->query("SELECT partner_id, day_of_week FROM Partner_Schedule");
$schedules = $schedule_stmt->fetchAll(PDO::FETCH_ASSOC);

// تبدیل برنامه‌ها به آرایه برای دسترسی سریع
$partner_schedules = [];
foreach ($schedules as $schedule) {
    $partner_schedules[$schedule['partner_id']][] = $schedule['day_of_week'];
}

// روزهای هفته برای نمایش
$days_of_week = [
    1 => 'شنبه',
    2 => 'یک‌شنبه',
    3 => 'دوشنبه',
    4 => 'سه‌شنبه',
    5 => 'چهارشنبه',
    6 => 'پنج‌شنبه',
    7 => 'جمعه'
];

// دریافت کاربران (فقط فروشندگان)
$users_stmt = $pdo->query("SELECT user_id, full_name FROM Users WHERE role = 'seller'");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- [BLOCK-PARTNERS-002] -->
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="card-title">لیست همکاران</h5>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPartnerModal">افزودن همکار</button>
    </div>

    <?php if (empty($partners)): ?>
        <div class="alert alert-warning text-center">هیچ همکاری ثبت نشده است.</div>
    <?php else: ?>
        <table class="table table-light table-hover">
            <thead>
                <tr>
                    <th>ردیف</th>
                    <th>همکار 1</th>
                    <th>همکار 2</th>
                    <th>روزهای کاری</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php $row = 1;
                foreach ($partners as $partner): ?>
                    <tr>
                        <td><?php echo $row++; ?></td>
                        <td><?php echo $partner['full_name1'] ?: '-'; ?></td>
                        <td><?php echo $partner['full_name2'] ?: '-'; ?></td>
                        <td>
                            <?php
                            if (isset($partner_schedules[$partner['partner_id']])) {
                                $days = array_map(function ($day) use ($days_of_week) {
                                    return $days_of_week[$day];
                                }, $partner_schedules[$partner['partner_id']]);
                                echo implode(', ', $days);
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <!-- ستون عملیات -->
                        <td>
                            <button class="btn btn-sm <?= $partner['active'] ? 'btn-warning' : 'btn-success' ?> toggle-active"
                                data-partner-id="<?= $partner['partner_id'] ?>" data-active="<?= $partner['active'] ?>">
                                <?= $partner['active'] ? 'غیرفعال' : 'فعال' ?>
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="confirmDeletePartner(<?= $partner['partner_id'] ?>)">
                                حذف
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- مودال افزودن همکار -->
<div class="modal fade" id="addPartnerModal" tabindex="-1" aria-labelledby="addPartnerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-light">
            <div class="modal-header">
                <h5 class="modal-title" id="addPartnerModalLabel">افزودن همکار جدید</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="add_partner.php">
                    <div class="mb-3">
                        <label for="add_user_id1" class="form-label">همکار 1</label>
                        <select class="form-select" id="add_user_id1" name="user_id1" required>
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['user_id']; ?>"><?php echo $user['full_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="add_user_id2" class="form-label">همکار 2</label>
                        <select class="form-select" id="add_user_id2" name="user_id2">
                            <option value="">انتخاب کنید (اختیاری)</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['user_id']; ?>"><?php echo $user['full_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="add_days_of_week" class="form-label">روزهای کاری</label>
                        <select class="form-select" id="add_days_of_week" name="days_of_week[]" multiple required>
                            <?php foreach ($days_of_week as $day_number => $day_name): ?>
                                <option value="<?php echo $day_number; ?>"><?php echo $day_name; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">ثبت همکار</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- مودال ویرایش همکار -->
<div class="modal fade" id="editPartnerModal" tabindex="-1" aria-labelledby="editPartnerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-light">
            <div class="modal-header">
                <h5 class="modal-title" id="editPartnerModalLabel">ویرایش همکار</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="edit_partner.php">
                    <input type="hidden" id="edit_partner_id" name="partner_id">
                    <div class="mb-3">
                        <label for="edit_user_id1" class="form-label">همکار 1</label>
                        <select class="form-select" id="edit_user_id1" name="user_id1" required>
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['user_id']; ?>"><?php echo $user['full_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_user_id2" class="form-label">همکار 2</label>
                        <select class="form-select" id="edit_user_id2" name="user_id2">
                            <option value="">انتخاب کنید (اختیاری)</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['user_id']; ?>"><?php echo $user['full_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_days_of_week" class="form-label">روزهای کاری</label>
                        <select class="form-select" id="edit_days_of_week" name="days_of_week[]" multiple required>
                            <?php foreach ($days_of_week as $day_number => $day_name): ?>
                                <option value="<?php echo $day_number; ?>"><?php echo $day_name; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">بروزرسانی همکار</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- اسکریپت‌ها -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // دکمه فعال/غیرفعال
        document.querySelectorAll('.toggle-active').forEach(btn => {
            btn.addEventListener('click', function () {
                const partnerId = this.dataset.partnerId;
                const isActive = this.dataset.active === '1';
                const action = isActive ? 'غیرفعال' : 'فعال';
                const confirmMsg = `آیا مطمئن هستید که می‌خواهید این همکار را ${action} کنید؟`;

                if (confirm(confirmMsg)) {
                    fetch('toggle_partner_active.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'partner_id=' + partnerId
                    })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                alert(data.message);
                                location.reload();
                            } else {
                                alert('خطا: ' + data.message);
                            }
                        })
                        .catch(() => alert('خطا در ارتباط با سرور!'));
                }
            });
        });

        // حذف (قبلی)
        window.confirmDeletePartner = function (partnerId) {
            if (confirm('آیا مطمئن هستید که می‌خواهید این همکار را حذف کنید؟')) {
                fetch('delete_partner.php?partner_id=' + partnerId)
                    .then(r => r.ok ? location.reload() : alert('خطا در حذف!'));
            }
        };
    });
</script>

<?php
// [BLOCK-PARTNERS-004]
require_once 'footer.php';
?>