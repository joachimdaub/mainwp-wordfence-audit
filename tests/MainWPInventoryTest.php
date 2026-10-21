<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MainWPInventoryTest extends TestCase {
    private function normalize($item, string $type='plugin') {
        $method=new ReflectionMethod('MWA_Audit','normalize_inventory_item');
        $method->setAccessible(true);
        return $method->invoke(null,$item,$type,'https://example.test');
    }

    public function testPluginDirectoryIsMappedToSlug(): void {
        $result=$this->normalize(['file'=>'sample-plugin/sample-plugin.php','name'=>'Sample Plugin','version'=>'2.3.1']);
        $this->assertSame('sample-plugin',$result['slug']);
        $this->assertSame('2.3.1',$result['version']);
        $this->assertSame('https://example.test',$result['site']);
        $this->assertSame('plugin',$result['type']);
    }

    public function testThemeUsesItsFullSlug(): void {
        $result=$this->normalize(['slug'=>'sample-theme','name'=>'Sample Theme','version'=>'1.0'],'theme');
        $this->assertSame('sample-theme',$result['slug']);
        $this->assertSame('theme',$result['type']);
    }

    public function testMissingOrMalformedInventoryIsRejected(): void {
        foreach ([null,[],['file'=>'../bad','version'=>'1.0'],['file'=>['unexpected'=>'type']],['slug'=>''],['slug'=>'sample','name'=>[]],['slug'=>'sample','version'=>[]]] as $item) {
            $this->assertNull($this->normalize($item));
        }
    }

    public function testNoSlugIsGuessedFromDisplayName(): void {
        $this->assertNull($this->normalize(['name'=>'Sample Plugin','version'=>'1.0']));
    }

    public function testUnexpectedSoftwareTypeIsRejected(): void {
        $this->assertNull($this->normalize(['slug'=>'sample'],'core'));
    }
}
