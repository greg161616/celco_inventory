<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/app.php';
$file=setting('company_logo');$path=APP_ROOT.'/assets/image/CELCO.png';
if(preg_match('/^[a-f0-9]{40}\.png$/D',$file) && is_file(APP_ROOT.'/storage/uploads/'.$file))$path=APP_ROOT.'/storage/uploads/'.$file;
header('Content-Type: image/png');readfile($path);
