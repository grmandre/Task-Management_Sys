<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$settings_tab = 'security';
?>

<div class="container-fluid py-4 bg-light">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-9">

            <?php require 'pages/settings/nav.php'; ?>

            <?php if (!empty($_SESSION['must_change_password'])): ?>
            <div class="alert alert-dark d-flex align-items-center gap-2 rounded-3 mb-4 py-2 px-3 border-0 shadow-sm" role="alert">
                <i class="lni lni-locked-2 fs-5"></i>
                <span>
                    Your password was reset by an administrator. Enter the temporary password you were given
                    as your <strong>current password</strong>, then choose a new one to continue.
                </span>
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-12 col-lg-8">
                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-body p-3 p-md-4">

                            <form id="PASSWORD_FORM" onsubmit="CHANGE_PASSWORD(); return false;">

                                <h6 class="fw-bold mb-3 text-uppercase text-muted">Change Password</h6>

                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">Current Password <span class="text-danger">*</span></label>
                                    <div class="password-field">
                                        <input type="password"
                                               id="CURRENT_PASSWORD"
                                               class="form-control"
                                               autocomplete="current-password"
                                               placeholder="Enter your current password">
                                        <button type="button" class="password-eye" onclick="TOGGLE_PASSWORD(this);"
                                                title="Show password" aria-label="Show password" aria-pressed="false">
                                            <i class="lni lni-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="bg-light p-3 rounded-3 mb-4">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">New Password <span class="text-danger">*</span></label>
                                            <div class="password-field">
                                                <input type="password"
                                                       id="NEW_PASSWORD"
                                                       class="form-control"
                                                       autocomplete="new-password"
                                                       placeholder="Minimum 6 characters">
                                                <button type="button" class="password-eye" onclick="TOGGLE_PASSWORD(this);"
                                                        title="Show password" aria-label="Show password" aria-pressed="false">
                                                    <i class="lni lni-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Confirm New Password <span class="text-danger">*</span></label>
                                            <div class="password-field">
                                                <input type="password"
                                                       id="CONFIRM_PASSWORD"
                                                       class="form-control"
                                                       autocomplete="new-password"
                                                       placeholder="Re-enter new password">
                                                <button type="button" class="password-eye" onclick="TOGGLE_PASSWORD(this);"
                                                        title="Show password" aria-label="Show password" aria-pressed="false">
                                                    <i class="lni lni-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-grid">
                                    <button type="submit"
                                            id="PASSWORD_BTN"
                                            class="btn btn-dark py-2 fw-semibold rounded-3 shadow-sm">
                                        Update Password
                                    </button>
                                </div>

                            </form>

                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-4 mt-3 mt-lg-0">
                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-body p-3 p-md-4">
                            <h6 class="fw-bold mb-3"><i class="lni lni-shield-2 me-1"></i> Password tips</h6>
                            <ul class="small text-muted ps-3 mb-0">
                                <li>Use at least 6 characters — longer is better.</li>
                                <li>Mix letters, numbers and symbols.</li>
                                <li>Don't reuse a password from another site.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="pages/settings/script.js"></script>
