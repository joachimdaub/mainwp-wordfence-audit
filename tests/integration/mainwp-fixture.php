<?php
namespace MainWP\Dashboard;
final class MainWP_DB {
    public static $plugins=[];
    public static function instance() { return new self(); }
    public function get_websites_by_user_id($user) {
        return [(object)['id'=>9,'url'=>'https://fixture.example.test']];
    }
    public function get_website_by_id($id) {
        return (object)[
            'id'=>9,'url'=>'https://fixture.example.test',
            'plugins'=>json_encode(self::$plugins),
            'themes'=>'[]'
        ];
    }
    public function get_website_option($site,$name,$default='') { return $default; }
}
