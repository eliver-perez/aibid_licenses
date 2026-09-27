<?php
declare(strict_types=1);
namespace Aibid\Infrastructure;

use Aibid\App;

final class Backup
{
    public function __construct(private App $app) {}

    public function create(string $output, string $key, string $mysqldump = 'mysqldump', ?string $credentials = null): array
    {
        self::outsidePublic($output);
        if (file_exists($output) || is_link($output)) { throw new \RuntimeException('Backup destination already exists.'); }
        $stage = dirname($output).'/.aibid-backup-'.bin2hex(random_bytes(8));
        mkdir($stage,0700); mkdir($stage.'/keys',0700);
        $files = []; $mask = umask(0077);
        try {
            $manifest = $this->app->db->transaction(function () use ($stage,$mysqldump,$credentials,&$files): array {
                // Match signer lock order. Holding the audit head freezes committed business
                // changes while mysqldump takes its consistent snapshot and keys are copied.
                $this->app->db->all('SELECT * FROM signing_scopes ORDER BY environment,purpose FOR SHARE');
                $head = $this->app->db->one("SELECT * FROM audit_heads WHERE stream_id='admin' FOR SHARE");
                $events = $this->app->audit->verify();
                $values = [];
                foreach (['APP_ENV','APP_URL','DB_DSN','DB_USER','DB_PASSWORD','ADMIN_NETWORKS','MFA_KEY','CREDENTIAL_KEY','AUDIT_KEY','SESSION_KEY','OPERATION_KEY'] as $name) { $values[$name]=$this->app->config->get($name); }
                $values['SIGNING_KEY_DIR'] = $this->app->signingKeys->directory();
                $files['config.json'] = $stage.'/config.json';
                self::write($files['config.json'],json_encode($values,JSON_THROW_ON_ERROR));
                $files['database.sql'] = $stage.'/database.sql';
                MySqlFiles::dump($this->app->config,$files['database.sql'],$mysqldump,$credentials);
                foreach ($this->app->db->all('SELECT private_ref,state FROM signing_keys WHERE environment=? ORDER BY kid',[$this->app->signingKeys->environment()]) as $record) {
                    if (!preg_match('/\A[a-f0-9]{32}\.json\z/',$record['private_ref'])) { throw new \RuntimeException('Invalid signing key reference.'); }
                    $source = $this->app->signingKeys->directory().'/'.$record['private_ref'];
                    if (!is_file($source) || is_link($source) || (fileperms($source)&0077)!==0) { throw new \RuntimeException('A signing key is missing or has unsafe permissions.'); }
                    $name = 'keys/'.$record['private_ref']; $files[$name] = $stage.'/'.$name;
                    if (!copy($source,$files[$name])) { throw new \RuntimeException('Cannot stage signing key.'); }
                    chmod($files[$name],0600);
                }
                $hashes = []; foreach ($files as $name=>$path) {
                    $hashes[$name]=hash_file('sha256',$path);
                    if ($hashes[$name]===false) { throw new \RuntimeException('Cannot checksum staged backup file.'); }
                }
                return ['schema_version'=>1,'created_at'=>gmdate(DATE_ATOM),'environment'=>$this->app->config->get('APP_ENV'),'database'=>MySqlFiles::databaseName($this->app->config),'audit'=>['events'=>$events,'last_hash'=>$head['last_event_hash']],'files_sha256'=>$hashes,'migrations'=>$this->app->db->all('SELECT name,checksum,state FROM schema_migrations ORDER BY name')];
            });
            $files['manifest.json'] = $stage.'/manifest.json';
            self::write($files['manifest.json'],json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
            EncryptedArchive::pack($files,$key,$output);
            return ['archive_sha256'=>hash_file('sha256',$output),'created_at'=>$manifest['created_at'],'audit'=>$manifest['audit']];
        } finally {
            umask($mask);
            foreach ($files as $path) { if (is_file($path)) { unlink($path); } }
            rmdir($stage.'/keys'); rmdir($stage);
        }
    }
    private static function write(string $path, string $contents): void
    {
        if (file_put_contents($path,$contents)!==strlen($contents)) { throw new \RuntimeException('Incomplete staged backup file.'); }
    }
    public static function extract(string $archive, string $key, string $directory): array
    {
        self::outsidePublic($directory);
        $files = EncryptedArchive::unpack($archive,$key,$directory);
        try {
            foreach (['manifest.json','database.sql','config.json'] as $required) { if (!isset($files[$required])) { throw new \RuntimeException('Incomplete backup.'); } }
            $manifest = json_decode(file_get_contents($files['manifest.json']),true,16,JSON_THROW_ON_ERROR);
            if (($manifest['schema_version'] ?? null)!==1 || !is_array($manifest['files_sha256'] ?? null)) { throw new \RuntimeException('Invalid backup manifest.'); }
            if (count($files)!==count($manifest['files_sha256'])+1) { throw new \RuntimeException('Backup file set mismatch.'); }
            foreach ($manifest['files_sha256'] as $name=>$hash) {
                if (!isset($files[$name]) || !is_string($hash) || !hash_equals($hash,hash_file('sha256',$files[$name]))) { throw new \RuntimeException('Backup checksum mismatch.'); }
            }
            return $manifest;
        } catch (\Throwable $error) {
            foreach ($files as $file) { unlink($file); }
            if (is_dir($directory.'/keys')) { rmdir($directory.'/keys'); } rmdir($directory);
            throw $error;
        }
    }
    public static function outsidePublic(string $path): void
    {
        $parent = realpath(dirname($path)); $public = realpath(dirname(__DIR__,2).'/public');
        if (!str_starts_with($path,'/') || $parent===false || $parent===$public || str_starts_with($parent,$public.'/') || in_array(basename($path),['.','..'],true)) { throw new \RuntimeException('Use an absolute path outside public with an existing parent directory.'); }
    }
}
