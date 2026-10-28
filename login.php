<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="loginstyle.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.26.20/dist/sweetalert2.min.css">
    <link href="https://cdn.lineicons.com/4.0/lineicons.css" rel="stylesheet">

</head>
<body>

<div class="container login-wrapper">
    <div class="row min-vh-100 align-items-center justify-content-center">
        <div class="col-12 col-sm-10 col-md-8 col-lg-4">

            <div class="card login-card shadow-lg ">
                <div class="card-body p-4">

                    <div class="text-center mb-4">
                        <i class="lni lni-shield-2 fs-1"></i>
                        <h4 class="mt-2">Productivity Tracker</h4>
                    </div>

                    <form method="post" action="auth.php">

                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" >
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <div class="password-field">
                                <input type="password" name="password" class="form-control" >
                                <button type="button" class="password-eye" onclick="TOGGLE_PASSWORD(this);"
                                        title="Show password" aria-label="Show password" aria-pressed="false">
                                    <i class="lni lni-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="d-grid mb-4">
                        </div>
                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-primary mx-auto" id="login-button">
                                Sign In
                            </button>
                        </div>

                        <div class="text-center">
                            <a href="#" class="small text-decoration-none" id="FORGOT_LINK">
                                Forgot password?
                            </a>
                        </div>
 
                    </form>

                </div>
            </div>

        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.26.20/dist/sweetalert2.all.min.js"></script>
<script>
    // Eye button next to the password field: shows / hides what was typed (same as script.js)
    function TOGGLE_PASSWORD(btn) {
        const input = btn.parentElement.querySelector('input');
        const show  = input.type === 'password';
        input.type  = show ? 'text' : 'password';
        btn.classList.toggle('active', show);
        btn.setAttribute('aria-pressed', show);
        btn.title = show ? 'Hide password' : 'Show password';
        btn.setAttribute('aria-label', btn.title);
    }

    document.getElementById('FORGOT_LINK').addEventListener('click', function (e) {
        e.preventDefault();
        Swal.fire({
            theme: 'dark',
            icon: 'info',
            title: 'Forgot Password',
            text: 'Please contact your system administrator to have your password reset.',
            confirmButtonText: 'OK'
        });
    });
</script>

<?php if (isset($_SESSION['empty'])): ?>
    <script> 
        Swal.fire({
            theme: 'dark',
            icon: 'warning',
            title: 'Empty Fields',
            text: <?= json_encode($_SESSION['empty']) ?>,
            confirmButtonText: 'OK'          
          }); 
    </script>
    <?php unset($_SESSION['empty']); ?>
    <?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<script>
        Swal.fire({
            theme: 'dark',
            icon: 'error',
            title: 'Login Failed',
            text: <?= json_encode($_SESSION['error']) ?>,
            confirmButtonText: 'OK'
            });
    </script>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>



</body>
</html>
