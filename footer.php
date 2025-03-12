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
        // دیباگ: چک کردن لود شدن jQuery و Persian Datepicker
        console.log('jQuery لود شده:', typeof $ !== 'undefined');
        console.log('Persian Datepicker لود شده:', typeof $.fn.persianDatepicker !== 'undefined');

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

                // فعال‌سازی Datepicker بعد از باز شدن مودال
                $(modalId).on('shown.bs.modal', function() {
                    console.log('مودال باز شد، فعال‌سازی Datepicker...');
                    
                    // فعال‌سازی Datepicker برای تاریخ شروع
                    $(modalId).find('.persian-date').persianDatepicker({
                        format: 'YYYY/MM/DD',
                        autoClose: true,
                        calendar: {
                            persian: {
                                locale: 'fa',
                                digits: true
                            }
                        }
                    });
                    console.log('Datepicker برای تاریخ شروع فعال شد');

                    // فعال‌سازی Datepicker برای تاریخ پایان
                    $(modalId).find('.optional-date').persianDatepicker({
                        format: 'YYYY/MM/DD',
                        autoClose: true,
                        calendar: {
                            persian: {
                                locale: 'fa',
                                digits: true
                            }
                        },
                        initialValue: false,
                        onSelect: function(unix) {
                            console.log('تاریخ پایان انتخاب شد: ', unix);
                        },
                        onHide: function() {
                            if (!this.getState().selectedUnix) {
                                $(this.$input).val('');
                            }
                        }
                    });
                    console.log('Datepicker برای تاریخ پایان فعال شد');

                    // مدیریت چک‌باکس "روز جاری"
                    updateCurrentDay();
                });
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

        // تابع مدیریت چک‌باکس "روز جاری"
        function updateCurrentDay() {
            const today = '<?php echo get_today_jalali(); ?>';
            $('.optional-date').each(function() {
                const $endDate = $(this);
                const $checkbox = $('#' + $endDate.attr('id').replace('end_date', 'is_current_day'));
                console.log('چک‌باکس وضعیت:', $checkbox.is(':checked'), 'برای فیلد:', $endDate.attr('id'));
                if ($checkbox.is(':checked')) {
                    $endDate.val(today).trigger('change');
                    $endDate.prop('disabled', true);
                    $endDate.addClass('disabled');
                    console.log('فیلد تاریخ پایان غیرفعال شد و تاریخ امروز تنظیم شد:', today);
                } else {
                    $endDate.val('').trigger('change');
                    $endDate.prop('disabled', false);
                    $endDate.removeClass('disabled');
                    console.log('فیلد تاریخ پایان فعال شد');
                }
            });
        }

        // اجرا وقتی چک‌باکس تغییر می‌کنه
        $('input[name="is_current_day"]').on('change', function() {
            console.log('چک‌باکس تغییر کرد');
            updateCurrentDay();
        });
    });
</script>
</body>
</html>