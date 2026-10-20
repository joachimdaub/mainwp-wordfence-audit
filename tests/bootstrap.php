<?php
if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__).'/');
if (!function_exists('add_action')) {
    function add_action(...$args) { return true; }
}
require_once dirname(__DIR__).'/mainwp-wordfence-audit.php';
