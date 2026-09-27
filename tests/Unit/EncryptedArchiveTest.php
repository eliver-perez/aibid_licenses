<?php
declare(strict_types=1);
namespace Aibid\Tests\Unit;

use Aibid\Infrastructure\EncryptedArchive;
use Aibid\Tests\NativeStackFixture;
use PHPUnit\Framework\TestCase;

final class EncryptedArchiveTest extends TestCase
{
    private string $directory;
    protected function setUp(): void { $this->directory=sys_get_temp_dir().'/aibid-archive-'.bin2hex(random_bytes(6)); mkdir($this->directory,0700); }
    protected function tearDown(): void { NativeStackFixture::remove($this->directory); }
    public function testRoundTripAcrossChunksPreservesBytesAndPrivatePermissions(): void
    {
        $key=random_bytes(32); $source=$this->directory.'/source'; $bytes=random_bytes(150000); file_put_contents($source,$bytes);
        $archive=$this->directory.'/backup'; EncryptedArchive::pack(['database.sql'=>$source],$key,$archive);
        self::assertSame(0,fileperms($archive)&0077);
        $files=EncryptedArchive::unpack($archive,$key,$this->directory.'/restored');
        self::assertSame($bytes,file_get_contents($files['database.sql'])); self::assertSame(0,fileperms($files['database.sql'])&0077);
        self::assertStringNotContainsString(substr($bytes,0,50),file_get_contents($archive));
    }
    public function testWrongKeyTruncationTamperAndTrailingBytesLeaveNoExtractedFiles(): void
    {
        $key=random_bytes(32); $source=$this->directory.'/source'; file_put_contents($source,str_repeat('secret',22000));
        $archive=$this->directory.'/backup'; EncryptedArchive::pack(['database.sql'=>$source],$key,$archive); $original=file_get_contents($archive);
        $tampered=$original; $tampered[100]=chr(ord($tampered[100])^1);
        foreach ([[$original,random_bytes(32)],[substr($original,0,-10),$key],[$tampered,$key],[$original.'x',$key]] as $index=>[$bytes,$usedKey]) {
            $path=$this->directory.'/invalid-'.$index; file_put_contents($path,$bytes);
            try { EncryptedArchive::unpack($path,$usedKey,$this->directory.'/rejected'); self::fail('Damaged archive accepted'); }
            catch (\RuntimeException) { self::assertDirectoryDoesNotExist($this->directory.'/rejected'); }
        }
    }
    public function testRefusesTraversalAndExistingDestination(): void
    {
        $key=random_bytes(32); $source=$this->directory.'/source'; file_put_contents($source,'unchanged');
        try { EncryptedArchive::pack(['../escape'=>$source],$key,$this->directory.'/bad'); self::fail('Traversal accepted'); }
        catch (\RuntimeException) { self::assertFileDoesNotExist($this->directory.'/bad'); }
        try { EncryptedArchive::pack(['database.sql'=>$source],$key,$source); self::fail('Overwrite accepted'); }
        catch (\RuntimeException) { self::assertSame('unchanged',file_get_contents($source)); }
    }
}
