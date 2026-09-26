<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli' || count($argv)!==2) { exit(2); }
$data=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$config=new Aibid\Config($data['config'],false);
$clock=new Aibid\Tests\FrozenClock(new DateTimeImmutable('2026-09-25T12:00:00Z'));
$app=new Aibid\App($config,Aibid\Infrastructure\Database::connect($config),$clock);
$actor=new Aibid\Application\Actor($data['actor'],'superadmin');
$result=$app->licenses->change($actor,$data['operation'],$data['license'],'renew',$data['input']);
echo json_encode($result,JSON_THROW_ON_ERROR);
