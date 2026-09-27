<?php
declare(strict_types=1);
require __DIR__ . '/console.php';

consoleRun(function (): void {
    $options = getopt('', ['env:', 'url:', 'dsn:', 'user:', 'output:', 'signing-dir:']);
    if (array_diff(['env','url','dsn','user'], array_keys($options))) {
        throw new Aibid\Domain\Problem('Uso: php bin/configure.php --env=production --url=https://licencias.example.com --dsn="mysql:host=127.0.0.1;dbname=aibid_licenses;charset=utf8mb4" --user=aibid_app');
    }
    if (!in_array($options['env'], ['production','development'], true)) { throw new Aibid\Domain\Problem('Entorno no válido.'); }
    $path = $options['output'] ?? dirname(__DIR__) . '/config/local.php';
    Aibid\Infrastructure\Backup::outsidePublic($path);
    if (file_exists($path) || is_link($path)) { throw new Aibid\Domain\Problem('La configuración ya existe; no se reemplazaron sus claves.'); }
    $values = ['APP_ENV' => $options['env'], 'APP_URL' => $options['url'], 'DB_DSN' => $options['dsn'], 'DB_USER' => $options['user'], 'DB_PASSWORD' => hiddenInput('Contraseña de la base de datos: '), 'ADMIN_NETWORKS' => ''];
    foreach (['MFA_KEY','CREDENTIAL_KEY','AUDIT_KEY','SESSION_KEY','OPERATION_KEY'] as $key) { $values[$key] = base64_encode(random_bytes(32)); }
    if (isset($options['signing-dir'])) {
        Aibid\Infrastructure\Backup::outsidePublic($options['signing-dir']);
        $values['SIGNING_KEY_DIR'] = $options['signing-dir'];
    }
    (new Aibid\Config($values,false))->validate();
    $oldMask = umask(0077);
    try {
        $file = fopen($path, 'x');
        if (!$file) { throw new RuntimeException('Could not create configuration.'); }
        $contents = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($values, true) . ";\n";
        if (fwrite($file, $contents) !== strlen($contents)) { throw new RuntimeException('Incomplete configuration write.'); }
        fclose($file);
    } finally { umask($oldMask); }
    fwrite(STDOUT, "Configuración y claves independientes creadas. Protege y respalda el archivo: " . $path . "\n");
});
