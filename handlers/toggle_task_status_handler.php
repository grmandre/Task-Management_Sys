<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include_once '../db/db.php';
include_once '../includes/tasks.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$task_id = (int)($_POST['task_id'] ?? 0);
$status  = $_POST['status'] ?? '';

$allowed = ['Pending', 'In Progress', 'Completed'];
if (!$task_id || !in_array($status, $allowed, true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit();
}

$stmt = $GLOBALS['db']->prepare('SELECT * FROM tbl_tasks WHERE id = ? AND user_id = ? AND is_deleted = 0');
$stmt->execute([$task_id, $user_id]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$task) {
    echo json_encode(['ok' => false, 'error' => 'Task not found.']);
    exit();
}
if (day_locked($task)) {
    echo json_encode(['ok' => false, 'error' => LOCKED_DAY_MESSAGE]);
    exit();
}

$completed_at = ($status === 'Completed') ? date('Y-m-d H:i:s') : null;

$stmt = $conn->prepare("
    UPDATE tbl_tasks
    SET status = ?, completed_at = ?
    WHERE id = ? AND user_id = ? AND is_deleted = 0
");
$stmt->bind_param("ssii", $status, $completed_at, $task_id, $user_id);
$stmt->execute();
$ok = $stmt->affected_rows > 0;

if ($ok) {
    log_activity($user_id, 'task_status_' . strtolower(str_replace(' ', '_', $status)), $task_id);
}

echo json_encode(['ok' => $ok]);
