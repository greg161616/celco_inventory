<?php
declare(strict_types=1);

require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/navbar.php';
require_once __DIR__ . '/footer.php';

function layout_head(string $title): void
{
    $styles = [
        'lib/bootstrap/dist/css/bootstrap.min.css',
        'lib/bootstrap-icons/font/bootstrap-icons.min.css',
        'lib/sweetalert2/dist/sweetalert2.min.css',
        'lib/datatables.net-bs5/css/dataTables.bootstrap5.min.css',
        'css/app.css',
    ];
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= e($title) ?> · CELCO IMS</title>
    <link rel="icon" href="<?= e(app_url('assets/image/CELCO.png')) ?>" type="image/png">
    <?php foreach ($styles as $style): ?>
        <link rel="stylesheet" href="<?= e(app_url('assets/' . $style)) ?>">
    <?php endforeach; ?>
</head>
    <?php
}

function layout_start(string $title, string $module = ''): void
{
    layout_head($title);
    $navigation = module_nav();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    ?>
<body class="workspace" data-base-url="<?= e(rtrim(app_url(''), '/')) ?>">
<a class="skip-link" href="#main-content">Skip to content</a>
<?php layout_sidebar($module, $navigation); ?>
<button class="sidebar-overlay" type="button" aria-label="Close navigation" data-menu-close hidden></button>
<div class="workspace-main">
    <?php layout_navbar($title, $module, $navigation); ?>
    <main class="page-content" id="main-content" tabindex="-1">
        <div class="page-heading">
            <h1><?= e($title) ?></h1>
        </div>
        <?php if (is_array($flash) && isset($flash[0], $flash[1])):
            $level = in_array($flash[0], ['success','danger','warning','info'], true) ? $flash[0] : 'info'; ?>
            <div class="alert alert-<?= e($level) ?>" role="status" data-flash="<?= e($level) ?>"><?= e($flash[1]) ?></div>
        <?php endif; ?>
    <?php
}
