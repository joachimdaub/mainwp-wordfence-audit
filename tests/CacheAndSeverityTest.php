<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CacheAndSeverityTest extends TestCase {
    private function invoke(string $name, array $args) {
        $method=new ReflectionMethod('MWA_Audit',$name);
        $method->setAccessible(true);
        return $method->invokeArgs(null,$args);
    }

    public function testMalformedFeedCannotReplaceExistingCache(): void {
        $dir=sys_get_temp_dir().'/mwa-cache-'.uniqid('',true);
        mkdir($dir);
        $cache=$dir.'/'.MWA_Audit::INDEX_FILE;
        $original='{"plugin/example":[{"id":"known","row":{"cvss":{"score":9.8}}}]}';
        file_put_contents($cache,$original);
        $incoming=$dir.'/incoming.json';
        file_put_contents($incoming,'{"vulnerability":{');
        try {
            $result=$this->invoke('stream_index',[$incoming,['plugin/example'=>true]]);
            $this->assertInstanceOf(WP_Error::class,$result);
            // Caller must not publish a rejected feed; the stored cache remains intact.
            $this->assertSame($original,file_get_contents($cache));
        } finally {
            @unlink($incoming);
            @unlink($cache);
            @rmdir($dir);
        }
    }

    public function testSeverityEnrichmentUpdatesMatchingIdOnly(): void {
        $old=[
            'plugin/one'=>[
                ['id'=>'v1','row'=>['title'=>'First','cvss'=>['score'=>5.0]],'software'=>['slug'=>'one']],
                ['id'=>'v2','row'=>['title'=>'Second'],'software'=>['slug'=>'one']],
            ],
            'theme/two'=>[['id'=>'v3','row'=>['title'=>'Third','cvss'=>['score'=>4.0]]]],
        ];
        $production=['matches'=>[
            'plugin/one'=>[
                ['id'=>'v1','row'=>['cvss'=>['score'=>9.8]]],
                ['id'=>'v2','row'=>['cvss'=>['score'=>7.2]]],
            ],
            'theme/two'=>[['id'=>'v3','row'=>['cvss'=>['bad'=>'missing score']]]],
        ]];
        [$result,$count]=$this->invoke('apply_severity',[$old,$production]);
        $this->assertSame(2,$count);
        $this->assertSame(9.8,$result['plugin/one'][0]['row']['cvss']['score']);
        $this->assertSame(7.2,$result['plugin/one'][1]['row']['cvss']['score']);
        $this->assertSame('First',$result['plugin/one'][0]['row']['title']);
        $this->assertSame('one',$result['plugin/one'][0]['software']['slug']);
        $this->assertSame(4.0,$result['theme/two'][0]['row']['cvss']['score']);
        $this->assertSame($old['theme/two'],$result['theme/two']);
    }

    public function testUnmatchedProductionEntriesAreIgnored(): void {
        $old=['plugin/one'=>[['id'=>'v1','row'=>['title'=>'One']]]];
        $production=['matches'=>['plugin/one'=>[['id'=>'unknown','row'=>['cvss'=>['score'=>9.9]]]]]];
        [$result,$count]=$this->invoke('apply_severity',[$old,$production]);
        $this->assertSame(0,$count);
        $this->assertSame($old,$result);
    }
}
