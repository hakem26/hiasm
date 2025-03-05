<?php
// [BLOCK-DASHBOARD-001]
require_once 'header.php';
require_once 'jdf.php';

$gregorian_date = date('Y-m-d');
$jalali_date = jdate('Y/m/d', strtotime($gregorian_date));
?>

<!-- [BLOCK-DASHBOARD-002] -->
<div class="container mt-4">
    <div class="card mx-auto" style="max-width: 600px;">
        <div class="card-body text-center">
            <h3 class="card-title">تاریخ: <?php echo $jalali_date; ?></h3>
            <h1 class="card-title">سلام <?php echo htmlspecialchars($full_name); ?> عزیز، خوش آمدی!</h1>
        </div>
    </div>
</div>

<?php
// [BLOCK-DASHBOARD-003]
require_once 'footer.php';
?>