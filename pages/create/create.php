<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once 'db/db.php';

// Same form serves "Create Task" and "Edit Task" (main.php?page=tasks_edit&id=…)
$TASK_ID = intval($_GET['id'] ?? 0);
$IS_EDIT = ($_GET['page'] ?? '') === 'tasks_edit';
$task    = null;

if ($IS_EDIT) {
    $stmt = $conn->prepare("
        SELECT id, title, description, priority, status, start_date, due_date
        FROM tbl_tasks
        WHERE id = ? AND user_id = ? AND is_deleted = 0
    ");
    $stmt->bind_param("ii", $TASK_ID, $_SESSION['user_id']);
    $stmt->execute();
    $task = $stmt->get_result()->fetch_assoc();
}

// Calendar "Add Task" links pass ?due=YYYY-MM-DD
$prefill_due = $_GET['due'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefill_due)) {
    $prefill_due = '';
}

$val = fn($key, $default = '') => htmlspecialchars($task[$key] ?? $default);
?>

<div class="container-fluid py-4 bg-light">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-7">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="fw-bold mb-0"><?= $IS_EDIT ? 'Edit Task' : 'Create Task' ?></h4>
                    <small class="text-muted">
                        <?= $IS_EDIT ? 'Update the details of this task' : 'Fill in the details to create a new task' ?>
                    </small>
                </div>
                <span class="badge bg-dark px-3 py-2">
                    <?= htmlspecialchars($_SESSION['username'] ?? '') ?>
                </span>
            </div>

            <?php if ($IS_EDIT && !$task): ?>

            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-5 text-center">
                    <i class="lni lni-search-1 fs-1 text-muted"></i>
                    <h6 class="fw-bold mt-3">Task not found</h6>
                    <p class="text-muted small">It may have been deleted, or it belongs to another user.</p>
                    <a href="main.php?page=task" class="btn btn-dark btn-sm px-4">Back to Tasks</a>
                </div>
            </div>

            <?php else: ?>

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
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Start Date</label>
                                <input type="date"
                                       name="start_date"
                                       class="form-control"
                                       id="PUBLISH"
                                       value="<?= $IS_EDIT ? $val('start_date') : date('Y-m-d') ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Due Date</label>
                                <input type="date"
                                       name="due_date"
                                       class="form-control"
                                       id="EXPIRY"
                                       value="<?= $IS_EDIT ? $val('due_date') : $prefill_due ?>">
                            </div>
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

                        <div class="d-grid gap-2">
                            <button type="submit"
                                    class="btn btn-dark py-2 fw-semibold rounded-3 shadow-sm"
                                    id="CRTE_BTN">
                                <?= $IS_EDIT ? 'Save Changes' : 'Create Task' ?>
                            </button>
                            <a href="main.php?page=task"
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

<script src="pages/create/script.js"></script>
