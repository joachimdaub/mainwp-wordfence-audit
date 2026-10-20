<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CombinedRefreshTest extends TestCase {
    private function state(string $stage, $result): string {
        $method=new ReflectionMethod('MWA_Audit','combined_result_state');
        $method->setAccessible(true);
        return $method->invoke(null,$stage,$result);
    }

    public function testScannerSuccessMovesToSeverity(): void {
        $this->assertSame('waiting_severity',$this->state('scanner',['status'=>'updated']));
    }

    public function testScannerErrorsStopCombinedRefresh(): void {
        foreach (['unexpected_error','api_error','throttled','no_key','unknown'] as $status) {
            $this->assertSame('scanner_failed',$this->state('scanner',['status'=>$status]));
        }
        $this->assertSame('scanner_failed',$this->state('scanner',[]));
    }

    public function testSeveritySuccessCompletesRefresh(): void {
        $this->assertSame('complete',$this->state('severity',['status'=>'severity_updated']));
    }

    public function testSeverityErrorsCannotBeMarkedComplete(): void {
        foreach (['unexpected_error','api_error','throttled','no_scanner_feed','unknown'] as $status) {
            $this->assertSame('severity_failed',$this->state('severity',['status'=>$status]));
        }
        $this->assertSame('severity_failed',$this->state('severity',null));
    }
}
