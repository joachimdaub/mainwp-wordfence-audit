<?php
/**
 * Legacy JSON-to-database migration smoke test in disposable WordPress.
 * Does not contact Wordfence or any connected child site.
 */
function mwa_migrate_assert($ok,$message) {
    if (!$ok) WP_CLI::error($message);
    WP_CLI::log('PASS: '.$message);
}
global $wpdb;
mwa_migrate_assert(MWA_Audit::ensure_cache_table(),'Database cache schema is installed');
$table=$wpdb->prefix.'mwa_vulnerability_cache';
$wpdb->delete($table,['cache_id'=>'scanner'],['%s']);
$uploads=wp_get_upload_dir();
$legacy_dir=trailingslashit($uploads['basedir']).'mainwp-wordfence-audit';
if (!is_dir($legacy_dir) && !wp_mkdir_p($legacy_dir)) WP_CLI::error('Unable to create legacy migration fixture');
$path=$legacy_dir.'/'.MWA_Audit::INDEX_FILE;
$original=['plugin/migration-fixture'=>[['id'=>'migration-one','row'=>['title'=>'Retained legacy finding'],'software'=>['type'=>'plugin','slug'=>'migration-fixture']]]];
file_put_contents($path,wp_json_encode($original));
$read=new ReflectionMethod('MWA_Audit','cached_index');
$read->setAccessible(true);
$imported=$read->invoke(null);
mwa_migrate_assert($imported===$original,'Valid legacy index imports into database');
mwa_migrate_assert(is_file($path),'Migration does not remove legacy source file');
mwa_migrate_assert($wpdb->get_var($wpdb->prepare("SELECT payload FROM $table WHERE cache_id=%s",'scanner'))!==null,'Migration creates persistent database row');
file_put_contents($path,'{"broken":');
mwa_migrate_assert($read->invoke(null)===$original,'Subsequent reads prefer database to changed legacy file');
@unlink($path);
$wpdb->delete($table,['cache_id'=>'scanner'],['%s']);
file_put_contents($path,'{"broken":');
mwa_migrate_assert($read->invoke(null)===[],'Invalid legacy JSON is not imported');
mwa_migrate_assert(is_file($path),'Invalid legacy file is preserved');
@unlink($path);
WP_CLI::success('Database cache migration integration passed');
