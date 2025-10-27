<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
    exit;
}

require_once 'db.php';

$partner_id = $_POST['partner_id'] ?? 0;
if (!$partner_id) {
    echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT active FROM Partners WHERE partner_id = ?");
    $stmt->execute([$partner_id]);
    $current = $stmt->fetchColumn();

    if ($current === false) {
        echo json_encode(['success' => false, 'message' => 'همکار یافت نشد']);
        exit;
    }

    $new_status = $current ? 0 : 1;
    $update = $pdo->prepare("UPDATE Partners SET active = ? WHERE partner_id = ?");
    $update->execute([$new_status, $partner_id]);

    $msg = $new_status ? 'همکار با موفقیت فعال شد.' : 'همکار با موفقیت غیرفعال شد.';
    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'خطای دیتابیس']);
}
?>