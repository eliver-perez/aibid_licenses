<?php
declare(strict_types=1);
namespace Aibid\Infrastructure;

use Aibid\Config;

final class MySqlFiles
{
    public static function databaseName(Config $config): string
    {
        $dsn = $config->get('DB_DSN');
        if (!str_starts_with($dsn,'mysql:')) { throw new \RuntimeException('MySQL DSN required.'); }
        $fields = [];
        foreach (explode(';',substr($dsn,6)) as $field) { if ($field!=='') { [$name,$value] = array_pad(explode('=',$field,2),2,''); $fields[$name]=$value; } }
        if (!preg_match('/\A[a-zA-Z0-9_]+\z/',$fields['dbname'] ?? '')) { throw new \RuntimeException('A simple MySQL database name is required.'); }
        return $fields['dbname'];
    }
    public static function dump(Config $config, string $destination, string $binary = 'mysqldump', ?string $credentials = null): void
    {
        self::run($config,$binary,['--single-transaction','--skip-lock-tables','--skip-add-locks','--no-tablespaces','--set-gtid-purged=OFF','--hex-blob','--skip-add-drop-table','--skip-triggers','--skip-comments','--column-statistics=0'],null,$destination,$credentials);
    }
    /** An explicit new empty database is mandatory; never DROP, truncate or overwrite a target. */
    public static function restoreEmpty(Config $target, string $source, string $binary = 'mysql'): void
    {
        $name = self::databaseName($target); $db = Database::connect($target);
        $lock = 'aibid:restore:'.$name;
        if ((int)$db->execute('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1) { throw new \RuntimeException('Another restore is running.'); }
        try {
            if ((int)$db->execute('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=?',[$name])->fetchColumn()!==0) { throw new \RuntimeException('Restore target must be empty.'); }
            self::run($target,$binary,['--binary-mode','--default-character-set=utf8mb4'], $source,null,null);
        } finally { $db->execute('SELECT RELEASE_LOCK(?)',[$lock]); }
    }
    private static function run(Config $config, string $binary, array $arguments, ?string $input, ?string $output, ?string $credentials): void
    {
        $database = self::databaseName($config);
        $temporary = null;
        if ($credentials === null) {
            $temporary = tempnam(sys_get_temp_dir(),'aibid-mysql-'); chmod($temporary,0600);
            $quote = static fn(string $value): string => '"'.strtr($value,["\\"=>"\\\\",'"'=>'\\"',"\n"=>'\\n',"\r"=>'\\r',"\t"=>'\\t']).'"';
            file_put_contents($temporary,"[client]\nuser=".$quote($config->get('DB_USER'))."\npassword=".$quote($config->get('DB_PASSWORD'))."\n");
            $credentials = $temporary;
        }
        try {
            if (!is_file($credentials) || is_link($credentials) || (fileperms($credentials)&0077)!==0) { throw new \RuntimeException('MySQL option file must be private.'); }
            $connection = [];
            foreach (explode(';',substr($config->get('DB_DSN'),6)) as $field) {
                [$name,$value] = array_pad(explode('=',$field,2),2,'');
                if (in_array($name,['host','port','unix_socket'],true)) { $connection[] = '--'.($name==='unix_socket'?'socket':$name).'='.$value; if ($name==='unix_socket') { $connection[]='--protocol=SOCKET'; } }
            }
            if (!$connection) { throw new \RuntimeException('An explicit MySQL host/socket is required.'); }
            $command = [$binary,'--defaults-file='.$credentials,'--no-login-paths',...$connection,...$arguments,$database];
            $mask = umask(0077);
            try {
                if ($output!==null && (file_exists($output) || is_link($output))) { throw new \RuntimeException('Dump destination already exists.'); }
                $process = proc_open($command,[0=>$input===null?['pipe','r']:['file',$input,'r'],1=>$output===null?['pipe','w']:['file',$output,'w'],2=>['pipe','w']],$pipes,null,[]);
            } finally { umask($mask); }
            if (!is_resource($process)) { throw new \RuntimeException('MySQL utility unavailable.'); }
            if ($input===null) { fclose($pipes[0]); }
            if ($output===null) { stream_get_contents($pipes[1]); fclose($pipes[1]); }
            stream_get_contents($pipes[2]); fclose($pipes[2]);
            if (proc_close($process)!==0) { throw new \RuntimeException('MySQL utility failed; no backup/restore success was recorded.'); }
        } finally { if ($temporary!==null) { unlink($temporary); } }
    }
}
