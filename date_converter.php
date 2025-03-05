<?php
// [BLOCK-DATE-CONVERTER-001]
class DateConverter {
    public function jalali_to_gregorian($jy, $jm, $jd, $mod = '') {
        // تبدیل ورودی‌ها به عدد صحیح برای اطمینان
        $jy = (int)$jy;
        $jm = (int)$jm;
        $jd = (int)$jd;

        if ($jy > 979) {
            $gy = 1600;  // پایه سال میلادی برای سال‌های شمسی بالای 979
            $jy -= 979;  // کسر کردن 979 برای تنظیم به سال‌های شمسی جدید
        } else {
            $gy = 621;   // پایه سال میلادی برای سال‌های شمسی زیر 979
        }

        // محاسبه روزهای کل
        $days = (365 * $jy) + (((int)($jy / 33)) * 8) + ((int)((($jy % 33) + 3) / 4)) + 78 + $jd + 
                (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

        // محاسبه سال میلادی
        $gy += 400 * ((int)($days / 146097));
        $days %= 146097;

        if ($days > 36524) {
            $gy += 100 * ((int)(--$days / 36524));
            $days %= 36524;
            if ($days >= 365) $days++;
        }

        $gy += 4 * ((int)(($days) / 1461));
        $days %= 1461;

        $gy += (int)(($days - 1) / 365);
        if ($days > 365) $days = ($days - 1) % 365;
        $gd = $days + 1;

        // محاسبه ماه و روز میلادی
        $leap = ((($gy % 4 == 0) and ($gy % 100 != 0)) or ($gy % 400 == 0)) ? 29 : 28;
        $gregorian_month_days = [0, 31, $leap, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        
        $gm = 1;
        foreach ($gregorian_month_days as $month => $days_in_month) {
            if ($gd <= $days_in_month) break;
            $gd -= $days_in_month;
            $gm++;
        }

        return ($mod === '') ? array($gy, $gm, $gd) : sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    // متد کمکی برای تبدیل تاریخ شمسی به فرمت میلادی (Y-m-d)
    public function convertJalaliToGregorian($jalali_date) {
        $date_parts = explode('/', $jalali_date);
        if (count($date_parts) === 3) {
            $year = (int)$date_parts[0];  // تبدیل سال به عدد صحیح
            $month = (int)$date_parts[1]; // تبدیل ماه به عدد صحیح
            $day = (int)$date_parts[2];   // تبدیل روز به عدد صحیح
            $gregorian = $this->jalali_to_gregorian($year, $month, $day, '-');
            return $gregorian;
        }
        return '0000-00-00';
    }
}