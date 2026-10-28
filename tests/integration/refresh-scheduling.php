<?php
/** WordPress cron opt-in and rollback regression tests. No external calls. */
function mwa_schedule_assert($condition,$message) {
    if (!$condition) WP_CLI::error($message);
    WP_CLI::log('PASS: '.$message);
}
$scanner=MWA_Audit::CRON_SCANNER;
$severity=MWA_Audit::CRON_SEVERITY;
$notify=MWA_Audit::CRON_NOTIFY;
update_option(MWA_Audit::FAST_REFRESH,false,false);
MWA_Audit::ensure_schedule();
mwa_schedule_assert(wp_get_schedule($scanner)==='daily','Default scanner is daily');
mwa_schedule_assert(wp_get_schedule($severity)==='daily','Default severity is daily');
$notification=wp_next_scheduled($notify);
$known=['known-finding-key'];
update_option(MWA_Audit::KNOWN,$known,false);
$before_last=get_option(MWA_Audit::LAST,0);
update_option(MWA_Audit::FAST_REFRESH,true,false);
MWA_Audit::ensure_schedule();
mwa_schedule_assert(wp_get_schedule($scanner)==='mwa_three_hours','Fast scanner uses three-hour recurrence');
mwa_schedule_assert(wp_get_schedule($severity)==='mwa_six_hours','Fast severity uses six-hour recurrence');
mwa_schedule_assert(wp_next_scheduled($notify)===$notification,'Switch preserves notification schedule');
$scanner_at=wp_next_scheduled($scanner);
$severity_at=wp_next_scheduled($severity);
mwa_schedule_assert($severity_at>$scanner_at+3000,'Severity starts more than 45 minutes after scanner');
MWA_Audit::ensure_schedule();
mwa_schedule_assert(wp_next_scheduled($scanner)===$scanner_at && wp_next_scheduled($severity)===$severity_at,'Repeated initialization does not reschedule');
mwa_schedule_assert(get_option(MWA_Audit::KNOWN)===$known && get_option(MWA_Audit::LAST,0)===$before_last,'Switch preserves comparison baseline and successful feed timestamp');
update_option(MWA_Audit::FAST_REFRESH,false,false);
MWA_Audit::ensure_schedule();
mwa_schedule_assert(wp_get_schedule($scanner)==='daily' && wp_get_schedule($severity)==='daily','Rollback restores daily schedules');
mwa_schedule_assert(wp_next_scheduled($notify)===$notification,'Rollback preserves notification schedule');
delete_option(MWA_Audit::KNOWN);
WP_CLI::success('Opt-in scheduler integration passed');
