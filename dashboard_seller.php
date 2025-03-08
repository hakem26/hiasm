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
    <h2>میز کار فروشندگان</h2>
    <div class="card bg-light text-dark">
        <div class="card-body">
            <h5 class="card-title">لیست فاکتورها</h5>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <select class="form-select w-auto bg-light text-dark border-secondary">
                    <option>10</option>
                    <option>25</option>
                    <option>50</option>
                </select>
                <input type="text" class="form-control w-auto bg-light text-dark border-secondary" placeholder="جستجو...">
            </div>
            <table class="table table-light table-hover">
                <thead>
                    <tr>
                        <th><input type="checkbox" class="form-check-input"></th>
                        <th>شماره</th>
                        <th>فاکتور</th>
                        <th>نام</th>
                        <th>تاریخ صدور</th>
                        <th>مبلغ</th>
                        <th>وضعیت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><input type="checkbox" class="form-check-input"></td>
                        <td><?php echo $order['id']; ?></td>
                        <td><?php echo $order['invoice']; ?></td>
                        <td><?php echo $order['name']; ?></td>
                        <td><?php echo $order['date']; ?></td>
                        <td><?php echo $order['amount']; ?> تومان</td>
                        <td>
                            <span class="badge <?php echo $order['status'] === 'پرداخت‌شده' ? 'bg-success' : 'bg-warning'; ?>">
                                <?php echo $order['status']; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// [BLOCK-DASHBOARD-003]
require_once 'footer.php';
?>