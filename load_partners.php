<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}

require_once 'db.php';

$work_month_id = $_GET['work_month_id'] ?? null;
if (!$work_month_id) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];

try {
    if ($is_admin) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.partner_id, CONCAT(u1.full_name, ' - ', COALESCE(u2.full_name, u1.full_name)) AS name
            FROM Work_Details wd
            JOIN Partners p ON wd.partner_id = p.partner_id
            JOIN Users u1 ON p.user_id1 = u1.user_id
            LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
            WHERE wd.work_month_id = ?
            ORDER BY name
        ");
        $stmt->execute([$work_month_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.partner_id, CONCAT(u1.full_name, ' - ', COALESCE(u2.full_name, u1.full_name)) AS name
            FROM Work_Details wd
            JOIN Partners p ON wd.partner_id = p.partner_id
            JOIN Users u1 ON p.user_id1 = u1.user_id
            LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
            WHERE wd.work_month_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
            ORDER BY name
        ");
        $stmt->execute([$work_month_id, $current_user_id, $current_user_id]);
    }
    $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($partners);
} catch (Exception $e) {
    error_log("Error in load_partners.php: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'خطای سرور']);
}
?>