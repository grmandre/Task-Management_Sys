<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include_once 'db/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT t.id, t.parent_id, t.title, t.priority, t.start_date, t.due_date, t.due_time, t.status, t.created_at,
           t.repeat_type, t.remind_at,
           (SELECT COUNT(*) FROM tbl_task_checklist c WHERE c.task_id = t.id)                  AS items_total,
           (SELECT COUNT(*) FROM tbl_task_checklist c WHERE c.task_id = t.id AND c.is_done = 1) AS items_done,
           (SELECT COUNT(*) FROM tbl_task_notes n WHERE n.task_id = t.id)                      AS notes
    FROM tbl_tasks t
    WHERE t.user_id = ? AND t.is_deleted = 0
    ORDER BY t.created_at DESC, t.id DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="fw-bold mb-0">My Tasks</h4>
            <small class="text-muted"><?= $result->num_rows ?> task<?= $result->num_rows === 1 ? '' : 's' ?></small>
        </div>
        <a href="main.php?page=tasks_create" class="btn btn-dark">
            <i class="lni lni-file-plus-circle me-1"></i>
            Create Task
        </a>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">

            <?php if ($result->num_rows === 0): ?>
                <div class="p-4 text-center text-muted">
                    No tasks found. <a href="main.php?page=tasks_create" class="text-dark fw-semibold">Create one</a>.
                </div>
            <?php else: ?>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-stack">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Title</th>
                                <th>Priority</th>
                                <th>Start Date</th>
                                <th>Due</th>
                                <th>Status</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>

                        <?php while ($task = $result->fetch_assoc()):
                            $priorityClass = match($task['priority']) {
                                'High' => 'bg-danger',
                                'Medium' => 'bg-warning text-dark',
                                'Low' => 'bg-secondary',
                                default => 'bg-secondary'
                            };
                            $statusClass = match($task['status']) {
                                'Completed'   => 'bg-success',
                                'In Progress' => 'bg-warning text-dark',
                                'Archived'    => 'bg-secondary',
                                default       => 'bg-dark',
                            };
                            $due_ts  = $task['due_date'] ? strtotime($task['due_date'] . ' ' . ($task['due_time'] ?: '23:59:59')) : null;
                            $overdue = $due_ts && $due_ts < time()
                                       && !in_array($task['status'], ['Completed', 'Archived']);
                            $is_series = !$task['parent_id'] && $task['repeat_type'] !== 'none';
                        ?>
                            <tr>
                                <td class="ps-4" style="max-width:300px;">
                                    <a href="main.php?page=task_view&id=<?= $task['id'] ?>"
                                       class="d-block text-truncate fw-semibold text-dark"
                                       title="<?= htmlspecialchars($task['title']) ?>">
                                        <?= htmlspecialchars($task['title']) ?>
                                    </a>
                                    <small class="text-muted d-flex flex-wrap gap-2">
                                        <span>Added <?= date('M j, Y', strtotime($task['created_at'])) ?></span>
                                        <?php if ($is_series || $task['parent_id']): ?>
                                            <span title="Repeating task"><i class="lni lni-refresh-circle-1-clockwise"></i> Repeats</span>
                                        <?php endif; ?>
                                        <?php if ($task['items_total'] > 0): ?>
                                            <span title="Checklist"><i class="lni lni-check-square-2"></i> <?= $task['items_done'] ?>/<?= $task['items_total'] ?></span>
                                        <?php endif; ?>
                                        <?php if ($task['notes'] > 0): ?>
                                            <span title="Notes"><i class="lni lni-comment-1-text"></i> <?= $task['notes'] ?></span>
                                        <?php endif; ?>
                                        <?php if ($task['remind_at']): ?>
                                            <span title="Reminder set"><i class="lni lni-bell-1"></i></span>
                                        <?php endif; ?>
                                    </small>
                                </td>

                                <td data-label="Priority">
                                    <span class="badge <?= $priorityClass ?>">
                                        <?= htmlspecialchars($task['priority']) ?>
                                    </span>
                                </td>

                                <td data-label="Start Date">
                                    <?= $task['start_date']
                                        ? '<span class="text-muted">' . date('M d, Y', strtotime($task['start_date'])) . '</span>'
                                        : '<span class="text-muted">—</span>' ?>
                                </td>

                                <td data-label="Due">
                                    <?php if ($task['due_date']): ?>
                                        <span class="<?= $overdue ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                            <?= date('M d, Y', strtotime($task['due_date'])) ?>
                                            <?= $overdue ? ' <i class="lni lni-alarm-1 text-danger" title="Overdue"></i>' : '' ?>
                                            <?php if ($task['due_time']): ?>
                                                <br><small><?= date('g:i A', strtotime($task['due_time'])) ?></small>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td data-label="Status">
                                    <span class="badge <?= $statusClass ?>">
                                        <?= htmlspecialchars($task['status'] ?? 'Pending') ?>
                                    </span>
                                </td>

                                <td class="text-end pe-4 text-nowrap cell-actions">
                                    <a href="main.php?page=tasks_edit&id=<?= $task['id'] ?>"
                                       class="btn btn-sm btn-outline-dark">
                                        Edit
                                    </a>

                                    <a href="main.php?page=tasks_create&from=<?= $task['id'] ?>"
                                       class="btn btn-sm btn-outline-dark" title="Duplicate">
                                        Duplicate
                                    </a>

                                    <button type="button"
                                            class="btn btn-sm btn-outline-danger"
                                            onclick='DELETE_TASK(<?= (int)$task['id'] ?>, <?= htmlspecialchars(json_encode($task['title']), ENT_QUOTES) ?>, <?= $is_series ? 'true' : 'false' ?>)'>
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>

                        </tbody>
                    </table>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<script src="pages/create/script.js"></script>
