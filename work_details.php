<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ
function gregorian_to_jalali_format($date) {
    return jdate('Y/m/d', strtotime($date));
}

// دریافت ماه‌های کاری
$work_months = $pdo->query("SELECT * FROM Work_Months ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$selected_month_id = $_GET['month_id'] ?? $work_months[0]['work_month_id'] ?? null;

// دریافت کاربران
$users = $pdo->query("SELECT user_id, full_name FROM Users WHERE role = 'seller'")->fetchAll(PDO::FETCH_ASSOC);
$selected_user_id = $_GET['user_id'] ?? null;

// پر کردن خودکار Work_Details
if ($selected_month_id) {
    // پاک کردن همه ردیف‌های مربوط به این ماه
    $pdo->prepare("DELETE FROM Work_Details WHERE work_month_id = ?")->execute([$selected_month_id]);

    // بازسازی Work_Details با کوئری SQL
    $month = $pdo->query("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = $selected_month_id")->fetch(PDO::FETCH_ASSOC);
    $start_date = $month['start_date'];
    $end_date = $month['end_date'];

    $query = "
        INSERT INTO Work_Details (work_month_id, work_date, partner1_id, partner2_id, agency_partner_id, work_day)
        SELECT 
            :month_id AS work_month_id,
            d.work_date,
            p.user_id1 AS partner1_id,
            COALESCE(p.user_id2, p.user_id1) AS partner2_id,
            p.user_id1 AS agency_partner_id,
            jdate('l', UNIX_TIMESTAMP(d.work_date), '', '', 'gregorian', 'persian') AS work_day
        FROM (
            SELECT DATE_ADD(:start_date, INTERVAL (t4.i*10000 + t3.i*1000 + t2.i*100 + t1.i*10 + t0.i) DAY) AS work_date
            FROM 
                (SELECT 0 i UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) t0,
                (SELECT 0 i UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) t1,
                (SELECT 0 i UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) t2,
                (SELECT 0 i UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) t3,
                (SELECT 0 i UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) t4
            HAVING work_date BETWEEN :start_date AND :end_date
        ) d
        JOIN Partners p ON jdate('l', UNIX_TIMESTAMP(d.work_date), '', '', 'gregorian', 'persian') = p.work_day
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        'month_id' => $selected_month_id,
        'start_date' => $start_date,
        'end_date' => $end_date
    ]);
}

// نمایش اطلاعات
$work_details = [];
if ($selected_month_id) {
    $query = "
        SELECT wd.*, 
               u1.full_name AS partner1_name, 
               u2.full_name AS partner2_name, 
               u3.full_name AS agency_name 
        FROM Work_Details wd 
        LEFT JOIN Users u1 ON wd.partner1_id = u1.user_id 
        LEFT JOIN Users u2 ON wd.partner2_id = u2.user_id 
        LEFT JOIN Users u3 ON wd.agency_partner_id = u3.user_id 
        WHERE wd.work_month_id = ? 
    ";
    if ($selected_user_id) {
        $query .= " AND (wd.partner1_id = ? OR wd.partner2_id = ?)";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$selected_month_id, $selected_user_id, $selected_user_id]);
    } else {
        $query .= " ORDER BY wd.work_date";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$selected_month_id]);
    }
    $work_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="container mt-3">
    <select class="form-select mb-3" onchange="location.href='work_details.php?month_id='+this.value">
        <option value="">انتخاب ماه</option>
        <?php foreach ($work_months as $m): ?>
            <option value="<?= $m['work_month_id'] ?>" <?= $selected_month_id == $m['work_month_id'] ? 'selected' : '' ?>>
                <?= gregorian_to_jalali_format($m['start_date']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select class="form-select mb-3" onchange="location.href='work_details.php?month_id=<?= $selected_month_id ?>&user_id='+this.value">
        <option value="">همه همکاران</option>
        <?php foreach ($users as $u): ?>
            <option value="<?= $u['user_id'] ?>" <?= $selected_user_id == $u['user_id'] ? 'selected' : '' ?>>
                <?= $u['full_name'] ?>
            </option>
        <?php endforeach; ?>
    </select>

    <?php if ($selected_month_id && $work_details): ?>
        <table class="table table-light">
            <thead>
                <tr>
                    <th>تاریخ</th>
                    <th>روز</th>
                    <th>همکار 1</th>
                    <th>همکار 2</th>
                    <th>آژانس</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($work_details as $d): ?>
                    <tr>
                        <td><?= gregorian_to_jalali_format($d['work_date']) ?></td>
                        <td><?= $d['work_day'] ?></td>
                        <td><?= $d['partner1_name'] ?? '-' ?></td>
                        <td><?= $d['partner2_name'] ?? '-' ?></td>
                        <td><?= $d['agency_name'] ?? '-' ?></td>
                        <td>
                            <button class="btn btn-primary btn-sm edit-btn" 
                                    data-id="<?= $d['work_detail_id'] ?>" 
                                    data-partner1="<?= $d['partner1_id'] ?? '' ?>" 
                                    data-partner1-name="<?= $d['partner1_name'] ?? '' ?>" 
                                    data-partner2="<?= $d['partner2_id'] ?? '' ?>" 
                                    data-partner2-name="<?= $d['partner2_name'] ?? '' ?>" 
                                    data-agency="<?= $d['agency_partner_id'] ?? '' ?>">
                                ویرایش
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($selected_month_id): ?>
        <div class="alert alert-warning">اطلاعاتی وجود ندارد.</div>
    <?php endif; ?>
</div>

<!-- مودال ویرایش -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5>ویرایش آژانس</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editForm" method="POST" action="edit_work_detail.php">
                    <input type="hidden" name="detail_id" id="edit_id">
                    <div class="mb-3">
                        <label>همکار 1</label>
                        <input type="text" class="form-control" id="edit_partner1" readonly>
                    </div>
                    <div class="mb-3">
                        <label>همکار 2</label>
                        <input type="text" class="form-control" id="edit_partner2" readonly>
                    </div>
                    <div class="mb-3">
                        <label>آژانس</label>
                        <select class="form-select" name="agency_partner_id" id="edit_agency">
                            <option value="">انتخاب</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">ذخیره</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const modal = new bootstrap.Modal(document.getElementById('editModal'));
        document.getElementById('edit_id').value = btn.dataset.id;
        document.getElementById('edit_partner1').value = btn.dataset.partner1Name;
        document.getElementById('edit_partner2').value = btn.dataset.partner2Name;
        const agencySelect = document.getElementById('edit_agency');
        agencySelect.innerHTML = '<option value="">انتخاب</option>';
        const partners = [
            { id: btn.dataset.partner1, name: btn.dataset.partner1Name },
            { id: btn.dataset.partner2, name: btn.dataset.partner2Name }
        ].filter(p => p.id);
        partners.forEach(p => {
            const option = document.createElement('option');
            option.value = p.id;
            option.text = p.name;
            agencySelect.appendChild(option);
        });
        agencySelect.value = btn.dataset.agency;
        modal.show();
    });
});
</script>

<?php require_once 'footer.php'; ?>