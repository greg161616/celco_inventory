<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/app.php';$user=require_permission('settings.manage');require_once APP_ROOT.'/includes/inventory.php';
$fields=['company_name'=>['Company name','Camotes Electric Cooperative, Inc.'],'system_name'=>['System name','CELCO Material Inventory Management System'],'company_address'=>['Company address',''],'contact_details'=>['Contact details',''],'timezone'=>['Time zone','Asia/Manila'],'currency'=>['Currency','PHP'],'default_warehouse'=>['Default warehouse',''],'low_stock_alerts'=>['Low-stock alerts','1'],'session_timeout'=>['Session timeout in minutes','30'],'report_footer'=>['Report footer',''],'report_signatories'=>['Report signatory labels','Prepared by · Reviewed by']];
$currencyOptions=currency_choices();$currentCurrency=setting('currency','PHP');
if(!isset($currencyOptions[$currentCurrency]) && preg_match('/^[A-Z]{3}$/D',$currentCurrency))$currencyOptions[$currentCurrency]=$currentCurrency.' (current)';
foreach([...array_keys(DOCUMENT_PERMISSIONS),'REQ','COUNT','MAT'] as $type)$fields['prefix_'.strtolower($type)]=[ucfirst(strtolower($type)).' number prefix',substr($type,0,4)];
post_action(function()use($fields,$user,$currencyOptions){atomic(function()use($fields,$user,$currencyOptions){
    $updates=[];foreach($fields as $key=>[$label,$default]){
        $value=string_value($_POST[$key]??'',255,false)??'';
        if(str_starts_with($key,'prefix_'))ensure((bool)preg_match('/^[A-Z0-9-]{1,14}$/D',$value),'Use 1–14 uppercase letters, digits or hyphens for prefixes.');
        if(in_array($key,['company_name','system_name'],true))ensure($value!=='','Enter company and system names.');
        if($key==='currency'){
            ensure(isset($currencyOptions[$value]),'Choose a currency from the list.');
            if($value!==setting('currency','PHP'))ensure(!scalar('SELECT id FROM stock_document_lines WHERE unit_cost IS NOT NULL LIMIT 1'),'Existing costs use the configured currency. A currency conversion requires an accounting decision.');
        }
        if($key==='timezone')choice($value,DateTimeZone::listIdentifiers());
        if($key==='low_stock_alerts')choice($value,['0','1']);
        if($key==='session_timeout')ensure(ctype_digit($value) && (int)$value>=5 && (int)$value<=480,'Session timeout must be 5–480 minutes.');
        if($key==='default_warehouse' && $value!=='')active_ref('warehouses',$value);
        $updates[$key]=$value;
    }
    $file=$_FILES['logo']??null;
    if($file && $file['error']!==UPLOAD_ERR_NO_FILE){
        ensure($file['error']===UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name']) && filesize($file['tmp_name'])<=2097152,'Upload a PNG or JPEG file of at most 2 MB.');
        $size=getimagesize($file['tmp_name']);ensure($size && in_array($size[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG],true) && $size[0]<=2048 && $size[1]<=2048,'Logo must be a PNG or JPEG up to 2048 pixels on each side.');
        $bitmap=imagecreatefromstring(file_get_contents($file['tmp_name']));ensure((bool)$bitmap,'Invalid image.');
        $name=bin2hex(random_bytes(20)).'.png';imagesavealpha($bitmap,true);ensure(imagepng($bitmap,APP_ROOT.'/storage/uploads/'.$name),'Logo could not be stored.');$updates['company_logo']=$name;
    }
    foreach($updates as $k=>$v)run_sql('INSERT INTO settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)',[$k,$v,$user['id']]);
    audit('SETTINGS_UPDATE','settings',null,['keys'=>array_keys($updates)]);
});},'modules/settings/index.php');
layout_start('System settings','settings');echo '<form class="panel" method="post" enctype="multipart/form-data">'.csrf_field().'<div class="form-grid">';
foreach($fields as $key=>[$label,$default]){
    $value=setting($key,$default);
    if($key==='low_stock_alerts')field($key,$label,$value,'select',[1=>'Enabled',0=>'Disabled'],true);
    elseif($key==='default_warehouse')field($key,$label,$value,'select',options_for('warehouses'));
    elseif($key==='currency')field($key,$label,$value,'select',$currencyOptions,true);
    else field($key,$label,$value);
}
echo '<label class="field">Company logo <input type="file" name="logo" accept="image/png,image/jpeg"></label></div>';button_action('save','Save settings',true);echo '</form>';layout_end();
