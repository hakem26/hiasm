<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

// دریافت اطلاعات محصول برای ویرایش
$product = null;
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $product_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM Products WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
}

// پردازش فرم ویرایش
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_product'])) {
    $product_id = (int)$_POST['product_id'];
    $product_name = trim($_POST['product_name']);
    $unit_price = trim($_POST['unit_price']);

    if (!empty($product_name) && !empty($unit_price) && is_numeric($unit_price)) {
        $stmt = $pdo->prepare("UPDATE Products SET product_name = ?, unit_price = ? WHERE product_id = ?");
        $stmt->execute([$product_name, $unit_price, $product_id]);
        echo "<script>alert('محصول با موفقیت ویرایش شد!'); window.location.href='products.php';</script>";
    } else {
        echo "<script>alert('لطفاً نام محصول و قیمت را به درستی وارد کنید!');</script>";
    }
}
?>

<div class="container-fluid mt-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="card-title">ویرایش محصول</h5>
    </div>

    <?php if ($product): ?>
        <form method="POST">
            <input type="hidden" name="product_id" value="<?= $product['product_id'] ?>">
            <div class="row g-3 mb-3">
                <div class="col-auto">
                    <input type="text" class="form-control" name="product_name" value="<?= htmlspecialchars($product['product_name']) ?>" placeholder="نام محصول" required>
                </div>
                <div class="col-auto">
                    <input type="number" class="form-control" name="unit_price" value="<?= $product['unit_price'] ?>" placeholder="قیمت واحد (تومان)" step="0.01" required>
                </div>
                <div class="col-auto">
                    <button type="submit" name="edit_product" class="btn btn-primary">ذخیره تغییرات</button>
                    <a href="products.php" class="btn btn-secondary">بازگشت</a>
                </div>
            </div>
        </form>
    <?php else: ?>
        <div class="alert alert-danger text-center">محصول پیدا نشد!</div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<?php require_once 'footer.php'; ?>