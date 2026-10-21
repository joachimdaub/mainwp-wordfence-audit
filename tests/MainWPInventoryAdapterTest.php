<?php
namespace MainWP\Dashboard {
    final class MainWP_DB {
        public static $sites=[];
        public static $details=[];
        public static $options=[];
        public static $userId=null;
        public static function instance() { return new self(); }
        public function get_websites_by_user_id($id) {
            self::$userId=$id;
            return self::$sites;
        }
        public function get_website_by_id($id) { return self::$details[$id]??null; }
        public function get_website_option($site,$name,$default='') {
            return self::$options[(int)($site['id']??0)][$name]??$default;
        }
    }
}
namespace {
    use PHPUnit\Framework\TestCase;
    use MainWP\Dashboard\MainWP_DB;
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id() { return 0; }
    }

    final class MainWPInventoryAdapterTest extends TestCase {
        protected function setUp(): void {
            MainWP_DB::$sites=[];
            MainWP_DB::$details=[];
            MainWP_DB::$options=[];
            MainWP_DB::$userId=null;
            $GLOBALS['mwa_test_options']=[];
            update_option(MWA_Audit::INVENTORY_USER,17,false);
        }
        private function inventory(): array {
            $method=new \ReflectionMethod('MWA_Audit','inventory');
            $method->setAccessible(true);
            return $method->invoke(null);
        }
        public function testReadsSyncedPluginsAndThemesAcrossSites(): void {
            MainWP_DB::$sites=[
                (object)['id'=>1,'url'=>'https://one.example.test'],
                (object)['id'=>2,'url'=>'https://two.example.test'],
            ];
            MainWP_DB::$details[1]=(object)[
                'id'=>1,'url'=>'https://one.example.test',
                'plugins'=>json_encode([['file'=>'hello-dolly/hello.php','name'=>'Hello Dolly','version'=>'1.7.2']]),
                'themes'=>serialize([['slug'=>'twentytwentyfive','name'=>'Twenty Twenty-Five','version'=>'1.2']]),
            ];
            MainWP_DB::$details[2]=(object)['id'=>2,'url'=>'https://two.example.test'];
            MainWP_DB::$options[2]=[
                'plugins'=>json_encode([['slug'=>'akismet/akismet.php','version'=>'5.0']]),
                'themes'=>json_encode([['slug'=>'example-theme','version'=>'2.0']]),
            ];
            [$rows,$error]=$this->inventory();
            $this->assertSame('', $error);
            $this->assertCount(4,$rows);
            $this->assertSame(17,MainWP_DB::$userId);
            $this->assertSame(['site'=>'https://one.example.test','type'=>'plugin','slug'=>'hello-dolly','name'=>'Hello Dolly','version'=>'1.7.2'],$rows[0]);
            $this->assertSame('twentytwentyfive',$rows[1]['slug']);
            $this->assertSame('akismet',$rows[2]['slug']);
            $this->assertSame('https://two.example.test',$rows[3]['site']);
        }
        public function testInvalidSiteRecordsAndMalformedInventoryAreSkipped(): void {
            MainWP_DB::$sites=[null,(object)['url'=>'https://missing.example.test'],(object)['id'=>3,'url'=>'https://valid.example.test']];
            MainWP_DB::$details[3]=(object)[
                'id'=>3,'url'=>'https://valid.example.test',
                'plugins'=>'{invalid json',
                'themes'=>json_encode([['slug'=>'../invalid'],['slug'=>'safe-theme','version'=>'1.0']]),
            ];
            [$rows,$error]=$this->inventory();
            $this->assertSame('',$error);
            $this->assertCount(1,$rows);
            $this->assertSame('safe-theme',$rows[0]['slug']);
        }
        public function testMissingBackgroundUserIsReportedWithoutQueryingMainWP(): void {
            update_option(MWA_Audit::INVENTORY_USER,0,false);
            [$rows,$error]=$this->inventory();
            $this->assertSame([],$rows);
            $this->assertStringContainsString('No MainWP inventory user',$error);
            $this->assertNull(MainWP_DB::$userId);
        }
    }
}
