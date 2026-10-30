<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once '../../db/db.php';
header('Content-Type: application/json');

function respond($status, $message) {
    echo json_encode(['status' => $status, 'message' => $message]);
    exit();
}

// Temporary password the admin hands to the user; they must replace it at next login.
// No look-alike characters (0/O, 1/l/I) so it's easy to read out or type.
function generate_temp_password($length = 10) {
    $CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $PW = '';
    for ($i = 0; $i < $length; $i++) {
        $PW .= $CHARS[random_int(0, strlen($CHARS) - 1)];
    }
    return $PW;
}

if (!isset($_SESSION['user_id'])) {
    respond('error', 'Your session has expired. Please log in again.');
}

if (($_SESSION['role'] ?? '') !== 'Admin') {
    respond('error', 'Only administrators can manage accounts.');
}

$ACTION  = intval($_POST['ACTION'] ?? 0);
$USER_ID = intval($_POST['USER_ID'] ?? 0);
$IS_EDIT = $ACTION == 2;

// RESET PASSWORD — generate a temporary password the admin hands to the user.
// The user is made to choose their own password at next login.
if ($ACTION == 3) {
    if ($USER_ID === (int)$_SESSION['user_id']) {
        respond('error', 'Use Settings → Security to change your own password.');
    }

    $STMT = $GLOBALS['db']->prepare('SELECT username FROM tbl_account WHERE id = ?');
    $STMT->execute([$USER_ID]);
    $TARGET = $STMT->fetchColumn();
    if (!$TARGET) {
        respond('error', 'User not found.');
    }

    $TEMP_PW = generate_temp_password();

    $STMT = $GLOBALS['db']->prepare('UPDATE tbl_account SET password = ?, default_password = ?, must_change_password = 1 WHERE id = ?');
    $STMT->execute([password_hash($TEMP_PW, PASSWORD_DEFAULT),$TEMP_PW, $USER_ID]);

    log_activity($_SESSION['user_id'], 'password_reset', $USER_ID);

    echo json_encode([
        'status'   => 'success',
        'message'  => 'Password has been reset.',
        'username' => $TARGET,
        'password' => $TEMP_PW,
    ]);
    exit();
}

if ($ACTION != 1 && $ACTION != 2) {
    respond('error', 'Invalid action.');
}

// ── Load the account being edited ──────────────────────────────────────────
$EXISTING = null;
if ($IS_EDIT) {
    $STMT = $GLOBALS['db']->prepare('SELECT id, role, status, avatar FROM vw_user WHERE id = ?');
    $STMT->execute([$USER_ID]);
    $EXISTING = $STMT->fetch(PDO::FETCH_ASSOC);
    if (!$EXISTING) {
        respond('error', 'User not found.');
    }
}
$IS_SELF = $IS_EDIT && $USER_ID === (int)$_SESSION['user_id'];

$FNAME    = trim($_POST['FIRST_NAME']  ?? '');
$MNAME    = trim($_POST['MIDDLE_NAME'] ?? '');
$LNAME    = trim($_POST['LAST_NAME']   ?? '');
$AGE      = trim($_POST['AGE']         ?? '');
$MNUM     = trim($_POST['MOBILE_NUM']  ?? '');
$ROLE     = $_POST['ROLE']             ?? '';
$STATUS   = $_POST['STATUS']           ?? 'Active';
$UNAME    = trim($_POST['USERNAME']    ?? '');

// Admins can't change their own role/status, so there is always at least one active admin.
if ($IS_SELF) {
    $ROLE   = $EXISTING['role'];
    $STATUS = $EXISTING['status'];
}

// ── Validate ───────────────────────────────────────────────────────────────
if (!in_array($ROLE, ['Admin', 'User'], true)) {
    respond('error', 'Role must be Admin or User.');
}

if (empty($LNAME) || empty($FNAME)) {
    respond('error', 'First name and last name are required.');
}

if ($AGE !== '' && (!ctype_digit($AGE) || $AGE < 1 || $AGE > 120)) {
    respond('error', 'Age must be a number between 1 and 120.');
}

if ($MNUM !== '' && !preg_match('/^09\d{9}$/', $MNUM)) {
    respond('error', 'Phone number must follow the format 09XXXXXXXXX.');
}

if (empty($UNAME)) {
    respond('error', 'Username is required.');
}

if (mb_strlen($UNAME) > 55) {
    respond('error', 'Username must be 55 characters or fewer.');
}

$CHK = $GLOBALS['db']->prepare('SELECT 1 FROM tbl_account WHERE username = ? AND id <> ?');
$CHK->execute([$UNAME, $USER_ID]);
if ($CHK->fetchColumn()) {
    respond('error', 'Username already exists.');
}

// ── Avatar (optional) — validate it's a real image before saving ───────────
$AVATAR = null;
if (!empty($_FILES['AVATAR']['name'])) {
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $info  = @getimagesize($_FILES['AVATAR']['tmp_name']);

    if ($_FILES['AVATAR']['error'] !== UPLOAD_ERR_OK || !$info || !isset($types[$info[2]])
        || $_FILES['AVATAR']['size'] > 2 * 1024 * 1024) {
        respond('error', 'Invalid avatar. Use JPG, PNG, or WEBP under 2 MB.');
    }

    $AVATAR = time() . '_' . bin2hex(random_bytes(6)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($_FILES['AVATAR']['tmp_name'], '../../uploads/avatars/' . $AVATAR)) {
        respond('error', 'Could not save the avatar. Please try again.');
    }
}

try {
    $GLOBALS['db']->beginTransaction();

    if (!$IS_EDIT) {
        // CREATE ACCOUNT — credentials into tbl_account with a generated password
        // the user must change at first login.
        $TEMP_PW = generate_temp_password();
        $STMT1 = $GLOBALS['db']->prepare('INSERT INTO tbl_account (username, password, default_password, must_change_password, role)
                                          VALUES (:USERNAME, :PASSWORD, :DEFAULT_PASSWORD, 1, :ROLE)');
        $STMT1->bindValue(':USERNAME',         $UNAME);
        $STMT1->bindValue(':PASSWORD',         password_hash($TEMP_PW, PASSWORD_DEFAULT));
        $STMT1->bindValue(':DEFAULT_PASSWORD', $TEMP_PW);
        $STMT1->bindValue(':ROLE',             $ROLE);
        $STMT1->execute();

        $USER_ID = (int)$GLOBALS['db']->lastInsertId();

        // Personal info into tbl_info
        $STMT2 = $GLOBALS['db']->prepare('INSERT INTO tbl_info (user_id, first_name, middle_name, last_name, age, phone, avatar)
                                          VALUES (:USER_ID, :FNAME, :MNAME, :LNAME, :AGE, :MNUM, :AVATAR)');
        $STMT2->bindValue(':AVATAR', $AVATAR);
    } else {
        // EDIT USER — passwords are changed through Reset Password (ACTION 3), not here.
        $STMT1 = $GLOBALS['db']->prepare('UPDATE tbl_account SET username = :USERNAME, role = :ROLE, status = :STATUS WHERE id = :USER_ID');
        $STMT1->bindValue(':USERNAME', $UNAME);
        $STMT1->bindValue(':ROLE',     $ROLE);
        $STMT1->bindValue(':STATUS',   $STATUS);
        $STMT1->bindValue(':USER_ID',  $USER_ID, PDO::PARAM_INT);
        $STMT1->execute();

        $SQL2 = 'UPDATE tbl_info SET first_name = :FNAME, middle_name = :MNAME, last_name = :LNAME, age = :AGE, phone = :MNUM'
              . ($AVATAR ? ', avatar = :AVATAR' : '')
              . ' WHERE user_id = :USER_ID';
        $STMT2 = $GLOBALS['db']->prepare($SQL2);
        if ($AVATAR) {
            $STMT2->bindValue(':AVATAR', $AVATAR);
        }
    }

    $STMT2->bindValue(':USER_ID', $USER_ID, PDO::PARAM_INT);
    $STMT2->bindValue(':FNAME',   $FNAME);
    $STMT2->bindValue(':MNAME',   $MNAME ?: null);
    $STMT2->bindValue(':LNAME',   $LNAME);
    $STMT2->bindValue(':AGE',     $AGE !== '' ? (int)$AGE : null);
    $STMT2->bindValue(':MNUM',    $MNUM ?: null);
    $STMT2->execute();

    $GLOBALS['db']->commit();

} catch (Exception $e) {
    $GLOBALS['db']->rollBack();
    if ($AVATAR) {
        @unlink('../../uploads/avatars/' . $AVATAR);
    }
    respond('error', 'A database error occurred. Please try again.');
}

if (!$IS_EDIT) {
    log_activity($_SESSION['user_id'], 'account_created', $USER_ID);
    echo json_encode([
        'status'   => 'success',
        'message'  => 'Account created successfully.',
        'username' => $UNAME,
        'password' => $TEMP_PW,
    ]);
    exit();
}

// Replacing the avatar — remove the old file so uploads don't pile up.
if ($AVATAR && !empty($EXISTING['avatar']) && is_file('../../uploads/avatars/' . basename($EXISTING['avatar']))) {
    @unlink('../../uploads/avatars/' . basename($EXISTING['avatar']));
}

if ($IS_SELF) {
    $_SESSION['username'] = $UNAME;
}

log_activity($_SESSION['user_id'], 'account_updated', $USER_ID);
respond('success', 'User updated successfully.');
