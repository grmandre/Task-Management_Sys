<?php
if (session_status() === PHP_SESSION_NONE){
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
$TASK_ID = intval($_POST['TASK_ID'] ?? 0);

//create task (1) / edit task (2)

if ($ACTION == 1 || $ACTION == 2) {
    $TITLE    = trim($_POST['TITLE'] ?? '');
    $DESC     = trim($_POST['DESCRIPTION'] ?? '');
    $PRIORITY = trim($_POST['PRIORITY'] ?? '');
    $STATUS   = trim($_POST['STATUS'] ?? '');
    $PUB      = trim($_POST['PUBLISH'] ?? '');
    $EXP      = trim($_POST['EXPIRY'] ?? '');

    if ($TITLE === '') {
        respond('error', 'Task title cannot be empty.');
    }

    if (mb_strlen($TITLE) > 150) {
        respond('error', 'Task title must be 150 characters or fewer.');
    }

    if (!in_array($PRIORITY, ['Low', 'Medium', 'High'], true)) {
        respond('error', 'Please assign a valid priority value.');
    }

    foreach ([$PUB, $EXP] as $date) {
        if ($date !== '' && !DateTime::createFromFormat('!Y-m-d', $date)) {
            respond('error', 'Invalid date format.');
        }
    }

    if ($PUB !== '' && $EXP !== '' && $PUB > $EXP) {
        respond('error', 'Start date cannot be later than the due date.');
    }

    if ($ACTION == 1) {
        $SQL = 'INSERT INTO tbl_tasks (user_id, title, description, priority, start_date, due_date)
                VALUES (:USER_ID, :TITLE, :DESC, :PRIORITY, :PUB, :EXP)';
        $stmt = $GLOBALS['db']->prepare($SQL);
    } else {
        if (!in_array($STATUS, ['Pending', 'In Progress', 'Completed', 'Archived'], true)) {
            respond('error', 'Please select a valid status.');
        }

        // completed_at is stamped when a task first becomes Completed and cleared if it is reopened.
        $SQL = 'UPDATE tbl_tasks
                SET title = :TITLE, description = :DESC, priority = :PRIORITY,
                    start_date = :PUB, due_date = :EXP, status = :STATUS,
                    completed_at = CASE
                        WHEN :STATUS2 = \'Completed\' THEN COALESCE(completed_at, NOW())
                        ELSE NULL END
                WHERE id = :TASK_ID AND user_id = :USER_ID AND is_deleted = 0';
        $stmt = $GLOBALS['db']->prepare($SQL);
        $stmt->bindValue(':STATUS', $STATUS);
        $stmt->bindValue(':STATUS2', $STATUS);
        $stmt->bindValue(':TASK_ID', $TASK_ID, PDO::PARAM_INT);
    }

    $stmt->bindValue(':USER_ID', $USER_ID, PDO::PARAM_INT);
    $stmt->bindValue(':TITLE', $TITLE);
    $stmt->bindValue(':DESC', $DESC !== '' ? $DESC : null);
    $stmt->bindValue(':PRIORITY', $PRIORITY);
    $stmt->bindValue(':PUB', $PUB !== '' ? $PUB : null);
    $stmt->bindValue(':EXP', $EXP !== '' ? $EXP : null);
    $stmt->execute();

    if ($ACTION == 1) {
        log_activity($USER_ID, 'task_created', $GLOBALS['db']->lastInsertId());
        respond('success', 'Task created.');
    }

    if ($stmt->rowCount() === 0) {
        // rowCount is 0 both for "not found" and "nothing changed" — tell them apart.
        $chk = $GLOBALS['db']->prepare('SELECT 1 FROM tbl_tasks WHERE id = ? AND user_id = ? AND is_deleted = 0');
        $chk->execute([$TASK_ID, $USER_ID]);
        if (!$chk->fetchColumn()) {
            respond('error', 'Task not found.');
        }
    }

    log_activity($USER_ID, 'task_updated', $TASK_ID);
    respond('success', 'Task updated.');
}

//delete task (soft delete)

if ($ACTION == 3) {
    $stmt = $GLOBALS['db']->prepare('UPDATE tbl_tasks SET is_deleted = 1 WHERE id = ? AND user_id = ? AND is_deleted = 0');
    $stmt->execute([$TASK_ID, $USER_ID]);

    if ($stmt->rowCount() === 0) {
        respond('error', 'Task not found.');
    }

    log_activity($USER_ID, 'task_deleted', $TASK_ID);
    respond('success', 'Task deleted.');
}

respond('error', 'Invalid action.');
