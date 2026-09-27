<?php
declare(strict_types=1);
require __DIR__.'/console.php';

consoleRun(function (): void {
    $options = getopt('',['against:']);
    $anchor = isset($options['against']) ? json_decode(file_get_contents($options['against']),true,16,JSON_THROW_ON_ERROR) : null;
    $app = Aibid\App::boot();
    $result = Aibid\Infrastructure\RecoveryVerifier::verify($app,$anchor);
    fwrite(STDOUT,json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL);
    fwrite(STDOUT,"Verificación completada; este comando no habilita emisión ni elimina mantenimiento.\n");
});
