<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/app.php';$user=require_permission('dashboard.view');require_once APP_ROOT.'/includes/notifications.php';json_response(true,'Notifications loaded.',recent_notifications((int)$user['id']));
