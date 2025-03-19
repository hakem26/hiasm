<?php
// [BLOCK-ADD-PARTNER-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id1 = (int) $_POST['user_id1'];
    $user_id2 = isset($_POST['user_id2']) && !empty($_POST['user_id2']) ? (int) $_POST['user_id2'] : null;
    $days_of_week = isset($_POST['days_of_week']) ? $_POST['days_of_week'] : [];

    try {
        // افزودن همکار جدید به جدول Partners
        $stmt = $pdo->prepare("INSERT INTO Partners (user_id1, user_id2) VALUES (?, ?)");
        $stmt->execute([$user_id1, $user_id2]);
        $partner_id = $pdo->lastInsertId();

        // ثبت روزهای کاری در جدول Partner_Schedule
        foreach ($days_of_week as $day) {
            $day = (int) $day;
            $schedule_stmt = $pdo->prepare("INSERT INTO Partner_Schedule (partner_id, day_of_week) VALUES (?, ?)");
            $schedule_stmt->execute([$partner_id, $day]);
        }

        // هدایت به صفحه همکاران
        header("Location: partners.php");
        exit;
    } catch (PDOException $e) {
        die("خطا در افزودن همکار: " . $e->getMessage());
    }
} else {
    // اگر درخواست مستقیم باشه، به صفحه همکاران هدایت کن
    header("Location: partners.php");
    exit;
}
?>