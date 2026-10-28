<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once 'db/db.php';
include_once 'includes/tasks.php';

// One form serves:
//   Create Task        main.php?page=tasks_create            (optionally &due=YYYY-MM-DD from the calendar)
//   Duplicate a task   main.php?page=tasks_create&from=ID
//   From a template    main.php?page=tasks_create&template=ID
//   Edit Task          main.php?page=tasks_edit&id=ID
$USER_ID  = (int)$_SESSION['user_id'];
$IS_EDIT  = ($_GET['page'] ?? '') === 'tasks_edit';
$FROM_ID  = intval($_GET['from'] ?? 0);
$TPL_ID   = intval($_GET['template'] ?? 0);
$task     = null;   // values used to prefill the form
$items    = [];     // checklist [{id, title, done}]
$source   = null;   // what the form was prefilled from, shown in the header
$not_found = false;

$load_task = function ($id) use ($USER_ID) {
    $stmt = $GLOBALS['db']->prepare('SELECT * FROM tbl_tasks WHERE id = ? AND user_id = ? AND is_deleted = 0');
    $stmt->execute([$id, $USER_ID]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};
$load_items = function ($id) {
    $stmt = $GLOBALS['db']->prepare('SELECT id, title, is_done AS done FROM tbl_task_checklist WHERE task_id = ? ORDER BY position, id');
    $stmt->execute([$id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

if ($IS_EDIT) {
    $task = $load_task(intval($_GET['id'] ?? 0));
    $not_found = !$task;
    if ($task) {
        $items = $load_items($task['id']);
    }
} elseif ($FROM_ID) {
    // Duplicate: same details and checklist (unchecked), fresh dates
    $orig = $load_task($FROM_ID);
    if ($orig) {
        $task = $orig;
        $task['title'] = $orig['title'];
        $task['start_date'] = date('Y-m-d');
        $task['due_date'] = '';
        $task['repeat_until'] = '';
        if ($orig['parent_id']) {
            // An occurrence: copy its series' repeat rule
            $root = $load_task($orig['parent_id']);
            $task['repeat_type'] = $root['repeat_type'] ?? 'none';
            $task['repeat_days'] = $root['repeat_days'] ?? null;
        }
        $items = array_map(fn($i) => ['id' => 0, 'title' => $i['title'], 'done' => 0], $load_items($FROM_ID));
        $source = 'Duplicating “' . $orig['title'] . '”';
    }
} elseif ($TPL_ID) {
    $stmt = $GLOBALS['db']->prepare('SELECT * FROM tbl_task_templates WHERE id = ? AND user_id = ?');
    $stmt->execute([$TPL_ID, $USER_ID]);
    $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tpl) {
        $task = $tpl + ['start_date' => date('Y-m-d'), 'due_date' => '', 'repeat_until' => ''];
        $items = array_map(fn($t) => ['id' => 0, 'title' => $t, 'done' => 0], json_decode($tpl['checklist'] ?? '[]', true) ?: []);
        $source = 'From template “' . $tpl['name'] . '”';
    }
}

$templates = [];
if (!$IS_EDIT) {
    $stmt = $GLOBALS['db']->prepare('SELECT id, name FROM tbl_task_templates WHERE user_id = ? ORDER BY name');
    $stmt->execute([$USER_ID]);
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calendar "Add Task" links pass ?due=YYYY-MM-DD
$prefill_due = $_GET['due'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefill_due)) {
    $prefill_due = '';
}

$IS_OCCURRENCE = $IS_EDIT && $task && $task['parent_id'];
$val         = fn($key, $default = '') => htmlspecialchars((string)($task[$key] ?? $default));
$repeat_type = $task['repeat_type'] ?? 'none';
$repeat_days = array_map('intval', array_filter(explode(',', (string)($task['repeat_days'] ?? '')), 'strlen'));
$due_time    = isset($task['due_time']) && $task['due_time'] ? substr($task['due_time'], 0, 5) : '';
$remind      = $task['remind_before'] ?? null;
?>

<div class="container-fluid py-4 bg-light">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="fw-bold mb-0"><?= $IS_EDIT ? 'Edit Task' : 'Create Task' ?></h4>
                    <small class="text-muted">
                        <?php if ($source): ?>
                            <?= htmlspecialchars($source) ?>
                        <?php else: ?>
                            <?= $IS_EDIT ? 'Update the details of this task' : 'Fill in the details to create a new task' ?>
                        <?php endif; ?>
                    </small>
                </div>
                <span class="badge bg-dark px-3 py-2">
                    <?= htmlspecialchars($_SESSION['username'] ?? '') ?>
                </span>
            </div>

            <?php if ($not_found): ?>

            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-5 text-center">
                    <i class="lni lni-search-1 fs-1 text-muted"></i>
                    <h6 class="fw-bold mt-3">Task not found</h6>
                    <p class="text-muted small">It may have been deleted, or it belongs to another user.</p>
                    <a href="main.php?page=task" class="btn btn-dark btn-sm px-4">Back to Tasks</a>
                </div>
            </div>

            <?php else: ?>

            <?php if (!$IS_EDIT && $templates): ?>
            <!-- Start from a template -->
            <div class="card shadow-sm border-0 rounded-4 mb-3">
                <div class="card-body p-3 d-flex flex-wrap align-items-center gap-2">
                    <i class="lni lni-layers-1 fs-5"></i>
                    <span class="small fw-semibold me-1">Start from a template</span>
                    <select class="form-select form-select-sm" style="max-width:260px;" aria-label="Template"
                            onchange="if (this.value) window.location.href = 'main.php?page=tasks_create&template=' + this.value;">
                        <option value="">Choose a template…</option>
                        <?php foreach ($templates as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === $TPL_ID ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($TPL_ID && $source): ?>
                        <button type="button" class="btn btn-sm btn-link text-danger ms-auto" onclick="DELETE_TEMPLATE(<?= $TPL_ID ?>);">
                            Delete this template
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-4">

                    <form id="TASK_FORM" onsubmit="SAVE_TASK(); return false;">
                        <input type="hidden" id="TASK_ID" value="<?= $IS_EDIT ? (int)$task['id'] : 0 ?>">

                        <h6 class="fw-bold mb-3 text-uppercase text-muted">Task Details</h6>

                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Task Title <span class="text-danger">*</span></label>
                                <input type="text"
                                       name="title"
                                       id="TITLE"
                                       class="form-control"
                                       placeholder="Enter task title"
                                       maxlength="150"
                                       value="<?= $val('title') ?>"
                                       required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Priority</label>
                                <select name="priority" class="form-select"
                                id="PRIORITY">
                                    <?php foreach (['Low', 'Medium', 'High'] as $p): ?>
                                        <option value="<?= $p ?>" <?= ($task['priority'] ?? 'Medium') === $p ? 'selected' : '' ?>><?= $p ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <?php if ($IS_EDIT): ?>
                                <label class="form-label small fw-semibold">Status</label>
                                <select name="status" class="form-select" id="STATUS">
                                    <?php foreach (['Pending', 'In Progress', 'Completed', 'Archived'] as $s): ?>
                                        <option value="<?= $s ?>" <?= $task['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Start Date</label>
                                <input type="date"
                                       name="start_date"
                                       class="form-control"
                                       id="PUBLISH"
                                       value="<?= $task ? $val('start_date') : date('Y-m-d') ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Due Date</label>
                                <input type="date"
                                       name="due_date"
                                       class="form-control"
                                       id="EXPIRY"
                                       oninput="SYNC_DUE_FIELDS();"
                                       value="<?= $task ? $val('due_date') : $prefill_due ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Due Time</label>
                                <input type="time"
                                       name="due_time"
                                       class="form-control"
                                       id="DUE_TIME"
                                       value="<?= $due_time ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Reminder</label>
                                <select id="REMIND_BEFORE" class="form-select">
                                    <option value="">No reminder</option>
                                    <?php foreach (REMINDER_OPTIONS as $min => $label): ?>
                                        <option value="<?= $min ?>" <?= $remind !== null && (int)$remind === $min ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted" id="REMIND_HINT">Needs a due date. Without a due time, reminders use 9:00 AM.</small>
                            </div>
                        </div>

                        <hr>

                        <!-- Repeat -->
                        <h6 class="fw-bold mb-3 text-uppercase text-muted">Repeat</h6>

                        <?php if ($IS_OCCURRENCE): ?>
                            <div class="bg-light p-3 rounded-3 mb-4 d-flex align-items-center gap-2 small">
                                <i class="lni lni-refresh-circle-1-clockwise fs-5"></i>
                                <span class="flex-grow-1">This task is one occurrence of a recurring series. Change how often it repeats on the series itself.</span>
                                <a href="main.php?page=tasks_edit&id=<?= (int)$task['parent_id'] ?>" class="btn btn-sm btn-outline-dark text-nowrap">Edit series</a>
                            </div>
                            <input type="hidden" id="REPEAT_TYPE" value="none">
                        <?php else: ?>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Repeats</label>
                                <select id="REPEAT_TYPE" class="form-select" onchange="SYNC_REPEAT_FIELDS();">
                                    <?php foreach (['none' => 'Does not repeat', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $k => $label): ?>
                                        <option value="<?= $k ?>" <?= $repeat_type === $k ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 repeat-only">
                                <label class="form-label small fw-semibold">Repeat until <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="date" id="REPEAT_UNTIL" class="form-control" value="<?= $val('repeat_until') ?>">
                            </div>

                            <div class="col-12 repeat-weekly">
                                <label class="form-label small fw-semibold d-block">On these days</label>
                                <div class="d-flex flex-wrap gap-1" id="WEEKDAY_PICKER">
                                    <?php foreach (WEEKDAYS as $n => $name): ?>
                                        <input type="checkbox" class="btn-check" id="WD_<?= $n ?>" value="<?= $n ?>" autocomplete="off"
                                            <?= $repeat_type === 'weekly' && in_array($n, $repeat_days, true) ? 'checked' : '' ?>>
                                        <label class="btn btn-sm btn-outline-dark day-chip" for="WD_<?= $n ?>"><?= $name ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="col-12 repeat-monthly">
                                <label class="form-label small fw-semibold d-block">On these days of the month</label>
                                <div class="month-grid" id="MONTHDAY_PICKER">
                                    <?php for ($d = 1; $d <= 31; $d++): ?>
                                        <input type="checkbox" class="btn-check" id="MD_<?= $d ?>" value="<?= $d ?>" autocomplete="off"
                                            <?= $repeat_type === 'monthly' && in_array($d, $repeat_days, true) ? 'checked' : '' ?>>
                                        <label class="btn btn-sm btn-outline-dark" for="MD_<?= $d ?>"><?= $d ?></label>
                                    <?php endfor; ?>
                                </div>
                                <small class="text-muted">In months that are shorter, days 29–31 fall on the month's last day.</small>
                            </div>

                            <div class="col-12 repeat-only">
                                <small class="text-muted">
                                    <i class="lni lni-refresh-circle-1-clockwise me-1"></i>
                                    A new copy of this task (with a fresh checklist) is added on each repeat day.
                                </small>
                            </div>
                        </div>
                        <?php endif; ?>

                        <hr>

                        <!-- Checklist -->
                        <h6 class="fw-bold mb-3 text-uppercase text-muted">Checklist</h6>
                        <div id="CHECKLIST" class="mb-2">
                            <?php foreach ($items as $item): ?>
                                <div class="checklist-row d-flex align-items-center gap-2 mb-2" data-id="<?= (int)$item['id'] ?>">
                                    <input type="checkbox" class="form-check-input mt-0 item-done" <?= $item['done'] ? 'checked' : '' ?> aria-label="Done">
                                    <input type="text" class="form-control form-control-sm item-title" maxlength="255" value="<?= htmlspecialchars($item['title']) ?>">
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.parentElement.remove();" aria-label="Remove item">
                                        <i class="lni lni-trash-3"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="input-group input-group-sm mb-4">
                            <input type="text" id="NEW_ITEM" class="form-control" maxlength="255" placeholder="Add a checklist item and press Enter"
                                   onkeydown="if (event.key === 'Enter') { event.preventDefault(); ADD_CHECKLIST_ROW(); }">
                            <button type="button" class="btn btn-outline-dark" onclick="ADD_CHECKLIST_ROW();">
                                <i class="lni lni-plus"></i> Add
                            </button>
                        </div>

                        <hr>

                        <div class="bg-light p-3 rounded-3 mb-4">
                            <h6 class="fw-bold mb-3">Description</h6>
                            <label class="form-label small">Details (optional)</label>
                            <textarea name="description"
                                      class="form-control"
                                      rows="4"
                                      id="DESCRIPTION"
                                      placeholder="Task description (optional)"><?= $val('description') ?></textarea>
                        </div>

                        <?php if (!$IS_EDIT): ?>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" id="SAVE_TEMPLATE"
                                       onchange="document.getElementById('TEMPLATE_NAME').classList.toggle('d-none', !this.checked);">
                                <label class="form-check-label small" for="SAVE_TEMPLATE">Also save as a template</label>
                            </div>
                            <input type="text" id="TEMPLATE_NAME" class="form-control form-control-sm d-none" style="max-width:260px;"
                                   maxlength="100" placeholder="Template name (defaults to title)">
                        </div>
                        <?php endif; ?>

                        <div class="d-grid gap-2">
                            <button type="submit"
                                    class="btn btn-dark py-2 fw-semibold rounded-3 shadow-sm"
                                    id="CRTE_BTN">
                                <?= $IS_EDIT ? 'Save Changes' : 'Create Task' ?>
                            </button>
                            <a href="<?= $IS_EDIT ? 'main.php?page=task_view&id=' . (int)$task['id'] : 'main.php?page=task' ?>"
                                id="CANCEL_BTN"
                               class="btn btn-outline-dark py-2 fw-semibold rounded-3">
                               Cancel
                         </a>
                        </div>

                    </form>

                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<style>
.day-chip { min-width: 52px; }
.month-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; max-width: 380px; }
.month-grid .btn { padding: .25rem 0; }
</style>

<script src="pages/create/script.js"></script>
<script>
    // jQuery loads at the end of main.php, so wait for the page to finish
    document.addEventListener("DOMContentLoaded", () => {
        SYNC_REPEAT_FIELDS();
        SYNC_DUE_FIELDS();
    });
</script>
