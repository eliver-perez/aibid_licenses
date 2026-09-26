<?php
declare(strict_types=1);
namespace Aibid\Tests;

use Aibid\{App, Config};
use Aibid\Infrastructure\{Database, Migrator};

final class MySqlFixture
{
    public readonly string $database;
    public readonly string $username;
    public readonly array $values;
    public readonly Database $owner;
    public readonly App $app;
    private \PDO $server;

    public function __construct(?FrozenClock $clock = null)
    {
        $dsn = getenv('TEST_MYSQL_DSN');
        if (!$dsn || !str_starts_with($dsn, 'mysql:')) { throw new \RuntimeException('TEST_MYSQL_DSN must explicitly identify an isolated MySQL 8 test server.'); }
        $this->server = new \PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $suffix = bin2hex(random_bytes(5));
        $this->database = 'aibid_test_' . $suffix;
        $this->username = 'aibid_test_' . $suffix;
        $password = bin2hex(random_bytes(24));
        $this->server->exec('CREATE DATABASE `' . $this->database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $databaseDsn = preg_replace('/;?dbname=[^;]*/', '', $dsn) . ';dbname=' . $this->database;
        $this->owner = new Database(new \PDO($databaseDsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: ''));
        (new Migrator($this->owner, dirname(__DIR__) . '/database/migrations'))->migrate();
        $this->server->exec("CREATE USER '" . $this->username . "'@'localhost' IDENTIFIED BY " . $this->server->quote($password));
        $command = [PHP_BINARY, dirname(__DIR__) . '/bin/grants.php', '--database=' . $this->database, '--user=' . $this->username];
        $process = proc_open($command, [1=>['pipe','w'],2=>['pipe','w']], $pipes);
        $grants = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new \RuntimeException('Grant generation failed: ' . $error); }
        foreach (explode(';', $grants) as $sql) { if (trim($sql) !== '') { $this->server->exec($sql); } }
        $values = ['APP_ENV'=>'testing','APP_URL'=>'http://127.0.0.1:8088','DB_DSN'=>$databaseDsn,'DB_USER'=>$this->username,'DB_PASSWORD'=>$password,'SIGNING_KEY_DIR'=>sys_get_temp_dir().'/'.$this->database.'-keys'];
        foreach (['MFA_KEY','CREDENTIAL_KEY','AUDIT_KEY','SESSION_KEY','OPERATION_KEY'] as $name) { $values[$name]=base64_encode(random_bytes(32)); }
        $this->values = $values;
        $config = new Config($values, false);
        $this->app = new App($config, Database::connect($config), $clock);
    }

    public function destroy(): void
    {
        // Only random databases/users created by this fixture can be removed.
        if (!preg_match('/\Aaibid_test_[a-f0-9]{10}\z/', $this->database)) { throw new \LogicException('Unsafe fixture identifier.'); }
        $this->server->exec('DROP DATABASE `' . $this->database . '`');
        $this->server->exec("DROP USER '" . $this->username . "'@'localhost'");
        foreach (glob($this->values['SIGNING_KEY_DIR'].'/*.json') ?: [] as $file) { unlink($file); }
        if (is_dir($this->values['SIGNING_KEY_DIR'])) { rmdir($this->values['SIGNING_KEY_DIR']); }
    }

    public function signingKeys(?FrozenClock $clock = null): \Aibid\Infrastructure\SigningKeys
    {
        return (new App(new Config($this->values, false), $this->owner, $clock))->signingKeys;
    }
}
