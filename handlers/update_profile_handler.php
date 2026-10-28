<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once '../db/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

function back_with_error($message) {
    set_flash('error', $message);
    header('Location: ../main.php?page=profile');
    exit();
}

$user_id     = (int)$_SESSION['user_id'];
$username    = trim($_POST['username']    ?? '');
$first_name  = trim($_POST['first_name']  ?? '');
$middle_name = trim($_POST['middle_name'] ?? '');
$last_name   = trim($_POST['last_name']   ?? '');
$age         = trim($_POST['age']         ?? '');
$phone       = trim($_POST['phone']       ?? '');

// ── 1. Validate ────────────────────────────────────────────────────────────
if ($username === '') {
    back_with_error("Username cannot be empty.");
}
if (mb_strlen($username) > 55) {
    back_with_error("Username must be 55 characters or fewer.");
}
if ($first_name === '' || $last_name === '') {
    back_with_error("First name and last name are required.");
}
if ($age !== '' && (!ctype_digit($age) || $age < 1 || $age > 120)) {
    back_with_error("Age must be a number between 1 and 120.");
}
if ($phone !== '' && !preg_match('/^09\d{9}$/', $phone)) {
    back_with_error("Phone number must follow the format 09XXXXXXXXX.");
}

$chk = $conn->prepare("SELECT 1 FROM tbl_account WHERE username = ? AND id <> ?");
$chk->bind_param("si", $username, $user_id);
$chk->execute();
if ($chk->get_result()->num_rows > 0) {
    back_with_error("That username is already taken.");
}

// ── 2. Handle avatar upload ────────────────────────────────────────────────
$avatar_val = null;
if (!empty($_FILES['avatar']['name'])) {
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $info  = @getimagesize($_FILES['avatar']['tmp_name']);

    if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK || !$info || !isset($types[$info[2]])
        || $_FILES['avatar']['size'] > 2 * 1024 * 1024) {
        back_with_error("Invalid file. Use JPG, PNG, or WEBP under 2 MB.");
    }

    $avatar_val = time() . '_' . bin2hex(random_bytes(6)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($_FILES['avatar']['tmp_name'], '../uploads/avatars/' . $avatar_val)) {
        back_with_error("Could not save the avatar. Please try again.");
    }

    // Remove the previous avatar file so old uploads don't pile up.
    $old = $conn->prepare("SELECT avatar FROM tbl_info WHERE user_id = ?");
    $old->bind_param("i", $user_id);
    $old->execute();
    $old_avatar = $old->get_result()->fetch_assoc()['avatar'] ?? null;
    if ($old_avatar && is_file('../uploads/avatars/' . basename($old_avatar))) {
        @unlink('../uploads/avatars/' . basename($old_avatar));
    }
}

// ── 3. Update tbl_account (username) ───────────────────────────────────────
$stmt = $conn->prepare("UPDATE tbl_account SET username=? WHERE id=?");
$stmt->bind_param("si", $username, $user_id);
$stmt->execute();

// ── 4. Update tbl_info (names, age, phone, optional avatar) ────────────────
$middle_name = $middle_name !== '' ? $middle_name : null;
$age         = $age !== '' ? (int)$age : null;
$phone       = $phone !== '' ? $phone : null;

if ($avatar_val !== null) {
    $stmt2 = $conn->prepare("
        UPDATE tbl_info SET first_name=?, middle_name=?, last_name=?, age=?, phone=?, avatar=? WHERE user_id=?
    ");
    $stmt2->bind_param("sssissi", $first_name, $middle_name, $last_name, $age, $phone, $avatar_val, $user_id);
} else {
    $stmt2 = $conn->prepare("
        UPDATE tbl_info SET first_name=?, middle_name=?, last_name=?, age=?, phone=? WHERE user_id=?
    ");
    $stmt2->bind_param("sssisi", $first_name, $middle_name, $last_name, $age, $phone, $user_id);
}
$stmt2->execute();

log_activity($user_id, 'profile_updated', $user_id);

$_SESSION['username'] = $username;
set_flash('success', "Profile updated successfully.");
header('Location: ../main.php?page=profile');
exit();
