<?php
// GET  → reminders that are due and not yet read
// POST → mark one (task_id) or all (task_id=0) as read
if (session_status() === PHP_SESSION_NONE) session_start();
include_once '../db/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit();
}

$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $task_id = (int)($_POST['task_id'] ?? 0);
    $sql = 'UPDATE tbl_tasks SET reminder_seen = 1 WHERE user_id = ? AND remind_at <= NOW()' . ($task_id ? ' AND id = ?' : '');
    $GLOBALS['db']->prepare($sql)->execute($task_id ? [$user_id, $task_id] : [$user_id]);
    echo json_encode(['ok' => true]);
    exit();
}

$stmt = $GLOBALS['db']->prepare("
    SELECT id, title, due_date, due_time, remind_at
    FROM tbl_tasks
    WHERE user_id = ? AND is_deleted = 0 AND reminder_seen = 0
      AND status IN ('Pending', 'In Progress')
      AND remind_at IS NOT NULL AND remind_at <= NOW()
    ORDER BY remind_at DESC
    LIMIT 20
");
$stmt->execute([$user_id]);

$items = array_map(function ($t) {
    $due = strtotime($t['due_date'] . ' ' . ($t['due_time'] ?: '23:59:59'));
    $when = date('M j', $due) . ($t['due_time'] ? ', ' . date('g:i A', $due) : '');
    return [
        'id'      => (int)$t['id'],
        'title'   => $t['title'],
        'due'     => ($due < time() ? 'Was due ' : 'Due ') . $when,
        'overdue' => $due < time(),
    ];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

echo json_encode(['ok' => true, 'items' => $items]);
