<?php
if(session_status() === PHP_SESSION_NONE || session_status() !== PHP_SESSION_ACTIVE) {session_start();}
include_once 'db/db.php';

if (isset($_SESSION['user_id'])) {
    log_activity($_SESSION['user_id'], 'logout');
}
session_destroy();
header('Location: login.php');
exit();
?>
