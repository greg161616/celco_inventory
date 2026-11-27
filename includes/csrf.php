<?php
declare(strict_types=1);
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">'; }
function verify_csrf(): void {
    $token=$_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if(!is_string($token) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],$token)) {
        if(is_api()) json_response(false,'Refresh the page and try again.',null,403);
        http_response_code(403); exit('The form expired. Refresh the page and try again.');
    }
}

