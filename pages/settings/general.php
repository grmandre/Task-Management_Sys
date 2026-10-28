<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once 'db/db.php';

$user_id = $_SESSION['user_id'];

// ── Account details ────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT username, role, status, last_login, created_at, first_name, last_name
    FROM vw_user WHERE id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Recent activity ────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT l.action, l.reference_id, l.created_at, t.title
    FROM tbl_activity_logs l
    LEFT JOIN tbl_tasks t ON t.id = l.reference_id AND l.action LIKE 'task\\_%'
    WHERE l.user_id = ?
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 10
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$activity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// action => [label, icon]
$ACTION_LABELS = [
    'login'                  => ['Signed in',             'lni-enter'],
    'logout'                 => ['Signed out',            'lni-exit'],
    'task_created'           => ['Created task',          'lni-file-plus-circle'],
    'task_updated'           => ['Updated task',          'lni-pencil-1'],
    'task_deleted'           => ['Deleted task',          'lni-trash-3'],
    'task_status_completed'  => ['Completed task',        'lni-check-circle-1'],
    'task_status_pending'    => ['Reopened task',         'lni-refresh-circle-1-clockwise'],
    'task_status_in_progress'=> ['Started task',          'lni-hourglass'],
    'profile_updated'        => ['Updated profile',       'lni-user-4'],
    'password_changed'       => ['Changed password',      'lni-locked-2'],
    'account_created'        => ['Created a user account','lni-user-multiple-4'],
    'account_updated'        => ['Updated a user account','lni-pencil-1'],
    'password_reset'         => ["Reset a user's password",'lni-locked-2'],
];

$status_class = match($account['status'] ?? '') {
    'Active'   => 'bg-success',
    'Inactive' => 'bg-warning text-dark',
    default    => 'bg-secondary',
};

$settings_tab = 'general';
?>

<div class="container-fluid py-4 bg-light">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-9">

            <?php require 'pages/settings/nav.php'; ?>

            <div class="row g-3">

                <!-- Account details -->
                <div class="col-12 col-md-5">
                    <div class="card shadow-sm border-0 rounded-4 h-100">
                        <div class="card-body p-4">
                            <h6 class="fw-bold mb-3 text-uppercase text-muted">Account</h6>

                            <dl class="row small mb-0">
                                <dt class="col-5 text-muted fw-normal">Name</dt>
                                <dd class="col-7 fw-semibold">
                                    <?= htmlspecialchars(trim(($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? ''))) ?>
                                </dd>

                                <dt class="col-5 text-muted fw-normal">Username</dt>
                                <dd class="col-7 fw-semibold"><?= htmlspecialchars($account['username'] ?? '') ?></dd>

                                <dt class="col-5 text-muted fw-normal">Role</dt>
                                <dd class="col-7"><span class="badge bg-dark"><?= htmlspecialchars($account['role'] ?? '') ?></span></dd>

                                <dt class="col-5 text-muted fw-normal">Status</dt>
                                <dd class="col-7"><span class="badge <?= $status_class ?>"><?= htmlspecialchars($account['status'] ?? '') ?></span></dd>

                                <dt class="col-5 text-muted fw-normal">Member since</dt>
                                <dd class="col-7"><?= !empty($account['created_at']) ? date('M j, Y', strtotime($account['created_at'])) : '—' ?></dd>

                                <dt class="col-5 text-muted fw-normal">Last login</dt>
                                <dd class="col-7 mb-0">
                                    <?= !empty($account['last_login']) ? date('M j, Y g:i A', strtotime($account['last_login'])) : '—' ?>
                                </dd>
                            </dl>

                            <hr>

                            <div class="d-grid gap-2">
                                <a href="main.php?page=profile" class="btn btn-sm btn-outline-dark rounded-3">
                                    <i class="lni lni-user-4 me-1"></i> Edit Profile
                                </a>
                                <a href="main.php?page=settings_security" class="btn btn-sm btn-outline-dark rounded-3">
                                    <i class="lni lni-locked-2 me-1"></i> Change Password
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent activity -->
                <div class="col-12 col-md-7">
                    <div class="card shadow-sm border-0 rounded-4 h-100">
                        <div class="card-body p-4">
                            <h6 class="fw-bold mb-3 text-uppercase text-muted">Recent Activity</h6>

                            <?php if (!$activity): ?>
                                <p class="text-muted small mb-0">No activity recorded yet.</p>
                            <?php else: ?>
                                <ul class="list-unstyled mb-0">
                                <?php foreach ($activity as $a):
                                    [$label, $icon] = $ACTION_LABELS[$a['action']]
                                        ?? [ucfirst(str_replace('_', ' ', $a['action'])), 'lni-bolt-2'];
                                ?>
                                    <li class="d-flex align-items-center gap-3 py-2 border-bottom">
                                        <div class="rounded-3 bg-dark text-white d-flex align-items-center justify-content-center flex-shrink-0"
                                             style="width:34px;height:34px;">
                                            <i class="lni <?= $icon ?>"></i>
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <div class="small fw-semibold text-truncate">
                                                <?= $label ?>
                                                <?php if (!empty($a['title'])): ?>
                                                    <span class="fw-normal text-muted">&ldquo;<?= htmlspecialchars($a['title']) ?>&rdquo;</span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted"><?= date('M j, Y g:i A', strtotime($a['created_at'])) ?></small>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </div>
</div>
