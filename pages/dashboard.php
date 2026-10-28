<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = $_SESSION['user_id'];

// ── Greeting ───────────────────────────────────────────────────────────────
$hour = (int)date('H');
$greeting = match(true) {
    $hour < 12 => 'Good morning',
    $hour < 17 => 'Good afternoon',
    default    => 'Good evening',
};

// ── User first name ────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT first_name FROM tbl_info WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$_res  = $stmt->get_result();
$info  = $_res->fetch_assoc();
$_res->free();
$stmt->close();
$first_name = $info['first_name'] ?? ($_SESSION['username'] ?? 'there');

// ── Task stats ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
   SELECT 
    COUNT(*) AS total,
    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending,
    SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress,
    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed,
    SUM(CASE 
            WHEN priority = 'High' 
             AND status NOT IN ('Completed','Archived') 
            THEN 1 ELSE 0 
        END) AS high_prio,
    SUM(CASE 
            WHEN DATE(due_date) = CURDATE()
             AND status NOT IN ('Completed','Archived') 
            THEN 1 ELSE 0 
        END) AS due_today
FROM tbl_tasks
WHERE user_id = ?
  AND is_deleted = 0
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$_res  = $stmt->get_result();
$stats = $_res->fetch_assoc();
$_res->free();
$stmt->close();

$total         = (int)($stats['total']         ?? 0);
$pending       = (int)($stats['pending']       ?? 0);
$in_progress   = (int)($stats['in_progress']   ?? 0);
$completed     = (int)($stats['completed']     ?? 0);
$high_priority = (int)($stats['high_prio'] ?? 0);
$due_today     = (int)($stats['due_today']     ?? 0);
$completion_pct = $total > 0 ? round(($completed / $total) * 100) : 0;

// ── Due within 7 days ──────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, title, priority, due_date, status
    FROM tbl_tasks
    WHERE user_id = ?
      AND is_deleted = 0
      AND status NOT IN ('Completed','Archived')
      AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY due_date ASC
    LIMIT 5
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$_res     = $stmt->get_result();
$upcoming = $_res->fetch_all(MYSQLI_ASSOC);
$_res->free();
$stmt->close();

// ── Recent tasks ───────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, title, priority, status, due_date, created_at
    FROM tbl_tasks
    WHERE user_id = ? AND is_deleted = 0
    ORDER BY created_at DESC
    LIMIT 6
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$_res   = $stmt->get_result();
$recent = $_res->fetch_all(MYSQLI_ASSOC);
$_res->free();
$stmt->close();
?>

<div class="container-fluid">

    <!-- ── Header ── -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-0"><?= $greeting ?>, <?= htmlspecialchars($first_name) ?>.</h4>
            <small class="text-muted"><?= date('l, F j, Y') ?></small>
        </div>
        <a href="main.php?page=tasks_create" class="btn btn-dark">
            <i class="lni lni-file-plus-circle me-1"></i> New Task
        </a>
    </div>

    <?php if ($due_today > 0): ?>
    <!-- ── Due today banner ── -->
    <div class="alert alert-dark d-flex align-items-center gap-2 rounded-3 mb-4 py-2 px-3 border-0 shadow-sm" role="alert">
        <i class="lni lni-alarm fs-5"></i>
        <span>You have <strong><?= $due_today ?> task<?= $due_today > 1 ? 's' : '' ?></strong> due today.</span>
        <a href="main.php?page=task" class="ms-auto btn btn-sm btn-outline-light">View</a>
    </div>
    <?php endif; ?>

    <!-- ── Stat Cards ── -->
    <div class="row g-3 mb-4">

        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-3 bg-dark text-white p-3 d-flex align-items-center justify-content-center" style="width:48px;height:48px;">
                        <i class="lni lni-agenda fs-5"></i>
                    </div>
                    <div>
                        <div class="fs-3 fw-bold lh-1"><?= $total ?></div>
                        <div class="text-muted small mt-1">Total Tasks</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-3 bg-secondary text-white p-3 d-flex align-items-center justify-content-center" style="width:48px;height:48px;">
                        <i class="lni lni-hourglass fs-5"></i>
                    </div>
                    <div>
                        <div class="fs-3 fw-bold lh-1"><?= $pending ?></div>
                        <div class="text-muted small mt-1">Pending</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-3 text-white p-3 d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:#ffc107;">
                        <i class="lni lni-reload fs-5 text-dark"></i>
                    </div>
                    <div>
                        <div class="fs-3 fw-bold lh-1"><?= $in_progress ?></div>
                        <div class="text-muted small mt-1">In Progress</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-3 text-white p-3 d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:#198754;">
                        <i class="lni lni-checkmark-circle fs-5"></i>
                    </div>
                    <div>
                        <div class="fs-3 fw-bold lh-1"><?= $completed ?></div>
                        <div class="text-muted small mt-1">Completed</div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ── Completion Progress + High Priority ── -->
    <div class="row g-3 mb-4">

        <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold">Overall Completion</span>
                        <span class="fw-bold"><?= $completion_pct ?>%</span>
                    </div>
                    <div class="progress rounded-pill" style="height:10px;">
                        <div class="progress-bar bg-dark" role="progressbar"
                             style="width:<?= $completion_pct ?>%"
                             aria-valuenow="<?= $completion_pct ?>"
                             aria-valuemin="0" aria-valuemax="100">
                        </div>
                    </div>
                    <div class="d-flex justify-content-between mt-2">
                        <small class="text-muted"><?= $completed ?> of <?= $total ?> tasks done</small>
                        <?php if ($total > 0 && $completion_pct < 100): ?>
                            <small class="text-muted"><?= $total - $completed ?> remaining</small>
                        <?php elseif ($completion_pct === 100): ?>
                            <small class="text-success fw-semibold">All done!</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-3 h-100" style="border-left:4px solid #dc3545!important;">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-3 bg-danger text-white p-3 d-flex align-items-center justify-content-center" style="width:48px;height:48px;">
                        <i class="lni lni-alarm-1 fs-5"></i>
                    </div>
                    <div>
                        <div class="fs-3 fw-bold lh-1"><?= $high_priority ?></div>
                        <div class="text-muted small mt-1">High Priority</div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ── Recent Tasks + Upcoming ── -->
    <div class="row g-3">

        <!-- Recent Tasks -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-body p-0">

                    <div class="d-flex justify-content-between align-items-center px-4 pt-4 pb-3">
                        <h6 class="fw-bold mb-0">Recent Tasks</h6>
                        <a href="main.php?page=task" class="btn btn-sm btn-outline-dark">View All</a>
                    </div>

                    <?php if (count($recent) === 0): ?>
                        <div class="text-center text-muted py-5">
                            <i class="lni lni-agenda fs-1 d-block mb-2 opacity-25"></i>
                            No tasks yet. <a href="main.php?page=tasks_create" class="text-dark fw-semibold">Create one</a>.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">Task</th>
                                        <th>Priority</th>
                                        <th>Due</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($recent as $t):
                                    $pc = match($t['priority']) {
                                        'High'   => 'bg-danger',
                                        'Medium' => 'bg-warning text-dark',
                                        default  => 'bg-secondary',
                                    };
                                    $sc = match($t['status']) {
                                        'Completed'  => 'bg-success',
                                        'In Progress'=> 'bg-warning text-dark',
                                        'Archived'   => 'bg-secondary',
                                        default      => 'bg-dark',
                                    };
                                    $overdue = $t['due_date'] && $t['due_date'] < date('Y-m-d')
                                               && !in_array($t['status'], ['Completed','Archived']);
                                ?>
                                <tr>
                                    <td class="ps-4 fw-semibold" style="max-width:220px;">
                                        <span class="d-block text-truncate" title="<?= htmlspecialchars($t['title']) ?>">
                                            <?= htmlspecialchars($t['title']) ?>
                                        </span>
                                        <small class="text-muted fw-normal">
                                            Added <?= date('M j', strtotime($t['created_at'])) ?>
                                        </small>
                                    </td>
                                    <td><span class="badge <?= $pc ?>"><?= $t['priority'] ?></span></td>
                                    <td>
                                        <?php if ($t['due_date']): ?>
                                            <span class="<?= $overdue ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                                <?= date('M j, Y', strtotime($t['due_date'])) ?>
                                                <?= $overdue ? ' <i class="lni lni-alarm-1 text-danger" title="Overdue"></i>' : '' ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge <?= $sc ?>"><?= htmlspecialchars($t['status']) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

        <!-- Due This Week -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body">

                    <h6 class="fw-bold mb-3">Due This Week</h6>

                    <?php if (count($upcoming) === 0): ?>
                        <div class="text-center text-muted py-4">
                            <i class="lni lni-checkmark-circle fs-1 d-block mb-2 opacity-25"></i>
                            <small>Nothing due in the next 7 days.</small>
                        </div>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                        <?php foreach ($upcoming as $u):
                            $pc = match($u['priority']) {
                                'High'   => 'bg-danger',
                                'Medium' => 'bg-warning text-dark',
                                default  => 'bg-secondary',
                            };
                            $days_left = (int)floor((strtotime($u['due_date']) - strtotime('today')) / 86400);
                            $label = match(true) {
                                $days_left === 0 => '<span class="text-danger fw-semibold">Today</span>',
                                $days_left === 1 => '<span class="text-warning fw-semibold">Tomorrow</span>',
                                default          => '<span class="text-muted">In ' . $days_left . ' days</span>',
                            };
                        ?>
                        <li class="mb-3 pb-3 border-bottom">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="fw-semibold text-truncate me-2" style="max-width:160px;" title="<?= htmlspecialchars($u['title']) ?>">
                                    <?= htmlspecialchars($u['title']) ?>
                                </span>
                                <span class="badge <?= $pc ?> flex-shrink-0"><?= $u['priority'] ?></span>
                            </div>
                            <small><?= $label ?> &mdash; <?= date('M j', strtotime($u['due_date'])) ?></small>
                        </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <div class="d-grid mt-3">
                        <a href="main.php?page=tasks_create" class="btn btn-dark btn-sm rounded-3">
                            <i class="lni lni-file-plus-circle me-1"></i> Add Task
                        </a>
                    </div>

                </div>
            </div>
        </div>

    </div>
</div>
