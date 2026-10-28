<?php
include_once 'db/db.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      header('Location: login.php');
      exit();
}

$USERNAME = trim($_POST['username'] ?? '');
$PASSWORD =  $_POST['password'] ?? '';

if($USERNAME === '' || $PASSWORD === '') {
   $_SESSION['empty'] = 'Username and password are required.';
   header('Location: login.php');
    exit();
}


      $res = $conn->prepare("SELECT id, username, password, role, status, must_change_password FROM tbl_account WHERE username = ? LIMIT 1");
      $res->bind_param("s", $USERNAME);
      $res->execute();
      $result = $res->get_result();
      $row = $result->fetch_assoc();

      // Same message for unknown username and wrong password, so usernames can't be guessed.
      if (!$row || !password_verify($PASSWORD, $row['password'])) {
         $_SESSION['error'] = 'Invalid username or password.';
         header('Location: login.php');
         exit();
      }

      if ($row['status'] !== 'Active') {
         $_SESSION['error'] = 'Your account is ' . strtolower($row['status']) . '. Please contact an administrator.';
         header('Location: login.php');
         exit();
      }

      session_regenerate_id(true);
      $_SESSION['user_id'] = $row['id'];
      $_SESSION['username'] = $row['username'];
      $_SESSION['role'] = $row['role'];
      // Admin reset the password — user must pick a new one before using the system.
      $_SESSION['must_change_password'] = (bool)$row['must_change_password'];

      $upd = $conn->prepare("UPDATE tbl_account SET last_login = NOW() WHERE id = ?");
      $upd->bind_param("i", $row['id']);
      $upd->execute();
      log_activity($row['id'], 'login');

      header('Location: main.php');
      exit();

?>