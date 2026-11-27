<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/app.php';
require_user();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit;}
verify_csrf(); audit('LOGOUT','users',(int)$_SESSION['user_id']); clear_session(); redirect_to('auth/login.php');

