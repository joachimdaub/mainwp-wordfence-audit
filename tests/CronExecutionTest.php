<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CronExecutionTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['mwa_test_options']=[];
    }

    private function runCallback(callable $callback): string {
        $method=new ReflectionMethod('MWA_Audit','cron_execute');
        $method->setAccessible(true);
        return $method->invoke(null,'scanner',$callback);
    }

    public function testSuccessfulCallbackStoresResult(): void {
        $ran=false;
        $status=$this->runCallback(function () use (&$ran) {
            $ran=true;
            throw new RuntimeException('updated');
        });
        $this->assertTrue($ran);
        $this->assertSame('updated',$status);
        $this->assertSame('updated',get_option('mwa_cron_scanner_result')['status']);
    }

    public function testExpectedFailureStoresStatus(): void {
        $status=$this->runCallback(function () { throw new RuntimeException('throttled'); });
        $this->assertSame('throttled',$status);
        $this->assertSame('throttled',get_option('mwa_cron_scanner_result')['status']);
    }

    public function testUnexpectedExceptionPreservesDiagnostics(): void {
        $status=$this->runCallback(function () { throw new LogicException('Unexpected test failure'); });
        $this->assertSame('unexpected_error',$status);
        $this->assertSame('unexpected_error',get_option('mwa_cron_scanner_result')['status']);
        $details=get_option('mwa_cron_scanner_error');
        $this->assertSame('LogicException',$details['type']);
        $this->assertSame('Unexpected test failure',$details['detail']);
    }

    public function testNormalReturnIsReportedUnknown(): void {
        $this->assertSame('unknown',$this->runCallback(function () {}));
    }
}
