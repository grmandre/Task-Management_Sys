
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
    SELECT id, username, role, first_name, middle_name, last_name, age, phone, avatar
    FROM vw_user WHERE id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Uploaded avatar if the file exists, otherwise an initials placeholder.
$avatar_url = fn($u, $size = 128) => !empty($u['avatar']) && is_file('uploads/avatars/' . $u['avatar'])
    ? 'uploads/avatars/' . rawurlencode($u['avatar'])
    : 'https://ui-avatars.com/api/?name=' . urlencode(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: '?')
      . '&background=6c757d&color=fff&size=' . $size;

$avatar_src = $avatar_url($user);

?>

<div class="container-fluid py-4 bg-light">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-7">

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="fw-bold mb-0">My Profile</h4>
                    <small class="text-muted">Manage your personal information</small>
                </div>
                <span class="badge bg-dark px-3 py-2">
                    <?= htmlspecialchars($user['role']) ?>
                </span>
            </div>

            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-4">

                    <form method="POST"
                          action="handlers/update_profile_handler.php"
                          enctype="multipart/form-data">

                        <!-- Avatar Section -->
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

                        <hr>

                        <!-- Account Information -->
                        <h6 class="fw-bold mb-3 text-uppercase text-muted">
                            Account Information
                        </h6>

                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Username <span class="text-danger">*</span></label>
                                <input type="text"
                                       name="username"
                                       class="form-control"
                                       maxlength="55"
                                       value="<?= htmlspecialchars($user['username'] ?? '') ?>"
                                       required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">First Name <span class="text-danger">*</span></label>
                                <input type="text"
                                       name="first_name"
                                       class="form-control"
                                       maxlength="100"
                                       value="<?= htmlspecialchars($user['first_name'] ?? '') ?>"
                                       required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Middle Name</label>
                                <input type="text"
                                       name="middle_name"
                                       class="form-control"
                                       maxlength="100"
                                       value="<?= htmlspecialchars($user['middle_name'] ?? '') ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Last Name <span class="text-danger">*</span></label>
                                <input type="text"
                                       name="last_name"
                                       class="form-control"
                                       maxlength="100"
                                       value="<?= htmlspecialchars($user['last_name'] ?? '') ?>"
                                       required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Age</label>
                                <input type="number"
                                       name="age"
                                       class="form-control"
                                       min="1"
                                       max="120"
                                       value="<?= htmlspecialchars($user['age'] ?? '') ?>">
                            </div>

                            <div class="col-md-8">
                                <label class="form-label small fw-semibold">Phone Number</label>
                                <input type="tel"
                                       name="phone"
                                       class="form-control"
                                       maxlength="11"
                                       inputmode="numeric"
                                       pattern="09[0-9]{9}"
                                       title="Format: 09XXXXXXXXX"
                                       placeholder="09XXXXXXXXX"
                                       value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                            </div>
                        </div>

                        <!-- Password Section -->
                        <div class="bg-light p-3 rounded-3 mb-4 d-flex align-items-center gap-3">
                            <i class="lni lni-locked-2 fs-4"></i>
                            <div class="flex-grow-1">
                                <h6 class="fw-bold mb-0">Password</h6>
                                <small class="text-muted">Change your password from the security settings.</small>
                            </div>
                            <a href="main.php?page=settings_security" class="btn btn-sm btn-outline-dark">Change Password</a>
                        </div>

                        <!-- Submit -->
                        <div class="d-grid">
                            <button type="submit"
                                    class="btn btn-dark py-2 fw-semibold rounded-3 shadow-sm">
                                Update Profile
                            </button>
                        </div>

                    </form>

                </div>
            </div>

        </div>
    </div>
</div>
<script src="pages/profile/script.js"></script>
