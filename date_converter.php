<?php
// [BLOCK-DATE-CONVERTER-001]
class DateConverter {
    public function jalali_to_gregorian($jy, $jm, $jd, $mod = '') {
        if ($jy > 979) {
            $gy = 1600;
            $jy -= 979;
        } else {
            $gy = 621;
        }
        $days = (365 * $jy) + (((int)($jy / 33)) * 8) + ((int)((($jy % 33) + 3) / 4)) + 78 + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
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
        foreach (array(0, 31, ((($gy % 4 == 0) and ($gy % 100 != 0)) or ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31) as $gm => $v) {
            if ($gd <= $v) break;
            $gd -= $v;
        }
        return ($mod === '') ? array($gy, $gm, $gd) : $gy . $mod . $gm . $mod . $gd;
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