<?php
/**
 * WordPress production-feed integration test using local HTTP fixtures.
 * This test never calls Wordfence or any child site.
 */
function mwa_severity_assert($ok,$message) {
    if (!$ok) WP_CLI::error($message);
    WP_CLI::log('PASS: '.$message);
}
$path=dirname(rtrim(ABSPATH,'/\\')).'/mainwp-wordfence-audit-private/'.MWA_Audit::INDEX_FILE;
$dir=dirname($path);
if (!is_dir($dir) && !wp_mkdir_p($dir)) WP_CLI::error('Cannot create private fixture directory');
$original=[
    'plugin/fixture-plugin'=>[
        ['id'=>'fixture-one','row'=>['title'=>'Keep title','cvss'=>['score'=>4.0]],'software'=>['type'=>'plugin','slug'=>'fixture-plugin']],
        ['id'=>'fixture-two','row'=>['title'=>'Unrated item'],'software'=>['type'=>'plugin','slug'=>'fixture-plugin']]
    ],
    'theme/fixture-theme'=>[
        ['id'=>'fixture-three','row'=>['title'=>'Unchanged','cvss'=>['score'=>3.0]],'software'=>['type'=>'theme','slug'=>'fixture-theme']]
    ]
];
file_put_contents($path,wp_json_encode($original));
$payload=wp_json_encode([
    'fixture-one'=>['title'=>'External title','cvss'=>['score'=>9.8],'software'=>[['type'=>'plugin','slug'=>'fixture-plugin']]],
    'fixture-two'=>['title'=>'External second','cvss'=>['score'=>7.1],'software'=>[['type'=>'plugin','slug'=>'fixture-plugin']]],
    'fixture-three'=>['cvss'=>['invalid'=>'no score'],'software'=>[['type'=>'theme','slug'=>'fixture-theme']]],
    'unmatched'=>['cvss'=>['score'=>10.0],'software'=>[['type'=>'plugin','slug'=>'not-installed']]]
]);
$http_code=200;
$requests=0;
$mock=function($preempt,$args,$url) use (&$payload,&$http_code,&$requests) {
    if ($url!==MWA_Audit::PRODUCTION_ENDPOINT) return $preempt;
    $requests++;
    if ($http_code===200) file_put_contents($args['filename'],$payload);
    return ['headers'=>[],'body'=>'','response'=>['code'=>$http_code,'message'=>'fixture']];
};
add_filter('pre_http_request',$mock,10,3);
update_option(MWA_Audit::OPT_KEY,'fixture-key',false);
$execute=new ReflectionMethod('MWA_Audit','cron_execute');
$execute->setAccessible(true);
$run=static function() use ($execute) {
    return $execute->invoke(null,'severity',static function() { MWA_Audit::enrich_severity(true); });
};
update_option(MWA_Audit::NEXT_ATTEMPT,0,false);
mwa_severity_assert($run()==='severity_updated','Production feed enriches cached scanner findings');
$index=json_decode(file_get_contents($path),true);
mwa_severity_assert($index['plugin/fixture-plugin'][0]['row']['cvss']['score']===9.8,'Matching ID receives new CVSS');
mwa_severity_assert($index['plugin/fixture-plugin'][1]['row']['cvss']['score']===7.1,'Second matching ID receives CVSS');
mwa_severity_assert($index['plugin/fixture-plugin'][0]['row']['title']==='Keep title','Scanner title is preserved');
mwa_severity_assert($index['theme/fixture-theme']===$original['theme/fixture-theme'],'Invalid CVSS does not overwrite earlier score');
mwa_severity_assert(count($index)===2,'Unmatched production software is not inserted');
mwa_severity_assert((int)get_option('mwa_severity_enriched_count',-1)===2,'Enrichment count persisted');
$updated=(int)get_option(MWA_Audit::SEVERITY_UPDATED,0);
mwa_severity_assert($updated>0,'Successful enrichment timestamp persisted');
$before=file_get_contents($path);
$payload='{"broken":';
update_option(MWA_Audit::NEXT_ATTEMPT,0,false);
mwa_severity_assert($run()==='api_error','Malformed production JSON rejected');
mwa_severity_assert(file_get_contents($path)===$before,'Malformed production feed preserves cache');
mwa_severity_assert((int)get_option(MWA_Audit::SEVERITY_UPDATED,0)===$updated,'Failed refresh preserves success timestamp');
$http_code=500;
update_option(MWA_Audit::NEXT_ATTEMPT,0,false);
mwa_severity_assert($run()==='api_error','HTTP 500 does not succeed');
mwa_severity_assert(file_get_contents($path)===$before,'HTTP 500 preserves cache');
mwa_severity_assert($requests===3,'All production requests intercepted locally');
remove_filter('pre_http_request',$mock,10);
WP_CLI::success('Production severity integration test passed');
