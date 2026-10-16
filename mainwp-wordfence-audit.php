<?php
/**
 * Plugin Name: MainWP Wordfence Audit
 * Description: Read-only vulnerability inventory for MainWP sites using Wordfence Intelligence v3.
 * Version: 0.2.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

final class MWA_Audit {
    const OPT_KEY = 'mwa_wordfence_key';
    const CACHE = 'mwa_wordfence_feed_v1';
    const INDEX_FILE = 'mainwp-wordfence-audit-index.json';
    const LAST = 'mwa_wordfence_feed_updated';
    const ENDPOINT = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner';
    const ERROR_OPTION = 'mwa_last_feed_error';
    const NEXT_ATTEMPT = 'mwa_next_feed_attempt';
    const MIN_INTERVAL = 2700; // 45 minutes, above the default Wordfence limit.
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
    private static function feed_error($message) {
        update_option(self::ERROR_OPTION, sanitize_text_field($message), false);
        self::redirect('api_error');
    }
    public static function refresh() {
        if (!self::allowed()) wp_die('Forbidden', '', ['response'=>403]);
        check_admin_referer('mwa_refresh');
        $key = get_option(self::OPT_KEY, '');
        if (!$key) self::redirect('no_key');
        $next = (int) get_option(self::NEXT_ATTEMPT, 0);
        if (time() < $next) self::redirect('throttled');
        update_option(self::NEXT_ATTEMPT, time() + self::MIN_INTERVAL, false);
        update_option('mwa_last_attempt', time(), false);
        // Stream directly to disk; WordPress must never buffer the full feed.
        $dir = self::storage_dir();
        if (is_wp_error($dir)) self::feed_error('Cache directory unavailable: '.$dir->get_error_code());
        $tmp = wp_tempnam('mwa-wordfence-feed.json');
        if (!$tmp) self::feed_error('Could not create temporary download file.');
        $res = wp_remote_get(self::ENDPOINT, [
            'timeout'=>180, 'redirection'=>0,
            'headers'=>['Authorization'=>'Bearer '.$key,'Accept'=>'application/json'],
            'stream'=>true, 'filename'=>$tmp,
        ]);
        if (is_wp_error($res)) {
            @unlink($tmp);
            self::feed_error('WordPress HTTP error: '.$res->get_error_code());
        }
        $http_code = (int) wp_remote_retrieve_response_code($res);
        if ($http_code === 429) {
            $retry = wp_remote_retrieve_header($res, 'retry-after');
            $seconds = is_numeric($retry) ? max(0,(int)$retry) : (is_string($retry) && strtotime($retry) ? max(0,strtotime($retry)-time()) : 0);
            update_option(self::NEXT_ATTEMPT, time() + max(3600,$seconds), false);
        }
        if ($http_code !== 200) {
            @unlink($tmp);
            self::feed_error('Wordfence HTTP '.$http_code.($http_code===429?' (rate limit reached)':''));
        }
        // Extract only the installed software's vulnerabilities, not the entire feed.
        list($inventory,) = self::inventory();
        $wanted = [];
        foreach ($inventory as $item) $wanted[$item['type'].'/'.strtolower($item['slug'])] = true;
        $index = self::stream_index($tmp, $wanted);
        @unlink($tmp);
        if (is_wp_error($index)) self::feed_error('Feed validation failed: '.$index->get_error_code());
        if (!$index['seen']) self::feed_error('The feed contained no vulnerability entries.');
        $json = wp_json_encode($index['matches']);
        if (!is_string($json)) self::feed_error('Could not encode vulnerability index.');
        $target = trailingslashit($dir).self::INDEX_FILE;
        $stage = $target.'.tmp-'.wp_generate_password(12,false,false);
        if (file_put_contents($stage,$json,LOCK_EX)===false || !@rename($stage,$target)) {
            @unlink($stage);
            self::feed_error('Could not save vulnerability index.');
        }
        @chmod($target,0600);
        delete_option(self::ERROR_OPTION);
        delete_option(self::CACHE); // Remove legacy oversized option.
        update_option(self::LAST, time(), false);
        self::redirect('updated');
    }
    private static function storage_dir() {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['basedir'])) return new WP_Error('uploads_unavailable');
        $dir = trailingslashit($uploads['basedir']).'mainwp-wordfence-audit';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) return new WP_Error('mkdir_failed');
        if (!is_writable($dir)) return new WP_Error('directory_not_writable');
        // Prevent direct HTTP access on Apache; never store secrets in this directory.
        if (!file_exists($dir.'/index.php')) @file_put_contents($dir.'/index.php', '<?php // Silence is golden.');
        if (!file_exists($dir.'/.htaccess')) @file_put_contents($dir.'/.htaccess', "Require all denied\nDeny from all\n");
        return $dir;
    }
    /**
     * Iterate a top-level JSON object without loading it all into memory.
     * JSON values are individually decoded and discarded unless they match
     * an installed plugin or theme. Reject incomplete/invalid JSON.
     */
    private static function stream_index($path, $wanted) {
        $fp = @fopen($path,'rb');
        if (!$fp) return new WP_Error('file_open_failed');
        $matches=[]; $seen=0; $state='start'; $key=''; $value='';
        $token=''; $in_string=false; $escaped=false; $depth=0;
        $error=null;
        // Buffered single-character reader: memory stays bounded.
        $buffer=''; $position=0; $length=0;
        while (true) {
            if ($position >= $length) {
                $buffer=fread($fp,65536);
                if ($buffer===false) { $error='read_failed'; break; }
                $length=strlen($buffer); $position=0;
                if (!$length) break;
            }
            $ch=$buffer[$position++];
            if ($state==='done') { if (!ctype_space($ch)) { $error='trailing_data'; break; } continue; }
            if ($state==='start') {
                if (ctype_space($ch)) continue;
                if ($ch!=='{') { $error='root_not_object'; break; }
                $state='key_or_end'; continue;
            }
            if ($state==='key_or_end') {
                if (ctype_space($ch)) continue;
                if ($ch==='}') { $state='done'; continue; }
                if ($ch!=='"') { $error='invalid_key'; break; }
                $token='"'; $in_string=true; $escaped=false; $state='key'; continue;
            }
            if ($state==='key') {
                $token.=$ch;
                if ($escaped) { $escaped=false; continue; }
                if ($ch==='\\') { $escaped=true; continue; }
                if ($ch==='"') {
                    $key=json_decode($token,true);
                    if (!is_string($key)) { $error='invalid_key_json'; break; }
                    $state='colon'; $token='';
                }
                if (strlen($token)>10240) { $error='key_too_large'; break; }
                continue;
            }
            if ($state==='colon') {
                if (ctype_space($ch)) continue;
                if ($ch!==':') { $error='missing_colon'; break; }
                $state='value_start'; continue;
            }
            if ($state==='value_start') {
                if (ctype_space($ch)) continue;
                if ($ch!=='{') { $error='invalid_entry'; break; }
                $value='{'; $depth=1; $in_string=false; $escaped=false; $state='value'; continue;
            }
            if ($state==='value') {
                $value.=$ch;
                if ($in_string) {
                    if ($escaped) $escaped=false;
                    elseif ($ch==='\\') $escaped=true;
                    elseif ($ch==='"') $in_string=false;
                } else {
                    if ($ch==='"') $in_string=true;
                    elseif ($ch==='{' || $ch==='[') $depth++;
                    elseif ($ch==='}' || $ch===']') $depth--;
                }
                if ($depth<0 || strlen($value)>8388608) { $error='entry_too_large'; break; }
                if ($depth===0) {
                    $row=json_decode($value,true);
                    if (!is_array($row) || json_last_error()!==JSON_ERROR_NONE) { $error='invalid_entry_json'; break; }
                    $seen++;
                    foreach ($row['software']??[] as $sw) {
                        if (!is_array($sw)) continue;
                        $type=$sw['type']??''; $slug=$sw['slug']??'';
                        if (!is_string($type) || !is_string($slug) || !isset($wanted[$type.'/'.strtolower($slug)])) continue;
                        $matches[$type.'/'.strtolower($slug)][]=['id'=>$key,'row'=>['title'=>(string)($row['title']??$key)],'software'=>$sw];
                    }
                    $value=''; $state='separator';
                }
                continue;
            }
            if ($state==='separator') {
                if (ctype_space($ch)) continue;
                if ($ch===',') { $state='key_or_end'; continue; }
                if ($ch==='}') { $state='done'; continue; }
                $error='invalid_separator'; break;
            }
        }
        fclose($fp);
        if ($error || $state!=='done') return new WP_Error($error?:'incomplete_json');
        return ['seen'=>$seen,'matches'=>$matches];
    }
    private static function cached_index() {
        $uploads=wp_get_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['basedir'])) return [];
        $path=trailingslashit($uploads['basedir']).'mainwp-wordfence-audit/'.self::INDEX_FILE;
        if (!is_readable($path)) return [];
        $raw=@file_get_contents($path);
        $decoded=is_string($raw)?json_decode($raw,true):null;
        return is_array($decoded)?$decoded:[];
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
        $idx=self::cached_index();
        $when=(int)get_option(self::LAST,0);
        list($inventory,$error)=self::inventory();

        $hits=[];$unknown=[];
        foreach ($inventory as $item) {
            foreach ($idx[$item['type'].'/'.strtolower($item['slug'])]??[] as $v) {
                $status=self::range_status($item['version'],$v['software']['affected_versions']??null);
                if ($status==='affected') $hits[]=[$item,$v];
                elseif ($status==='unknown') $unknown[]=[$item,$v];
            }
        }
        echo '<div class="wrap"><h1>MainWP Security Audit <small>v0.2.0</small></h1>';
        echo '<p>Nur lesender Abgleich des zuletzt von MainWP synchronisierten Inventars mit Wordfence Intelligence. Keine Updates auf Kundenseiten.</p>';
        if (isset($_GET['mwa_status'])) {
            $status=sanitize_key(wp_unslash($_GET['mwa_status']));
            echo '<div class="notice '.($status==='api_error'?'notice-error':'notice-info').'"><p>'.esc_html($status).'</p></div>';
            if ($status==='api_error') echo '<div class="notice notice-error"><p>'.esc_html((string)get_option(self::ERROR_OPTION, 'Keine Details verfügbar.')).'</p></div>';
        }
        if ($error) echo '<div class="notice notice-warning"><p>'.esc_html($error).'</p></div>';
        echo '<p>Feed: '.($when?esc_html(wp_date('d.m.Y H:i:s',$when)):'noch nicht geladen').' | Inventareinträge: '.esc_html((string)count($inventory)).' | Treffer: '.esc_html((string)count($hits)).' | Unklare Zuordnungen: '.esc_html((string)count($unknown)).'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwa_save_key">';
        wp_nonce_field('mwa_save_key');echo '<label for="mwa-key">Wordfence v3 API-Key:</label> <input id="mwa-key" type="password" name="api_key" autocomplete="new-password" size="35" placeholder="Neuen Key eingeben (leer = unverändert)"> ';
        submit_button('Key speichern','secondary','submit',false);echo '</form>';
        $next=(int)get_option(self::NEXT_ATTEMPT,0);
        if (time()<$next) echo '<p><strong>Wordfence-Sperrfrist:</strong> Nächster Abruf frühestens '.esc_html(wp_date('d.m.Y H:i:s',$next)).'.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwa_refresh">';
        wp_nonce_field('mwa_refresh');submit_button('Wordfence-Feed aktualisieren','primary','submit',false,['disabled'=>time()<$next]);echo '</form>';
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
