<?php
// [BLOCK-USERS-001]
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

// کوئری برای دریافت کاربران (به جز ادمین)
$stmt = $pdo->query("SELECT * FROM Users WHERE role != 'admin'");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- [BLOCK-USERS-002] -->
<div class="container-fluid mt-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="card-title">لیست کاربران</h5>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">افزودن کاربر</button>
    </div>

    <?php if (empty($users)): ?>
        <div class="alert alert-warning text-center">کاربری ساخته نشده است.</div>
    <?php else: ?>
        <table class="table table-light table-hover">
            <thead>
                <tr>
                    <th>نام کاربری</th>
                    <th>نام کامل</th>
                    <th>نقش</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                        <td><?php echo $user['role'] === 'seller' ? 'فروشنده' : 'کاربر'; ?></td>
                        <td>
                            <a href="#" class="text-primary me-2" data-bs-toggle="modal" data-bs-target="#editUserModal"
                                data-user-id="<?php echo $user['user_id']; ?>"
                                data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                data-fullname="<?php echo htmlspecialchars($user['full_name']); ?>"
                                data-role="<?php echo $user['role']; ?>">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="#" class="text-danger" onclick="confirmDelete(<?php echo $user['user_id']; ?>)">
                                <i class="fas fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- مودال افزودن کاربر -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-light">
            <div class="modal-header">
                <h5 class="modal-title" id="addUserModalLabel">افزودن کاربر جدید</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="addUserForm" method="POST" action="add_user.php">
                    <div class="mb-3">
                        <label for="username" class="form-label">نام کاربری</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">رمز عبور</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <div class="mb-3">
                        <label for="full_name" class="form-label">نام کامل</label>
                        <input type="text" class="form-control" id="full_name" name="full_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="role" class="form-label">نقش</label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="seller">فروشنده</option>
                            <option value="user">کاربر</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">ثبت کاربر</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- مودال ویرایش کاربر -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-light">
            <div class="modal-header">
                <h5 class="modal-title" id="editUserModalLabel">ویرایش کاربر</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editUserForm" method="POST" action="edit_user.php">
                    <input type="hidden" id="edit_user_id" name="user_id">
                    <div class="mb-3">
                        <label for="edit_username" class="form-label">نام کاربری</label>
                        <input type="text" class="form-control" id="edit_username" name="username" required readonly>
                    </div>
                    <div class="mb-3">
                        <label for="edit_password" class="form-label">رمز عبور (خالی بگذارید برای عدم تغییر)</label>
                        <input type="password" class="form-control" id="edit_password" name="password">
                    </div>
                    <div class="mb-3">
                        <label for="edit_full_name" class="form-label">نام کامل</label>
                        <input type="text" class="form-control" id="edit_full_name" name="full_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_role" class="form-label">نقش</label>
                        <select class="form-select" id="edit_role" name="role" required>
                            <option value="seller">فروشنده</option>
                            <option value="user">کاربر</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">بروزرسانی کاربر</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- اسکریپت‌ها -->
<script>
    // [BLOCK-USERS-003]
    document.addEventListener('DOMContentLoaded', () => {
        // پر کردن اطلاعات در مودال ویرایش
        document.querySelectorAll('[data-bs-target="#editUserModal"]').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const userId = e.target.getAttribute('data-user-id');
                const username = e.target.getAttribute('data-username');
                const fullName = e.target.getAttribute('data-fullname');
                const role = e.target.getAttribute('data-role');

                // پر کردن فیلدها
                document.getElementById('edit_user_id').value = userId;
                document.getElementById('edit_username').value = username;
                document.getElementById('edit_full_name').value = fullName;
                document.getElementById('edit_role').value = role;
                document.getElementById('edit_password').value = ''; // خالی برای عدم تغییر پیش‌فرض

                // اطمینان از غیرفعال بودن نام کاربری
                document.getElementById('edit_username').setAttribute('readonly', 'readonly');
            });
        });

        // حذف کاربر (بدون تغییر، چون درست کار می‌کنه)
        window.confirmDelete = function (userId) {
            if (confirm('آیا مطمئن هستید که می‌خواهید این کاربر را حذف کنید؟')) {
                fetch('delete_user.php?user_id=' + userId, {
                    method: 'GET'
                })
                    .then(response => {
                        if (response.ok) {
                            window.location.reload(); // رفرش صفحه پس از حذف
                        } else {
                            alert('خطا در حذف کاربر!');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('خطا در اتصال به سرور!');
                    });
            }
            return false;
        }
    });
</script>

<?php
// [BLOCK-USERS-004]
require_once 'footer.php';
?>