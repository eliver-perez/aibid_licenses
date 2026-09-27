<?php
declare(strict_types=1);
namespace Aibid\Tests;

final class RealClientBridge
{
    public readonly string $directory;
    public readonly array $sourceHashes;
    private array $environment;

    public function __construct(string $source)
    {
        if (!is_file($source.'/internal/licensing/contract.go')) { throw new \RuntimeException('Set TEST_CLIENT_SOURCE to the real AIBID Go source.'); }
        $this->directory = '/private/tmp/aibid-client-'.bin2hex(random_bytes(6)); mkdir($this->directory,0700);
        $hashes = [];
        foreach (['go.mod','go.sum','db','internal/licensing','internal/storage','internal/domain','internal/audit','internal/buildinfo','internal/licensefixture','testdata/license'] as $name) {
            $paths = is_dir($source.'/'.$name) ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source.'/'.$name,\FilesystemIterator::SKIP_DOTS)) : [new \SplFileInfo($source.'/'.$name)];
            foreach ($paths as $entry) {
                if (!$entry->isFile() || $entry->isLink()) { continue; }
                $relative = substr($entry->getPathname(),strlen($source)+1);
                $target = $this->directory.'/'.$relative;
                if (!is_dir(dirname($target))) { mkdir(dirname($target),0700,true); }
                copy($entry->getPathname(),$target); $hashes[$relative] = hash_file('sha256',$target);
            }
        }
        ksort($hashes); $this->sourceHashes = $hashes;
        copy(__DIR__.'/client-bridge_test.go',$this->directory.'/internal/licensing/server_bridge_test.go');
        $this->environment = array_replace(getenv(),['GOTOOLCHAIN'=>'local','GOPROXY'=>'off','GOSUMDB'=>'off','GOCACHE'=>getenv('TEST_GO_CACHE') ?: '/private/tmp/aibidlicense-real-client-go-cache','GOMODCACHE'=>getenv('TEST_GO_MODCACHE') ?: '/private/tmp/gestor-documental-go-mod']);
        NativeStackFixture::command([getenv('TEST_GO_BIN') ?: 'go','test','-mod=readonly','-c','-o',$this->directory.'/client.test','./internal/licensing'],$this->directory,$this->environment);
    }

    public function run(NativeStackFixture $stack, string $installation, array $keys, array $input): array
    {
        $job = $input + ['directory'=>$this->directory.'/state-'.$installation,'origin'=>$stack->origin,'ca'=>$stack->directory.'/tls.crt','keys'=>$keys,'output'=>$this->directory.'/result.json'];
        $file = $this->directory.'/job.json'; file_put_contents($file,json_encode($job,JSON_THROW_ON_ERROR)); chmod($file,0600);
        NativeStackFixture::command([$this->directory.'/client.test','-test.run=^TestServerBridge$','-test.timeout=30s'],$this->directory,array_replace($this->environment,['AIBID_BRIDGE_JOB'=>$file]));
        $result = json_decode(file_get_contents($job['output']),true,512,JSON_THROW_ON_ERROR);
        unlink($file); unlink($job['output']);
        return $result;
    }
    public function existingTests(): string
    {
        return NativeStackFixture::command([$this->directory.'/client.test','-test.run=^Test(JWS|Subscription|Revision|Missing|Proof|Security)','-test.timeout=90s'],$this->directory.'/internal/licensing',$this->environment);
    }
    public function destroy(): void { NativeStackFixture::remove($this->directory); }
}
