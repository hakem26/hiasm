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
function gregorian_to_jalali_format($date) {
    return jdate('Y/m/d', strtotime($date));
}

// دریافت ماه‌های کاری
$work_months = $pdo->query("SELECT * FROM Work_Months ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$selected_month_id = $_GET['month_id'] ?? $work_months[0]['work_month_id'] ?? null;

// دریافت کاربران
$users = $pdo->query("SELECT user_id, full_name FROM Users WHERE role = 'seller'")->fetchAll(PDO::FETCH_ASSOC);
$selected_user_id = $_GET['user_id'] ?? null;

// دریافت جفت‌های کاری
$partners = $pdo->query("SELECT partner_id, user_id1, user_id2, work_day 
                        FROM Partners")->fetchAll(PDO::FETCH_ASSOC);

// پر کردن خودکار Work_Details
if ($selected_month_id) {
    $month = $pdo->query("SELECT * FROM Work_Months WHERE work_month_id = $selected_month_id")->fetch(PDO::FETCH_ASSOC);
    $start_date = new DateTime($month['start_date']);
    $end_date = new DateTime($month['end_date']);
    
    while ($start_date <= $end_date) {
        $current_date = $start_date->format('Y-m-d');
        $work_day = jdate('l', strtotime($current_date), '', '', 'gregorian', 'persian');
        
        $day_partners = array_filter($partners, fn($p) => $p['work_day'] === $work_day);
        if (!empty($day_partners)) {
            $partner = $day_partners[array_rand($day_partners)];
            $partner1_id = $partner['user_id1'];
            $partner2_id = $partner['user_id2'] ?? null;
            if (!$partner2_id) {
                // اگه همکار دوم نداشت، از یه کاربر دیگه انتخاب کن
                $other_users = array_filter($users, fn($u) => $u['user_id'] != $partner1_id);
                $other_user = $other_users[array_rand(array_keys($other_users))];
                $partner2_id = $other_user['user_id'];
            }
            $agency_partner_id = $partner1_id;

            $check = $pdo->prepare("SELECT work_detail_id FROM Work_Details WHERE work_month_id = ? AND work_date = ?");
            $check->execute([$selected_month_id, $current_date]);
            if ($check->fetch()) {
                $pdo->prepare("UPDATE Work_Details SET partner1_id = ?, partner2_id = ?, agency_partner_id = ?, work_day = ? 
                               WHERE work_month_id = ? AND work_date = ?")
                    ->execute([$partner1_id, $partner2_id, $agency_partner_id, $work_day, $selected_month_id, $current_date]);
            } else {
                $pdo->prepare("INSERT INTO Work_Details (work_month_id, work_date, partner1_id, partner2_id, agency_partner_id, work_day) 
                               VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$selected_month_id, $current_date, $partner1_id, $partner2_id, $agency_partner_id, $work_day]);
            }
        }
        $start_date->modify('+1 day');
    }
}

// نمایش اطلاعات
$work_details = [];
if ($selected_month_id) {
    $query = "SELECT wd.*, 
                     u1.full_name AS partner1_name, 
                     u2.full_name AS partner2_name, 
                     u3.full_name AS agency_name 
              FROM Work_Details wd 
              LEFT JOIN Users u1 ON wd.partner1_id = u1.user_id 
              LEFT JOIN Users u2 ON wd.partner2_id = u2.user_id 
              LEFT JOIN Users u3 ON wd.agency_partner_id = u3.user_id 
              WHERE wd.work_month_id = ? ORDER BY wd.work_date";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$selected_month_id]);
    $work_details = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($selected_user_id) {
        $work_details = array_filter($work_details, fn($d) => $d['partner1_id'] == $selected_user_id || $d['partner2_id'] == $selected_user_id);
    }
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
                                    data-partner2="<?= $d['partner2_id'] ?? '' ?>" 
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
                <h5>ویرایش</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editForm" method="POST" action="edit_work_detail.php">
                    <input type="hidden" name="detail_id" id="edit_id">
                    <div class="mb-3">
                        <label>همکار 1</label>
                        <select class="form-select" name="partner1_id" id="edit_partner1" onchange="updateOptions()">
                            <option value="">انتخاب</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= $u['user_id'] ?>"><?= $u['full_name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label>همکار 2</label>
                        <select class="form-select" name="partner2_id" id="edit_partner2" onchange="updateOptions()">
                            <option value="">انتخاب</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= $u['user_id'] ?>"><?= $u['full_name'] ?></option>
                            <?php endforeach; ?>
                        </select>
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
        document.getElementById('edit_partner1').value = btn.dataset.partner1;
        document.getElementById('edit_partner2').value = btn.dataset.partner2;
        document.getElementById('edit_agency').value = btn.dataset.agency;
        updateOptions(); // به‌روزرسانی گزینه‌های آژانس
        modal.show();
    });
});

function updateOptions() {
    const partner1 = document.getElementById('edit_partner1').value;
    const partner2 = document.getElementById('edit_partner2').value;
    const agencySelect = document.getElementById('edit_agency');
    const currentAgency = agencySelect.value;

    agencySelect.innerHTML = '<option value="">انتخاب</option>';
    const partners = [partner1, partner2].filter(p => p); // فقط مقادیر معتبر
    partners.forEach(p => {
        const option = document.createElement('option');
        option.value = p;
        option.text = Array.from(document.querySelectorAll('#edit_partner1 option, #edit_partner2 option'))
            .find(opt => opt.value === p)?.text || '';
        agencySelect.appendChild(option);
    });

    agencySelect.value = currentAgency && agencySelect.querySelector(`option[value="${currentAgency}"]`) ? currentAgency : partner1 || '';
}
</script>

<?php require_once 'footer.php'; ?>