<?php
declare(strict_types=1);

function layout_sidebar(string $module, array $navigation): void
{
    $groups = [];
    foreach ($navigation as $key => $item) {
        if (empty($item['permission']) || can($item['permission'])) {
            $groups[$item['group'] ?: 'Workspace'][$key] = $item;
        }
    }
    ?>
<aside class="sidebar" id="workspace-menu" aria-label="Main navigation">
    <div class="sidebar-brand">
        <a class="brand-lockup" href="<?= e(app_url('index.php')) ?>" aria-label="CELCO IMS home">
            <img src="<?= e(app_url('api/logo.php')) ?>" width="42" height="40" alt="">
            <span><strong>CELCO</strong><small>Material inventory</small></span>
        </a>
        <button class="icon-button mobile-close" type="button" data-menu-close aria-label="Close navigation"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <nav class="sidebar-nav" aria-label="Workspace sections">
    <?php foreach ($groups as $heading => $items): ?>
        <div class="nav-group">
            <div class="nav-group-label"><?= e($heading) ?></div>
            <?php foreach ($items as $key => $item):
                $icon = preg_replace('/^bi[- ](?:bi-)?/', '', $item['icon'] ?? 'grid');
                ?>
                <a href="<?= e(app_url('modules/' . $key . '/index.php')) ?>" class="nav-item-link<?= $module === $key ? ' active' : '' ?>"<?= $module === $key ? ' aria-current="page"' : '' ?> title="<?= e($item['label']) ?>">
                    <i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i>
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    </nav>
</aside>
    <?php
}
