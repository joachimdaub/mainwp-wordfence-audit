<?php
/**
 * Clean activation + upgrade-state preservation against disposable WordPress.
 * No network, no child sites, no real Wordfence key.
 */
if (!function_exists('is_plugin_active')) require_once ABSPATH.'wp-admin/includes/plugin.php';
function mwa_lifecycle_assert($ok,$label) {
    if (!$ok) WP_CLI::error($label);
    WP_CLI::log('PASS: '.$label);
}
global $wpdb;
$table=$wpdb->prefix.'mwa_vulnerability_cache';
$plugin='mainwp-wordfence-audit/mainwp-wordfence-audit.php';
mwa_lifecycle_assert(is_plugin_active($plugin),'Fresh install activated');
mwa_lifecycle_assert(MWA_Audit::ensure_cache_table(),'Fresh install creates database cache');
mwa_lifecycle_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table,'Cache table exists');
mwa_lifecycle_assert(get_option(MWA_Audit::DB_SCHEMA_OPTION)===MWA_Audit::DB_SCHEMA_VERSION,'Schema version stored');
foreach ([MWA_Audit::CRON_SCANNER,MWA_Audit::CRON_SEVERITY,MWA_Audit::CRON_NOTIFY] as $hook) {
    mwa_lifecycle_assert((bool)wp_next_scheduled($hook),'Fresh install registered '.$hook);
}
// Simulate persisted state from an older installation, without requiring its binaries.
$ignored=['a'=>['timestamp'=>123456789,'site'=>'https://example.test','slug'=>'fixture']];
$known=['existing-finding-key'];
$cache=['plugin/fixture'=>[['id'=>'fixture-one','row'=>['title'=>'Existing finding'],'software'=>['slug'=>'fixture','type'=>'plugin']]]];
update_option(MWA_Audit::IGNORED,$ignored,false);
update_option(MWA_Audit::KNOWN,$known,false);
update_option(MWA_Audit::EMAIL_ENABLED,true,false);
update_option(MWA_Audit::FAST_REFRESH,true,false);
MWA_Audit::ensure_schedule();
$json=wp_json_encode($cache);
mwa_lifecycle_assert($wpdb->query($wpdb->prepare(
    "INSERT INTO $table (cache_id,payload,updated_at) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE payload=VALUES(payload)",
    'scanner',$json,current_time('mysql',true)))!==false,'Fixture cache saved');
$before=$wpdb->get_var($wpdb->prepare("SELECT payload FROM $table WHERE cache_id=%s",'scanner'));
deactivate_plugins($plugin);
mwa_lifecycle_assert(!wp_next_scheduled(MWA_Audit::CRON_SCANNER),'Deactivation clears scanner schedule');
mwa_lifecycle_assert(get_option(MWA_Audit::IGNORED)===$ignored,'Ignored findings persist across deactivation');
mwa_lifecycle_assert(get_option(MWA_Audit::KNOWN)===$known,'Known notification baseline persists');
$result=activate_plugin($plugin);
mwa_lifecycle_assert(!is_wp_error($result),'Plugin reactivation succeeds');
MWA_Audit::ensure_schedule();
mwa_lifecycle_assert(wp_get_schedule(MWA_Audit::CRON_SCANNER)==='mwa_three_hours','Saved accelerated schedule restored');
mwa_lifecycle_assert(wp_get_schedule(MWA_Audit::CRON_SEVERITY)==='mwa_six_hours','Saved severity schedule restored');
mwa_lifecycle_assert(get_option(MWA_Audit::IGNORED)===$ignored,'Ignored findings survive reactivation');
mwa_lifecycle_assert(get_option(MWA_Audit::KNOWN)===$known,'Email baseline survives reactivation');
mwa_lifecycle_assert($wpdb->get_var($wpdb->prepare("SELECT payload FROM $table WHERE cache_id=%s",'scanner'))===$before,'Database payload survives reactivation');
// Restore fixture defaults for subsequent integration tests.
update_option(MWA_Audit::FAST_REFRESH,false,false);
MWA_Audit::ensure_schedule();
delete_option(MWA_Audit::IGNORED);
delete_option(MWA_Audit::KNOWN);
delete_option(MWA_Audit::EMAIL_ENABLED);
$wpdb->delete($table,['cache_id'=>'scanner'],['%s']);
WP_CLI::success('Fresh installation and persisted-state lifecycle passed');
