<?php
/**
 * End-to-end scanner smoke test against disposable WordPress and simulated HTTP.
 * A local MainWP fixture supplies installed software; no Wordfence network access.
 */
require_once __DIR__.'/mainwp-fixture.php';
function mwa_scan_assert($ok,$message) {
    if (!$ok) WP_CLI::error($message);
    WP_CLI::log('PASS: '.$message);
}

$temporary=new ReflectionMethod('MWA_Audit','create_feed_temp_file');
$temporary->setAccessible(true);
$temp_path=$temporary->invoke(null,'scanner-fixture.json');
mwa_scan_assert(is_string($temp_path) && is_file($temp_path),'Temporary feed file is created using real WordPress runtime');
if (is_string($temp_path)) @unlink($temp_path);
$save_index=new ReflectionMethod('MWA_Audit','save_cached_index');
$save_index->setAccessible(true);
$read_index=new ReflectionMethod('MWA_Audit','cached_index');
$read_index->setAccessible(true);
$execute=new ReflectionMethod('MWA_Audit','cron_execute');
$execute->setAccessible(true);
\MainWP\Dashboard\MainWP_DB::$plugins=[['file'=>'fixture-plugin/plugin.php','name'=>'Fixture Plugin','version'=>'1.5']];
update_option(MWA_Audit::INVENTORY_USER,1,false);
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
mwa_scan_assert(count($index->invoke(null)['plugin/fixture-plugin']??[])===1,'Matched software is persisted in database cache');
$last=(int)get_option(MWA_Audit::LAST,0);
mwa_scan_assert($last>0,'Successful refresh updates timestamp');
mwa_scan_assert($read_index->invoke(null)===$index->invoke(null),'Database cache is readable');
$before=$read_index->invoke(null);
$payload='{"broken":';
update_option(MWA_Audit::NEXT_ATTEMPT,0,false);
$status=$run();
mwa_scan_assert($status==='api_error','Malformed scanner feed is rejected');
mwa_scan_assert($read_index->invoke(null)===$before,'Malformed response leaves stored cache unchanged');
mwa_scan_assert((int)get_option(MWA_Audit::LAST,0)===$last,'Malformed feed does not advance successful timestamp');
$http_code=500;
update_option(MWA_Audit::NEXT_ATTEMPT,0,false);
$status=$run();
mwa_scan_assert($status==='api_error','HTTP 500 is reported as an error');
mwa_scan_assert($read_index->invoke(null)===$before,'HTTP 500 does not replace the cache');
mwa_scan_assert($requests===3,'All three requests handled by fixture, not network');
$http_code=200;
$payload=$feed;
\MainWP\Dashboard\MainWP_DB::$plugins=[];
update_option(MWA_Audit::NEXT_ATTEMPT,0,false);
$status=$run();
mwa_scan_assert($status==='api_error','Empty MainWP inventory rejects scanner update');
mwa_scan_assert($read_index->invoke(null)===$before,'Empty inventory cannot replace the previous cache');
\MainWP\Dashboard\MainWP_DB::$plugins=[['file'=>'unrelated-plugin/plugin.php','version'=>'1.0']];
update_option(MWA_Audit::NEXT_ATTEMPT,0,false);
$status=$run();
mwa_scan_assert($status==='api_error','Unmatched scanner feed rejects cache replacement');
$diag=get_option(MWA_Audit::SCANNER_DIAGNOSTICS,[]);
mwa_scan_assert((int)($diag['inventory_slugs']??-1)===1,'Scanner diagnostics count distinct inventory slugs');
mwa_scan_assert((int)($diag['feed_vulnerabilities']??-1)===1,'Scanner diagnostics count feed entries');
mwa_scan_assert((int)($diag['valid_software_slugs']??-1)===1,'Scanner diagnostics count valid software slugs');
mwa_scan_assert((int)($diag['matched_records']??-1)===0,'Scanner diagnostics retain zero-match counts after rejected refresh');
mwa_scan_assert((int)($diag['matched_slugs']??-1)===0,'Scanner diagnostics identify zero overlapping slugs');
mwa_scan_assert($read_index->invoke(null)===$before,'Zero matches cannot erase cached findings');
mwa_scan_assert($requests===5,'Both empty-inventory and zero-match checks preserve cache after intercepted HTTP requests');
remove_filter('pre_http_request',$mock,10);
WP_CLI::success('Scanner HTTP-to-cache smoke test passed');
