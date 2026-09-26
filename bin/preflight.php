<?php
declare(strict_types=1);
require __DIR__ . '/console.php';

consoleRun(function (): void {
    if (PHP_VERSION_ID < 80300) { throw new Aibid\Domain\Problem('Se requiere PHP 8.3 o superior.'); }
    foreach (['sodium','pdo_mysql','mbstring','xmlwriter'] as $extension) { if (!extension_loaded($extension)) { throw new Aibid\Domain\Problem('Falta la extensión ' . $extension); } }
    if (!in_array('argon2id', password_algos(), true)) { throw new Aibid\Domain\Problem('Argon2id no está disponible.'); }
    $app = Aibid\App::boot();
    $version = $app->db->execute('SELECT VERSION()')->fetchColumn();
    if (!str_starts_with($version, '8.') || stripos($version, 'MariaDB') !== false) { throw new Aibid\Domain\Problem('El servidor requiere MySQL 8.'); }
    if ($app->db->one("SELECT name FROM schema_migrations WHERE state<>'complete'")) { throw new Aibid\Domain\Problem('Existe una migración incompleta.'); }
    foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') as $file) {
        $migration = $app->db->one('SELECT checksum FROM schema_migrations WHERE name=?', [basename($file)]);
        if (!$migration || !hash_equals($migration['checksum'], hash_file('sha256', $file))) {
            throw new Aibid\Domain\Problem('Falta aplicar una migración o su archivo cambió: ' . basename($file));
        }
    }
    if (!$app->config->development()) {
        foreach (['audit_events','license_changes','subscription_periods','maintenance_purchases','license_revisions'] as $table) {
            foreach (['UPDATE ' . $table . ' SET id=id WHERE 1=0', 'DELETE FROM ' . $table . ' WHERE 1=0'] as $sql) {
                if ($table === 'audit_events') { $sql = str_replace('SET id=id', 'SET sequence=sequence', $sql); }
                if ($table === 'license_revisions') { $sql = str_replace('SET id=id', 'SET revision_id=revision_id', $sql); }
                try { $app->db->execute($sql); throw new Aibid\Domain\Problem('La cuenta de aplicación tiene permisos de modificación excesivos sobre ' . $table . '.'); }
                catch (PDOException $error) { if (!in_array((int) ($error->errorInfo[1] ?? 0), [1142,1143], true)) { throw $error; } }
            }
        }
    }
    $options = getopt('', ['require-signer']);
    if (array_key_exists('require-signer', $options)) {
        $app->signingKeys->check();
        fwrite(STDOUT, "Firmante de licencias del entorno verificado.\n");
    }
    fwrite(STDOUT, 'PHP ' . PHP_VERSION . ' · MySQL ' . $version . " · configuración y extensiones verificadas.\n");
    fwrite(STDOUT, "Comprueba también el binario PHP-FPM, TLS y la raíz web public/ antes de desplegar.\n");
});
