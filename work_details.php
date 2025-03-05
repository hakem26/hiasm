<?php
// [BLOCK-WORK-DETAILS-001]
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

$gregorian_date = date('Y-m-d');
$jalali_date = jdate('Y/m/d', strtotime($gregorian_date));

// کوئری برای دریافت ماه‌های کاری
$work_months_stmt = $pdo->query("SELECT * FROM Work_Months ORDER BY start_date DESC");
$work_months = $work_months_stmt->fetchAll(PDO::FETCH_ASSOC);

// انتخاب ماه کاری پیش‌فرض (اولین ماه)
$selected_month_id = isset($_GET['month_id']) ? $_GET['month_id'] : (isset($work_months[0]['work_month_id']) ? $work_months[0]['work_month_id'] : null);
$work_details = [];
if ($selected_month_id) {
    $stmt = $pdo->prepare("SELECT wd.*, p1.user_id as partner1_user_id, u1.username as partner1_name, p2.user_id as partner2_user_id, u2.username as partner2_name, p3.user_id as agency_partner_user_id, u3.username as agency_partner_name 
                          FROM Work_Details wd 
                          LEFT JOIN Partners p1 ON wd.partner1_id = p1.partner_id 
                          LEFT JOIN Users u1 ON p1.user_id = u1.user_id 
                          LEFT JOIN Partners p2 ON wd.partner2_id = p2.partner_id 
                          LEFT JOIN Users u2 ON p2.user_id = u2.user_id 
                          LEFT JOIN Partners p3 ON wd.agency_partner_id = p3.partner_id 
                          LEFT JOIN Users u3 ON p3.user_id = u3.user_id 
                          WHERE wd.work_month_id = ? 
                          ORDER BY wd.work_date");
    $stmt->execute([$selected_month_id]);
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
        
        // دریافت ID همکاران از جدول Partners
        $partners = $pdo->query("SELECT partner_id, user_id FROM Partners")->fetchAll(PDO::FETCH_ASSOC);
        $partner_map = array_column($partners, 'partner_id', 'user_id');

        while ($start_date <= $end_date) {
            $jalali_date = jdate('Y/m/d', strtotime($start_date->format('Y-m-d')));
            $partner1_id = null;
            $partner2_id = null;
            switch (jdate('l', strtotime($start_date->format('Y-m-d')), '', '', 'gregorian', 'persian')) {
                case 'شنبه':
                    $partner1_id = $partner_map[array_search('sheyda_johari', array_column($partners, 'user_id'))] ?? null;
                    $partner2_id = $partner_map[array_search('mehri_taremi', array_column($partners, 'user_id'))] ?? null;
                    break;
                case 'دوشنبه':
                    $partner1_id = $partner_map[array_search('mehri_taremi', array_column($partners, 'user_id'))] ?? null;
                    $partner2_id = $partner_map[array_search('marzieh_ebadi', array_column($partners, 'user_id'))] ?? null;
                    break;
                case 'سه‌شنبه':
                    $partner1_id = $partner_map[array_search('marzieh_ebadi', array_column($partners, 'user_id'))] ?? null;
                    $partner2_id = $partner_map[array_search('sheyda_johari', array_column($partners, 'user_id'))] ?? null;
                    break;
                default:
                    $partner1_id = null;
                    $partner2_id = null;
            }
            $stmt = $pdo->prepare("INSERT INTO Work_Details (work_month_id, work_date, partner1_id, partner2_id, agency_partner_id) VALUES (?, ?, ?, ?, NULL)");
            $stmt->execute([$selected_month_id, $start_date->format('Y-m-d'), $partner1_id, $partner2_id]);
            $start_date->modify('+1 day');
        }
        // رفرش برای نمایش داده‌های جدید
        header("Location: work_details.php?month_id=$selected_month_id");
        exit;
    }
}
?>

<!-- [BLOCK-WORK-DETAILS-002] -->
<div class="container-fluid mt-5">
    <div class="mb-3">
        <label for="month_select" class="form-label">انتخاب ماه کاری</label>
        <select class="form-select" id="month_select" onchange="window.location.href='work_details.php?month_id=' + this.value">
            <option value="">ماه کاری را انتخاب کنید</option>
            <?php foreach ($work_months as $month): ?>
            <option value="<?php echo $month['work_month_id']; ?>" <?php echo $selected_month_id == $month['work_month_id'] ? 'selected' : ''; ?>>
                <?php echo jdate('Y/m', strtotime($month['start_date'])); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($selected_month_id && !empty($work_details)): ?>
    <table class="table table-light table-hover">
        <thead>
            <tr>
                <th>تاریخ</th>
                <th>همکار 1</th>
                <th>همکار 2</th>
                <th>آژانس</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($work_details as $detail): ?>
            <tr>
                <td><?php echo jdate('Y/m/d', strtotime($detail['work_date'])); ?></td>
                <td><?php echo $detail['partner1_name'] ?: '-'; ?></td>
                <td><?php echo $detail['partner2_name'] ?: '-'; ?></td>
                <td>
                    <select class="form-select" data-detail-id="<?php echo $detail['work_detail_id']; ?>" onchange="updateAgency(this)">
                        <option value="">انتخاب آژانس</option>
                        <?php
                        $partners_stmt = $pdo->query("SELECT p.partner_id, u.username FROM Partners p JOIN Users u ON p.user_id = u.user_id");
                        $partners = $partners_stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($partners as $partner): ?>
                        <option value="<?php echo $partner['partner_id']; ?>" <?php echo $detail['agency_partner_user_id'] == $partner['user_id'] ? 'selected' : ''; ?>>
                            <?php echo $partner['username']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <a href="#" class="text-primary" data-bs-toggle="modal" data-bs-target="#editWorkDetailModal" data-detail-id="<?php echo $detail['work_detail_id']; ?>" data-date="<?php echo $detail['work_date']; ?>" data-partner1="<?php echo $detail['partner1_user_id']; ?>" data-partner2="<?php echo $detail['partner2_user_id']; ?>" data-agency="<?php echo $detail['agency_partner_user_id']; ?>">
                        <i class="fas fa-edit"></i>
                    </a>
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
                            <?php foreach ($partners as $partner): ?>
                            <option value="<?php echo $partner['partner_id']; ?>">
                                <?php echo $partner['username']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_partner2" class="form-label">همکار 2</label>
                        <select class="form-select" id="edit_partner2" name="partner2_id">
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($partners as $partner): ?>
                            <option value="<?php echo $partner['partner_id']; ?>">
                                <?php echo $partner['username']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_agency" class="form-label">آژانس</label>
                        <select class="form-select" id="edit_agency" name="agency_partner_id">
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($partners as $partner): ?>
                            <option value="<?php echo $partner['partner_id']; ?>">
                                <?php echo $partner['username']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
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
                const detailId = e.target.getAttribute('data-detail-id');
                const workDate = e.target.getAttribute('data-date');
                const partner1 = e.target.getAttribute('data-partner1');
                const partner2 = e.target.getAttribute('data-partner2');
                const agency = e.target.getAttribute('data-agency');

                document.getElementById('edit_detail_id').value = detailId;
                document.getElementById('edit_work_date').value = jdate('Y/m/d', strtotime(workDate));
                document.getElementById('edit_partner1').value = partner1 || '';
                document.getElementById('edit_partner2').value = partner2 || '';
                document.getElementById('edit_agency').value = agency || '';
            });
        });

        // به‌روزرسانی آژانس در جدول
        function updateAgency(select) {
            const detailId = select.getAttribute('data-detail-id');
            const agencyPartnerId = select.value;

            fetch('update_work_detail_agency.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `detail_id=${detailId}&agency_partner_id=${agencyPartnerId}`
            })
            .then(response => response.text())
            .then(result => {
                if (result === 'success') {
                    console.log('آژانس به‌روزرسانی شد.');
                } else {
                    alert('خطا در به‌روزرسانی آژانس!');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('خطا در اتصال به سرور!');
            });
        }
    });
</script>

<?php
// [BLOCK-WORK-DETAILS-004]
require_once 'footer.php';
?>