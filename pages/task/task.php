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
    SELECT id, title, priority, start_date, due_date, status, created_at
    FROM tbl_tasks
    WHERE user_id = ? AND is_deleted = 0
    ORDER BY created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
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
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Title</th>
                                <th>Priority</th>
                                <th>Start Date</th>
                                <th>Due Date</th>
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
                            $overdue = $task['due_date'] && $task['due_date'] < date('Y-m-d')
                                       && !in_array($task['status'], ['Completed', 'Archived']);
                        ?>
                            <tr>
                                <td class="ps-4 fw-semibold" style="max-width:260px;">
                                    <span class="d-block text-truncate" title="<?= htmlspecialchars($task['title']) ?>">
                                        <?= htmlspecialchars($task['title']) ?>
                                    </span>
                                    <small class="text-muted fw-normal">
                                        Added <?= date('M j, Y', strtotime($task['created_at'])) ?>
                                    </small>
                                </td>

                                <td>
                                    <span class="badge <?= $priorityClass ?>">
                                        <?= htmlspecialchars($task['priority']) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= $task['start_date']
                                        ? '<span class="text-muted">' . date('M d, Y', strtotime($task['start_date'])) . '</span>'
                                        : '<span class="text-muted">—</span>' ?>
                                </td>

                                <td>
                                    <?php if ($task['due_date']): ?>
                                        <span class="<?= $overdue ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                            <?= date('M d, Y', strtotime($task['due_date'])) ?>
                                            <?= $overdue ? ' <i class="lni lni-alarm-1 text-danger" title="Overdue"></i>' : '' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="badge <?= $statusClass ?>">
                                        <?= htmlspecialchars($task['status'] ?? 'Pending') ?>
                                    </span>
                                </td>

                                <td class="text-end pe-4 text-nowrap">
                                    <a href="main.php?page=tasks_edit&id=<?= $task['id'] ?>"
                                       class="btn btn-sm btn-outline-dark">
                                        Edit
                                    </a>

                                    <button type="button"
                                            class="btn btn-sm btn-outline-danger"
                                            onclick='DELETE_TASK(<?= (int)$task['id'] ?>, <?= htmlspecialchars(json_encode($task['title']), ENT_QUOTES) ?>)'>
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
