<?php
require_once 'jdf.php';

// تابع برای دریافت سال جاری شمسی
function get_persian_current_year() {
    // دریافت تاریخ فعلی میلادی
    $gregorian_date = date('Y-m-d');
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    
    // تبدیل به تاریخ شمسی
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    
    return $jy;
}

// تابع برای بررسی تغییر سال شمسی (مثلاً 29 یا 30 اسفند به 1 فروردین)
function is_persian_new_year_transition($date = null) {
    if ($date === null) {
        $date = date('Y-m-d');
    }
    list($gy, $gm, $gd) = explode('-', $date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);

    // بررسی آیا تاریخ نزدیک به تعویض سال شمسی هست (مثلاً 29 یا 30 اسفند)
    $persian_last_day = jalali_to_gregorian($jy, 12, jalaali_month_days($jy, 12));
    $last_day = $persian_last_day[2] . '-' . $persian_last_day[1] . '-' . $persian_last_day[0];

    $current = new DateTime($date);
    $last = new DateTime($last_day);

    return $current >= $last || $jm == 1 && $jd == 1; // اگر آخر سال یا اول فروردین باشه
}

// تابع برای دریافت سال شمسی بر اساس تاریخ
function get_persian_year($date = null) {
    if ($date === null) {
        $date = date('Y-m-d');
    }
    list($gy, $gm, $gd) = explode('-', $date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return $jy;
}
?>