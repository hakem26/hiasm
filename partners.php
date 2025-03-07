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

// دریافت کاربران (فقط فروشندگان)
$users_stmt = $pdo->query("SELECT user_id, full_name FROM Users WHERE role = 'seller'");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- [BLOCK-PARTNERS-002] -->
<div class="container-fluid mt-5">
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
                <th>روز هفته</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php $row = 1; foreach ($partners as $partner): ?>
            <tr>
                <td><?php echo $row++; ?></td>
                <td><?php echo $partner['full_name1'] ?: '-'; ?></td>
                <td><?php echo $partner['full_name2'] ?: '-'; ?></td>
                <td><?php echo $partner['work_day'] ?: '-'; ?></td>
                <td>
                    <button class="btn btn-primary btn-sm me-2" data-bs-toggle="modal" data-bs-target="#editPartnerModal" data-partner-id="<?php echo $partner['partner_id']; ?>" data-user1="<?php echo $partner['user_id1']; ?>" data-user2="<?php echo $partner['user_id2']; ?>" data-work-day="<?php echo $partner['work_day']; ?>">
                        ویرایش
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="confirmDeletePartner(<?php echo $partner['partner_id']; ?>)">
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
                        <select class="form-select" id="add_user_id2" name="user_id2" required>
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['user_id']; ?>"><?php echo $user['full_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="add_work_day" class="form-label">روز هفته</label>
                        <select class="form-select" id="add_work_day" name="work_day" required>
                            <option value="">انتخاب کنید</option>
                            <option value="شنبه">شنبه</option>
                            <option value="یکشنبه">یک‌شنبه</option>
                            <option value="دوشنبه">دوشنبه</option>
                            <option value="سهشنبه">سه شنبه</option>
                            <option value="چهارشنبه">چهارشنبه</option>
                            <option value="پنجشنبه">پنجشنبه</option>
                            <option value="جمعه">جمعه</option>
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
                        <select class="form-select" id="edit_user_id2" name="user_id2" required>
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['user_id']; ?>"><?php echo $user['full_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_work_day" class="form-label">روز هفته</label>
                        <select class="form-select" id="edit_work_day" name="work_day" required>
                            <option value="">انتخاب کنید</option>
                            <option value="شنبه">شنبه</option>
                            <option value="یک‌شنبه">یک‌شنبه</option>
                            <option value="دوشنبه">دوشنبه</option>
                            <option value="سه‌شنبه">سه‌شنبه</option>
                            <option value="چهارشنبه">چهارشنبه</option>
                            <option value="پنج‌شنبه">پنج‌شنبه</option>
                            <option value="جمعه">جمعه</option>
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
    // [BLOCK-PARTNERS-003]
    document.addEventListener('DOMContentLoaded', () => {
        // پر کردن اطلاعات در مودال ویرایش
        document.querySelectorAll('[data-bs-target="#editPartnerModal"]').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const partnerId = button.getAttribute('data-partner-id');
                const user1 = button.getAttribute('data-user1');
                const user2 = button.getAttribute('data-user2');
                const workDay = button.getAttribute('data-work-day');

                document.getElementById('edit_partner_id').value = partnerId;
                document.getElementById('edit_user_id1').value = user1 || '';
                document.getElementById('edit_user_id2').value = user2 || '';
                document.getElementById('edit_work_day').value = workDay || '';
            });
        });

        // حذف همکار
        window.confirmDeletePartner = function(partnerId) {
            if (confirm('آیا مطمئن هستید که می‌خواهید این همکار را حذف کنید؟')) {
                fetch('delete_partner.php?partner_id=' + partnerId, {
                    method: 'GET'
                })
                .then(response => {
                    if (response.ok) {
                        window.location.reload(); // رفرش صفحه پس از حذف
                    } else {
                        alert('خطا در حذف همکار!');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('خطا در اتصال به سرور!');
                });
            }
            return false;
        };
    });
</script>

<?php
// [BLOCK-PARTNERS-004]
require_once 'footer.php';
?>