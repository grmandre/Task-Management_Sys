<?php
// Shared header + tabs for the settings pages. Expects $settings_tab = 'general' | 'security'.
$settings_tab = $settings_tab ?? 'general';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h4 class="fw-bold mb-0">Settings</h4>
        <small class="text-muted">Manage your account and security</small>
    </div>
    <span class="badge bg-dark px-3 py-2"><?= htmlspecialchars($_SESSION['role'] ?? '') ?></span>
</div>

<div class="btn-group btn-group-sm mb-4" role="group" aria-label="Settings sections">
    <a href="main.php?page=settings_general"
       class="btn <?= $settings_tab === 'general' ? 'btn-dark' : 'btn-outline-dark' ?>">
        <i class="lni lni-gear-1 me-1"></i> General
    </a>
    <a href="main.php?page=settings_security"
       class="btn <?= $settings_tab === 'security' ? 'btn-dark' : 'btn-outline-dark' ?>">
        <i class="lni lni-locked-2 me-1"></i> Security
    </a>
</div>
