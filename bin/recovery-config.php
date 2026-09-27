<?php
declare(strict_types=1);
require __DIR__.'/console.php';

consoleRun(function (): void {
    $options = getopt('',['directory:','output:','dsn:','user:','signing-dir:']);
    if (array_diff(['directory','output','dsn','user','signing-dir'],array_keys($options))) { throw new Aibid\Domain\Problem('Uso: recovery-config.php --directory=/respaldo/extraido --output=/config/nueva.php --dsn="mysql:..." --user=cuenta --signing-dir=/claves/recuperadas'); }
    Aibid\Infrastructure\Backup::outsidePublic($options['output']);
    Aibid\Infrastructure\Backup::outsidePublic($options['signing-dir']);
    if (file_exists($options['output']) || is_link($options['output'])) { throw new Aibid\Domain\Problem('El archivo de destino ya existe.'); }
    $source = rtrim($options['directory'],'/').'/config.json';
    $manifest = json_decode(file_get_contents(rtrim($options['directory'],'/').'/manifest.json'),true,16,JSON_THROW_ON_ERROR);
    if (!is_file($source) || is_link($source) || !hash_equals($manifest['files_sha256']['config.json'] ?? '',hash_file('sha256',$source))) { throw new Aibid\Domain\Problem('La configuración no coincide con el respaldo extraído.'); }
    $values = json_decode(file_get_contents($source),true,16,JSON_THROW_ON_ERROR);
    foreach (['DB_DSN'=>'dsn','DB_USER'=>'user','SIGNING_KEY_DIR'=>'signing-dir'] as $name=>$option) { $values[$name]=$options[$option]; }
    $values['DB_PASSWORD'] = hiddenInput('Contraseña SQL de la cuenta de destino: ');
    (new Aibid\Config($values,false))->validate();
    $mask = umask(0077);
    try {
        $file = fopen($options['output'],'x');
        if (!$file) { throw new RuntimeException('Cannot create recovery configuration.'); }
        $bytes = "<?php\ndeclare(strict_types=1);\nreturn ".var_export($values,true).";\n";
        try { if (fwrite($file,$bytes)!==strlen($bytes) || !fflush($file) || !fsync($file)) { throw new RuntimeException('Incomplete recovery configuration.'); } }
        finally { fclose($file); }
    } finally { umask($mask); }
    fwrite(STDOUT,"Configuración de recuperación creada; se conservaron las cinco claves y el entorno del respaldo. No se habilitó el servicio.\n");
});
