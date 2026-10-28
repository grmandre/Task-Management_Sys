

<?php

    if (session_status() == PHP_SESSION_NONE) {
    session_start();
}  
    if(!isset($_SESSION["user_id"])) {
        header('Location: login.php');
        exit();
    }
    include_once 'db/db.php';
    include_once 'includes/tasks.php';

    // Add today's copies of the user's recurring tasks (cheap when there's nothing to do).
    generate_recurring_tasks((int)$_SESSION['user_id']);

    $stmt = $conn->prepare("
                SELECT id, first_name, last_name, username FROM vw_user WHERE id = ?
                ");
                $stmt->bind_param("i", $_SESSION['user_id']);
                $stmt->execute();
                $res = $stmt->get_result();
                $uname = $res->fetch_assoc();

    $route = $_GET['page'] ?? 'dashboard';

    // After an admin password reset, every page leads to Change Password until a new one is set.
    if (!empty($_SESSION['must_change_password'])) {
        $route = 'settings_security';
    }

    // ' active' when the current page belongs to this sidebar item (e.g. Users covers users + create_account)
    $nav_active = fn(...$pages) => in_array($route, $pages, true) ? ' active' : '';
    $auth_open  = in_array($route, ['users', 'create_account', 'users_edit', 'settings_security'], true);

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Web Design Main Page</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.lineicons.com/5.0/lineicons.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" 
  integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
  <link rel="stylesheet" href="styles.css">
</head>
<body>

<div class="d-flex">
  <aside id="sidebar" class="sidebar-toggle">
    <div class="sidebar-logo">
    <a href="#">LOGO</a>
    
    </div>
    
    <ul class="sidebar-nav p-0">
      <li class="sidebar-header">
        Main Menu
      </li>
      <li class="sidebar-item">
  <a href="main.php?page=dashboard" class="sidebar-link<?= $nav_active('dashboard') ?>">
<i class="lni lni-dashboard-square-1"></i>    <span>Dashboard</span>
  </a>
</li>
   
<li class="sidebar-item">
  <a href="main.php?page=tasks_create" class="sidebar-link<?= $nav_active('tasks_create') ?>">
    <i class="lni lni-file-plus-circle"></i>
    <span>Create task</span>
  </a>
</li>

      <li class="sidebar-item">
  <a href="main.php?page=task" class="sidebar-link<?= $nav_active('task', 'tasks_edit', 'task_view') ?>">
    <i class="lni lni-agenda"></i>
    <span>Tasks</span>
  </a>
</li>
<li class="sidebar-item">
  <a href="main.php?page=calendar" class="sidebar-link<?= $nav_active('calendar') ?>">
    <i class="lni lni-calendar-days"></i>
    <span>Calendar</span>
  </a>
</li>
<li class="sidebar-item">
  <a href="main.php?page=analytics" class="sidebar-link<?= $nav_active('analytics') ?>">
    <i class="lni lni-bar-chart-4"></i>
    <span>Analytics</span>
  </a>
</li>
      <li class="sidebar-header">
        Pages
      </li>
      <li class="sidebar-item">
        <a href="#" class="sidebar-link has-dropdown<?= $auth_open ? '' : ' collapsed' ?>" data-bs-toggle="collapse"
        data-bs-target="#auth" aria-expanded="<?= $auth_open ? 'true' : 'false' ?>" aria-controls="auth">
        <i class="lni lni-shield-2"></i>
        <span>Auth</span>
        </a>
        <ul id="auth" class="sidebar-dropdown list-unstyled collapse<?= $auth_open ? ' show' : '' ?>" data-bs-parent="#sidebar">
          <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
          <li class="sidebar-item" >
            <a href="main.php?page=users" class="sidebar-link<?= $nav_active('users', 'create_account', 'users_edit') ?>">
              <i class="lni lni-user-multiple-4"></i>
              <span>Users</span></a>
          </li>
          <?php endif; ?>
          <li class="sidebar-item" >
            <a href="main.php?page=settings_security" class="sidebar-link<?= $nav_active('settings_security') ?>">
              <i class="lni lni-locked-2"></i>
              <span>Change Password</span></a>
          </li>
        </ul>
      </li>
      <li class="sidebar-item">
  <a href="main.php?page=settings_general" class="sidebar-link<?= $nav_active('settings_general') ?>">
    <i class="lni lni-gear-1"></i>
    <span>Settings</span>
  </a>
</li>
    </ul>
    <div class="sidebar-footer">
        <a href="logout.php" class="sidebar-link" name="LOGOUT_BTN">
          <i class="lni lni-exit"></i>
          <span>Logout</span>
        </a>
    </div>
  </aside>

  <!-- Dims the page behind the sidebar when it slides in on phones -->
  <div class="sidebar-backdrop" id="SIDEBAR_BACKDROP"></div>



  <div class="main">
    <nav class="navbar navbar-expand border-bottom px-3">
    <button class="toggler-btn me-3" type="button" aria-label="Toggle menu" aria-controls="sidebar" aria-expanded="false">
        <i class="lni lni-align-text-left"></i>
    </button>

    <div class="ms-auto d-flex align-items-center">

    <!-- Reminders bell (filled by REMINDERS in script.js) -->
    <div class="dropdown me-3">
        <a href="#" class="position-relative d-inline-flex text-dark" id="BELL"
           data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Reminders">
            <i class="lni lni-bell-1 fs-4"></i>
            <span id="BELL_COUNT" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none">0</span>
        </a>
        <div class="dropdown-menu dropdown-menu-end p-0 shadow-sm overflow-hidden" style="width:min(320px, calc(100vw - 1.5rem));">
            <div class="d-flex justify-content-between align-items-center px-3 py-2 bg-dark text-white">
                <span class="small fw-semibold">Reminders</span>
                <button type="button" class="btn btn-sm btn-link text-white p-0 small text-decoration-none" onclick="REMINDERS.dismiss(0);">
                    Mark all as read
                </button>
            </div>
            <div id="BELL_LIST" style="max-height:320px;overflow-y:auto;">
                <p class="text-muted small text-center py-3 mb-0">No reminders right now.</p>
            </div>
        </div>
    </div>

   <span class="badge d-none d-sm-inline-block
        <?php
            $name = $uname['first_name'] ?? 'Guest';
            $role = $_SESSION['role'] ?? 'Guest';
            echo match($role) {
                'Admin' => 'bg-dark',
                'User'  => 'bg-secondary',
                default => 'bg-secondary',
            };
        ?>" 
        title="User Role"
    >
     Welcome, <?php echo htmlspecialchars($name); ?>.
    </span>

    <div class="dropdown">
    <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle"
       data-bs-toggle="dropdown" aria-expanded="false">
        <i class="lni lni-user-4 fs-4 text-dark ms-2"></i>
    </a>

    <ul class="dropdown-menu dropdown-menu-end p-0">
        <li>
            <div class="dropdown-header text-center bg-dark text-white py-2">
             Logged on as <?php echo htmlspecialchars($uname['first_name'] ?? 'Unknown'); ?>
            </div>
        </li>

        <li><hr class="dropdown-divider m-0"></li>

        <li>
            <a class="dropdown-item" href="main.php?page=profile">
                <i class="lni lni-user-4 me-2"></i> Profile
            </a>
        </li>

        <li>
            <a class="dropdown-item" href="main.php?page=settings_general">
                <i class="lni lni-gear-1 me-2"></i> Settings
            </a>
        </li>

        <li>
            <a class="dropdown-item" href="logout.php">
                <i class="lni lni-exit me-2"></i> Logout
            </a>
        </li>
    </ul>
</div>
</nav>
<div class="container-fluid px-2 py-3 p-md-4">
<?php
$ALL = ['Admin', 'User'];

// page => [file, roles allowed to open it]
$routes = [
    'dashboard'        => ['pages/dashboard.php',            $ALL],
    'profile'          => ['pages/profile/profile.php',      $ALL],
    'task'             => ['pages/task/task.php',            $ALL],
    'tasks_create'     => ['pages/create/create.php',        $ALL],
    'tasks_edit'       => ['pages/create/create.php',        $ALL],
    'task_view'        => ['pages/task/view.php',            $ALL],
    'settings_general' => ['pages/settings/general.php',     $ALL],
    'settings_security'=> ['pages/settings/security.php',    $ALL],
    'users'            => ['pages/users/users.php',          ['Admin']],
    'create_account'   => ['pages/create_account/index.php', ['Admin']],
    'users_edit'       => ['pages/create_account/index.php', ['Admin']],
    'calendar'         => ['pages/calendar/calendar.php',    $ALL],
    'analytics'        => ['pages/analytics/analytics.php', $ALL],
];

if (!array_key_exists($route, $routes)) {
    $error_code = 404;
    require 'pages/unauthorized/unauthorized.php';
} elseif (!in_array($_SESSION['role'] ?? '', $routes[$route][1], true)) {
    $error_code = 403;
    require 'pages/unauthorized/unauthorized.php';
} else {
    require $routes[$route][0];
}

?>
</div>
  </div>
</div>


 
 
      <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
      <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
      <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM"
        crossorigin="anonymous"></script>

<script src="script.js"></script>

<?php if (!empty($_SESSION['flash'])): $flash = $_SESSION['flash']; unset($_SESSION['flash']); ?>
<script>
    Swal.fire({
        icon:  <?= json_encode($flash['type']) ?>,
        title: <?= json_encode($flash['type'] === 'success' ? 'Success' : 'Error') ?>,
        text:  <?= json_encode($flash['message']) ?>
    });
</script>
<?php endif; ?>

</body>
</html>
