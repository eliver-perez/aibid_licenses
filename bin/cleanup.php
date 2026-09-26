<?php
declare(strict_types=1);
require __DIR__ . '/console.php';

consoleRun(function (): void {
    $app=Aibid\App::boot();
    $app->db->transaction(function () use($app): void {
        $sessions=$app->db->execute('DELETE FROM admin_sessions WHERE expires_at<?',[$app->clock->now()->modify('-1 day')->format('Y-m-d H:i:s.u')])->rowCount();
        $buckets=$app->db->execute('DELETE FROM rate_limit_buckets WHERE window_start<?',[$app->clock->now()->getTimestamp()-172800])->rowCount();
        $challenges=$app->db->execute('DELETE FROM activation_challenges WHERE expires_at<?',[$app->clock->now()->modify('-1 day')->format('Y-m-d H:i:s.u')])->rowCount();
        $app->audit->append(null,'maintenance.cleanup','system',null,['sessions'=>$sessions,'rate_buckets'=>$buckets,'challenges'=>$challenges]);
    });
    echo "Sesiones y contadores temporales vencidos depurados. La historia comercial e idempotencia se conservan.\n";
});
