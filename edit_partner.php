<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $partner_id = (int) $_POST['partner_id'];
    $user_id1 = (int) $_POST['user_id1'];
    $user_id2 = isset($_POST['user_id2']) && !empty($_POST['user_id2']) ? (int) $_POST['user_id2'] : null;
    $days_of_week = isset($_POST['days_of_week']) ? $_POST['days_of_week'] : [];

    try {
        // بروزرسانی جفت همکار
        $stmt = $pdo->prepare("UPDATE Partners SET user_id1 = ?, user_id2 = ? WHERE partner_id = ?");
        $stmt->execute([$user_id1, $user_id2, $partner_id]);

        // حذف برنامه‌های قبلی
        $delete_schedule_stmt = $pdo->prepare("DELETE FROM Partner_Schedule WHERE partner_id = ?");
        $delete_schedule_stmt->execute([$partner_id]);

        // ثبت برنامه‌های جدید
        foreach ($days_of_week as $day) {
            $day = (int) $day;
            $schedule_stmt = $pdo->prepare("INSERT INTO Partner_Schedule (partner_id, day_of_week) VALUES (?, ?)");
            $schedule_stmt->execute([$partner_id, $day]);
        }

        // هدایت به صفحه همکاران
        header("Location: partners.php");
        exit;
    } catch (PDOException $e) {
        die("خطا در ویرایش همکار: " . $e->getMessage());
    }
} else {
    header("Location: partners.php");
    exit;
}
?>