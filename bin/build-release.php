<?php
declare(strict_types=1);
require __DIR__.'/console.php';

consoleRun(function (): void {
    $options = getopt('',['output:']);
    $output = $options['output'] ?? '';
    if (!str_ends_with($output,'.tar.gz')) { throw new Aibid\Domain\Problem('Uso: build-release.php --output=/ruta/nueva/aibidlicense.tar.gz (directorio existente).'); }
    Aibid\Infrastructure\Backup::outsidePublic($output);
    $tar = substr($output,0,-3);
    foreach ([$output,$tar,$output.'.sha256'] as $path) { if (file_exists($path) || is_link($path)) { throw new Aibid\Domain\Problem('El destino ya existe.'); } }
    $root = dirname(__DIR__); $files = [];
    // Allowlist: configuration, data, dev dependencies and other repositories never enter the package.
    foreach (['bin','src','public','templates','database','deploy'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isLink()) { throw new RuntimeException('Release files must not be symlinks.'); }
            if ($entry->isFile()) { $files[]=substr($entry->getPathname(),strlen($root)+1); }
        }
    }
    foreach (['composer.json','composer.lock','config/local.example.php','README.md','ARCHITECTURE.md','SCHEMA.md','API.md','SECURITY.md','LICENSE_CONTRACT.md','OPERATIONS.md','DECISIONS.md','DEPLOY_UBUNTU_24_04.md','STATUS.md','TEST_PLAN.md','prompt_2_servidor_licencias_php_v1_2.md'] as $file) { $files[]=$file; }
    sort($files); $hashes = [];
    try {
        $archive = new PharData($tar);
        foreach ($files as $file) {
            if (!is_file($root.'/'.$file) || is_link($root.'/'.$file)) { throw new RuntimeException('Missing or unsafe release file.'); }
            $archive->addFile($root.'/'.$file,'aibidlicense/'.$file);
            $hashes[$file]=hash_file('sha256',$root.'/'.$file);
        }
        $archive->addFromString('aibidlicense/RELEASE.json',json_encode(['format'=>1,'created_at'=>gmdate(DATE_ATOM),'dependencies'=>'Run composer install --no-dev from the included lockfile.','files_sha256'=>$hashes],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        $archive->compress(Phar::GZ); unset($archive); unlink($tar);
        $hash = hash_file('sha256',$output);
        file_put_contents($output.'.sha256',$hash.'  '.basename($output).PHP_EOL);
        fwrite(STDOUT,json_encode(['archive'=>$output,'sha256'=>$hash,'files'=>count($files),'vendor_included'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL);
    } catch (Throwable $error) {
        unset($archive);
        foreach ([$tar,$output,$output.'.sha256'] as $path) { if (is_file($path)) { unlink($path); } }
        throw $error;
    }
});
