<?php
declare(strict_types=1);
require __DIR__ . '/console.php';

consoleRun(function (): void {
    $app = Aibid\App::boot();
    $anchor = $app->db->transaction(function () use ($app): array {
        $app->db->one("SELECT * FROM audit_heads WHERE stream_id='admin' FOR SHARE");
        $count = $app->audit->verify();
        $head = $app->db->one("SELECT * FROM audit_heads WHERE stream_id='admin'");
        return ['events' => $count, 'last_hash' => $head['last_event_hash'], 'verified_at' => $app->clock->now()->format(DATE_ATOM)];
    });
    fwrite(STDOUT, json_encode($anchor, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
});
