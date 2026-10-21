<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdvisoryOutputSecurityTest extends TestCase {
    private function title($value): string {
        $method=new ReflectionMethod('MWA_Audit','safe_finding_title');
        $method->setAccessible(true);
        return $method->invoke(null,$value);
    }

    public function testMaliciousAdvisoryTitleIsEscaped(): void {
        $output=$this->title('<img src=x onerror=alert(1)> & "test"');
        $this->assertStringNotContainsString('<img',$output);
        $this->assertStringContainsString('&lt;img',$output);
        $this->assertStringContainsString('&amp;',$output);
        $this->assertStringContainsString('&quot;test&quot;',$output);
    }

    public function testOversizedExternalTitleIsBounded(): void {
        $output=$this->title(str_repeat('X',10000));
        $this->assertLessThan(320,strlen($output));
    }

    public function testUnexpectedArrayValueIsNotPrinted(): void {
        $this->assertSame('',$this->title(['unexpected'=>'value']));
    }
}
