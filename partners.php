<?php
// [BLOCK-PARTNERS-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'header.php'; // اضافه کردن هدر برای استایل‌ها و منو
require_once 'db.php';
require_once 'jdf.php';

$gregorian_date = date('Y-m-d');
$jalali_date = jdate('Y/m/d', strtotime($gregorian_date));

// کوئری برای دریافت گروه‌های همکار (از Partners و Users)
$stmt = $pdo->query("SELECT p.partner_id, u1.username AS username1, u1.full_name AS full_name1, u2.username AS username2, u2.full_name AS full_name2, p.user_id1, p.user_id2 
                    FROM Partners p 
                    LEFT JOIN Users u1 ON p.user_id1 = u1.user_id 
                    LEFT JOIN Users u2 ON p.user_id2 = u2.user_id");
$partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

// کوئری برای دریافت کاربران موجود (برای افزودن همکار)
$users_stmt = $pdo->query("SELECT user_id, username, full_name FROM Users");
$available_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- [BLOCK-PARTNERS-002] -->
<div class="container-fluid mt-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="card-title">لیست همکاران</h5>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPartnerModal">افزودن همکار</button>
    </div>

    <?php if (empty($partners)): ?>
    <div class="alert alert-warning text-center">گروه همکاری تعریف نشده است.</div>
    <?php else: ?>
    <table class="table table-light table-hover">
        <thead>
            <tr>
                <th>شناسه</th>
                <th>همکاران</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($partners as $partner): ?>
            <tr>
                <td><?php echo $partner['partner_id']; ?></td>
                <td><?php echo htmlspecialchars(($partner['full_name1'] ?: '-') . ' - ' . ($partner['full_name2'] ?: '-')); ?></td>
                <td>
                    <a href="#" class="text-primary me-2 edit-partner-btn" data-bs-toggle="modal" data-bs-target="#editPartnerModal" data-partner-id="<?php echo $partner['partner_id']; ?>" data-user-id1="<?php echo $partner['user_id1'] ?: ''; ?>" data-user-id2="<?php echo $partner['user_id2'] ?: ''; ?>">
                        <i class="fas fa-edit"></i>
                    </a>
                    <a href="#" class="text-danger" onclick="confirmDeletePartner(<?php echo $partner['partner_id']; ?>)">
                        <i class="fas fa-trash"></i>
                    </a>
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
                <h5 class="modal-title" id="addPartnerModalLabel">افزودن گروه همکار جدید</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="addPartnerForm" method="POST" action="add_partner.php">
                    <div class="mb-3">
                        <label for="user_id1" class="form-label">همکار اول</label>
                        <select class="form-select" id="user_id1" name="user_id1" required>
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($available_users as $user): ?>
                            <option value="<?php echo $user['user_id']; ?>">
                                <?php echo htmlspecialchars($user['username']) . ' - ' . htmlspecialchars($user['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="user_id2" class="form-label">همکار دوم</label>
                        <select class="form-select" id="user_id2" name="user_id2" required>
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($available_users as $user): ?>
                            <option value="<?php echo $user['user_id']; ?>">
                                <?php echo htmlspecialchars($user['username']) . ' - ' . htmlspecialchars($user['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">ثبت گروه همکار</button>
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
                <h5 class="modal-title" id="editPartnerModalLabel">ویرایش گروه همکار</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editPartnerForm" method="POST" action="edit_partner.php">
                    <input type="hidden" id="edit_partner_id" name="partner_id">
                    <div class="mb-3">
                        <label for="edit_user_id1" class="form-label">همکار اول</label>
                        <select class="form-select" id="edit_user_id1" name="user_id1" required>
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($available_users as $user): ?>
                            <option value="<?php echo $user['user_id']; ?>">
                                <?php echo htmlspecialchars($user['username']) . ' - ' . htmlspecialchars($user['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_user_id2" class="form-label">همکار دوم</label>
                        <select class="form-select" id="edit_user_id2" name="user_id2" required>
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($available_users as $user): ?>
                            <option value="<?php echo $user['user_id']; ?>">
                                <?php echo htmlspecialchars($user['username']) . ' - ' . htmlspecialchars($user['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">بروزرسانی گروه همکار</button>
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
        document.querySelectorAll('.edit-partner-btn').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const partnerId = button.getAttribute('data-partner-id');
                const userId1 = button.getAttribute('data-user-id1');
                const userId2 = button.getAttribute('data-user-id2');

                document.getElementById('edit_partner_id').value = partnerId;
                document.getElementById('edit_user_id1').value = userId1 || '';
                document.getElementById('edit_user_id2').value = userId2 || '';
            });
        });

        // حذف گروه همکار (بدون تغییر)
        window.confirmDeletePartner = function(partnerId) {
            if (confirm('آیا مطمئن هستید که می‌خواهید این گروه همکار را حذف کنید؟')) {
                fetch('delete_partner.php?partner_id=' + partnerId, {
                    method: 'GET'
                })
                .then(response => {
                    if (response.ok) {
                        window.location.reload(); // رفرش صفحه پس از حذف
                    } else {
                        alert('خطا در حذف گروه همکار!');
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
// [BLOCK-PARTNERS-004]
require_once 'footer.php';
?>