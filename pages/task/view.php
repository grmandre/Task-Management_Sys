<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once 'db/db.php';
include_once 'includes/tasks.php';

$USER_ID = (int)$_SESSION['user_id'];
$TASK_ID = intval($_GET['id'] ?? 0);

$stmt = $GLOBALS['db']->prepare('SELECT * FROM tbl_tasks WHERE id = ? AND user_id = ? AND is_deleted = 0');
$stmt->execute([$TASK_ID, $USER_ID]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if ($task) {
    $stmt = $GLOBALS['db']->prepare('SELECT id, title, is_done FROM tbl_task_checklist WHERE task_id = ? ORDER BY position, id');
    $stmt->execute([$TASK_ID]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $GLOBALS['db']->prepare('SELECT id, note, created_at FROM tbl_task_notes WHERE task_id = ? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$TASK_ID]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Repeat info comes from the series' first task
    $series = $task;
    if ($task['parent_id']) {
        $stmt = $GLOBALS['db']->prepare('SELECT * FROM tbl_tasks WHERE id = ? AND user_id = ?');
        $stmt->execute([$task['parent_id'], $USER_ID]);
        $series = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $series_active = $series && $series['repeat_type'] !== 'none' && !$series['is_deleted'];

    // Next date a copy will be added (looks up to ~2 months ahead)
    $next_occurrence = null;
    if ($series_active) {
        $from = max(date('Y-m-d', strtotime('+1 day')),
                    $series['last_generated'] ? date('Y-m-d', strtotime($series['last_generated'] . ' +1 day')) : '');
        for ($i = 0, $d = $from; $i < 62; $i++, $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if ($series['repeat_until'] && $d > $series['repeat_until']) break;
            if (repeat_matches($series['repeat_type'], $series['repeat_days'], $d)) { $next_occurrence = $d; break; }
        }
    }

    $done_count = count(array_filter($items, fn($i) => $i['is_done']));
    $is_closed  = in_array($task['status'], ['Completed', 'Archived'], true);
    $due_ts     = $task['due_date'] ? strtotime($task['due_date'] . ' ' . ($task['due_time'] ?: '23:59:59')) : null;
    $overdue    = $due_ts && $due_ts < time() && !$is_closed;

    $priorityClass = match($task['priority']) {
        'High' => 'bg-danger', 'Medium' => 'bg-warning text-dark', default => 'bg-secondary',
    };
    $statusClass = match($task['status']) {
        'Completed' => 'bg-success', 'In Progress' => 'bg-warning text-dark', 'Archived' => 'bg-secondary', default => 'bg-dark',
    };
    $fmt_dt = fn($ts) => date('M j, Y g:i A', strtotime($ts));
}
?>

<div class="container-fluid">

<?php if (!$task): ?>

    <div class="row justify-content-center py-4">
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-4 p-md-5 text-center">
                    <i class="lni lni-search-1 fs-1 text-muted"></i>
                    <h6 class="fw-bold mt-3">Task not found</h6>
                    <p class="text-muted small">It may have been deleted, or it belongs to another user.</p>
                    <a href="main.php?page=task" class="btn btn-dark btn-sm px-4">Back to Tasks</a>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>

    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div class="overflow-hidden">
            <small class="text-muted"><a href="main.php?page=task" class="text-muted">Tasks</a> / Task details</small>
            <h4 class="fw-bold mb-2 text-break"><?= htmlspecialchars($task['title']) ?></h4>
            <div class="d-flex flex-wrap gap-1">
                <span class="badge <?= $priorityClass ?>"><?= $task['priority'] ?> priority</span>
                <span class="badge <?= $statusClass ?>"><?= $task['status'] ?></span>
                <?php if ($overdue): ?>
                    <span class="badge bg-danger"><i class="lni lni-alarm-1 me-1"></i>Overdue</span>
                <?php endif; ?>
                <?php if ($series_active): ?>
                    <span class="badge bg-light text-dark border"><i class="lni lni-refresh-circle-1-clockwise me-1"></i>Repeats</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <?php if ($task['status'] === 'Pending'): ?>
                <button class="btn btn-sm btn-outline-dark" onclick="SET_STATUS(<?= $TASK_ID ?>, 'In Progress');">
                    <i class="lni lni-hourglass me-1"></i> Start
                </button>
            <?php endif; ?>
            <?php if (!$is_closed): ?>
                <button class="btn btn-sm btn-dark" onclick="SET_STATUS(<?= $TASK_ID ?>, 'Completed');">
                    <i class="lni lni-check-circle-1 me-1"></i> Mark complete
                </button>
            <?php else: ?>
                <button class="btn btn-sm btn-outline-dark" onclick="SET_STATUS(<?= $TASK_ID ?>, 'Pending');">
                    <i class="lni lni-refresh-circle-1-clockwise me-1"></i> Reopen
                </button>
            <?php endif; ?>
            <a href="main.php?page=tasks_edit&id=<?= $TASK_ID ?>" class="btn btn-sm btn-outline-dark">
                <i class="lni lni-pencil-1 me-1"></i> Edit
            </a>
            <a href="main.php?page=tasks_create&from=<?= $TASK_ID ?>" class="btn btn-sm btn-outline-dark">
                <i class="lni lni-clipboard me-1"></i> Duplicate
            </a>
            <button class="btn btn-sm btn-outline-dark"
                    onclick='SAVE_AS_TEMPLATE(<?= $TASK_ID ?>, <?= htmlspecialchars(json_encode($task['title']), ENT_QUOTES) ?>);'>
                <i class="lni lni-layers-1 me-1"></i> Save as template
            </button>
            <button class="btn btn-sm btn-outline-danger"
                    onclick='DELETE_TASK(<?= $TASK_ID ?>, <?= htmlspecialchars(json_encode($task['title']), ENT_QUOTES) ?>, <?= !$task['parent_id'] && $series_active ? 'true' : 'false' ?>, "main.php?page=task");'>
                <i class="lni lni-trash-3 me-1"></i> Delete
            </button>
        </div>
    </div>

    <div class="row g-3">

        <div class="col-12 col-lg-8">

            <?php if ($task['description']): ?>
            <div class="card shadow-sm border-0 rounded-3 mb-3">
                <div class="card-body">
                    <h6 class="fw-bold mb-2">Description</h6>
                    <p class="mb-0 small" style="white-space:pre-line;"><?= htmlspecialchars($task['description']) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Checklist -->
            <div class="card shadow-sm border-0 rounded-3 mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0">Checklist</h6>
                        <small class="text-muted" id="CHECKLIST_COUNT"><?= $done_count ?> of <?= count($items) ?> done</small>
                    </div>
                    <div class="progress mb-3" style="height:6px;">
                        <div class="progress-bar bg-dark" id="CHECKLIST_BAR"
                             style="width:<?= $items ? round($done_count / count($items) * 100) : 0 ?>%"></div>
                    </div>

                    <div id="CHECKLIST_ITEMS">
                        <?php foreach ($items as $item): ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="ITEM_<?= (int)$item['id'] ?>"
                                       <?= $item['is_done'] ? 'checked' : '' ?>
                                       onchange="TOGGLE_ITEM(<?= (int)$item['id'] ?>, this.checked);">
                                <label class="form-check-label small <?= $item['is_done'] ? 'text-decoration-line-through text-muted' : '' ?>"
                                       for="ITEM_<?= (int)$item['id'] ?>"><?= htmlspecialchars($item['title']) ?></label>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$items): ?>
                            <p class="text-muted small mb-2" id="CHECKLIST_EMPTY">No checklist items yet.</p>
                        <?php endif; ?>
                    </div>

                    <div class="input-group input-group-sm mt-2">
                        <input type="text" id="NEW_ITEM" class="form-control" maxlength="255" placeholder="Add an item"
                               onkeydown="if (event.key === 'Enter') { event.preventDefault(); ADD_ITEM(<?= $TASK_ID ?>); }">
                        <button class="btn btn-outline-dark" onclick="ADD_ITEM(<?= $TASK_ID ?>);"><i class="lni lni-plus"></i> Add</button>
                    </div>
                </div>
            </div>

            <!-- Progress notes -->
            <div class="card shadow-sm border-0 rounded-3 mb-3">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Progress notes</h6>

                    <div class="mb-3">
                        <textarea id="NEW_NOTE" class="form-control form-control-sm" rows="2" maxlength="2000"
                                  placeholder="What happened? e.g. &quot;Called the supplier, waiting on a quote&quot;"></textarea>
                        <div class="d-flex justify-content-end mt-2">
                            <button class="btn btn-sm btn-dark" onclick="ADD_NOTE(<?= $TASK_ID ?>);">
                                <i class="lni lni-comment-1-text me-1"></i> Add note
                            </button>
                        </div>
                    </div>

                    <?php if (!$notes): ?>
                        <p class="text-muted small mb-0">No notes yet.</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0 note-list">
                        <?php foreach ($notes as $n): ?>
                            <li class="d-flex gap-3 py-2 border-top">
                                <div class="rounded-3 bg-dark text-white d-flex align-items-center justify-content-center flex-shrink-0"
                                     style="width:30px;height:30px;">
                                    <i class="lni lni-comment-1-text"></i>
                                </div>
                                <div class="flex-grow-1 overflow-hidden">
                                    <div class="small text-break" style="white-space:pre-line;"><?= htmlspecialchars($n['note']) ?></div>
                                    <small class="text-muted"><?= $fmt_dt($n['created_at']) ?></small>
                                </div>
                                <button class="btn btn-sm btn-link text-danger p-0 align-self-start" title="Delete note"
                                        onclick="DELETE_NOTE(<?= (int)$n['id'] ?>);">
                                    <i class="lni lni-trash-3"></i>
                                </button>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Details -->
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Details</h6>
                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted fw-normal">Start date</dt>
                        <dd class="col-7"><?= $task['start_date'] ? date('M j, Y', strtotime($task['start_date'])) : '—' ?></dd>

                        <dt class="col-5 text-muted fw-normal">Due</dt>
                        <dd class="col-7 <?= $overdue ? 'text-danger fw-semibold' : '' ?>">
                            <?php if ($task['due_date']): ?>
                                <?= date('M j, Y', strtotime($task['due_date'])) ?>
                                <?= $task['due_time'] ? '<br>' . date('g:i A', strtotime($task['due_time'])) : '' ?>
                            <?php else: ?>—<?php endif; ?>
                        </dd>

                        <dt class="col-5 text-muted fw-normal">Reminder</dt>
                        <dd class="col-7">
                            <?php if ($task['remind_at']): ?>
                                <?= REMINDER_OPTIONS[(int)$task['remind_before']] ?? '' ?>
                                <br><span class="text-muted"><?= $fmt_dt($task['remind_at']) ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </dd>

                        <dt class="col-5 text-muted fw-normal">Repeats</dt>
                        <dd class="col-7">
                            <?php if ($series_active): ?>
                                <?= htmlspecialchars(describe_repeat($series['repeat_type'], $series['repeat_days'], $series['repeat_until'])) ?>
                                <?php if ($next_occurrence): ?>
                                    <br><span class="text-muted">Next: <?= date('D, M j', strtotime($next_occurrence)) ?></span>
                                <?php endif; ?>
                                <?php if ($task['parent_id']): ?>
                                    <br><a href="main.php?page=task_view&id=<?= (int)$series['id'] ?>" class="text-dark">View series</a>
                                <?php endif; ?>
                            <?php else: ?>Does not repeat<?php endif; ?>
                        </dd>

                        <dt class="col-5 text-muted fw-normal">Created</dt>
                        <dd class="col-7"><?= $fmt_dt($task['created_at']) ?></dd>

                        <?php if ($task['completed_at']): ?>
                        <dt class="col-5 text-muted fw-normal">Completed</dt>
                        <dd class="col-7 mb-0"><?= $fmt_dt($task['completed_at']) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        </div>

    </div>

<?php endif; ?>

</div>

<script src="pages/create/script.js"></script>
<script src="pages/task/script.js"></script>
