<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/app.php';$user=require_permission('dashboard.view');
post_action(function()use($user){if(isset($_POST['id']))run_sql('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=?',[id_value($_POST['id']),$user['id']]);else run_sql('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL',[$user['id']]);},'modules/notifications/index.php');
layout_start('Notifications','notifications');echo '<form method="post">'.csrf_field();button_action('all','Mark all as read');echo '</form>';
table_html(['created_at'=>'Received','message'=>'Message','read_at'=>'Read at'],rows('SELECT id,created_at,message,read_at FROM notifications WHERE user_id=? ORDER BY id DESC'.page_limit(),[$user['id']]),function($r){if(!$r['read_at']){echo '<form method="post">'.csrf_field().'<input type="hidden" name="id" value="'.e($r['id']).'">';button_action('one','Mark as read');echo '</form>';}});
pager((int)scalar('SELECT COUNT(*) FROM notifications WHERE user_id=?',[$user['id']]));layout_end();
