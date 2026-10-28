<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once '../../db/db.php';
header('Content-Type: application/json');

function respond($status, $message) {
    echo json_encode(['status' => $status, 'message' => $message]);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    respond('error', 'Your session has expired. Please log in again.');
}

$USER_ID = (int)$_SESSION['user_id'];
$ACTION  = intval($_POST['ACTION'] ?? 0);

// CHANGE PASSWORD
if ($ACTION == 1) {
    $CURRENT = $_POST['CURRENT_PASSWORD'] ?? '';
    $NEW_PW  = $_POST['NEW_PASSWORD']     ?? '';

    if ($CURRENT === '' || strlen($NEW_PW) < 6) {
        respond('error', 'Current password is required and the new password must be at least 6 characters.');
    }

    $stmt = $GLOBALS['db']->prepare('SELECT password FROM tbl_account WHERE id = ?');
    $stmt->execute([$USER_ID]);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($CURRENT, $hash)) {
        respond('error', 'Current password is incorrect.');
    }

    if (password_verify($NEW_PW, $hash)) {
        respond('error', 'New password must be different from the current one.');
    }

    $stmt = $GLOBALS['db']->prepare('UPDATE tbl_account SET password = ?, must_change_password = 0 WHERE id = ?');
    $stmt->execute([password_hash($NEW_PW, PASSWORD_DEFAULT), $USER_ID]);

    log_activity($USER_ID, 'password_changed', $USER_ID);

    if (!empty($_SESSION['must_change_password'])) {
        unset($_SESSION['must_change_password']);
        echo json_encode(['status' => 'success', 'message' => 'Password updated. You can now use the system.', 'redirect' => 'main.php']);
        exit();
    }
    respond('success', 'Password updated successfully.');
}

respond('error', 'Invalid action.');
