</div>
<!-- پایان main-container -->

<!-- Bootstrap RTL JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
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

    $(document).ready(function() {
        // مدیریت باز کردن مودال
        $('.open-modal').on('click', function() {
            const modalId = $(this).data('bs-target');
            console.log('دکمه کلیک شد برای مودال: ', modalId);
            if ($(modalId).length) {
                console.log('مودال با شناسه ', modalId, ' وجود دارد و باید باز شود.');
                const modal = new bootstrap.Modal($(modalId)[0], {
                    backdrop: true,
                    keyboard: true
                });
                modal.show();
            } else {
                console.error('مودال با شناسه ', modalId, ' یافت نشد!');
            }
        });

        // مدیریت بستن مودال و پاکسازی backdrop
        $('.modal').on('hidden.bs.modal', function() {
            console.log('مودال بسته شد، پاکسازی backdrop...');
            $('.modal-backdrop').remove();
            $('body').removeClass('modal-open');
            $('.modal').removeClass('show');
        });

        // فعال‌سازی Datepicker برای تاریخ شروع
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

        // فعال‌سازی Datepicker برای تاریخ پایان با تنظیمات اختیاری
        $('.optional-date').persianDatepicker({
            format: 'YYYY/MM/DD',
            autoClose: true,
            calendar: {
                persian: {
                    locale: 'fa',
                    digits: true
                }
            },
            initialValue: false, // بدون مقدار پیش‌فرض
            onSelect: function(unix) {
                console.log('تاریخ انتخاب شد: ', unix);
            },
            onHide: function() {
                if (!this.getState().selectedUnix) {
                    $(this.$input).val('');
                }
            }
        });

        // مدیریت چک‌باکس "روز جاری" با فعال/غیرفعال کردن فیلد
        function updateCurrentDay() {
            const today = '<?php echo get_today_jalali(); ?>';
            $('.optional-date').each(function() {
                const $endDate = $(this);
                const $checkbox = $('#' + $endDate.attr('id').replace('end_date', 'is_current_day'));
                if ($checkbox.is(':checked')) {
                    $endDate.val(today).trigger('change');
                    $endDate.prop('disabled', true); // غیرفعال کردن فیلد
                    $endDate.addClass('disabled'); // استایل غیرفعال
                } else {
                    $endDate.val('').trigger('change');
                    $endDate.prop('disabled', false); // فعال کردن فیلد
                    $endDate.removeClass('disabled'); // حذف استایل غیرفعال
                }
            });
        }

        // اجرا وقتی صفحه لود میشه
        updateCurrentDay();

        // اجرا وقتی چک‌باکس تغییر می‌کنه
        $('input[name="is_current_day"]').on('change', function() {
            updateCurrentDay();
        });
    });
</script>
</body>
</html>