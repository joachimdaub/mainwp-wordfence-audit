<?php
if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__).'/');
if (!function_exists('add_action')) {
    function add_action(...$args) { return true; }
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) { return true; }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code;
        public function __construct($code) { $this->code=$code; }
        public function get_error_code() { return $this->code; }
    }
}
$GLOBALS['mwa_test_options']=[];
if (!function_exists('get_option')) {
    function get_option($key,$default=false) { return $GLOBALS['mwa_test_options'][$key]??$default; }
}
if (!function_exists('update_option')) {
    function update_option($key,$value,$autoload=false) { $GLOBALS['mwa_test_options'][$key]=$value; return true; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim(strip_tags((string)$text)); }
}
$GLOBALS['mwa_test_scheduled_events']=[];
$GLOBALS['mwa_test_schedule_success']=true;
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp,$hook,$args=[]) {
        if (!$GLOBALS['mwa_test_schedule_success']) return false;
        $GLOBALS['mwa_test_scheduled_events'][]=['time'=>$timestamp,'hook'=>$hook,'args'=>$args];
        return true;
    }
}
require_once dirname(__DIR__).'/mainwp-wordfence-audit.php';
