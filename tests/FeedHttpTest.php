<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FeedHttpTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['mwa_test_options']=[];
        $GLOBALS['mwa_test_http_response']=['code'=>200,'headers'=>[]];
        $GLOBALS['mwa_test_http_requests']=[];
    }

    private function download($endpoint='scanner'): array {
        $file=tempnam(sys_get_temp_dir(),'mwa-http-');
        if ($file===false) throw new RuntimeException('Temporary file unavailable');
        $download=new ReflectionMethod('MWA_Audit','download_feed');
        $download->setAccessible(true);
        $executor=new ReflectionMethod('MWA_Audit','cron_execute');
        $executor->setAccessible(true);
        try {
            $status=$executor->invoke(null,'scanner',function () use ($download,$file,$endpoint) {
                $download->invoke(null,$endpoint,'fake-test-key',$file,'Test Wordfence');
                throw new RuntimeException('updated');
            });
            return [$status,file_exists($file),file_exists($file)?file_get_contents($file):null];
        } finally {
            @unlink($file);
        }
    }

    public function testHttp200StreamsSuccessfulBody(): void {
        $GLOBALS['mwa_test_http_response']=['code'=>200,'headers'=>[],'body'=>'{"vuln":{}}'];
        [$status,$exists,$body]=$this->download();
        $this->assertSame('updated',$status);
        $this->assertTrue($exists);
        $this->assertSame('{"vuln":{}}',$body);
        $this->assertTrue($GLOBALS['mwa_test_http_requests'][0]['args']['stream']);
    }

    public function testHttp429StoresRetryAfterAndDeletesPartialFile(): void {
        $GLOBALS['mwa_test_http_response']=['code'=>429,'headers'=>['retry-after'=>'7200'],'body'=>'partial'];
        $started=time();
        [$status,$exists]=$this->download();
        $this->assertSame('api_error',$status);
        $this->assertFalse($exists);
        $this->assertGreaterThanOrEqual($started+7200,(int)get_option(MWA_Audit::NEXT_ATTEMPT));
        $this->assertStringContainsString('429',(string)get_option(MWA_Audit::ERROR_OPTION));
    }

    public function testHttp500RejectsPartialFile(): void {
        $GLOBALS['mwa_test_http_response']=['code'=>500,'headers'=>[],'body'=>'partial'];
        [$status,$exists]=$this->download();
        $this->assertSame('api_error',$status);
        $this->assertFalse($exists);
        $this->assertStringContainsString('500',(string)get_option(MWA_Audit::ERROR_OPTION));
    }

    public function testDownloadFailureDeletesPartialFile(): void {
        $GLOBALS['mwa_test_http_response']=new WP_Error('timeout');
        [$status,$exists]=$this->download();
        $this->assertSame('api_error',$status);
        $this->assertFalse($exists);
        $this->assertStringContainsString('timeout',(string)get_option(MWA_Audit::ERROR_OPTION));
    }

    public function testProductionFeedUsesSameHandling(): void {
        $GLOBALS['mwa_test_http_response']=['code'=>429,'headers'=>['retry-after'=>'120'],'body'=>'partial'];
        [$status,$exists]=$this->download(MWA_Audit::PRODUCTION_ENDPOINT);
        $this->assertSame('api_error',$status);
        $this->assertFalse($exists);
        $this->assertGreaterThanOrEqual(time()+3500,(int)get_option(MWA_Audit::NEXT_ATTEMPT));
        $this->assertSame(MWA_Audit::PRODUCTION_ENDPOINT,$GLOBALS['mwa_test_http_requests'][0]['url']);
    }
}
