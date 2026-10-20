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
require_once dirname(__DIR__).'/mainwp-wordfence-audit.php';
