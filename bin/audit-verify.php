<?php
declare(strict_types=1);
require __DIR__ . '/console.php';

consoleRun(function (): void {
    $options = getopt('',['against:']);
    $expected = isset($options['against']) ? json_decode(file_get_contents($options['against']),true,16,JSON_THROW_ON_ERROR) : null;
    $app = Aibid\App::boot();
    $anchor = $app->db->transaction(function () use ($app,$expected): array {
        $app->db->one("SELECT * FROM audit_heads WHERE stream_id='admin' FOR SHARE");
        $count = $app->audit->verify();
        if ($expected!==null) { $app->audit->assertAnchor($expected); }
        $head = $app->db->one("SELECT * FROM audit_heads WHERE stream_id='admin'");
        return ['events' => $count, 'last_hash' => $head['last_event_hash'], 'verified_at' => $app->clock->now()->format(DATE_ATOM)];
    });
    fwrite(STDOUT, json_encode($anchor, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
});
