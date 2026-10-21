<?php
/**
 * Run with WP-CLI eval-file against a temporary real WordPress installation.
 * No MainWP instance, API key, mail transport, or Wordfence network is required.
 */
function mwa_smoke_assert($condition, $description) {
    if (!$condition) {
        WP_CLI::error($description);
    }
    WP_CLI::log('PASS: '.$description);
}

mwa_smoke_assert(is_plugin_active('mainwp-wordfence-audit/mainwp-wordfence-audit.php'), 'Plugin activates successfully');
mwa_smoke_assert(class_exists('MWA_Audit'), 'Audit class loads');

foreach ([
    MWA_Audit::CRON_SCANNER,
    MWA_Audit::CRON_SEVERITY,
    MWA_Audit::CRON_NOTIFY
] as $hook) {
    mwa_smoke_assert((bool)wp_next_scheduled($hook), 'Recurring cron registered: '.$hook);
}

mwa_smoke_assert(has_action('admin_post_mwa_save_key', ['MWA_Audit','save_key']) !== false, 'API key handler is registered');
mwa_smoke_assert(has_action('admin_post_mwa_ignore', ['MWA_Audit','ignore_action']) !== false, 'Ignore handler is registered');
mwa_smoke_assert(has_action('admin_post_mwa_combined', ['MWA_Audit','start_combined']) !== false, 'Combined refresh handler is registered');

$admin=get_user_by('login','mwaadmin');
mwa_smoke_assert($admin instanceof WP_User, 'Administrator exists');
wp_set_current_user($admin->ID);
mwa_smoke_assert(current_user_can('manage_options'), 'Administrator has audit capability');

$subscriber_id=wp_create_user('mwasubscriber','testing-only-password','subscriber@example.test');
mwa_smoke_assert(!is_wp_error($subscriber_id), 'Subscriber account created');
(new WP_User($subscriber_id))->set_role('subscriber');
wp_set_current_user($subscriber_id);
mwa_smoke_assert(!current_user_can('manage_options'), 'Subscriber does not have audit capability');
wp_set_current_user($admin->ID);

MWA_Audit::deactivate();
foreach ([MWA_Audit::CRON_SCANNER,MWA_Audit::CRON_SEVERITY,MWA_Audit::CRON_NOTIFY] as $hook) {
    mwa_smoke_assert(wp_next_scheduled($hook)===false, 'Recurring cron is cleared on deactivation: '.$hook);
}
WP_CLI::success('WordPress integration smoke test passed');
