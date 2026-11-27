<?php
declare(strict_types=1);
function ensure(bool $valid,string $message): void { if(!$valid) throw new InputError($message); }
function string_value(mixed $v,int $max=255,bool $required=true): ?string {
    ensure(is_scalar($v)||$v===null,'Invalid text field.');
    $v=trim((string)$v);
    ensure(!$required || $v!=='','Complete all required fields.');
    ensure(mb_strlen($v)<=$max,"Text exceeds the $max character limit.");
    return $v==='' ? null : $v;
}
function id_value(mixed $v,bool $required=true): ?int {
    if(!$required && ($v===null || $v==='')) return null;
    ensure(is_scalar($v) && filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false,'Choose a valid record.');
    return (int)$v;
}
function decimal_value(mixed $v,int $scale=3,bool $positive=false): string {
    ensure(is_scalar($v),'Enter a decimal number.');
    $v=trim((string)$v);
    ensure((bool)preg_match('/^\d{1,'.(18-$scale).'}(?:\.\d{1,'.$scale.'})?$/D',$v),'Enter a non-negative number with up to '.$scale.' decimal places.');
    ensure(!$positive || bccomp($v,'0',$scale)>0,'Quantity must be greater than zero.');
    return bcadd($v,'0',$scale);
}
function date_value(mixed $v): string {
    $v=string_value($v,10);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
    ensure($date && $date->format('Y-m-d')===$v,'Enter a valid calendar date.');
    return $v;
}
function choice(mixed $v,array $allowed): string {
    ensure(is_string($v) && in_array($v,$allowed,true),'Choose a valid option.'); return $v;
}
function password_value(mixed $value): string {
    ensure(is_string($value) && strlen($value)>=12 && strlen($value)<=72 && !str_contains($value,"\0"),'Use a password between 12 and 72 bytes.');
    return $value;
}
function active_ref(string $table,mixed $id,bool $required=true): ?int {
    $id=id_value($id,$required);
    if($id===null) return null;
    $status=match($table){'projects'=>" AND status IN ('PLANNED','ACTIVE')",'units','roles'=> '',default=>' AND is_active=1'};
    ensure((bool)scalar("SELECT id FROM $table WHERE id=?$status",[$id]),'Choose an active record.');
    return $id;
}

