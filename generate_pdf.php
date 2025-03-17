<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// لود کردن Dompdf
require_once 'vendor/autoload.php'; // اگه از Composer استفاده می‌کنی
// اگه دستی نصب کردی، مسیر رو درست کن:
// require_once 'vendor/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// تنظیمات Dompdf
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true); // برای لود فونت‌ها و فایل‌های خارجی
$options->set('defaultFont', 'Vazirmatn');
$options->set('chroot', realpath('')); // مسیر پایه برای دسترسی به فایل‌ها
$options->set('isPhpEnabled', true); // فعال کردن PHP در HTML

$dompdf = new Dompdf($options);

// دریافت پارامترها
$work_month_id = $_GET['work_month_id'] ?? null;
$partner_id = $_GET['partner_id'] ?? null;

if (!$work_month_id || !$partner_id) {
    die('پارامترهای مورد نیاز یافت نشد.');
}

// گرفتن HTML از print-report-monthly.php
ob_start();
$is_pdf = true; // مشخص کردن اینکه خروجی برای PDF هست
include 'print-report-monthly.php';
$html = ob_get_clean();

// لود HTML به Dompdf
$dompdf->loadHtml($html, 'UTF-8');

// تنظیم اندازه کاغذ و جهت
$dompdf->setPaper('A4', 'landscape');

// رندر PDF
$dompdf->render();

// تولید نام فایل
$filename = "monthly_report_{$work_month_id}_{$partner_id}.pdf";

// خروجی PDF برای دانلود
$dompdf->stream($filename, ['Attachment' => 1]);
?>