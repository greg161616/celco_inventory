<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/app.php';require_permission('audit.view');layout_start('Audit trail','audit-logs');$q='%'.string_value($_GET['q']??'',100,false).'%';
echo '<form class="panel">';field('q','Find action, entity or user',$_GET['q']??'');echo '<button class="btn btn-light">Search</button></form>';
$from=' FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE a.action LIKE ? OR a.entity_type LIKE ? OR u.username LIKE ?';$args=[$q,$q,$q];table_html(['created_at'=>'Time','username'=>'Account','action'=>'Action','entity_type'=>'Entity','entity_id'=>'Record','details'=>'Details','ip_address'=>'Address'],rows('SELECT a.created_at,u.username,a.action,a.entity_type,a.entity_id,a.details,a.ip_address'.$from.' ORDER BY a.id DESC'.page_limit(),$args));pager((int)scalar('SELECT COUNT(*)'.$from,$args));layout_end();
