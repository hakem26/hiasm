<?php
function persian_to_jd($year, $month, $day) {
    $g_d = jdtogregorian(PersianToJalali($year, $month, $day));
    return gregoriantojd(date("m", strtotime($g_d)), date("d", strtotime($g_d)), date("Y", strtotime($g_d)));
}

function jalali_date($format, $timestamp = null) {
    if (!$timestamp) $timestamp = time();
    $date = jdate($format, $timestamp);
    return $date;
}
?>
