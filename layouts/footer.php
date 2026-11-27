<?php
declare(strict_types=1);

function layout_scripts(): void
{
    $scripts = [
        'lib/bootstrap/dist/js/bootstrap.bundle.min.js',
        'lib/sweetalert2/dist/sweetalert2.min.js',
        'lib/jquery/dist/jquery.min.js',
        'lib/datatables.net/js/dataTables.min.js',
        'lib/datatables.net-bs5/js/dataTables.bootstrap5.min.js',
        'lib/chart.js/dist/chart.umd.js',
        'js/app.js',
    ];
    foreach ($scripts as $script): ?>
        <script src="<?= e(app_url('assets/' . $script)) ?>" defer></script>
    <?php endforeach;
    ?>
</body>
</html>
    <?php
}

function layout_end(): void
{
    ?>
    </main>
</div>
<div class="toast-region" aria-live="polite" aria-atomic="false"></div>
    <?php
    layout_scripts();
}
