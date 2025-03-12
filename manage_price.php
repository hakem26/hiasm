<?php
// فعال کردن نمایش خطاها برای دیباگ
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// لود هدر و فایل‌های مورد نیاز
require_once 'header.php';

echo "<!-- دیباگ: بعد از لود header -->";
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="card-title">مدیریت قیمت برای محصول تست</h5>
        <a href="products.php" class="btn btn-secondary">بازگشت به لیست محصولات</a>
    </div>

    <div class="mb-3">
        <label for="test_date" class="form-label">تست دیت‌پیکر (شمسی)</label>
        <input type="text" class="form-control datepicker" id="test_date" name="test_date">
    </div>
</div>

<script>
    console.log('اسکریپت شروع شد...');

    window.onload = function() {
        console.log('صفحه کامل لود شد، شروع اجرای جاوااسکریپت...');

        // چک کردن لود شدن jQuery و Persian Datepicker
        if (typeof $ === 'undefined') {
            console.error('jQuery لود نشده است!');
            return;
        }
        if (typeof $.fn.persianDatepicker === 'undefined') {
            console.error('Persian Datepicker لود نشده است!');
            return;
        }
        console.log('jQuery و Persian Datepicker لود شدند.');

        // فعال‌سازی دیت‌پیکر
        $('.datepicker').persianDatepicker({
            format: 'YYYY/MM/DD',
            autoClose: true,
            calendar: {
                persian: {
                    locale: 'fa',
                    digits: true
                }
            }
        });
        console.log('دیت‌پیکر برای تست فعال شد.');
    };
</script>

<?php
echo "<!-- دیباگ: قبل از لود فوتر -->";
require_once 'footer.php';
echo "<!-- دیباگ: بعد از لود فوتر -->";
?>