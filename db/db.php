<?php

date_default_timezone_set('Asia/Manila');

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'inv_test';
$db_port = "3306";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


$GLOBALS['db'] = new PDO(
"mysql:host=$db_host;port=$db_port;dbname=$db_name", $db_user, $db_pass, 
[
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'"
]
);

// Record a user action in tbl_activity_logs (e.g. 'task_created', 'login').
function log_activity($user_id, $action, $reference_id = null) {
    $stmt = $GLOBALS['db']->prepare('INSERT INTO tbl_activity_logs (user_id, action, reference_id) VALUES (?, ?, ?)');
    $stmt->execute([$user_id, $action, $reference_id]);
}

// Queue a SweetAlert message that main.php shows on the next page load.
function set_flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

?>
