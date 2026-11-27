<?php
declare(strict_types=1);
require_once __DIR__.'/config/app.php';
require_user();
foreach(module_nav() as $key=>$menu) if(can($menu['permission'])) redirect_to('modules/'.$key.'/index.php');
deny();

