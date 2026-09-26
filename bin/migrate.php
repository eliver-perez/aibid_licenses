<?php
declare(strict_types=1);
require __DIR__ . '/console.php';

consoleRun(function (): void {
    $options = getopt('', ['user:']);
    if (isset($options['user'])) {
        putenv('DB_USER=' . $options['user']);
        putenv('DB_PASSWORD=' . hiddenInput('Contraseña de la cuenta de migración: '));
    }
    $db = Aibid\Infrastructure\Database::connect(Aibid\Config::load());
    $migrator = new Aibid\Infrastructure\Migrator($db, dirname(__DIR__) . '/database/migrations');
    foreach ($migrator->migrate() as $name) { fwrite(STDOUT, 'Aplicada: ' . $name . PHP_EOL); }
    fwrite(STDOUT, "Esquema actualizado.\n");
});
