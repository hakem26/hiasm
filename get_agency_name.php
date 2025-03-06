<?php
// [BLOCK-GET-AGENCY-NAME-001]
require_once 'db.php';

if (isset($_GET['agency_id'])) {
    $agency_id = $_GET['agency_id'];
    try {
        $stmt = $pdo->prepare("SELECT full_name FROM Users WHERE user_id IN (SELECT user_id1 FROM Partners WHERE partner_id = ?)");
        $stmt->execute([$agency_id]);
        $agency_name = $stmt->fetchColumn();
        echo $agency_name ?: '';
    } catch (PDOException $e) {
        echo '';
    }
}
?>