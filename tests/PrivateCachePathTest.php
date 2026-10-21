<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PrivateCachePathTest extends TestCase {
    public function testDefaultCacheIsOutsideWordPressRoot(): void {
        $method=new ReflectionMethod('MWA_Audit','private_cache_dir');
        $method->setAccessible(true);
        $path=$method->invoke(null);
        $root=realpath(ABSPATH);
        $this->assertIsString($root);
        $this->assertSame(dirname(rtrim(ABSPATH,'/\\')).'/mainwp-wordfence-audit-private',$path);
        $this->assertFalse(strpos($path,$root.DIRECTORY_SEPARATOR)===0);
    }

    public function testLegacyUploadsDirectoryIsNotDefaultCachePath(): void {
        $method=new ReflectionMethod('MWA_Audit','private_cache_dir');
        $method->setAccessible(true);
        $this->assertStringNotContainsString('/uploads/',$method->invoke(null));
    }
}
