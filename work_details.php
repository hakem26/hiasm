<?php
// [BLOCK-WORK-DETAILS-001]
session_start();
ob_start(); // شروع بافر خروجی
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

$gregorian_date = date('Y-m-d');
$jalali_date = jdate('Y/m/d', strtotime($gregorian_date));

// تابع تبدیل میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd"; // خروجی: YYYY/MM/DD
}

// کوئری برای دریافت ماه‌های کاری
$work_months_stmt = $pdo->query("SELECT * FROM Work_Months ORDER BY start_date DESC");
$work_months = $work_months_stmt->fetchAll(PDO::FETCH_ASSOC);

// دریافت کاربران (فقط فروشندگان)
$users_stmt = $pdo->query("SELECT user_id, full_name FROM Users WHERE role = 'seller'");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// انتخاب ماه کاری و کاربر پیش‌فرض
$selected_month_id = isset($_GET['month_id']) ? $_GET['month_id'] : (isset($work_months[0]['work_month_id']) ? $work_months[0]['work_month_id'] : null);
$selected_user_id = isset($_GET['user_id']) ? $_GET['user_id'] : null;
$work_details = [];
if ($selected_month_id) {
    $query = "SELECT DISTINCT wd.work_detail_id, wd.work_date, wd.work_day, wd.partner1_id, wd.partner2_id, wd.agency_partner_id, 
                     u1.full_name AS partner1_name, u2.full_name AS partner2_name, u3.full_name AS agency_partner_name 
              FROM Work_Details wd 
              LEFT JOIN Partners p1 ON wd.partner1_id = p1.partner_id 
              LEFT JOIN Users u1 ON p1.user_id1 = u1.user_id 
              LEFT JOIN Partners p2 ON wd.partner2_id = p2.partner_id 
              LEFT JOIN Users u2 ON p2.user_id2 = u2.user_id 
              LEFT JOIN Partners p3 ON wd.agency_partner_id = p3.partner_id 
              LEFT JOIN Users u3 ON p3.user_id1 = u3.user_id 
              WHERE wd.work_month_id = ? ";
    if ($selected_user_id) {
        $query .= "AND (p1.user_id1 = ? OR p2.user_id2 = ?)";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$selected_month_id, $selected_user_id, $selected_user_id]);
    } else {
        $query .= "ORDER BY wd.work_date";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$selected_month_id]);
    }
    $work_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// پر کردن خودکار اطلاعات برای ماه انتخابی
if ($selected_month_id && empty($work_details)) {
    $month = $pdo->prepare("SELECT * FROM Work_Months WHERE work_month_id = ?");
    $month->execute([$selected_month_id]);
    $month_data = $month->fetch(PDO::FETCH_ASSOC);

    if ($month_data) {
        $start_date = new DateTime($month_data['start_date']);
        $end_date = new DateTime($month_data['end_date']);
        
        $partners = $pdo->query("SELECT partner_id, user_id1, user_id2, work_day FROM Partners")->fetchAll(PDO::FETCH_ASSOC);
        $partner_map = [];
        foreach ($partners as $partner) {
            $partner_map[$partner['work_day']] = [
                'partner1_id' => $partner['partner_id'],
                'user_id1' => $partner['user_id1'],
                'user_id2' => $partner['user_id2']
            ];
        }

        while ($start_date <= $end_date) {
            $jalali_date = gregorian_to_jalali_format($start_date->format('Y-m-d'));
            $work_day = jdate('l', strtotime($start_date->format('Y-m-d')), '', '', 'gregorian', 'persian');
            $partner_info = $partner_map[$work_day] ?? null;
            $partner1_id = $partner_info['partner1_id'] ?? null;
            $partner2_id = $partner_info['partner1_id'] ?? null; // همکار 2 هم از همان partner_id می‌تونه باشد
            $agency_partner_id = $partner1_id; // آژانس پیش‌فرض = همکار 1
            if ($partner1_id) {
                $stmt = $pdo->prepare("INSERT INTO Work_Details (work_month_id, work_date, partner1_id, partner2_id, agency_partner_id, work_day) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$selected_month_id, $start_date->format('Y-m-d'), $partner1_id, $partner2_id, $agency_partner_id, $work_day]);
            }
            $start_date->modify('+1 day');
        }
        ob_end_clean(); // پاک کردن بافر قبل از رفرش
        header("Location: work_details.php?month_id=$selected_month_id");
        exit;
    }
}
?>

<!-- [BLOCK-WORK-DETAILS-002] -->
<div class="container-fluid mt-5">
    <div class="mb-3">
        <label for="month_select" class="form-label">انتخاب ماه کاری</label>
        <select class="form-select" id="month_select" onchange="updateUserSelect()">
            <option value="">ماه کاری را انتخاب کنید</option>
            <?php foreach ($work_months as $month): ?>
            <option value="<?php echo $month['work_month_id']; ?>" <?php echo $selected_month_id == $month['work_month_id'] ? 'selected' : ''; ?>>
                <?php echo gregorian_to_jalali_format($month['start_date']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3">
        <label for="user_select" class="form-label">انتخاب همکار</label>
        <select class="form-select" id="user_select" onchange="window.location.href='work_details.php?month_id=<?php echo $selected_month_id; ?>&user_id=' + this.value">
            <option value="">همه همکاران</option>
            <?php foreach ($users as $user): ?>
            <option value="<?php echo $user['user_id']; ?>" <?php echo $selected_user_id == $user['user_id'] ? 'selected' : ''; ?>>
                <?php echo $user['full_name']; ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($selected_month_id && !empty($work_details)): ?>
    <table class="table table-light table-hover">
        <thead>
            <tr>
                <th>تاریخ</th>
                <th>روز هفته</th>
                <th>همکار 1</th>
                <th>همکار 2</th>
                <th>آژانس</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($work_details as $detail): ?>
            <tr>
                <td><?php echo gregorian_to_jalali_format($detail['work_date']); ?></td>
                <td><?php echo $detail['work_day'] ?: '-'; ?></td>
                <td><?php echo $detail['partner1_name'] ?: '-'; ?></td>
                <td><?php echo $detail['partner2_name'] ?: '-'; ?></td>
                <td><?php echo $detail['agency_partner_name'] ?: '-'; ?></td>
                <td>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editWorkDetailModal" 
                            data-detail-id="<?php echo $detail['work_detail_id']; ?>" 
                            data-date="<?php echo gregorian_to_jalali_format($detail['work_date']); ?>" 
                            data-partner1-id="<?php echo $detail['partner1_id'] ?? ''; ?>" 
                            data-partner2-id="<?php echo $detail['partner2_id'] ?? ''; ?>" 
                            data-agency-id="<?php echo $detail['agency_partner_id'] ?? ''; ?>">
                        ویرایش
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php elseif ($selected_month_id): ?>
    <div class="alert alert-warning text-center">اطلاعاتی برای این ماه کاری وجود ندارد.</div>
    <?php endif; ?>
</div>

<!-- مودال ویرایش اطلاعات کار -->
<div class="modal fade" id="editWorkDetailModal" tabindex="-1" aria-labelledby="editWorkDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-light">
            <div class="modal-header">
                <h5 class="modal-title" id="editWorkDetailModalLabel">ویرایش اطلاعات کار</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editWorkDetailForm" method="POST" action="edit_work_detail.php">
                    <input type="hidden" id="edit_detail_id" name="detail_id">
                    <div class="mb-3">
                        <label for="edit_work_date" class="form-label">تاریخ</label>
                        <input type="text" class="form-control" id="edit_work_date" name="work_date" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="edit_partner1" class="form-label">همکار 1</label>
                        <select class="form-select" id="edit_partner1" name="partner1_id">
                            <option value="">انتخاب کنید</option>
                            <?php 
                            $partners_stmt = $pdo->query("SELECT p.partner_id, u.full_name FROM Partners p JOIN Users u ON p.user_id1 = u.user_id WHERE u.role = 'seller'");
                            $partners = $partners_stmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($partners as $partner): ?>
                            <option value="<?php echo $partner['partner_id']; ?>"><?php echo $partner['full_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_partner2" class="form-label">همکار 2</label>
                        <select class="form-select" id="edit_partner2" name="partner2_id">
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($partners as $partner): ?>
                            <option value="<?php echo $partner['partner_id']; ?>"><?php echo $partner['full_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_agency" class="form-label">آژانس</label>
                        <input type="text" class="form-control" id="edit_agency" name="agency_partner_name" readonly>
                    </div>
                    <button type="submit" class="btn btn-primary">بروزرسانی اطلاعات</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- اسکریپت‌ها -->
<script>
    // [BLOCK-WORK-DETAILS-003]
    document.addEventListener('DOMContentLoaded', () => {
        // پر کردن اطلاعات در مودال ویرایش
        document.querySelectorAll('[data-bs-target="#editWorkDetailModal"]').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const detailId = button.getAttribute('data-detail-id');
                const workDate = button.getAttribute('data-date');
                const partner1Id = button.getAttribute('data-partner1-id');
                const partner2Id = button.getAttribute('data-partner2-id');
                const agencyId = button.getAttribute('data-agency-id');
                const agencyName = agencyId ? <?php echo json_encode($pdo->query("SELECT full_name FROM Users WHERE user_id IN (SELECT user_id1 FROM Partners WHERE partner_id = ?)", [$agencyId])->fetchColumn() ?? ''); ?> : '';

                document.getElementById('edit_detail_id').value = detailId;
                document.getElementById('edit_work_date').value = workDate;
                document.getElementById('edit_partner1').value = partner1Id || '';
                document.getElementById('edit_partner2').value = partner2Id || '';
                document.getElementById('edit_agency').value = agencyName || '';
                console.log('Modal filled:', { detailId, workDate, partner1Id, partner2Id, agencyId, agencyName }); // برای دیباگ
            });
        });

        // به‌روزرسانی انتخاب کاربر بعد از انتخاب ماه
        function updateUserSelect() {
            const monthId = document.getElementById('month_select').value;
            if (monthId) {
                window.location.href = 'work_details.php?month_id=' + monthId;
            }
        }
    });
</script>

<?php
// [BLOCK-WORK-DETAILS-004]
require_once 'footer.php';
ob_end_flush(); // پایان بافر و ارسال خروجی
?>