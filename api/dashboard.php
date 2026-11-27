<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/app.php';require_permission('dashboard.view');require_once APP_ROOT.'/includes/dashboard.php';
ob_start();dashboard_panels(dashboard_data($_GET));json_response(true,'Overview updated.',['html'=>ob_get_clean()]);
