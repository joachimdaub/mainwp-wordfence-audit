<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class LegacyUploadsCacheStatusTest extends TestCase {
    private $dir;
    protected function setUp(): void {
        $this->dir=sys_get_temp_dir().'/mwa-legacy-check-'.bin2hex(random_bytes(8));
        mkdir($this->dir);
    }
    protected function tearDown(): void {
        $legacy=$this->dir.'/mainwp-wordfence-audit';
        $file=$legacy.'/mainwp-wordfence-audit-index.json';
        if (is_link($file) || is_file($file)) @unlink($file);
        if (is_link($legacy)) @unlink($legacy);
        else @rmdir($legacy);
        @rmdir($this->dir);
    }
    private function inspect($uploads) {
        $method=new ReflectionMethod('MWA_Audit','legacy_upload_cache_status');
        $method->setAccessible(true);
        return $method->invoke(null,$uploads);
    }
    public function testMissingUploadsCacheIsAbsent(): void {
        $this->assertSame('absent',$this->inspect(['basedir'=>$this->dir,'error'=>false]));
    }
    public function testExistingCacheIsReportedWithoutModifyingIt(): void {
        $legacy=$this->dir.'/mainwp-wordfence-audit';
        mkdir($legacy);
        $file=$legacy.'/mainwp-wordfence-audit-index.json';
        file_put_contents($file,'{"valid":true}');
        $this->assertSame('present',$this->inspect(['basedir'=>$this->dir,'error'=>false]));
        $this->assertSame('{"valid":true}',file_get_contents($file));
    }
    public function testSymlinkedFileIsFlagged(): void {
        if (!function_exists('symlink')) $this->markTestSkipped('Symlinks unavailable');
        $legacy=$this->dir.'/mainwp-wordfence-audit';
        mkdir($legacy);
        if (!@symlink($this->dir.'/target.json',$legacy.'/mainwp-wordfence-audit-index.json')) $this->markTestSkipped('Symlinks not permitted');
        $this->assertSame('symlink',$this->inspect(['basedir'=>$this->dir,'error'=>false]));
    }
    public function testUnknownUploadsLocationIsNotReportedAsSafe(): void {
        $this->assertSame('unknown',$this->inspect(['error'=>'unavailable']));
    }
}
