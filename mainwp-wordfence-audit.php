<?php
/**
 * Plugin Name: MainWP Wordfence Audit
 * Description: Read-only vulnerability inventory for MainWP sites using Wordfence Intelligence v3.
 * Version: 0.1.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

final class MWA_Audit {
    const OPT_KEY = 'mwa_wordfence_key';
    const CACHE = 'mwa_wordfence_feed_v1';
    const LAST = 'mwa_wordfence_feed_updated';
    const ENDPOINT = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner';
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_mwa_save_key', [__CLASS__, 'save_key']);
        add_action('admin_post_mwa_refresh', [__CLASS__, 'refresh']);
    }
    private static function allowed() { return current_user_can('manage_options'); }
    public static function menu() {
        add_management_page('MainWP Security Audit', 'MainWP Security Audit', 'manage_options', 'mwa-audit', [__CLASS__, 'page']);
    }
    private static function redirect($status) {
        wp_safe_redirect(add_query_arg(['page'=>'mwa-audit','mwa_status'=>$status], admin_url('tools.php')));
        exit;
    }
    public static function save_key() {
        if (!self::allowed()) wp_die('Forbidden', '', ['response'=>403]);
        check_admin_referer('mwa_save_key');
        $key = isset($_POST['api_key']) && is_string($_POST['api_key']) ? trim(wp_unslash($_POST['api_key'])) : '';
        if (strlen($key)>512 || preg_match('/[^a-zA-Z0-9_.\-]/', $key)) self::redirect('invalid_key');
        if ($key !== '') update_option(self::OPT_KEY, $key, false);
        self::redirect('saved');
    }
    public static function refresh() {
        if (!self::allowed()) wp_die('Forbidden', '', ['response'=>403]);
        check_admin_referer('mwa_refresh');
        $last = (int)get_option('mwa_last_attempt', 0);
        if ($last && time()-$last < 120) self::redirect('throttled');
        update_option('mwa_last_attempt', time(), false);
        $key = get_option(self::OPT_KEY, '');
        if (!$key) self::redirect('no_key');
        $res = wp_remote_get(self::ENDPOINT, [
            'timeout'=>60, 'redirection'=>0,
            'headers'=>['Authorization'=>'Bearer '.$key,'Accept'=>'application/json'],
            'limit_response_size'=>35000000,
        ]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) self::redirect('api_error');
        $body = wp_remote_retrieve_body($res);
        $data = json_decode($body,true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE || !$data || strlen($body)>=35000000) self::redirect('invalid_feed');
        // Store only vulnerability data, never user credentials, under private WordPress options.
        update_option(self::CACHE, $data, false);
        update_option(self::LAST, time(), false);
        self::redirect('updated');
    }
    private static function range_status($version, $ranges) {
        if (!is_array($ranges) || !$ranges || !is_string($version) || !preg_match('/^\d+(?:\.\d+)*(?:[-.]?(?:alpha|beta|rc)\d*)?$/i',$version)) return 'unknown';
        $unknown=false;
        foreach ($ranges as $range) {
            if (!is_array($range)) { $unknown=true; continue; }
            $from=$range['from_version']??null; $to=$range['to_version']??null;
            if (!is_string($from) || !is_string($to)) { $unknown=true; continue; }
            if (($from!=='*' && !preg_match('/^\d+(?:\.\d+)*$/',$from)) || ($to!=='*' && !preg_match('/^\d+(?:\.\d+)*$/',$to))) { $unknown=true; continue; }
            if (!array_key_exists('from_inclusive',$range) || !array_key_exists('to_inclusive',$range)) { $unknown=true;continue; }
            $lower=$from==='*' || (version_compare($version,$from)>0 || (version_compare($version,$from)===0 && $range['from_inclusive']===true));
            $upper=$to==='*' || (version_compare($version,$to)<0 || (version_compare($version,$to)===0 && $range['to_inclusive']===true));
            if ($lower && $upper) return 'affected';
        }
        return $unknown?'unknown':'unaffected';
    }
    private static function unpack($raw) {
        if (is_string($raw)) {
            $json=json_decode($raw,true);
            if (is_array($json)) return $json;
            $php=@unserialize($raw,['allowed_classes'=>false]);
            if (is_array($php)) return $php;
        }
        return is_array($raw)?$raw:[];
    }
    private static function inventory() {
        if (!class_exists('\\MainWP\\Dashboard\\MainWP_DB')) return [[], 'MainWP Dashboard nicht gefunden'];
        $db=\MainWP\Dashboard\MainWP_DB::instance();
        // MainWP stores synced inventory on its site records. All operations here are read-only.
        $sites=$db->get_websites_by_user_id(get_current_user_id());
        if (!is_array($sites)) return [[], 'MainWP hat keine Website-Liste zurückgegeben'];
        $out=[];
        foreach ($sites as $site) {
            if (!is_object($site) || empty($site->id)) continue;
            $full=$db->get_website_by_id((int)$site->id);
            if (!$full) $full=$site;
            $url=(string)($full->url??$site->url??'');
            foreach (['plugins'=>'plugin','themes'=>'theme'] as $field=>$type) {
                $raw=$full->{$field}??null;
                if (!$raw) {
                    $raw=$db->get_website_option((array)$full,$field,'');
                }
                foreach (self::unpack($raw) as $item) {
                    if (!is_array($item)) continue;
                    $path=(string)($item['slug']??$item['file']??'');
                    $slug=$type==='plugin' ? explode('/', $path)[0] : $path;
                    // Never guess a slug based on a display name: false positives are worse than an unknown.
                    if (!preg_match('/^[a-zA-Z0-9_.-]+$/',$slug)) continue;
                    $version=(string)($item['version']??'');
                    $out[]=['site'=>$url,'type'=>$type,'slug'=>$slug,'name'=>(string)($item['name']??$slug),'version'=>$version];
                }
            }
        }
        return [$out, ''];
    }
    private static function index_feed($feed) {
        $idx=[];
        foreach ($feed as $id=>$row) {
            if (!is_array($row) || !isset($row['software']) || !is_array($row['software'])) continue;
            foreach ($row['software'] as $sw) {
                if (!is_array($sw) || !in_array($sw['type']??'', ['plugin','theme'],true) || empty($sw['slug'])) continue;
                $idx[$sw['type'].'/'.strtolower($sw['slug'])][]=['id'=>$id,'row'=>$row,'software'=>$sw];
            }
        }
        return $idx;
    }
    public static function page() {
        if (!self::allowed()) wp_die('Forbidden','',['response'=>403]);
        $feed=get_option(self::CACHE,[]);
        $when=(int)get_option(self::LAST,0);
        list($inventory,$error)=self::inventory();
        $idx=is_array($feed)?self::index_feed($feed):[];
        $hits=[];$unknown=[];
        foreach ($inventory as $item) {
            foreach ($idx[$item['type'].'/'.strtolower($item['slug'])]??[] as $v) {
                $status=self::range_status($item['version'],$v['software']['affected_versions']??null);
                if ($status==='affected') $hits[]=[$item,$v];
                elseif ($status==='unknown') $unknown[]=[$item,$v];
            }
        }
        echo '<div class="wrap"><h1>MainWP Security Audit <small>v0.1.0</small></h1>';
        echo '<p>Nur lesender Abgleich des zuletzt von MainWP synchronisierten Inventars mit Wordfence Intelligence. Keine Updates auf Kundenseiten.</p>';
        if (isset($_GET['mwa_status'])) echo '<div class="notice notice-info"><p>'.esc_html(sanitize_key(wp_unslash($_GET['mwa_status']))).'</p></div>';
        if ($error) echo '<div class="notice notice-warning"><p>'.esc_html($error).'</p></div>';
        echo '<p>Feed: '.($when?esc_html(wp_date('d.m.Y H:i:s',$when)):'noch nicht geladen').' | Inventareinträge: '.esc_html((string)count($inventory)).' | Treffer: '.esc_html((string)count($hits)).' | Unklare Zuordnungen: '.esc_html((string)count($unknown)).'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwa_save_key">';
        wp_nonce_field('mwa_save_key');echo '<label for="mwa-key">Wordfence v3 API-Key:</label> <input id="mwa-key" type="password" name="api_key" autocomplete="new-password" size="35" placeholder="Neuen Key eingeben (leer = unverändert)"> ';
        submit_button('Key speichern','secondary','submit',false);echo '</form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwa_refresh">';
        wp_nonce_field('mwa_refresh');submit_button('Wordfence-Feed aktualisieren','primary','submit',false);echo '</form>';
        if ($when && time()-$when>86400) echo '<p><strong>Hinweis:</strong> Der Feed ist älter als 24 Stunden.</p>';
        echo '<h2>Bestätigte Treffer nach Versionsbereich</h2>';
        self::table($hits);
        echo '<h2>Unklar / manuell prüfen</h2><p>Unbekannte Versionsformate oder unvollständige Bereiche werden nicht als sicher eingestuft.</p>';
        self::table($unknown);
        echo '<p><em>Kein Treffer bedeutet nur: In dieser Feed-Version wurde keine passende bekannte Schwachstelle erkannt. Das ist keine Sicherheitsgarantie.</em></p></div>';
    }
    private static function table($rows) {
        echo '<table class="widefat striped"><thead><tr><th>Website</th><th>Typ / Plugin oder Theme</th><th>Version</th><th>Schwachstelle</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="4">Keine Einträge</td></tr>';
        foreach ($rows as $pair) {
            list($item,$v)=$pair;
            echo '<tr><td>'.esc_html($item['site']).'</td><td>'.esc_html($item['type'].' / '.$item['name'].' ('.$item['slug'].')').'</td><td>'.esc_html($item['version']).'</td><td>'.esc_html($v['row']['title']??(string)$v['id']).'</td></tr>';
        }
        echo '</tbody></table>';
    }
}
MWA_Audit::init();
