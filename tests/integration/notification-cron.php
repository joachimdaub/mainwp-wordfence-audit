<?php
/**
 * WordPress notification integration smoke test with an isolated MainWP fixture.
 * All email transport is intercepted; no outbound mail is sent.
 */
require_once __DIR__.'/mainwp-fixture.php';
function mwa_notify_assert($condition,$message) {
    if (!$condition) WP_CLI::error($message);
    WP_CLI::log('PASS: '.$message);
}
$sent=[];
$mail=function($preempt,$attributes) use (&$sent) {
    $sent[]=$attributes;
    return true;
};
add_filter('pre_wp_mail',$mail,10,2);
$dir=dirname(rtrim(ABSPATH,'/\\')).'/mainwp-wordfence-audit-private';
if (!is_dir($dir) && !wp_mkdir_p($dir)) WP_CLI::error('Cannot prepare private test cache');
$path=$dir.'/'.MWA_Audit::INDEX_FILE;
$range=[['from_version'=>'1.0','to_version'=>'2.0','from_inclusive'=>true,'to_inclusive'=>true]];
$entry=function($id,$title) use ($range) {
    return ['id'=>$id,'row'=>['title'=>$title,'cvss'=>['score'=>8.1]],'software'=>['type'=>'plugin','slug'=>'fixture-plugin','affected_versions'=>$range]];
};
$index=['plugin/fixture-plugin'=>[$entry('existing-id','Existing vulnerability')]];
file_put_contents($path,wp_json_encode($index));
\MainWP\Dashboard\MainWP_DB::$plugins=[['file'=>'fixture-plugin/plugin.php','name'=>'Fixture Plugin','version'=>'1.5']];
update_option(MWA_Audit::INVENTORY_USER,1,false);
update_option(MWA_Audit::LAST,time(),false);
update_option(MWA_Audit::EMAIL_ENABLED,true,false);
update_option(MWA_Audit::EMAIL_RECIPIENT,'alerts@example.test',false);
delete_option(MWA_Audit::KNOWN);
MWA_Audit::notify_new_findings();
mwa_notify_assert(get_option(MWA_Audit::EMAIL_RESULT)['status']==='baseline_created','First run establishes baseline');
mwa_notify_assert(count($sent)===0,'First run does not send old vulnerabilities');
MWA_Audit::notify_new_findings();
mwa_notify_assert(get_option(MWA_Audit::EMAIL_RESULT)['status']==='no_new_findings','Repeated run does not report unchanged vulnerabilities');
$index['plugin/fixture-plugin'][]=$entry('new-id','New vulnerability');
file_put_contents($path,wp_json_encode($index));
MWA_Audit::notify_new_findings();
mwa_notify_assert(get_option(MWA_Audit::EMAIL_RESULT)['status']==='sent','New vulnerability triggers notification');
mwa_notify_assert(count($sent)===1,'Exactly one email was intercepted');
mwa_notify_assert(strpos($sent[0]['message'],'New vulnerability')!==false,'Notification includes newly discovered vulnerability');
MWA_Audit::notify_new_findings();
mwa_notify_assert(count($sent)===1,'Repeated run does not duplicate notification');
update_option(MWA_Audit::EMAIL_ENABLED,false,false);
$index['plugin/fixture-plugin'][]=$entry('disabled-id','While disabled');
file_put_contents($path,wp_json_encode($index));
$known=get_option(MWA_Audit::KNOWN);
MWA_Audit::notify_new_findings();
mwa_notify_assert(get_option(MWA_Audit::EMAIL_RESULT)['status']==='disabled','Disabled notifications skip email');
mwa_notify_assert(get_option(MWA_Audit::KNOWN)===$known,'Disabled notifications preserve prior baseline');
mwa_notify_assert(count($sent)===1,'Disabled notifications do not send mail');
remove_filter('pre_wp_mail',$mail,10);
WP_CLI::success('Cron notification integration test passed');
