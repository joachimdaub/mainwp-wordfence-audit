<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CronScheduleTest extends TestCase {
    private function nextTime(string $stage, int $now, int $cooldown): int {
        $method = new ReflectionMethod('MWA_Audit','combined_next_time');
        $method->setAccessible(true);
        return $method->invoke(null,$stage,$now,$cooldown);
    }

    public function testScannerStartsAfterShortSchedulingDelay(): void {
        $this->assertSame(1015,$this->nextTime('scanner',1000,0));
    }

    public function testScannerRespectsExistingWordfenceCooldown(): void {
        $this->assertSame(5010,$this->nextTime('scanner',1000,5000));
    }

    public function testSeverityWaitsAtLeastOneHour(): void {
        $this->assertSame(4600,$this->nextTime('severity',1000,0));
        $this->assertSame(4600,$this->nextTime('severity',1000,2000));
    }

    public function testSeverityWaitsForLongerRateLimit(): void {
        $this->assertSame(8060,$this->nextTime('severity',1000,8000));
    }
}
