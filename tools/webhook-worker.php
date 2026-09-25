<?php

declare(strict_types=1);

use Forwext\App\Web\WebApplicationFactory;

$root=dirname(__DIR__);
require $root.'/vendor/autoload.php';

$raw=$argv[1]??'25';
if(!is_string($raw)||preg_match('/^[1-9][0-9]{0,2}$/D',$raw)!==1){
    fwrite(STDERR,"Usage: php tools/webhook-worker.php [1-500]\n");
    exit(2);
}
$limit=(int)$raw;
if($limit<1||$limit>500){
    fwrite(STDERR,"Webhook worker batch limit must be 1-500.\n");
    exit(2);
}

try{
    $processed=(new WebApplicationFactory($root))->createWebhookWorker()->run($limit);
    printf("Webhook worker processed %d job(s).\n",$processed);
    exit(0);
}catch(Throwable $e){
    fwrite(STDERR,"Webhook worker failed: ".$e->getMessage()."\n");
    exit(1);
}
