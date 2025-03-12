<?php
// [BLOCK-FOOTER-001]
?>
</div>
<!-- پایان محتوای اصلی -->
</div>
<!-- پایان layout-container -->

<!-- Bootstrap RTL JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"></script>
<script>
    // اطمینان از لود شدن بوت‌استرپ و فعال‌سازی دراپ‌داون‌ها
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
            const dropdowns = document.querySelectorAll('.dropdown-toggle');
            dropdowns.forEach(dropdown => {
                new bootstrap.Dropdown(dropdown);
            });
        } else {
            console.error('Bootstrap Dropdown is not available.');
        }
    });
</script>
<script>
    $(document).ready(function() {
        // دیباگ پیشرفته
        $('.open-modal').on('click', function() {
            const modalId = $(this).data('bs-target');
            console.log('دکمه کلیک شد برای مودال: ', modalId);
            // چک کردن وجود مودال
            if ($(modalId).length) {
                console.log('مودال با شناسه ', modalId, ' وجود دارد.');
            } else {
                console.error('مودال با شناسه ', modalId, ' یافت نشد!');
            }
        });

        // فعال‌سازی Datepicker
        $('.persian-date').persianDatepicker({
            format: 'YYYY/MM/DD',
            autoClose: true,
            calendar: {
                persian: {
                    locale: 'fa',
                    digits: true
                }
            }
        });
    });
</script>
</body>

</html>