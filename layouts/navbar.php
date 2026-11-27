<?php
declare(strict_types=1);

function layout_navbar(string $title, string $module, array $navigation): void
{
    $user = current_user();
    $name = trim((string) ($user['full_name'] ?? '')) ?: (string) ($user['username'] ?? '');
    $initial = mb_strtoupper(mb_substr($name, 0, 1));
    $label = $navigation[$module]['label'] ?? $title;
    $notifications = $navigation['notifications'] ?? null;
    ?>
<header class="topbar">
    <div class="topbar-left">
        <button class="icon-button menu-toggle" type="button" aria-label="Toggle navigation" aria-controls="workspace-menu" aria-expanded="true" data-menu-toggle>
            <i class="bi bi-layout-sidebar-inset" aria-hidden="true"></i>
        </button>
        <nav class="page-crumb" aria-label="Breadcrumb">
            <a href="<?= e(app_url('index.php')) ?>" aria-label="Home"><i class="bi bi-house-door" aria-hidden="true"></i></a>
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
            <span><?= e($label) ?></span>
        </nav>
    </div>
    <div class="topbar-right">
        <?php if ($notifications && (empty($notifications['permission']) || can($notifications['permission']))): ?>
            <div class="dropdown" data-notifications="<?= e(app_url('api/notifications.php')) ?>">
                <button class="icon-button notification-trigger" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                    <i class="bi bi-bell" aria-hidden="true"></i><span class="unread-count" hidden></span>
                </button>
                <div class="dropdown-menu dropdown-menu-end notification-menu">
                    <h2 class="dropdown-header">Notifications</h2>
                    <div class="notification-items" aria-live="polite"></div>
                    <a class="dropdown-item notification-history" href="<?= e(app_url('modules/notifications/index.php')) ?>">View notification history <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                </div>
            </div>
        <?php endif; ?>
        <div class="dropdown profile-dropdown">
            <button class="profile-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= e('Account menu for ' . $name) ?>">
                <span class="avatar-initial" aria-hidden="true"><?= e($initial) ?></span>
                <span class="profile-text"><strong><?= e($name) ?></strong><?php if (!empty($user['role_name'])): ?><small><?= e($user['role_name']) ?></small><?php endif; ?></span>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="dropdown-menu dropdown-menu-end">
                <a class="dropdown-item" href="<?= e(app_url('auth/password.php')) ?>"><i class="bi bi-key" aria-hidden="true"></i>Change password</a>
                <form action="<?= e(app_url('auth/logout.php')) ?>" method="post">
                    <?= csrf_field() ?>
                    <button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>Sign out</button>
                </form>
            </div>
        </div>
    </div>
</header>
    <?php
}
