<?php
declare(strict_types=1);
namespace Aibid\Tests;

use Aibid\{App, Config};

/** Real TLS -> Nginx -> PHP-FPM -> isolated MySQL, using the deployment templates. */
final class NativeStackFixture
{
    public readonly string $directory;
    public readonly string $origin;
    public readonly App $app;
    public readonly array $values;
    private array $processes = [];

    public function __construct(public readonly MySqlFixture $fixture)
    {
        $this->directory = '/private/tmp/aibid-stack-'.bin2hex(random_bytes(6));
        mkdir($this->directory,0700);
        foreach (['tmp','log'] as $name) { mkdir($this->directory.'/'.$name,0700); }
        $port = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($port,false); fclose($port);
        $this->origin = 'https://'.$address;
        $this->values = array_replace($fixture->values,['APP_ENV'=>'production','APP_URL'=>$this->origin]);
        $config = new Config($this->values,false); $config->validate();
        $this->app = new App($config,$fixture->app->db);
        $this->privateFile('config.php',"<?php return ".var_export($this->values,true).";\n");
        try {
            self::command([getenv('TEST_OPENSSL_BIN') ?: 'openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','2','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1,DNS:localhost','-keyout',$this->directory.'/tls.key','-out',$this->directory.'/tls.crt']);
            chmod($this->directory.'/tls.key',0600);
            $fpm = file_get_contents(dirname(__DIR__).'/deploy/php-fpm-pool.conf.example');
            $fpm = preg_replace('/^(?: ?user| ?group|listen\.owner|listen\.group)\s*=.*\n/m','',$fpm);
            $fpm = str_replace(['/srv/aibidlicense/shared','/run/php/aibidlicense.sock'],[$this->directory,$this->directory.'/fpm.sock'],$fpm);
            $this->privateFile('fpm.conf',"[global]\npid = {$this->directory}/fpm.pid\nerror_log = {$this->directory}/log/fpm.log\n".$fpm);
            $parameters = "fastcgi_param QUERY_STRING \$query_string;\nfastcgi_param REQUEST_METHOD \$request_method;\nfastcgi_param CONTENT_TYPE \$content_type;\nfastcgi_param CONTENT_LENGTH \$content_length;\nfastcgi_param SCRIPT_NAME /index.php;\nfastcgi_param REQUEST_URI \$request_uri;\nfastcgi_param SERVER_PROTOCOL \$server_protocol;\nfastcgi_param REMOTE_ADDR \$remote_addr;\nfastcgi_param REMOTE_PORT \$remote_port;\nfastcgi_param SERVER_ADDR \$server_addr;\nfastcgi_param SERVER_PORT \$server_port;\nfastcgi_param SERVER_NAME \$server_name;\nfastcgi_param REDIRECT_STATUS 200;\n";
            $this->privateFile('fastcgi_params',$parameters);
            $nginx = file_get_contents(dirname(__DIR__).'/deploy/nginx.conf.example');
            $nginx = str_replace(['listen 443 ssl;','licencias.example.com','/srv/aibidlicense/current/public','/etc/letsencrypt/live/127.0.0.1/fullchain.pem','/etc/letsencrypt/live/127.0.0.1/privkey.pem','/srv/aibidlicense/shared','/run/php/aibidlicense.sock'],['listen '.$address.' ssl;','127.0.0.1',dirname(__DIR__).'/public',$this->directory.'/tls.crt',$this->directory.'/tls.key',$this->directory,$this->directory.'/fpm.sock'],$nginx);
            $this->privateFile('nginx.conf',"pid {$this->directory}/nginx.pid;\nerror_log {$this->directory}/log/nginx-error.log;\nevents { worker_connections 128; }\nhttp { access_log off; client_body_temp_path {$this->directory}/tmp; fastcgi_temp_path {$this->directory}/tmp;\n".$nginx."\n}\n");
            self::command([getenv('TEST_FPM_BIN') ?: 'php-fpm','-t','-y',$this->directory.'/fpm.conf']);
            self::command([getenv('TEST_NGINX_BIN') ?: 'nginx','-t','-p',$this->directory.'/','-c',$this->directory.'/nginx.conf']);
            $this->start([getenv('TEST_FPM_BIN') ?: 'php-fpm','-F','-y',$this->directory.'/fpm.conf']);
            $this->start([getenv('TEST_NGINX_BIN') ?: 'nginx','-p',$this->directory.'/','-c',$this->directory.'/nginx.conf','-g','daemon off;']);
            $deadline = microtime(true)+8;
            do {
                $connection = @stream_socket_client('tcp://'.$address,$errno,$error,0.1);
                if ($connection && file_exists($this->directory.'/fpm.sock')) { fclose($connection); return; }
                if ($connection) { fclose($connection); } usleep(20000);
            } while (microtime(true)<$deadline);
            throw new \RuntimeException('Native test stack failed to start; inspect '.$this->directory.'/log');
        } catch (\Throwable $error) { $this->stop(); throw $error; }
    }

    public function request(string $path, string $body = '{}', string $method = 'POST', array $headers = ['Content-Type: application/json']): array
    {
        $stream = fopen($this->origin.$path,'r',false,stream_context_create(['ssl'=>['cafile'=>$this->directory.'/tls.crt','verify_peer'=>true,'verify_peer_name'=>true],'http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$method==='GET'?'':$body,'ignore_errors'=>true,'timeout'=>15,'follow_location'=>0]]));
        if (!$stream) { throw new \RuntimeException('TLS test request failed.'); }
        $bytes = stream_get_contents($stream); $metadata = stream_get_meta_data($stream); fclose($stream);
        preg_match('/HTTP\/\S+ (\d+)/',$metadata['wrapper_data'][0],$match);
        return ['status'=>(int)$match[1],'body'=>$bytes,'json'=>json_decode($bytes,true),'headers'=>strtolower(implode("\n",$metadata['wrapper_data']))];
    }

    public function privateFile(string $name, string $contents): void
    {
        $mask = umask(0077);
        try { file_put_contents($this->directory.'/'.$name,$contents); } finally { umask($mask); }
    }

    private function start(array $command): void
    {
        $environment = array_diff_key(getenv(),$this->values + ['AIBID_CONFIG'=>'']);
        $process = proc_open($command,[0=>['pipe','r'],1=>['file',$this->directory.'/log/process.log','a'],2=>['file',$this->directory.'/log/process.log','a']],$pipes,null,$environment);
        if (!is_resource($process)) { throw new \RuntimeException('Cannot start test process.'); }
        fclose($pipes[0]); $this->processes[] = $process;
    }
    public function stop(): void
    {
        foreach (array_reverse($this->processes) as $process) { if (is_resource($process)) { proc_terminate($process,15); proc_close($process); } }
        $this->processes = [];
    }
    public function destroy(): void { $this->stop(); self::remove($this->directory); }
    public static function remove(string $directory): void
    {
        if (!is_dir($directory)) { return; }
        foreach (new \FilesystemIterator($directory) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { self::remove($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($directory);
    }
    public static function command(array $command, ?string $cwd = null, ?array $environment = null): string
    {
        $process = proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd,$environment);
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new \RuntimeException(basename($command[0]).' failed: '.$error.$output); }
        return $output;
    }
}
