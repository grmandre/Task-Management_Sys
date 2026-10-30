<?php
if (session_status() === PHP_SESSION_NONE){
    session_start();
}
include_once 'db/db.php';

// Same form serves "Create Account" and "Edit User" (main.php?page=users_edit&id=…)
$IS_EDIT = ($_GET['page'] ?? '') === 'users_edit';
$EDIT_ID = intval($_GET['id'] ?? 0);
$acct    = null;

if ($IS_EDIT) {
    $stmt = $conn->prepare("
        SELECT id, username, role, status, first_name, middle_name, last_name, age, phone, avatar
        FROM vw_user WHERE id = ?
    ");
    $stmt->bind_param("i", $EDIT_ID);
    $stmt->execute();
    $acct = $stmt->get_result()->fetch_assoc();
}

// Admins can't change their own role/status, so there is always at least one active admin.
$IS_SELF = $acct && (int)$acct['id'] === (int)$_SESSION['user_id'];

$val = fn($key) => htmlspecialchars($acct[$key] ?? '');

$avatar_src = $acct && !empty($acct['avatar']) && is_file('uploads/avatars/' . $acct['avatar'])
    ? 'uploads/avatars/' . rawurlencode($acct['avatar'])
    : 'https://ui-avatars.com/api/?name=' . urlencode(trim(($acct['first_name'] ?? '') . ' ' . ($acct['last_name'] ?? '')) ?: '?')
      . '&background=6c757d&color=fff&size=128';
?>
<div class="container-fluid py-4 bg-light">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-7">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                <div>
                    <small class="text-muted">
                        <a href="main.php?page=users" class="text-muted">Users</a> / <?= $IS_EDIT ? 'Edit User' : 'Create Account' ?>
                    </small>
                    <h4 class="fw-bold mb-0"><?= $IS_EDIT ? 'Edit User' : 'Create Account' ?></h4>
                    <small class="text-muted">
                        <?= $IS_EDIT ? 'Update this user\'s information and access' : 'Fill in the details to register a new user' ?>
                    </small>
                </div>
                <a href="main.php?page=users" class="btn btn-sm btn-outline-dark">
                    <i class="lni lni-arrow-left me-1"></i> Back to Users
                </a>
            </div>

            <?php if ($IS_EDIT && !$acct): ?>

            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-4 p-md-5 text-center">
                    <i class="lni lni-search-1 fs-1 text-muted"></i>
                    <h6 class="fw-bold mt-3">User not found</h6>
                    <p class="text-muted small">This account may have been removed.</p>
                    <a href="main.php?page=users" class="btn btn-dark btn-sm px-4">Back to Users</a>
                </div>
            </div>

            <?php else: ?>

            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-3 p-md-4">
                    <form id="MY_FORM" enctype="multipart/form-data" onsubmit="SUBMIT_BTN(); return false;">
                        <input type="hidden" id="USER_ID" value="<?= $IS_EDIT ? (int)$acct['id'] : 0 ?>">

                        <div class="text-center mb-4">
                            <div class="position-relative d-inline-block">
                                <img id="AVATAR_PREVIEW"
                                     src="<?= htmlspecialchars($avatar_src) ?>"
                                     alt="Avatar Preview"
                                     class="rounded-circle border border-3 border-secondary"
                                     style="width:110px;height:110px;object-fit:cover;cursor:pointer;"
                                     onclick="document.getElementById('AVATAR').click();">
                                <span class="position-absolute bottom-0 end-0 bg-dark text-white rounded-circle d-flex align-items-center justify-content-center"
                                      style="width:28px;height:28px;cursor:pointer;"
                                      onclick="document.getElementById('AVATAR').click();">
                                    <i class="lni lni-camera-1" style="font-size:13px;"></i>
                                </span>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted">Click to upload avatar (JPG, PNG, WEBP, max 2 MB)</small>
                            </div>
                            <input type="file"
                                   id="AVATAR"
                                   name="avatar"
                                   accept="image/jpeg,image/png,image/webp"
                                   class="d-none"
                                   onchange="PREVIEW_AVATAR(this);">
                        </div>

                        <h6 class="fw-bold mb-3 text-uppercase text-muted">Personal Information</h6>

                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Last Name <span class="text-danger">*</span></label>
                                <input type="text"
                                       name="last_name"
                                       class="form-control"
                                       id="LAST_NAME"
                                       maxlength="100"
                                       value="<?= $val('last_name') ?>"
                                       placeholder="Last Name">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">First Name <span class="text-danger">*</span></label>
                                <input type="text"
                                       name="first_name"
                                       class="form-control"
                                       id="FIRST_NAME"
                                       maxlength="100"
                                       value="<?= $val('first_name') ?>"
                                       placeholder="First Name">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Middle Name</label>
                                <input type="text"
                                       name="middle_name"
                                       class="form-control"
                                       id="MIDDLE_NAME"
                                       maxlength="100"
                                       value="<?= $val('middle_name') ?>"
                                       placeholder="Middle Name">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Age</label>
                                <input type="number"
                                       name="age"
                                       class="form-control"
                                       id="AGE"
                                       placeholder="Age"
                                       min="1"
                                       max="120"
                                       value="<?= $val('age') ?>">
                            </div>

                            <div class="col-md-5">
                                <label class="form-label small fw-semibold">Phone Number</label>
                                <input type="tel"
                                       name="phone"
                                       class="form-control"
                                       id="MOBILE_NUM"
                                       maxlength="11"
                                       inputmode="numeric"
                                       value="<?= $val('phone') ?>"
                                       placeholder="09XXXXXXXXX">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Role <span class="text-danger">*</span></label>
                                <select name="role"
                                        id="ROLE"
                                        class="form-select"
                                        <?= $IS_SELF ? 'disabled' : '' ?>>
                                    <option value="" disabled <?= $acct ? '' : 'selected' ?>>Select Role</option>
                                    <?php foreach (['Admin', 'User'] as $r): ?>
                                        <option value="<?= $r ?>" <?= ($acct['role'] ?? '') === $r ? 'selected' : '' ?>><?= $r ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <hr>

                        <h6 class="fw-bold mb-3 text-uppercase text-muted">Account Credentials</h6>

                        <div class="row g-3 mb-3">
                            <div class="<?= $IS_EDIT ? 'col-md-8' : 'col-12' ?>">
                                <label class="form-label small fw-semibold">Username <span class="text-danger">*</span></label>
                                <input type="text"
                                       name="username"
                                       class="form-control"
                                       id="USERNAME"
                                       maxlength="55"
                                       autocomplete="off"
                                       value="<?= $val('username') ?>"
                                       placeholder="Username">
                            </div>

                            <?php if ($IS_EDIT): ?>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Status <span class="text-danger">*</span></label>
                                <select id="STATUS" class="form-select" <?= $IS_SELF ? 'disabled' : '' ?>>
                                    <?php foreach (['Active', 'Inactive', 'Disabled'] as $s): ?>
                                        <option value="<?= $s ?>" <?= $acct['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <?php if ($IS_SELF): ?>
                            <div class="col-12">
                                <small class="text-muted">
                                    <i class="lni lni-locked-2 me-1"></i>
                                    You can't change your own role or status. Ask another admin if this needs to change.
                                </small>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="bg-light p-3 rounded-3 mb-4">
                            <h6 class="fw-bold mb-1">Password</h6>
                            <small class="text-muted d-block">
                                <i class="lni lni-locked-2 me-1"></i>
                                <?php if ($IS_EDIT): ?>
                                    To give this user a new password, use <strong>Reset Password</strong> on the Users list.
                                <?php else: ?>
                                    A temporary password will be generated when the account is created.
                                    The user will be asked to set their own password at first login.
                                <?php endif; ?>
                            </small>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit"
                                    id="CREATE_BTN"
                                    class="btn btn-dark py-2 fw-semibold rounded-3 shadow-sm">
                                <?= $IS_EDIT ? 'Save Changes' : 'Create Account' ?>
                            </button>
                            <a href="main.php?page=users" class="btn btn-outline-dark py-2 fw-semibold rounded-3">
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

<script src="<?= asset('pages/profile/script.js') ?>"></script>
<script src="<?= asset('pages/users/script.js') ?>"></script>
<script src="<?= asset('pages/create_account/script.js') ?>"></script>
