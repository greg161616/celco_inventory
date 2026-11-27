<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/app.php';
http_response_code(403);
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Access denied | CELCO IMS</title><main><h1>Access denied</h1><p>Your account does not have permission to perform this action.</p><a href="<?= e(app_url()) ?>">Return to workspace</a></main></html>
