<?php
declare(strict_types=1);
function audit(string $action,string $entity,?int $id=null,array $details=[],?int $actor=null): void {
    run_sql('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,details,ip_address) VALUES(?,?,?,?,?,?)',[
        $actor ?? ($_SESSION['user_id'] ?? null),$action,$entity,$id,$details ? json_encode($details,JSON_THROW_ON_ERROR) : null,$_SERVER['REMOTE_ADDR'] ?? null
    ]);
}

