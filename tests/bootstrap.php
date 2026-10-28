<?php
if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__).'/');
if (!function_exists('add_action')) {
    function add_action(...$args) { return true; }
}
if (!function_exists('add_filter')) {
    function add_filter(...$args) { return true; }
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
if (!function_exists('is_wp_error')) {
    function is_wp_error($value) { return $value instanceof WP_Error; }
}
$GLOBALS['mwa_test_http_response']=['code'=>200,'headers'=>[]];
$GLOBALS['mwa_test_http_requests']=[];
if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url,$args=[]) {
        $GLOBALS['mwa_test_http_requests'][]=['url'=>$url,'args'=>$args];
        $result=$GLOBALS['mwa_test_http_response'];
        if (!($result instanceof WP_Error) && isset($args['filename']) && array_key_exists('body',$result)) {
            file_put_contents($args['filename'],$result['body']);
        }
        return $result;
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) { return $response['code']??0; }
}
if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header($response,$name) { return $response['headers'][$name]??''; }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length=12,$special_chars=false,$extra_special_chars=false) {
        return substr(bin2hex(random_bytes(16)),0,$length);
    }
}
if (!function_exists('esc_html')) {
    function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('wp_html_excerpt')) {
    function wp_html_excerpt($str,$count,$more=null) {
        return strlen($str)>$count ? substr($str,0,$count).($more??'') : $str;
    }
}
require_once dirname(__DIR__).'/mainwp-wordfence-audit.php';
