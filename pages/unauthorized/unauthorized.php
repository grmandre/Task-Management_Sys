<?php
// Rendered inside main.php for unknown routes (404) and pages the user's role can't open (403).
$error_code = $error_code ?? 403;
$is_404     = $error_code === 404;
?>

<div class="container-fluid py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">
            <div class="card shadow-sm border-0 rounded-4 text-center">
                <div class="card-body p-5">
                    <div class="rounded-circle bg-dark text-white d-inline-flex align-items-center justify-content-center mb-3"
                         style="width:72px;height:72px;">
                        <i class="lni <?= $is_404 ? 'lni-search-1' : 'lni-locked-2' ?> fs-2"></i>
                    </div>
                    <h4 class="fw-bold mb-1"><?= $is_404 ? 'Page Not Found' : 'Access Denied' ?></h4>
                    <p class="text-muted small mb-4">
                        <?= $is_404
                            ? 'The page you are looking for does not exist or has been moved.'
                            : 'You do not have permission to view this page. Contact an administrator if you need access.' ?>
                    </p>
                    <a href="main.php?page=dashboard" class="btn btn-dark px-4 rounded-3">
                        <i class="lni lni-dashboard-square-1 me-1"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
