<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once 'db/db.php';

$user_id = (int)$_SESSION['user_id'];

$all_users = $conn->query("
    SELECT id, username, role, status, last_login, created_at,
           first_name, middle_name, last_name, phone, avatar
    FROM vw_user
    ORDER BY role = 'Admin' DESC, first_name, last_name
")->fetch_all(MYSQLI_ASSOC);

// Uploaded avatar if the file exists, otherwise an initials placeholder.
$avatar_url = fn($u) => !empty($u['avatar']) && is_file('uploads/avatars/' . $u['avatar'])
    ? 'uploads/avatars/' . rawurlencode($u['avatar'])
    : 'https://ui-avatars.com/api/?name=' . urlencode(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: '?')
      . '&background=6c757d&color=fff&size=64';
?>

<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-0">Users</h4>
            <small class="text-muted">
                <?= count($all_users) ?> account<?= count($all_users) === 1 ? '' : 's' ?> registered in the system
            </small>
        </div>
        <a href="main.php?page=create_account" class="btn btn-dark">
            <i class="lni lni-user-multiple-4 me-1"></i> Add User
        </a>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">

            <div class="p-3 border-bottom">
                <input type="search"
                       id="USER_SEARCH"
                       class="form-control form-control-sm"
                       style="max-width:280px;"
                       placeholder="Search by name, username, role..."
                       oninput="FILTER_USERS(this.value);">
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">User</th>
                            <th>Username</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Joined</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="USER_TABLE">
                    <?php foreach ($all_users as $u):
                        $full_name = trim(implode(' ', array_filter([$u['first_name'], $u['middle_name'], $u['last_name']])));
                        $status_class = match($u['status']) {
                            'Active'   => 'bg-success',
                            'Inactive' => 'bg-warning text-dark',
                            default    => 'bg-secondary',
                        };
                    ?>
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-2">
                                    <img src="<?= htmlspecialchars($avatar_url($u)) ?>"
                                         alt=""
                                         class="rounded-circle border flex-shrink-0"
                                         style="width:36px;height:36px;object-fit:cover;">
                                    <div class="overflow-hidden">
                                        <div class="fw-semibold text-truncate" style="max-width:220px;">
                                            <?= htmlspecialchars($full_name ?: '—') ?>
                                        </div>
                                        <?php if ((int)$u['id'] === $user_id): ?>
                                            <small class="text-muted">You</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($u['username']) ?></td>
                            <td class="text-muted"><?= htmlspecialchars($u['phone'] ?? '') ?: '—' ?></td>
                            <td>
                                <span class="badge <?= $u['role'] === 'Admin' ? 'bg-dark' : 'bg-secondary' ?>"><?= htmlspecialchars($u['role']) ?></span>
                            </td>
                            <td><span class="badge <?= $status_class ?>"><?= htmlspecialchars($u['status']) ?></span></td>
                            <td class="text-muted small">
                                <?= $u['last_login'] ? date('M j, Y g:i A', strtotime($u['last_login'])) : 'Never' ?>
                            </td>
                            <td class="text-muted small"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                            <td class="text-end pe-4 text-nowrap">
                                <a href="main.php?page=users_edit&id=<?= (int)$u['id'] ?>"
                                   class="btn btn-sm btn-outline-dark">
                                    <i class="lni lni-pencil-1 me-1"></i> Edit
                                </a>
                                <?php if ((int)$u['id'] !== $user_id): ?>
                                <button type="button"
                                        class="btn btn-sm btn-outline-danger"
                                        onclick='RESET_PASSWORD(<?= (int)$u['id'] ?>, <?= htmlspecialchars(json_encode($full_name ?: $u['username']), ENT_QUOTES) ?>)'>
                                    <i class="lni lni-locked-2 me-1"></i> Reset Password
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="USER_EMPTY" class="d-none">
                        <td colspan="8" class="text-center text-muted py-4">No users match your search.</td>
                    </tr>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<script src="pages/users/script.js"></script>
