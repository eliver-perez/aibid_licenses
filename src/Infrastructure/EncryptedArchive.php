<?php
declare(strict_types=1);
namespace Aibid\Infrastructure;

/** Streaming authenticated archive, version 1. Never extract into an existing directory. */
final class EncryptedArchive
{
    private const MAGIC = "AIBID-BACKUP-V1\n";
    private const CHUNK = 65536;

    public static function key(string $path): string
    {
        if (is_link($path) || !is_file($path) || (fileperms($path)&0077)!==0) { throw new \RuntimeException('Backup key requires a private regular file.'); }
        $key = base64_decode(trim(file_get_contents($path)),true);
        if ($key === false || strlen($key)!==32) { throw new \RuntimeException('Invalid backup key.'); }
        return $key;
    }
    public static function pack(array $files, string $key, string $output): void
    {
        if (strlen($key)!==32 || file_exists($output) || is_link($output)) { throw new \RuntimeException('Invalid key or existing archive destination.'); }
        $mask = umask(0077); $stream = null; $complete = false;
        try {
            $stream = fopen($output,'xb');
            if (!$stream) { throw new \RuntimeException('Archive already exists or is not writable.'); }
            [$state,$header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            self::write($stream,self::MAGIC.$header);
            foreach ($files as $name=>$path) {
                self::name($name);
                if (is_link($path) || !is_file($path)) { throw new \RuntimeException('Archive source must be a regular file.'); }
                $input = fopen($path,'rb');
                try {
                    $size = fstat($input)['size'];
                    self::push($stream,$state,json_encode(['name'=>$name,'size'=>$size],JSON_THROW_ON_ERROR),SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_PUSH);
                    $remaining = $size;
                    while ($remaining>0) {
                        $chunk = self::read($input,min(self::CHUNK,$remaining)); $remaining -= strlen($chunk);
                        self::push($stream,$state,$chunk,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
                    }
                    if (fread($input,1)!=='') { throw new \RuntimeException('Archive source changed while reading.'); }
                } finally { fclose($input); }
            }
            self::push($stream,$state,'',SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            if (!fflush($stream) || !fsync($stream)) { throw new \RuntimeException('Cannot sync archive.'); }
            $complete = true;
        } finally {
            umask($mask);
            if (is_resource($stream)) { fclose($stream); if (!$complete) { unlink($output); } }
        }
    }
    public static function unpack(string $input, string $key, string $directory): array
    {
        if (strlen($key)!==32 || file_exists($directory) || is_link($directory)) { throw new \RuntimeException('Extraction requires a valid key and a new private directory.'); }
        if (!mkdir($directory,0700)) { throw new \RuntimeException('Cannot create extraction directory.'); }
        $stream = null; $files = []; $complete = false; $mask = umask(0077);
        try {
            $stream = fopen($input,'rb');
            if (!$stream || self::read($stream,strlen(self::MAGIC))!==self::MAGIC) { throw new \RuntimeException('Unsupported backup archive.'); }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(self::read($stream,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES),$key);
            while (true) {
                [$plaintext,$tag] = self::pull($stream,$state);
                if ($tag===SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL && $plaintext==='') {
                    if (fread($stream,1)!=='') { throw new \RuntimeException('Trailing archive data.'); }
                    $complete = true; return $files;
                }
                if ($tag!==SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_PUSH) { throw new \RuntimeException('Missing archive entry.'); }
                $entry = json_decode($plaintext,true,8,JSON_THROW_ON_ERROR);
                if (!is_array($entry) || !isset($entry['name'],$entry['size']) || !is_string($entry['name']) || !is_int($entry['size']) || $entry['size']<0 || isset($files[$entry['name']])) { throw new \RuntimeException('Invalid archive entry.'); }
                self::name($entry['name']);
                $path = $directory.'/'.$entry['name'];
                if (!is_dir(dirname($path))) { mkdir(dirname($path),0700); }
                $output = fopen($path,'xb'); $files[$entry['name']] = $path;
                try {
                    $remaining = $entry['size'];
                    while ($remaining>0) {
                        [$chunk,$tag] = self::pull($stream,$state);
                        if ($tag!==SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE || strlen($chunk)!==min(self::CHUNK,$remaining)) { throw new \RuntimeException('Invalid archive chunk.'); }
                        self::write($output,$chunk); $remaining -= strlen($chunk);
                    }
                } finally { fclose($output); }
            }
        } finally {
            umask($mask); if (is_resource($stream)) { fclose($stream); }
            if (!$complete) { foreach ($files as $path) { unlink($path); } if (is_dir($directory.'/keys')) { rmdir($directory.'/keys'); } rmdir($directory); }
        }
    }
    private static function name(string $name): void
    {
        if (!in_array($name,['database.sql','config.json','manifest.json'],true) && !preg_match('/\Akeys\/[a-f0-9]{32}\.json\z/',$name)) { throw new \RuntimeException('Unsupported archive path.'); }
    }
    private static function push($stream, string &$state, string $plaintext, int $tag): void
    {
        $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push($state,$plaintext,self::MAGIC,$tag);
        self::write($stream,pack('N',strlen($ciphertext)).$ciphertext);
    }
    private static function pull($stream, string &$state): array
    {
        $length = unpack('Nlength',self::read($stream,4))['length'];
        if ($length<17 || $length>self::CHUNK+17) { throw new \RuntimeException('Invalid encrypted chunk size.'); }
        $decoded = sodium_crypto_secretstream_xchacha20poly1305_pull($state,self::read($stream,$length),self::MAGIC);
        if ($decoded===false) { throw new \RuntimeException('Backup authentication failed.'); }
        return $decoded;
    }
    private static function read($stream, int $length): string
    {
        $result = '';
        while (strlen($result)<$length) {
            $bytes = fread($stream,$length-strlen($result));
            if ($bytes===false || $bytes==='') { throw new \RuntimeException('Truncated archive or source.'); }
            $result .= $bytes;
        }
        return $result;
    }
    private static function write($stream, string $bytes): void
    {
        for ($offset=0;$offset<strlen($bytes);$offset+=$written) {
            $written = fwrite($stream,substr($bytes,$offset));
            if ($written===false || $written===0) { throw new \RuntimeException('Archive write failed.'); }
        }
    }
}
