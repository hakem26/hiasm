<?php
// [BLOCK-DASHBOARD-001]
require_once 'header.php';
require_once 'jdf.php';

// دیتای نمونه برای جدول (می‌تونید از دیتابیس پر کنید)
$orders = [
    ['id' => 1, 'invoice' => '#526534', 'name' => 'کاترین مورفی', 'date' => '25/01/2025', 'amount' => '200,000', 'status' => 'پرداخت‌شده'],
    ['id' => 2, 'invoice' => '#696589', 'name' => 'آنت بلک', 'date' => '25/01/2025', 'amount' => '200,000', 'status' => 'پرداخت‌شده'],
    ['id' => 3, 'invoice' => '#256584', 'name' => 'رونالد ریچاردز', 'date' => '10/02/2025', 'amount' => '200,000', 'status' => 'پرداخت‌شده'],
    ['id' => 4, 'invoice' => '#526587', 'name' => 'النور پنها', 'date' => '10/02/2025', 'amount' => '150,000', 'status' => 'پرداخت‌شده'],
    ['id' => 5, 'invoice' => '#105986', 'name' => 'لسلی الکساندر', 'date' => '15/03/2025', 'amount' => '150,000', 'status' => 'در انتظار'],
];
?>

<!-- [BLOCK-DASHBOARD-002] -->
<div class="container-fluid mt-5"> <!-- افزایش فاصله با mt-5 برای زیر منوی بالا -->
    <h2>میز کار ادمین</h2>
</div>

<?php
// [BLOCK-DASHBOARD-003]
require_once 'footer.php';
?>