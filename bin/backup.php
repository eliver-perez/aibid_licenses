<?php
declare(strict_types=1);
require __DIR__.'/console.php';

use Aibid\Infrastructure\{Backup,EncryptedArchive};

consoleRun(function (): void {
    $options = getopt('',['action:','key-file:','output:','archive:','directory:','mysqldump:','mysql-options:']);
    $action = $options['action'] ?? ''; $keyFile = $options['key-file'] ?? '';
    if (!in_array($action,['keygen','create','extract'],true) || $keyFile==='') { throw new Aibid\Domain\Problem('Uso: backup.php --action=keygen|create|extract --key-file=/ruta/clave [--output=/ruta/copia.aibidbackup | --archive=/ruta/copia.aibidbackup --directory=/ruta/nueva]'); }
    Backup::outsidePublic($keyFile);
    if ($action==='keygen') {
        $mask = umask(0077);
        try {
            $file = fopen($keyFile,'x'); if (!$file) { throw new RuntimeException('Key file already exists.'); }
            $value = base64_encode(random_bytes(32))."\n";
            if (fwrite($file,$value)!==strlen($value) || !fflush($file) || !fsync($file)) { throw new RuntimeException('Cannot persist key.'); }
            fclose($file);
        } finally { umask($mask); }
        fwrite(STDOUT,"Clave de respaldo creada con permisos 0600. Conservar una copia separada del archivo cifrado.\n"); return;
    }
    $key = EncryptedArchive::key($keyFile);
    try {
        if ($action==='create') {
            if (!isset($options['output'])) { throw new Aibid\Domain\Problem('Falta --output.'); }
            $result = (new Backup(Aibid\App::boot()))->create($options['output'],$key,$options['mysqldump'] ?? 'mysqldump',$options['mysql-options'] ?? null);
        } else {
            if (!isset($options['archive'],$options['directory'])) { throw new Aibid\Domain\Problem('Faltan --archive y --directory nueva.'); }
            $manifest = Backup::extract($options['archive'],$key,$options['directory']);
            $result = ['verified'=>true,'created_at'=>$manifest['created_at'],'audit'=>$manifest['audit'],'files'=>count($manifest['files_sha256'])];
        }
        fwrite(STDOUT,json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL);
    } finally { sodium_memzero($key); }
});
