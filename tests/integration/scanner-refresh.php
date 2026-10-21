<?php
/**
 * End-to-end scanner smoke test against disposable WordPress and simulated HTTP.
 * No MainWP or Wordfence network access: empty inventory is expected.
 */
function mwa_scan_assert($ok,$message) {
    if (!$ok) WP_CLI::error($message);
    WP_CLI::log('PASS: '.$message);
}

$temporary=new ReflectionMethod('MWA_Audit','create_feed_temp_file');
$temporary->setAccessible(true);
$temp_path=$temporary->invoke(null,'scanner-fixture.json');
mwa_scan_assert(is_string($temp_path) && is_file($temp_path),'Temporary feed file is created using real WordPress runtime');
if (is_string($temp_path)) @unlink($temp_path);
$execute=new ReflectionMethod('MWA_Audit','cron_execute');
$execute->setAccessible(true);
$feed=json_encode(['fixture-vulnerability'=>[
    'title'=>'Fixture finding',
    'software'=>[['type'=>'plugin','slug'=>'fixture-plugin','affected_versions'=>[]]]
]]);
$payload=$feed;
$http_code=200;
$requests=0;
$mock=function($preempt,$args,$url) use (&$payload,&$http_code,&$requests) {
    if (strpos($url,'wordfence.com/api/intelligence/v3/vulnerabilities/')===false) return $preempt;
    $requests++;
    if ($http_code===200) file_put_contents($args['filename'],$payload);
    return ['headers'=>[],'body'=>'','response'=>['code'=>$http_code,'message'=>'mock']];
};
add_filter('pre_http_request',$mock,10,3);
update_option(MWA_Audit::OPT_KEY,'fixture-key',false);
update_option(MWA_Audit::NEXT_ATTEMPT,0,false);
$run=function() use ($execute) {
    return $execute->invoke(null,'scanner',static function() { MWA_Audit::refresh(true); });
};
$status=$run();
mwa_scan_assert($status==='updated','Valid scanner feed completes refresh');
$index=(new ReflectionMethod('MWA_Audit','cached_index'));
$index->setAccessible(true);
mwa_scan_assert($index->invoke(null)===[],'Unmatched feed produces empty installed-software index');
$last=(int)get_option(MWA_Audit::LAST,0);
mwa_scan_assert($last>0,'Successful refresh updates timestamp');
$path=dirname(rtrim(ABSPATH,'/\\')).'/mainwp-wordfence-audit-private/'.MWA_Audit::INDEX_FILE;
mwa_scan_assert(is_file($path),'Private cache written');
$before=file_get_contents($path);
$payload='{"broken":';
update_option(MWA_Audit::NEXT_ATTEMPT,0,false);
$status=$run();
mwa_scan_assert($status==='api_error','Malformed scanner feed is rejected');
mwa_scan_assert(file_get_contents($path)===$before,'Malformed response leaves stored cache unchanged');
mwa_scan_assert((int)get_option(MWA_Audit::LAST,0)===$last,'Malformed feed does not advance successful timestamp');
$http_code=500;
update_option(MWA_Audit::NEXT_ATTEMPT,0,false);
$status=$run();
mwa_scan_assert($status==='api_error','HTTP 500 is reported as an error');
mwa_scan_assert(file_get_contents($path)===$before,'HTTP 500 does not replace the cache');
mwa_scan_assert($requests===3,'All three requests handled by fixture, not network');
remove_filter('pre_http_request',$mock,10);
WP_CLI::success('Scanner HTTP-to-cache smoke test passed');
