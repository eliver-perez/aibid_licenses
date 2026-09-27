<?php
declare(strict_types=1);
require __DIR__.'/console.php';

consoleRun(function (): void {
    $options = getopt('',['directory:','target-config:','mysql:']);
    if (!isset($options['directory'],$options['target-config'])) { throw new Aibid\Domain\Problem('Uso: restore-empty.php --directory=/ruta/extraida --target-config=/ruta/config-destino.php [--mysql=/ruta/mysql]'); }
    $file = $options['target-config'];
    if (!is_file($file) || is_link($file) || (fileperms($file)&0077)!==0) { throw new Aibid\Domain\Problem('La configuración de destino debe tener permisos 0600.'); }
    $values = require $file;
    if (!is_array($values)) { throw new Aibid\Domain\Problem('La configuración de destino no es válida.'); }
    $directory = realpath($options['directory']);
    if (!$directory || !is_file($directory.'/manifest.json')) { throw new Aibid\Domain\Problem('Extrae y verifica primero el respaldo.'); }
    $manifest = json_decode(file_get_contents($directory.'/manifest.json'),true,16,JSON_THROW_ON_ERROR);
    $sql = $directory.'/database.sql';
    if (!isset($manifest['files_sha256']['database.sql']) || !is_file($sql) || is_link($sql) || !hash_equals($manifest['files_sha256']['database.sql'],hash_file('sha256',$sql))) { throw new Aibid\Domain\Problem('El dump no coincide con el manifiesto extraído.'); }
    Aibid\Infrastructure\MySqlFiles::restoreEmpty(new Aibid\Config($values,false),$sql,$options['mysql'] ?? 'mysql');
    fwrite(STDOUT,"Restauración SQL en base vacía completada. Mantenerla aislada hasta verificar claves, revisiones y auditoría contra el anclaje externo.\n");
});
