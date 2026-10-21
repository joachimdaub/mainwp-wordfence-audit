<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminSecurityTest extends TestCase {
    private function validKey($value): bool {
        $method=new ReflectionMethod('MWA_Audit','valid_api_key');
        $method->setAccessible(true);
        return $method->invoke(null,$value);
    }

    public function testApiKeyAcceptsExpectedCharactersAndEmptyKeepValue(): void {
        $this->assertTrue($this->validKey('valid-API_key.123'));
        $this->assertTrue($this->validKey(''));
        $this->assertTrue($this->validKey(str_repeat('a',512)));
    }

    public function testApiKeyRejectsHeaderInjectionWhitespaceAndInvalidTypes(): void {
        foreach (["bad\r\nAuthorization: injected","bad value","with/slash",str_repeat('a',513),[],null,123] as $value) {
            $this->assertFalse($this->validKey($value));
        }
    }

    public function testEveryAdminPostHandlerChecksCapabilityAndNonce(): void {
        // Static regression guard: catches accidental removal from endpoint entry points.
        $source=file_get_contents(dirname(__DIR__).'/mainwp-wordfence-audit.php');
        $this->assertIsString($source);
        foreach ([
            'start_combined'=>'mwa_combined',
            'save_visibility'=>'mwa_save_visibility',
            'save_key'=>'mwa_save_key',
            'save_notifications'=>'mwa_save_notifications',
            'test_email'=>'mwa_test_email',
            'refresh'=>'mwa_refresh',
            'enrich_severity'=>'mwa_enrich',
            'ignore_action'=>'mwa_ignore',
            'inventory_refresh'=>'mwa_inventory',
        ] as $handler=>$nonce) {
            $pattern='/public static function '.preg_quote($handler,'/').'\\([^)]*\\)\\s*\\{([\\s\\S]*?)(?=\\n    (?:public|private) static function |\\n    \/\\*|\\n})/';
            $this->assertSame(1,preg_match($pattern,$source,$matches),'Missing handler: '.$handler);
            $this->assertStringContainsString('self::allowed()',$matches[1],$handler.' missing capability check');
            $this->assertStringContainsString("check_admin_referer('".$nonce."')",$matches[1],$handler.' missing nonce check');
        }
    }
}
