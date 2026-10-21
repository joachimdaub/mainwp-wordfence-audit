<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CacheMigrationTest extends TestCase {
    private $dir;
    protected function setUp(): void {
        $this->dir=sys_get_temp_dir().'/mwa-migrate-'.bin2hex(random_bytes(8));
        mkdir($this->dir);
    }
    protected function tearDown(): void {
        foreach (glob($this->dir.'/*') ?: [] as $file) @unlink($file);
        @rmdir($this->dir);
    }
    private function migrate($source,$destination): bool {
        $method=new ReflectionMethod('MWA_Audit','migrate_legacy_cache');
        $method->setAccessible(true);
        return $method->invoke(null,$source,$destination);
    }
    public function testSuccessfulMigrationMovesValidJson(): void {
        $source=$this->dir.'/old.json'; $target=$this->dir.'/new.json';
        $data='{"plugin/example":[{"id":"v1"}]}';
        file_put_contents($source,$data);
        $this->assertTrue($this->migrate($source,$target));
        $this->assertFileDoesNotExist($source);
        $this->assertSame($data,file_get_contents($target));
    }
    public function testMalformedLegacyCacheIsPreserved(): void {
        $source=$this->dir.'/old.json'; $target=$this->dir.'/new.json';
        file_put_contents($source,'{"broken":');
        $this->assertFalse($this->migrate($source,$target));
        $this->assertFileExists($source);
        $this->assertFileDoesNotExist($target);
    }
    public function testInvalidExistingDestinationDoesNotDeleteLegacyCache(): void {
        $source=$this->dir.'/old.json'; $target=$this->dir.'/new.json';
        file_put_contents($source,'{"valid":[]}');
        file_put_contents($target,'not json');
        $this->assertFalse($this->migrate($source,$target));
        $this->assertFileExists($source);
        $this->assertSame('not json',file_get_contents($target));
    }
    public function testValidExistingDestinationKeepsNewCache(): void {
        $source=$this->dir.'/old.json'; $target=$this->dir.'/new.json';
        file_put_contents($source,'{"old":[]}');
        file_put_contents($target,'{"new":[]}');
        $this->assertTrue($this->migrate($source,$target));
        $this->assertSame('{"new":[]}',file_get_contents($target));
        $this->assertFileDoesNotExist($source);
    }
    public function testSymlinkedLegacySourceIsRejected(): void {
        if (!function_exists('symlink')) $this->markTestSkipped('Symlinks unavailable');
        $source=$this->dir.'/old.json'; $real=$this->dir.'/real.json'; $target=$this->dir.'/new.json';
        file_put_contents($real,'{"valid":[]}');
        if (!@symlink($real,$source)) $this->markTestSkipped('Symlinks not permitted');
        $this->assertFalse($this->migrate($source,$target));
        $this->assertFileExists($real);
        $this->assertFileDoesNotExist($target);
    }

    public function testSymlinkedDestinationIsNotTrustedOrDeleted(): void {
        if (!function_exists('symlink')) $this->markTestSkipped('Symlinks unavailable');
        $source=$this->dir.'/old.json'; $real=$this->dir.'/real.json'; $target=$this->dir.'/new.json';
        file_put_contents($source,'{"old":[]}');
        file_put_contents($real,'{"existing":[]}');
        if (!@symlink($real,$target)) $this->markTestSkipped('Symlinks not permitted');
        $this->assertFalse($this->migrate($source,$target));
        $this->assertFileExists($source);
        $this->assertSame('{"existing":[]}',file_get_contents($real));
    }

    public function testUnwritableDestinationPathPreservesLegacyCache(): void {
        $source=$this->dir.'/old.json';
        file_put_contents($source,'{"valid":[]}');
        $target=$this->dir.'/missing-directory/new.json';
        $this->assertFalse($this->migrate($source,$target));
        $this->assertSame('{"valid":[]}',file_get_contents($source));
        $this->assertFileDoesNotExist($target);
    }

}
