<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}

require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date)
{
    if (!$gregorian_date || !preg_match('/^\d{4}-\d{2}-\d{2}/', $gregorian_date)) {
        return 'نامشخص';
    }
    try {
        list($gy, $gm, $gd) = explode('-', $gregorian_date);
        if (!is_numeric($gy) || !is_numeric($gm) || !is_numeric($gd)) {
            return 'نامشخص';
        }
        list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
        return "$jy/$jm/$jd";
    } catch (Exception $e) {
        error_log("Error in gregorian_to_jalali_format: " . $e->getMessage());
        return 'نامشخص';
    }
}

$work_month_id = $_GET['work_month_id'] ?? null;
$partner_id = $_GET['partner_id'] ?? null;
if (!$work_month_id || !$partner_id) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];

try {
    $query = "
        SELECT wd.id, wd.work_date, CONCAT(u1.full_name, ' - ', COALESCE(u2.full_name, u1.full_name)) AS name
        FROM Work_Details wd
        JOIN Partners p ON wd.partner_id = p.partner_id
        JOIN Users u1 ON p.user_id1 = u1.user_id
        LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
        WHERE wd.work_month_id = ? AND p.partner_id = ?
    ";
    $params = [$work_month_id, $partner_id];

    if (!$is_admin) {
        $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
        $params[] = $current_user_id;
        $params[] = $current_user_id;
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($details as &$detail) {
        $detail['work_date'] = gregorian_to_jalali_format($detail['work_date']);
    }

    header('Content-Type: application/json');
    echo json_encode($details);
} catch (Exception $e) {
    error_log("Error in load_work_details.php: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'خطای سرور']);
}
?>