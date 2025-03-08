<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';
require_once 'header.php';

// پردازش فرم افزودن محصول
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $product_name = trim($_POST['product_name']);
    $unit_price = trim($_POST['unit_price']);

    if (!empty($product_name) && !empty($unit_price) && is_numeric($unit_price)) {
        $stmt = $pdo->prepare("INSERT INTO Products (product_name, unit_price) VALUES (?, ?)");
        $stmt->execute([$product_name, $unit_price]);
        echo "<script>alert('محصول با موفقیت اضافه شد!'); window.location.href='products.php';</script>";
    } else {
        echo "<script>alert('لطفاً نام محصول و قیمت را به درستی وارد کنید!');</script>";
    }
}

// پردازش حذف محصول
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $product_id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM Products WHERE product_id = ?");
    $stmt->execute([$product_id]);
    echo "<script>alert('محصول با موفقیت حذف شد!'); window.location.href='products.php';</script>";
}

// دریافت لیست محصولات
$products = $pdo->query("SELECT * FROM Products ORDER BY product_id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid mt-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="card-title">مدیریت محصولات</h5>
    </div>

    <!-- فرم افزودن محصول -->
    <form method="POST" class="row g-3 mb-3">
        <div class="col-auto">
            <input type="text" class="form-control" name="product_name" placeholder="نام محصول" required>
        </div>
        <div class="col-auto">
            <input type="number" class="form-control" name="unit_price" placeholder="قیمت واحد (تومان)" step="0.01" required>
        </div>
        <div class="col-auto">
            <button type="submit" name="add_product" class="btn btn-primary">افزودن محصول</button>
        </div>
    </form>

    <!-- جدول نمایش محصولات -->
    <?php if (!empty($products)): ?>
        <table class="table table-light table-hover">
            <thead>
                <tr>
                    <th>شناسه</th>
                    <th>نام محصول</th>
                    <th>قیمت واحد (تومان)</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td><?= $product['product_id'] ?></td>
                        <td><?= htmlspecialchars($product['product_name']) ?></td>
                        <td><?= number_format($product['unit_price'], 2) ?></td>
                        <td>
                            <a href="edit_product.php?id=<?= $product['product_id'] ?>" class="btn btn-warning btn-sm">ویرایش</a>
                            <a href="products.php?delete=<?= $product['product_id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('آیا مطمئن هستید؟')">حذف</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="alert alert-warning text-center">محصولی وجود ندارد.</div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<?php require_once 'footer.php'; ?>