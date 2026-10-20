<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FeedParserTest extends TestCase {
    private function parse(string $json, array $wanted): array {
        $path=tempnam(sys_get_temp_dir(),'mwa-test-');
        if ($path===false) throw new RuntimeException('Cannot create fixture file');
        try {
            file_put_contents($path,$json);
            $method=new ReflectionMethod('MWA_Audit','stream_index');
            $method->setAccessible(true);
            return [$method->invoke(null,$path,$wanted),$path];
        } finally {
            @unlink($path);
        }
    }

    public function testMatchesInstalledSoftwareAndIgnoresUninstalledSoftware(): void {
        $json=json_encode([
            'vulnerability-a'=>[
                'title'=>'Sample issue',
                'software'=>[
                    ['type'=>'plugin','slug'=>'Example-Plugin','affected_versions'=>[]],
                    ['type'=>'plugin','slug'=>'uninstalled-plugin','affected_versions'=>[]]
                ],
                'cvss'=>['score'=>8.1]
            ],
            'vulnerability-b'=>[
                'title'=>'Other issue',
                'software'=>[['type'=>'theme','slug'=>'my-theme']]
            ]
        ]);
        [$result]=$this->parse((string)$json,['plugin/example-plugin'=>true]);
        $this->assertSame(2,$result['seen']);
        $this->assertCount(1,$result['matches']);
        $this->assertCount(1,$result['matches']['plugin/example-plugin']);
        $this->assertSame('vulnerability-a',$result['matches']['plugin/example-plugin'][0]['id']);
        $this->assertSame(8.1,$result['matches']['plugin/example-plugin'][0]['row']['cvss']['score']);
    }

    public function testRejectsMalformedAndIncompleteFeed(): void {
        foreach (['','[]','{"id":','{"id":{} trailing'] as $json) {
            [$result]=$this->parse($json,[]);
            $this->assertInstanceOf(WP_Error::class,$result);
        }
    }

    public function testParsesEscapedJsonAndMultipleEntries(): void {
        $json=json_encode([
            'vuln-1'=>['title'=>'Escaped: "quote" and \\ slash','software'=>[['type'=>'theme','slug'=>'sample']]],
            'vuln-2'=>['title'=>'Second','software'=>[['type'=>'theme','slug'=>'sample']]]
        ]);
        [$result]=$this->parse((string)$json,['theme/sample'=>true]);
        $this->assertSame(2,$result['seen']);
        $this->assertCount(2,$result['matches']['theme/sample']);
    }
}
