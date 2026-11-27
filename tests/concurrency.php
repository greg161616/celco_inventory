<?php
declare(strict_types=1);
require_once __DIR__.'/inventory.php';
if(($argv[1]??'')==='worker'){
    $id=id_value($argv[2]);$actor=id_value($argv[3]);$_SESSION=['user_id'=>$actor];echo "READY\n";fflush(STDOUT);fgets(STDIN);
    try{post_document($id,$actor,'ISSUE');echo "POSTED\n";}
    catch(InputError $e){if(!str_contains($e->getMessage(),'Insufficient stock'))throw $e;echo "INSUFFICIENT\n";}
    exit;
}
$f=inventory_fixture();$opening=inventory_draft($f,'OPENING',[inventory_line($f['wire'],'10',null,$f['a'])]);post_document($opening,$f['actor']);
$jobs=[];$issues=[];
for($i=0;$i<2;$i++){
    $doc=inventory_draft($f,'ISSUE',[inventory_line($f['wire'],'7',$f['a'],null)]);$issues[]=$doc;
    $process=proc_open([PHP_BINARY,__FILE__,'worker',(string)$doc,(string)$f['actor']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process)||trim((string)fgets($pipes[1]))!=='READY')throw new RuntimeException('Worker unavailable.');
    $jobs[]=[$process,$pipes];
}
foreach($jobs as [$process,$pipes]){fwrite($pipes[0],"POST\n");fclose($pipes[0]);}
$outcomes=[];foreach($jobs as [$process,$pipes]){
    $outcomes[]=trim(stream_get_contents($pipes[1]));$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException($error);
}
sort($outcomes);checked($outcomes===['INSUFFICIENT','POSTED'],'two independent connections compete for the same stock and only one posts');
assert_balance($f['wire'],$f['a'],'3');assert_reconciled($f);

