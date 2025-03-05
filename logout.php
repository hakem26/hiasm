<?php
// [BLOCK-LOGOUT-001]
session_start();
session_destroy();
header("Location: index.php");
exit;
?>