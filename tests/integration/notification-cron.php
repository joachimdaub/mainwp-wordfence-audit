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
$mail_ok=true;
$mail=function($preempt,$attributes) use (&$sent,&$mail_ok) {
    $sent[]=$attributes;
    return $mail_ok;
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
// A mail transport failure must not consume the pending finding.
update_option(MWA_Audit::EMAIL_ENABLED,true,false);
$mail_ok=false;
MWA_Audit::notify_new_findings();
mwa_notify_assert(get_option(MWA_Audit::EMAIL_RESULT)['status']==='send_failed','Failed email transport is reported');
mwa_notify_assert(get_option(MWA_Audit::KNOWN)===$known,'Failed delivery leaves baseline unchanged');
mwa_notify_assert(count($sent)===2,'Failed delivery made one intercepted attempt');
$mail_ok=true;
MWA_Audit::notify_new_findings();
mwa_notify_assert(get_option(MWA_Audit::EMAIL_RESULT)['status']==='sent','Previously failed notification can be retried');
mwa_notify_assert(count($sent)===3,'Successful retry sends one more attempt');
MWA_Audit::notify_new_findings();
mwa_notify_assert(count($sent)===3,'No duplicate after successful retry');

// Ignore a confirmed new finding before dispatching the registered cron hook.
$ignored_entry=$entry('ignored-id','Ignored vulnerability');
$index['plugin/fixture-plugin'][]=$ignored_entry;
file_put_contents($path,wp_json_encode($index));
$finding_method=new ReflectionMethod('MWA_Audit','finding_key');
$finding_method->setAccessible(true);
$finding_id=$finding_method->invoke(null,[
    'site'=>'https://fixture.example.test','type'=>'plugin',
    'slug'=>'fixture-plugin','name'=>'Fixture Plugin','version'=>'1.5'
],$ignored_entry);
update_option(MWA_Audit::IGNORED,[$finding_id=>['site'=>'https://fixture.example.test']],false);
mwa_notify_assert(has_action(MWA_Audit::CRON_NOTIFY,['MWA_Audit','notify_new_findings'])!==false,'Notification cron callback is registered');
do_action(MWA_Audit::CRON_NOTIFY);
mwa_notify_assert(get_option(MWA_Audit::EMAIL_RESULT)['status']==='no_new_findings','Cron hook skips ignored vulnerability');
mwa_notify_assert(count($sent)===3,'Ignored finding does not generate an email');
mwa_notify_assert(!in_array($finding_id,get_option(MWA_Audit::KNOWN,[]),true),'Ignored finding does not enter confirmed baseline');
remove_filter('pre_wp_mail',$mail,10);
WP_CLI::success('Cron notification integration test passed');
