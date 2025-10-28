<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'header.php';
require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_full($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', date('Y-m-d', strtotime($gregorian_date)));
    return gregorian_to_jalali($gy, $gm, $gd);
}

function gregorian_to_jalali_full_date($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', date('Y-m-d', strtotime($gregorian_date)));
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

$years = [];
$current_gregorian_year = date('Y');
$current_jalali = gregorian_to_jalali_full(date('Y-m-d'));
$current_jalali_year = $current_jalali[0];
for ($i = $current_jalali_year - 5; $i <= $current_jalali_year + 1; $i++) {
    $years[] = $i;
}
$selected_year = $_GET['year'] ?? $current_jalali_year;

$gregorian_start = jalali_to_gregorian($selected_year, 1, 1);
$gregorian_end = jalali_to_gregorian($selected_year + 1, 1, 1);
$start_date = sprintf("%04d-%02d-%02d", $gregorian_start[0], $gregorian_start[1], $gregorian_start[2]);
$end_date = sprintf("%04d-%02d-%02d", $gregorian_end[0], $gregorian_end[1], $gregorian_end[2]);

$stmt_months = $pdo->prepare("SELECT * FROM Work_Months WHERE start_date >= ? AND end_date < ? ORDER BY start_date DESC");
$stmt_months->execute([$start_date, $end_date]);
$work_months = $stmt_months->fetchAll(PDO::FETCH_ASSOC);

$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];
$work_month_id = isset($_GET['work_month_id']) ? (int) $_GET['work_month_id'] : ($work_months ? $work_months[0]['work_month_id'] : null);

$product_id = $_GET['product_id'] ?? null;

$transactions = [];
if ($work_month_id) {
    $month_query = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
    $month_query->execute([$work_month_id]);
    $month = $month_query->fetch(PDO::FETCH_ASSOC);

    if ($month) {
        $start_date = $month['start_date'];
        $end_date = $month['end_date'];

        $query = "
            SELECT it.id, it.product_id, it.user_id, it.quantity, it.transaction_date, p.product_name
            FROM Inventory_Transactions it
            JOIN Products p ON it.product_id = p.product_id
            WHERE it.transaction_date >= ? AND it.transaction_date <= ?
        ";
        $params = [$start_date, $end_date . ' 23:59:59'];

        if (!$is_admin) {
            $query .= " AND it.user_id = ?";
            $params[] = $current_user_id;
        }
        if ($product_id) {
            $query .= " AND it.product_id = ?";
            $params[] = $product_id;
        }

        $query .= " ORDER BY it.transaction_date ASC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $transactions_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($transactions_raw as $transaction) {
            $user_id = $transaction['user_id'];
            $product_id = $transaction['product_id'];
            $transaction_date = $transaction['transaction_date'];

            // --- تخصیص‌های قبلی (همه تخصیص‌ها برای این کاربر و محصول) ---
            $stmt_before = $pdo->prepare("
                SELECT COALESCE(SUM(quantity), 0) 
                FROM Inventory_Transactions 
                WHERE user_id = ? AND product_id = ? AND transaction_date < ?
            ");
            $stmt_before->execute([$user_id, $product_id, $transaction_date]);
            $total_allocated_before = $stmt_before->fetchColumn();

            // --- فروش‌های قبلی (فقط وقتی کاربر همکار ۱ بوده) ---
            $stmt_sales_before = $pdo->prepare("
                SELECT COALESCE(SUM(oi.quantity), 0)
                FROM Order_Items oi
                JOIN Orders o ON oi.order_id = o.order_id
                JOIN Work_Details wd ON o.work_details_id = wd.id
                JOIN Partners p ON wd.partner_id = p.partner_id
                WHERE wd.work_date < ? 
                  AND oi.product_name = ? 
                  AND p.user_id1 = ?
            ");
            $stmt_sales_before->execute([$transaction_date, $transaction['product_name'], $user_id]);
            $total_sold_before = $stmt_sales_before->fetchColumn();

            // --- موجودی قبل از این تراکنش ---
            $previous_inventory = $total_allocated_before - $total_sold_before;

            // --- موجودی فعلی (از جدول Inventory) ---
            $stmt_current = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ?");
            $stmt_current->execute([$user_id, $product_id]);
            $current_inventory = $stmt_current->fetchColumn() ?: 0;

            $transactions[] = [
                'id' => $transaction['id'],
                'product_id' => $product_id,
                'date' => gregorian_to_jalali_full_date($transaction_date),
                'product_name' => $transaction['product_name'],
                'quantity' => abs($transaction['quantity']),
                'status' => $transaction['quantity'] > 0 ? 'تخصیص' : 'بازگشت',
                'previous_inventory' => $previous_inventory,
                'current_inventory' => $current_inventory
            ];
        }
    }
}
?>
<div class="container-fluid mt-5">
    <h5 class="card-title mb-4">گزارش تخصیص موجودی فروشنده</h5>

    <form method="GET" class="row g-3 mb-4">
        <div class="col-auto">
            <label for="year" class="form-label">سال</label>
            <select name="year" id="year" class="form-select" onchange="this.form.submit()">
                <?php foreach ($years as $year): ?>
                    <option value="<?php echo $year; ?>" <?php echo $selected_year == $year ? 'selected' : ''; ?>>
                        <?php echo $year; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label for="work_month_id" class="form-label">ماه کاری</label>
            <select name="work_month_id" id="work_month_id" class="form-select" onchange="this.form.submit()">
                <option value="" <?php echo !$work_month_id ? 'selected' : ''; ?>>انتخاب ماه</option>
                <?php foreach ($work_months as $month):
                    $start_j = gregorian_to_jalali_full($month['start_date']);
                    $end_j = gregorian_to_jalali_full($month['end_date']);
                    ?>
                    <option value="<?php echo $month['work_month_id']; ?>" <?php echo $work_month_id == $month['work_month_id'] ? 'selected' : ''; ?>>
                        <?php echo $start_j[2] . ' ' . jdate('F', strtotime($month['start_date'])); ?> تا
                        <?php echo $end_j[2] . ' ' . jdate('F', strtotime($month['end_date'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label for="product_search" class="form-label">جستجوی محصول</label>
            <input type="text" id="product_search" class="form-control" placeholder="حداقل ۳ حرف تایپ کنید"
                value="<?php echo isset($_GET['product_name']) ? htmlspecialchars($_GET['product_name']) : ''; ?>">
            <select id="product_list" class="form-select" style="display: none;" size="5"></select>
            <input type="hidden" id="product_id" name="product_id"
                value="<?php echo isset($_GET['product_id']) ? $_GET['product_id'] : ''; ?>">
        </div>
        <div class="col-auto align-self-end">
            <button type="submit" id="filter_product" class="btn btn-primary" disabled>فیلتر محصول</button>
        </div>
    </form>

    <div class="row g-3 mb-4">
        <div class="col-auto">
            <a href="inventory_monthly_report.php?work_month_id=<?php echo $work_month_id; ?>&product_id=<?php echo $product_id ?? ''; ?>"
                target="_blank" class="btn btn-success <?php echo !$work_month_id ? 'disabled' : ''; ?>">گزارش
                ماهانه</a>
        </div>
        <div class="col-auto">
            <a href="inventory_time_report.php?work_month_id=<?php echo $work_month_id; ?>&product_id=<?php echo $product_id ?? ''; ?>"
                target="_blank" class="btn btn-info <?php echo !$work_month_id ? 'disabled' : ''; ?>">گزارش زمانی</a>
        </div>
    </div>

    <?php if ($work_month_id && !empty($transactions)): ?>
        <div class="table-container">
            <table id="inventoryTable" class="table table-light table-inventory table-hover">
                <thead>
                    <tr>
                        <th>تاریخ</th>
                        <th>نام محصول</th>
                        <th>تعداد</th>
                        <th>ویرایش تعداد</th>
                        <th>وضعیت تخصیص</th>
                        <th>موجودی قبل</th>
                        <th>موجودی فعلی</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td><?php echo $transaction['date']; ?></td>
                            <td><?php echo htmlspecialchars($transaction['product_name']); ?></td>
                            <td><?php echo $transaction['quantity']; ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary edit-quantity-btn"
                                    data-id="<?php echo $transaction['id']; ?>"
                                    data-product-id="<?php echo $transaction['product_id']; ?>"
                                    data-date="<?php echo $transaction['date']; ?>"
                                    data-product="<?php echo htmlspecialchars($transaction['product_name']); ?>"
                                    data-quantity="<?php echo $transaction['quantity']; ?>"
                                    data-status="<?php echo $transaction['status']; ?>"
                                    data-previous="<?php echo $transaction['previous_inventory']; ?>" data-bs-toggle="modal"
                                    data-bs-target="#editQuantityModal">
                                    ویرایش
                                </button>
                            </td>
                            <td><?php echo $transaction['status']; ?></td>
                            <td><?php echo $transaction['previous_inventory']; ?></td>
                            <td><?php echo $transaction['current_inventory']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($work_month_id): ?>
        <div class="alert alert-warning text-center">تراکنشی برای این ماه یافت نشد.</div>
    <?php else: ?>
        <div class="alert alert-info text-center">لطفاً یک ماه کاری انتخاب کنید.</div>
    <?php endif; ?>

    <div class="modal fade" id="editQuantityModal" tabindex="-1" aria-labelledby="editQuantityModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editQuantityModalLabel">ویرایش تعداد محصول</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editQuantityForm">
                        <input type="hidden" id="transaction_id" name="transaction_id">
                        <input type="hidden" id="product_id" name="product_id">
                        <div class="mb-3">
                            <label for="modal_date" class="form-label">تاریخ</label>
                            <input type="text" class="form-control" id="modal_date" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="modal_product" class="form-label">نام محصول</label>
                            <input type="text" class="form-control" id="modal_product" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="modal_quantity" class="form-label">تعداد</label>
                            <input type="number" class="form-control" id="modal_quantity" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="modal_status" class="form-label">وضعیت تخصیص</label>
                            <input type="text" class="form-control" id="modal_status" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="modal_previous" class="form-label">موجودی قبل</label>
                            <input type="number" class="form-control" id="modal_previous" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="modal_new_quantity" class="form-label">تعداد جدید</label>
                            <input type="number" class="form-control" id="modal_new_quantity" name="new_quantity"
                                min="0" required>
                        </div>
                        <p class="text-muted" style="font-size: 0.9em;">
                            لطفاً ویرایش اطلاعات را در اسرع وقت انجام دهید. در صورت تأخیر در اصلاح اطلاعات نادرست،
                            مسئولیت تمامی عواقب خطاها در آمار و گزارش نهایی بر عهده کارشناس مربوطه خواهد بود.
                        </p>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" id="deleteTransactionBtn">حذف</button>
                    <button type="button" class="btn btn-primary" id="saveQuantityBtn">ثبت</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    $(document).ready(function () {
        var timeout;
        $("#product_search").on("input", function () {
            clearTimeout(timeout);
            var query = $(this).val().trim();
            var $productList = $("#product_list");
            var $filterButton = $("#filter_product");

            if (query.length < 3) {
                $productList.hide().empty();
                $("#product_id").val("");
                $filterButton.prop("disabled", true);
                return;
            }

            timeout = setTimeout(function () {
                $.ajax({
                    url: "get_products.php",
                    method: "POST",
                    data: { query: query },
                    dataType: "html",
                    success: function (data) {
                        $productList.empty();
                        if (data) {
                            $(data).each(function () {
                                var product = JSON.parse($(this).attr("data-product"));
                                $productList.append(
                                    $("<option>", {
                                        value: product.product_id,
                                        text: product.product_name
                                    })
                                );
                            });
                            $productList.show();
                        } else {
                            $productList.hide();
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error("AJAX error:", status, error);
                    }
                });
            }, 300);
        });

        $("#product_list").on("change", function () {
            var selectedId = $(this).val();
            var selectedName = $(this).find("option:selected").text();
            $("#product_id").val(selectedId);
            $("#product_search").val(selectedName);
            $("#product_list").hide();
            $("#filter_product").prop("disabled", false);
        });

        if (!$("#product_id").val()) {
            $("#filter_product").prop("disabled", true);
        }

        $('.edit-quantity-btn').on('click', function () {
            var id = $(this).data('id');
            var productId = $(this).data('product-id');
            var date = $(this).data('date');
            var product = $(this).data('product');
            var quantity = $(this).data('quantity');
            var status = $(this).data('status');
            var previous = $(this).data('previous');

            $('#transaction_id').val(id);
            $('#product_id').val(productId);
            $('#modal_date').val(date);
            $('#modal_product').val(product);
            $('#modal_quantity').val(quantity);
            $('#modal_status').val(status);
            $('#modal_previous').val(previous);
            $('#modal_new_quantity').val(quantity);

            console.log('Modal Data:', {
                transaction_id: id,
                product_id: productId,
                quantity: quantity
            });
        });

        $('#saveQuantityBtn').on('click', function () {
            var transactionId = $('#transaction_id').val();
            var productId = $('#product_id').val();
            var newQuantity = $('#modal_new_quantity').val();

            var formData = {
                transaction_id: transactionId,
                product_id: productId,
                new_quantity: newQuantity,
                action: 'update'
            };
            console.log('Form Data:', formData);

            if (!transactionId || transactionId <= 0) {
                alert('شناسه تراکنش نامعتبر است.');
                return;
            }
            if (!productId || product_id <= 0) {
                alert('شناسه محصول نامعتبر است.');
                return;
            }
            if (newQuantity === '' || newQuantity < 0 || isNaN(newQuantity)) {
                alert('لطفاً مقدار معتبر برای تعداد جدید وارد کنید.');
                return;
            }

            $.ajax({
                url: 'manage_inventory_transaction.php',
                method: 'POST',
                data: formData,
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        alert('تعداد با موفقیت به‌روزرسانی شد.');
                        location.reload();
                    } else {
                        alert('خطا: ' + response.message);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('AJAX error:', status, error);
                    alert('خطا در ارتباط با سرور.');
                }
            });
        });

        $('#deleteTransactionBtn').on('click', function () {
            if (!confirm('آیا مطمئن هستید که می‌خواهید این تراکنش را حذف کنید؟')) {
                return;
            }
            var transactionId = $('#transaction_id').val();
            var productId = $('#product_id').val();

            var data = {
                transaction_id: transactionId,
                product_id: productId,
                action: 'delete'
            };
            console.log('Delete Data:', data);

            $.ajax({
                url: 'manage_inventory_transaction.php',
                method: 'POST',
                data: data,
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        alert('تراکنش با موفقیت حذف شد.');
                        location.reload();
                    } else {
                        alert('خطا: ' + response.message);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('AJAX error:', status, error);
                    alert('خطا در ارتباط با سرور.');
                }
            });
        });

        $('#inventoryTable').DataTable({
            "language": {
                "url": "//cdn.datatables.net/plug-ins/1.13.1/i18n/fa.json"
            },
            "paging": true,
            "searching": false,
            "ordering": true,
            "info": true,
            "lengthMenu": [10, 25, 50, 100]
        });
    });
</script>

<?php require_once 'footer.php'; ?>