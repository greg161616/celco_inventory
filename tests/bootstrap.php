<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/schema.php';
$pdo=fixture_connection((string)getenv('CELCO_TEST_FIXTURE'));
define('APP_ROOT',dirname(__DIR__));
require_once APP_ROOT.'/includes/functions.php';
foreach(['validation','csrf','audit','permissions','auth','ui','catalog'] as $lib)require_once APP_ROOT."/includes/$lib.php";
$pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'");
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
date_default_timezone_set('Asia/Manila');
function checked(bool $result,string $label): void {if(!$result)throw new RuntimeException($label);echo "PASS $label\n";}
function rejected(callable $fn,string $label): void {try{$fn();}catch(InputError|PDOException $e){checked(true,$label);return;}throw new RuntimeException("Expected rejection: $label");}

