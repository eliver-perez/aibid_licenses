<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$job=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$config=new Aibid\Config($job['config'],false);
$app=new Aibid\App($config,Aibid\Infrastructure\Database::connect($config),new Aibid\Tests\FrozenClock(new DateTimeImmutable('2026-09-25T12:00:00Z')));
$response=$app->activations->execute('activate',$job['input']);
echo Aibid\Domain\Protocol::json(['status'=>$response->status,'body'=>$response->body]);
