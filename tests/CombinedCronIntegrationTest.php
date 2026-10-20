<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CombinedCronIntegrationTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['mwa_test_options']=[];
        $GLOBALS['mwa_test_scheduled_events']=[];
        $GLOBALS['mwa_test_schedule_success']=true;
    }

    public function testSeverityReschedulesWhenApiCooldownIsActive(): void {
        $until=time()+1800;
        update_option(MWA_Audit::NEXT_ATTEMPT,$until);
        MWA_Audit::combined_severity();
        $events=$GLOBALS['mwa_test_scheduled_events'];
        $this->assertCount(1,$events);
        $this->assertSame(MWA_Audit::CRON_COMBINED_SEVERITY,$events[0]['hook']);
        $this->assertSame($until+60,$events[0]['time']);
        $state=get_option(MWA_Audit::COMBINED_STATE);
        $this->assertSame('waiting_severity',$state['status']);
        $this->assertSame($until+60,$state['next']);
    }

    public function testSeverityReschedulingFailureIsVisible(): void {
        update_option(MWA_Audit::NEXT_ATTEMPT,time()+1800);
        $GLOBALS['mwa_test_schedule_success']=false;
        MWA_Audit::combined_severity();
        $this->assertSame('schedule_failed',get_option(MWA_Audit::COMBINED_STATE)['status']);
        $this->assertCount(0,$GLOBALS['mwa_test_scheduled_events']);
    }

    public function testCombinedScannerStopsIfApiKeyIsMissing(): void {
        MWA_Audit::combined_scanner();
        $this->assertSame('no_key',get_option('mwa_cron_scanner_result')['status']);
        $state=get_option(MWA_Audit::COMBINED_STATE);
        $this->assertSame('scanner_failed',$state['status']);
        $this->assertSame('no_key',$state['detail']);
        $this->assertCount(0,$GLOBALS['mwa_test_scheduled_events']);
    }
}
