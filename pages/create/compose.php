<?php
if (session_status() === PHP_SESSION_NONE){
    session_start();
}
include_once '../../db/db.php';
include_once '../../includes/tasks.php';
header('Content-Type: application/json');

function respond($status, $message, $extra = []) {
    echo json_encode(['status' => $status, 'message' => $message] + $extra);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    respond('error', 'Your session has expired. Please log in again.');
}

$USER_ID = (int)$_SESSION['user_id'];
$ACTION  = intval($_POST['ACTION'] ?? 0);
$TASK_ID = intval($_POST['TASK_ID'] ?? 0);

// Load one of the current user's (non-deleted) tasks, or stop with "not found".
function own_task($task_id, $user_id) {
    $stmt = $GLOBALS['db']->prepare('SELECT * FROM tbl_tasks WHERE id = ? AND user_id = ? AND is_deleted = 0');
    $stmt->execute([$task_id, $user_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) {
        respond('error', 'Task not found.');
    }
    return $task;
}

function save_template($user_id, $name, $fields, $checklist_titles) {
    $stmt = $GLOBALS['db']->prepare('
        INSERT INTO tbl_task_templates (user_id, name, title, description, priority, due_time, remind_before, repeat_type, repeat_days, checklist)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $user_id, mb_substr($name, 0, 100), $fields['title'], $fields['description'], $fields['priority'],
        $fields['due_time'], $fields['remind_before'], $fields['repeat_type'], $fields['repeat_days'],
        $checklist_titles ? json_encode(array_values($checklist_titles)) : null,
    ]);
    log_activity($user_id, 'template_saved', $GLOBALS['db']->lastInsertId());
}

//create task (1) / edit task (2)

if ($ACTION == 1 || $ACTION == 2) {
    $TITLE    = trim($_POST['TITLE'] ?? '');
    $DESC     = trim($_POST['DESCRIPTION'] ?? '');
    $PRIORITY = trim($_POST['PRIORITY'] ?? '');
    $STATUS   = trim($_POST['STATUS'] ?? '');
    $PUB      = trim($_POST['PUBLISH'] ?? '');
    $EXP      = trim($_POST['EXPIRY'] ?? '');
    $DUE_TIME = trim($_POST['DUE_TIME'] ?? '');
    $REMIND   = trim($_POST['REMIND_BEFORE'] ?? '');
    $REPEAT   = trim($_POST['REPEAT_TYPE'] ?? 'none');
    $R_DAYS   = trim($_POST['REPEAT_DAYS'] ?? '');
    $R_UNTIL  = trim($_POST['REPEAT_UNTIL'] ?? '');
    $CHECKLIST = json_decode($_POST['CHECKLIST'] ?? '[]', true);

    $EXISTING = $ACTION == 2 ? own_task($TASK_ID, $USER_ID) : null;

    if ($TITLE === '') {
        respond('error', 'Task title cannot be empty.');
    }

    if (mb_strlen($TITLE) > 150) {
        respond('error', 'Task title must be 150 characters or fewer.');
    }

    if (!in_array($PRIORITY, ['Low', 'Medium', 'High'], true)) {
        respond('error', 'Please assign a valid priority value.');
    }

    foreach ([$PUB, $EXP, $R_UNTIL] as $date) {
        if ($date !== '' && !DateTime::createFromFormat('!Y-m-d', $date)) {
            respond('error', 'Invalid date format.');
        }
    }

    if ($PUB !== '' && $EXP !== '' && $PUB > $EXP) {
        respond('error', 'Start date cannot be later than the due date.');
    }

    if ($DUE_TIME !== '') {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $DUE_TIME)) {
            respond('error', 'Invalid due time.');
        }
        if ($EXP === '') {
            respond('error', 'Set a due date to use a due time.');
        }
    }

    if ($REMIND !== '') {
        if (!array_key_exists((int)$REMIND, REMINDER_OPTIONS) || !ctype_digit($REMIND)) {
            respond('error', 'Please choose a valid reminder.');
        }
        if ($EXP === '') {
            respond('error', 'Set a due date to use a reminder.');
        }
    }

    // An occurrence of a recurring series can't itself repeat — the series does that.
    if ($EXISTING && $EXISTING['parent_id']) {
        $REPEAT = 'none';
    }
    $rule = normalize_repeat($REPEAT, $R_DAYS);
    if (is_string($rule)) {
        respond('error', $rule);
    }
    [$REPEAT, $R_DAYS] = $rule;

    if ($REPEAT === 'none') {
        $R_UNTIL = '';
    }

    // First occurrence of a series is the task itself — on its due date, start date or today.
    $SERIES_START = $EXP ?: ($PUB ?: date('Y-m-d'));
    if ($R_UNTIL !== '' && $R_UNTIL < $SERIES_START) {
        respond('error', '"Repeat until" cannot be earlier than the task\'s own date.');
    }

    if (!is_array($CHECKLIST) || count($CHECKLIST) > 50) {
        respond('error', 'A checklist can have up to 50 items.');
    }
    $ITEMS = [];
    foreach ($CHECKLIST as $i => $item) {
        $t = trim((string)($item['title'] ?? ''));
        if ($t === '') {
            continue;
        }
        if (mb_strlen($t) > 255) {
            respond('error', 'Checklist items must be 255 characters or fewer.');
        }
        $ITEMS[] = ['id' => (int)($item['id'] ?? 0), 'title' => $t, 'done' => !empty($item['done']) ? 1 : 0, 'position' => count($ITEMS)];
    }

    $FIELDS = [
        'title'         => $TITLE,
        'description'   => $DESC !== '' ? $DESC : null,
        'priority'      => $PRIORITY,
        'due_time'      => $DUE_TIME !== '' ? $DUE_TIME : null,
        'remind_before' => $REMIND !== '' ? (int)$REMIND : null,
        'repeat_type'   => $REPEAT,
        'repeat_days'   => $R_DAYS,
    ];
    $REMIND_AT = compute_remind_at($EXP ?: null, $FIELDS['due_time'], $FIELDS['remind_before']);

    $db = $GLOBALS['db'];
    $db->beginTransaction();

    if ($ACTION == 1) {
        $stmt = $db->prepare('
            INSERT INTO tbl_tasks (user_id, title, description, priority, start_date, due_date, due_time,
                                   remind_before, remind_at, repeat_type, repeat_days, repeat_until, last_generated)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $USER_ID, $TITLE, $FIELDS['description'], $PRIORITY, $PUB ?: null, $EXP ?: null, $FIELDS['due_time'],
            $FIELDS['remind_before'], $REMIND_AT, $REPEAT, $R_DAYS, $R_UNTIL ?: null,
            $REPEAT !== 'none' ? $SERIES_START : null,
        ]);
        $TASK_ID = (int)$db->lastInsertId();
    } else {
        if (!in_array($STATUS, ['Pending', 'In Progress', 'Completed', 'Archived'], true)) {
            $db->rollBack();
            respond('error', 'Please select a valid status.');
        }

        // Turning repeat on for an existing task starts the series from today (no back-filling).
        $LAST_GEN = $EXISTING['last_generated'];
        if ($REPEAT === 'none') {
            $LAST_GEN = null;
        } elseif (!$LAST_GEN) {
            $LAST_GEN = max($SERIES_START, date('Y-m-d'));
        }

        // completed_at is stamped when a task first becomes Completed and cleared if it is reopened.
        $stmt = $db->prepare("
            UPDATE tbl_tasks
            SET title = ?, description = ?, priority = ?, start_date = ?, due_date = ?, due_time = ?,
                status = ?, completed_at = CASE WHEN ? = 'Completed' THEN COALESCE(completed_at, NOW()) ELSE NULL END,
                remind_before = ?, remind_at = ?, reminder_seen = IF(remind_at <=> ?, reminder_seen, 0),
                repeat_type = ?, repeat_days = ?, repeat_until = ?, last_generated = ?
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([
            $TITLE, $FIELDS['description'], $PRIORITY, $PUB ?: null, $EXP ?: null, $FIELDS['due_time'],
            $STATUS, $STATUS,
            $FIELDS['remind_before'], $REMIND_AT, $REMIND_AT,
            $REPEAT, $R_DAYS, $R_UNTIL ?: null, $LAST_GEN,
            $TASK_ID, $USER_ID,
        ]);
    }

    // ── Sync checklist: update kept items, insert new ones, delete removed ones ──
    // (only when the form sent a checklist — a missing field must not wipe the items)
    if (isset($_POST['CHECKLIST'])) {
        $cur = $db->prepare('SELECT id FROM tbl_task_checklist WHERE task_id = ?');
        $cur->execute([$TASK_ID]);
        $current_ids = array_map('intval', $cur->fetchAll(PDO::FETCH_COLUMN));

        $keep = [];
        foreach ($ITEMS as $item) {
            if (in_array($item['id'], $current_ids, true)) {
                $upd = $db->prepare('UPDATE tbl_task_checklist SET title = ?, is_done = ?, position = ? WHERE id = ?');
                $upd->execute([$item['title'], $item['done'], $item['position'], $item['id']]);
                $keep[] = $item['id'];
            } else {
                $ins = $db->prepare('INSERT INTO tbl_task_checklist (task_id, title, is_done, position) VALUES (?, ?, ?, ?)');
                $ins->execute([$TASK_ID, $item['title'], $item['done'], $item['position']]);
                $keep[] = (int)$db->lastInsertId();
            }
        }
        $del = $db->prepare('DELETE FROM tbl_task_checklist WHERE task_id = ?' . ($keep ? ' AND id NOT IN (' . implode(',', $keep) . ')' : ''));
        $del->execute([$TASK_ID]);
    }

    if (!empty($_POST['SAVE_TEMPLATE'])) {
        $name = trim($_POST['TEMPLATE_NAME'] ?? '') ?: $TITLE;
        save_template($USER_ID, $name, $FIELDS, array_column($ITEMS, 'title'));
    }

    $db->commit();

    log_activity($USER_ID, $ACTION == 1 ? 'task_created' : 'task_updated', $TASK_ID);
    respond('success', $ACTION == 1 ? 'Task created.' : 'Task updated.', ['task_id' => $TASK_ID]);
}

//delete task (soft delete) — deleting the first task of a recurring series also stops the series

if ($ACTION == 3) {
    own_task($TASK_ID, $USER_ID);
    $stmt = $GLOBALS['db']->prepare('UPDATE tbl_tasks SET is_deleted = 1 WHERE id = ? AND user_id = ?');
    $stmt->execute([$TASK_ID, $USER_ID]);

    log_activity($USER_ID, 'task_deleted', $TASK_ID);
    respond('success', 'Task deleted.');
}

//toggle checklist item (4)

if ($ACTION == 4) {
    $ITEM_ID = intval($_POST['ITEM_ID'] ?? 0);
    $DONE    = !empty($_POST['DONE']) ? 1 : 0;

    $stmt = $GLOBALS['db']->prepare('
        UPDATE tbl_task_checklist c JOIN tbl_tasks t ON t.id = c.task_id
        SET c.is_done = ?
        WHERE c.id = ? AND t.user_id = ? AND t.is_deleted = 0
    ');
    $stmt->execute([$DONE, $ITEM_ID, $USER_ID]);

    $count = $GLOBALS['db']->prepare('
        SELECT COUNT(*) AS total, COALESCE(SUM(is_done), 0) AS done
        FROM tbl_task_checklist WHERE task_id = (SELECT task_id FROM tbl_task_checklist WHERE id = ?)
    ');
    $count->execute([$ITEM_ID]);
    respond('success', 'Checklist updated.', $count->fetch(PDO::FETCH_ASSOC));
}

//add checklist item from the task page (5)

if ($ACTION == 5) {
    own_task($TASK_ID, $USER_ID);
    $TITLE = trim($_POST['TITLE'] ?? '');
    if ($TITLE === '' || mb_strlen($TITLE) > 255) {
        respond('error', 'Checklist items must be 1–255 characters.');
    }
    $db  = $GLOBALS['db'];
    $pos = (int)$db->query('SELECT COALESCE(MAX(position) + 1, 0) FROM tbl_task_checklist WHERE task_id = ' . $TASK_ID)->fetchColumn();
    $db->prepare('INSERT INTO tbl_task_checklist (task_id, title, position) VALUES (?, ?, ?)')->execute([$TASK_ID, $TITLE, $pos]);
    respond('success', 'Item added.', ['item_id' => (int)$db->lastInsertId()]);
}

//add progress note (6) / delete note (7)

if ($ACTION == 6) {
    own_task($TASK_ID, $USER_ID);
    $NOTE = trim($_POST['NOTE'] ?? '');
    if ($NOTE === '') {
        respond('error', 'Note cannot be empty.');
    }
    if (mb_strlen($NOTE) > 2000) {
        respond('error', 'Notes must be 2000 characters or fewer.');
    }
    $GLOBALS['db']->prepare('INSERT INTO tbl_task_notes (task_id, user_id, note) VALUES (?, ?, ?)')->execute([$TASK_ID, $USER_ID, $NOTE]);
    log_activity($USER_ID, 'note_added', $TASK_ID);
    respond('success', 'Note added.');
}

if ($ACTION == 7) {
    $stmt = $GLOBALS['db']->prepare('DELETE FROM tbl_task_notes WHERE id = ? AND user_id = ?');
    $stmt->execute([intval($_POST['NOTE_ID'] ?? 0), $USER_ID]);
    if (!$stmt->rowCount()) {
        respond('error', 'Note not found.');
    }
    respond('success', 'Note deleted.');
}

//save an existing task as a template (8) / delete template (9)

if ($ACTION == 8) {
    $task = own_task($TASK_ID, $USER_ID);
    $name = trim($_POST['TEMPLATE_NAME'] ?? '') ?: $task['title'];

    // An occurrence carries no rule of its own — use its series' rule.
    $rule_src = $task;
    if ($task['parent_id']) {
        $p = $GLOBALS['db']->prepare('SELECT repeat_type, repeat_days FROM tbl_tasks WHERE id = ?');
        $p->execute([$task['parent_id']]);
        $rule_src = $p->fetch(PDO::FETCH_ASSOC) ?: $task;
    }

    $items = $GLOBALS['db']->prepare('SELECT title FROM tbl_task_checklist WHERE task_id = ? ORDER BY position, id');
    $items->execute([$TASK_ID]);

    save_template($USER_ID, $name, [
        'title' => $task['title'], 'description' => $task['description'], 'priority' => $task['priority'],
        'due_time' => $task['due_time'], 'remind_before' => $task['remind_before'],
        'repeat_type' => $rule_src['repeat_type'], 'repeat_days' => $rule_src['repeat_days'],
    ], $items->fetchAll(PDO::FETCH_COLUMN));
    respond('success', 'Saved as template "' . $name . '".');
}

if ($ACTION == 9) {
    $stmt = $GLOBALS['db']->prepare('DELETE FROM tbl_task_templates WHERE id = ? AND user_id = ?');
    $stmt->execute([intval($_POST['TEMPLATE_ID'] ?? 0), $USER_ID]);
    if (!$stmt->rowCount()) {
        respond('error', 'Template not found.');
    }
    respond('success', 'Template deleted.');
}

respond('error', 'Invalid action.');
