<?php
/**
 * Plugin Name: MainWP Wordfence Audit
 * Description: Read-only vulnerability inventory for MainWP sites using Wordfence Intelligence v3.
 *  Author: Joachim Daub
 *  Author URI: https://github.com/joachimdaub/mainwp-wordfence-audit
 * Version: 0.5.3
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

// GitHub updates are deliberately disabled until the first public release.

final class MWA_Audit {
    const OPT_KEY = 'mwa_wordfence_key';
    const CACHE = 'mwa_wordfence_feed_v1';
    const INDEX_FILE = 'mainwp-wordfence-audit-index.json';
    const LAST = 'mwa_wordfence_feed_updated';
    const ENDPOINT = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner';
    const PRODUCTION_ENDPOINT = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production';
    const SEVERITY_UPDATED = 'mwa_severity_updated';
    const ERROR_OPTION = 'mwa_last_feed_error';
    const NEXT_ATTEMPT = 'mwa_next_feed_attempt';
    const IGNORED = 'mwa_ignored_findings_v1';
    const KNOWN = 'mwa_known_finding_keys_v1';
    const INVENTORY_USER = 'mwa_inventory_user_id';
    const EMAIL_RESULT = 'mwa_last_email_result';
    const EMAIL_ENABLED = 'mwa_email_enabled';
    const EMAIL_RECIPIENT = 'mwa_email_recipient';
    const VISIBILITY_ENABLED = 'mwa_visibility_enabled';
    const MENU_LOCATION = 'mwa_menu_location';
    const CRON_NOTIFY = 'mwa_daily_finding_notification';
    private static $cron_context = false;
    const CRON_SCANNER = 'mwa_daily_scanner_refresh';
    const CRON_SEVERITY = 'mwa_daily_severity_refresh';
    const CRON_COMBINED_SCANNER = 'mwa_combined_scanner';
    const CRON_COMBINED_SEVERITY = 'mwa_combined_severity';
    const COMBINED_STATE = 'mwa_combined_refresh_state';
    const MIN_INTERVAL = 2700; // 45 minutes, above the default Wordfence limit.
    public static function init() {
        add_action('init', [__CLASS__, 'ensure_schedule']);
        add_action(self::CRON_SCANNER, [__CLASS__, 'cron_scanner']);
        add_action(self::CRON_SEVERITY, [__CLASS__, 'cron_severity']);
        add_action(self::CRON_COMBINED_SCANNER, [__CLASS__, 'combined_scanner']);
        add_action(self::CRON_COMBINED_SEVERITY, [__CLASS__, 'combined_severity']);
        add_action('admin_post_mwa_combined', [__CLASS__, 'start_combined']);
        add_action(self::CRON_NOTIFY, [__CLASS__, 'notify_new_findings']);
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('wp_dashboard_setup', [__CLASS__, 'register_widget']);
        add_action('admin_post_mwa_save_visibility', [__CLASS__, 'save_visibility']);
        add_action('admin_post_mwa_save_key', [__CLASS__, 'save_key']);
        add_action('admin_post_mwa_save_notifications', [__CLASS__, 'save_notifications']);
        add_action('admin_post_mwa_test_email', [__CLASS__, 'test_email']);
        add_action('admin_post_mwa_refresh', [__CLASS__, 'refresh']);
        add_action('admin_post_mwa_enrich', [__CLASS__, 'enrich_severity']);
        add_action('admin_post_mwa_ignore', [__CLASS__, 'ignore_action']);
        add_action('admin_post_mwa_inventory', [__CLASS__, 'inventory_refresh']);
    }
    public static function ensure_schedule() {
        if (!wp_next_scheduled(self::CRON_SCANNER)) wp_schedule_event(time()+300, 'daily', self::CRON_SCANNER);
        if (!wp_next_scheduled(self::CRON_SEVERITY)) wp_schedule_event(time()+3900, 'daily', self::CRON_SEVERITY);
        if (!wp_next_scheduled(self::CRON_NOTIFY)) wp_schedule_event(time()+7200, 'daily', self::CRON_NOTIFY);
    }
    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_SCANNER);
        wp_clear_scheduled_hook(self::CRON_SEVERITY);
        wp_clear_scheduled_hook(self::CRON_COMBINED_SCANNER);
        wp_clear_scheduled_hook(self::CRON_COMBINED_SEVERITY);
        wp_clear_scheduled_hook(self::CRON_NOTIFY);
    }
    public static function cron_scanner() { self::cron_run('scanner'); }
    public static function cron_severity() { self::cron_run('severity'); }
    private static function combined_next_time($stage, $now, $cooldown) {
        // Pure scheduling logic: test without WP-Cron or external HTTP requests.
        if ($stage==='scanner') return max($now+15,$cooldown+10);
        return max($now+3600,$cooldown+60);
    }
    public static function start_combined() {
        if (!self::allowed()) wp_die('Forbidden', '', ['response'=>403]);
        check_admin_referer('mwa_combined');
        if (!get_option(self::OPT_KEY,'')) self::redirect('no_key');
        $state=get_option(self::COMBINED_STATE,[]);
        if (is_array($state) && in_array($state['status']??'', ['queued','scanner_running','waiting_severity','severity_running'],true)) self::redirect('already_queued');
        $at=self::combined_next_time('scanner',time(),(int)get_option(self::NEXT_ATTEMPT,0));
        $scheduled=wp_schedule_single_event($at,self::CRON_COMBINED_SCANNER);
        if (!$scheduled) self::redirect('schedule_failed');
        update_option(self::COMBINED_STATE,['status'=>'queued','next'=>$at],false);
        self::redirect('combined_queued');
    }
    private static function combined_result_state($stage, $result) {
        $expected=$stage==='scanner'?'updated':'severity_updated';
        if (!is_array($result)) return $stage.'_failed';
        return ($result['status']??'')===$expected
            ? ($stage==='scanner'?'waiting_severity':'complete')
            : $stage.'_failed';
    }
    public static function combined_scanner() {
        update_option(self::COMBINED_STATE,['status'=>'scanner_running'],false);
        self::cron_run('scanner');
        $result=get_option('mwa_cron_scanner_result',[]);
        if (self::combined_result_state('scanner',$result)!=='waiting_severity') {
            update_option(self::COMBINED_STATE,['status'=>'scanner_failed','detail'=>(string)($result['status']??'unknown')],false);
            return;
        }
        // Preserve the API cooldown and allow for the free-tier Wordfence rate limit.
        $at=self::combined_next_time('severity',time(),(int)get_option(self::NEXT_ATTEMPT,0));
        if (!wp_schedule_single_event($at,self::CRON_COMBINED_SEVERITY)) {
            update_option(self::COMBINED_STATE,['status'=>'schedule_failed'],false);
            return;
        }
        update_option(self::COMBINED_STATE,['status'=>'waiting_severity','next'=>$at],false);
    }
    public static function combined_severity() {
        $next=(int)get_option(self::NEXT_ATTEMPT,0);
        if (time()<$next) {
            $at=$next+60;
            if (!wp_schedule_single_event($at,self::CRON_COMBINED_SEVERITY)) {
                update_option(self::COMBINED_STATE,['status'=>'schedule_failed','detail'=>'Could not reschedule severity after cooldown.'],false);
                return;
            }
            update_option(self::COMBINED_STATE,['status'=>'waiting_severity','next'=>$at],false);
            return;
        }
        update_option(self::COMBINED_STATE,['status'=>'severity_running'],false);
        self::cron_run('severity');
        $result=get_option('mwa_cron_severity_result',[]);
        $outcome=self::combined_result_state('severity',$result);
        update_option(self::COMBINED_STATE,[
            'status'=>$outcome,
            'detail'=>(string)($result['status']??'unknown'),
            'finished'=>time()
        ],false);
    }
    private static function cron_execute($type, $callback) {
        $status='unknown';
        $error=null;
        self::$cron_context=true;
        try {
            call_user_func($callback);
        } catch (RuntimeException $e) {
            $status=$e->getMessage();
        } catch (Throwable $e) {
            $status='unexpected_error';
            $error=['time'=>time(),'type'=>get_class($e),'file'=>basename($e->getFile()),'line'=>$e->getLine(),'detail'=>substr(sanitize_text_field($e->getMessage()),0,200)];
            update_option(self::ERROR_OPTION,'Cron '.$type.' failed: '.get_class($e).' in '.$error['file'].':'.$error['line'],false);
            update_option('mwa_cron_'.$type.'_error',$error,false);
        } finally {
            self::$cron_context=false;
            update_option('mwa_cron_'.$type.'_result',['time'=>time(),'status'=>$status],false);
        }
        return $status;
    }
    private static function cron_run($type) {
        if (!get_option(self::OPT_KEY,'')) {
            update_option('mwa_cron_'.$type.'_result',['time'=>time(),'status'=>'no_key'],false);
            return;
        }
        self::cron_execute($type,static function () use ($type) {
            if ($type==='scanner') self::refresh(true);
            else self::enrich_severity(true);
        });
    }
    private static function current_findings() {
        list($inventory,$error)=self::inventory();
        if ($error || !$inventory) return new WP_Error('inventory_unavailable');
        $index=self::cached_index();
        if (!$index || !(int)get_option(self::LAST,0)) return new WP_Error('feed_unavailable');
        $ignored=get_option(self::IGNORED,[]);
        if (!is_array($ignored)) $ignored=[];
        $found=[];
        foreach ($inventory as $item) {
            foreach ($index[$item['type'].'/'.strtolower($item['slug'])]??[] as $v) {
                if (self::range_status($item['version'],$v['software']['affected_versions']??null)!=='affected') continue;
                $id=self::finding_key($item,$v);
                if (isset($ignored[$id])) continue;
                $found[$id]=[
                    'site'=>$item['site'],'software'=>$item['name'],
                    'version'=>$item['version'],'title'=>(string)($v['row']['title']??$v['id']),
                    'severity'=>self::severity($v),
                ];
            }
        }
        return $found;
    }
    public static function notify_new_findings() {
        if (!get_option(self::EMAIL_ENABLED, true)) {
            update_option(self::EMAIL_RESULT,['time'=>time(),'status'=>'disabled'],false);
            return;
        }
        $found=self::current_findings();
        if (is_wp_error($found)) {
            update_option(self::EMAIL_RESULT,['time'=>time(),'status'=>$found->get_error_code()],false);
            return;
        }
        $known=get_option(self::KNOWN,null);
        if (!is_array($known)) {
            // First successful comparison establishes baseline; do not email existing findings.
            update_option(self::KNOWN,array_keys($found),false);
            update_option(self::EMAIL_RESULT,['time'=>time(),'status'=>'baseline_created','count'=>count($found)],false);
            return;
        }
        $new=array_diff_key($found,array_fill_keys($known,true));
        if (!$new) {
            update_option(self::KNOWN,array_keys($found),false);
            update_option(self::EMAIL_RESULT,['time'=>time(),'status'=>'no_new_findings','count'=>0],false);
            return;
        }
        $email=get_option(self::EMAIL_RECIPIENT,'');
        if (!$email) $email=get_option('admin_email','');
        if (!is_email($email)) {
            update_option(self::EMAIL_RESULT,['time'=>time(),'status'=>'invalid_email','count'=>count($new)],false);
            return;
        }
        $lines=['New, non-ignored WordPress vulnerabilities were detected:',''];
        foreach ($new as $row) {
            $lines[]=$row['site'].' | '.$row['software'].' '.$row['version'].' | '.$row['severity'];
            $lines[]=$row['title'];
            $lines[]='';
        }
        $lines[]='Review findings: '.self::audit_url();
        $sent=wp_mail($email,'[MainWP Security Audit] '.count($new).' new vulnerabilities',implode("\n",$lines));
        if ($sent) {
            update_option(self::KNOWN,array_keys($found),false);
            update_option(self::EMAIL_RESULT,['time'=>time(),'status'=>'sent','count'=>count($new)],false);
        } else {
            update_option(self::EMAIL_RESULT,['time'=>time(),'status'=>'send_failed','count'=>count($new)],false);
        }
    }
    private static function audit_url() {
        $file=get_option(self::MENU_LOCATION,'tools')==='top'?'admin.php':'tools.php';
        return admin_url($file.'?page=mwa-audit');
    }
    private static function audit_base_file() {
        return get_option(self::MENU_LOCATION,'tools')==='top'?'admin.php':'tools.php';
    }
    private static function visibility_enabled() {
        return (bool)get_option(self::VISIBILITY_ENABLED,true);
    }
    private static function summary() {
        // Cache briefly to avoid rebuilding large result sets on each admin page.
        $cached=get_transient('mwa_visibility_summary_v1');
        if (is_array($cached)) return $cached;
        $found=self::current_findings();
        if (is_wp_error($found)) return ['count'=>0,'sites'=>0,'ready'=>false];
        $sites=[];
        foreach ($found as $item) $sites[$item['site']]=true;
        $summary=['count'=>count($found),'sites'=>count($sites),'ready'=>true];
        set_transient('mwa_visibility_summary_v1',$summary,5*MINUTE_IN_SECONDS);
        return $summary;
    }
    public static function save_visibility() {
        if (!self::allowed()) wp_die('Forbidden','',['response'=>403]);
        check_admin_referer('mwa_save_visibility');
        update_option(self::VISIBILITY_ENABLED,isset($_POST['visibility_enabled']) && $_POST['visibility_enabled']==='1',false);
        $location=isset($_POST['menu_location']) && is_string($_POST['menu_location']) ? sanitize_key(wp_unslash($_POST['menu_location'])) : 'tools';
        update_option(self::MENU_LOCATION,in_array($location,['tools','top'],true)?$location:'tools',false);
        delete_transient('mwa_visibility_summary_v1');
        wp_safe_redirect(add_query_arg(['page'=>'mwa-audit','mwa_tab'=>'settings','mwa_status'=>'visibility_saved'],admin_url(self::audit_base_file())));
        exit;
    }
    public static function register_widget() {
        if (!self::allowed() || !self::visibility_enabled()) return;
        // Only on the dashboard hosting MainWP; never on connected sites.
        if (!class_exists('\\MainWP\\Dashboard\\MainWP_DB')) return;
        wp_add_dashboard_widget('mwa_security_summary','MainWP Security Audit',[__CLASS__,'render_widget']);
    }
    public static function render_widget() {
        if (!self::allowed()) return;
        $summary=self::summary();
        if (!$summary['ready']) {
            echo '<p>Vulnerability data is not available yet.</p>';
        } else {
            echo '<p><strong>'.esc_html((string)$summary['sites']).' affected websites</strong> · '.esc_html((string)$summary['count']).' active findings</p>';
            echo '<p class="description">Confirmed, non-ignored vulnerabilities only.</p>';
        }
        echo '<p><a class="button button-secondary" href="'.esc_url(self::audit_url()).'">View security audit</a></p>';
    }
    private static function allowed() { return current_user_can('manage_options'); }
    public static function menu() {
        $summary=self::visibility_enabled()?self::summary():['count'=>0,'ready'=>false];
        $title='MainWP Security Audit';
        if (!empty($summary['ready']) && $summary['count']>0) {
            $title.=' <span class="update-plugins count-'.(int)$summary['count'].'"><span class="plugin-count">'.(int)$summary['count'].'</span></span>';
        }
        if (get_option(self::MENU_LOCATION,'tools')==='top') {
            add_menu_page('MainWP Security Audit',$title,'manage_options','mwa-audit',[__CLASS__,'page'],'dashicons-shield-alt',58);
        } else {
            add_management_page('MainWP Security Audit',$title,'manage_options','mwa-audit',[__CLASS__,'page']);
        }
    }
    private static function redirect($status) {
        if (in_array($status,['updated','severity_updated','ignored','restored','inventory_refreshed'],true)) delete_transient('mwa_visibility_summary_v1');
        if (self::$cron_context) throw new RuntimeException($status);
        wp_safe_redirect(add_query_arg(['page'=>'mwa-audit','mwa_status'=>$status], admin_url(self::audit_base_file())));
        exit;
    }
    public static function test_email() {
        if (!self::allowed()) wp_die('Forbidden', '', ['response'=>403]);
        check_admin_referer('mwa_test_email');
        if (!get_option(self::EMAIL_ENABLED,true)) self::redirect('email_disabled');
        $email=(string)get_option(self::EMAIL_RECIPIENT,'');
        if (!$email) $email=(string)get_option('admin_email','');
        if (!is_email($email)) self::redirect('invalid_email');
        $sent=wp_mail($email,'[MainWP Security Audit] Test email',
            "This is a delivery test. No vulnerability data has been modified.");
        update_option('mwa_last_test_email', ['time'=>time(),'status'=>$sent?'accepted':'failed'],false);
        self::redirect($sent?'test_email_sent':'test_email_failed');
    }
    public static function save_notifications() {
        if (!self::allowed()) wp_die('Forbidden', '', ['response'=>403]);
        check_admin_referer('mwa_save_notifications');
        $enabled=isset($_POST['email_enabled']) && $_POST['email_enabled']==='1';
        $raw=isset($_POST['email_recipient']) && is_string($_POST['email_recipient'])
            ? trim(wp_unslash($_POST['email_recipient'])) : '';
        $email=sanitize_email($raw);
        if ($raw!=='' && (!is_email($email) || $email!==$raw)) self::redirect('invalid_email');
        update_option(self::EMAIL_RECIPIENT,$email,false);
        update_option(self::EMAIL_ENABLED,$enabled,false);
        wp_safe_redirect(add_query_arg(['page'=>'mwa-audit','mwa_tab'=>'settings','mwa_status'=>'notifications_saved'],admin_url(self::audit_base_file())));
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
    // Reloads current MainWP inventory from the database; does not sync child sites or contact Wordfence.
    public static function inventory_refresh() {
        if (!self::allowed()) wp_die('Forbidden', '', ['response'=>403]);
        check_admin_referer('mwa_inventory');
        self::redirect('inventory_refreshed');
    }
    private static function finding_key($item, $v) {
        return hash('sha256', $item['site'].'|'.$item['type'].'|'.$item['slug'].'|'.(string)$v['id']);
    }
    public static function ignore_action() {
        if (!self::allowed()) wp_die('Forbidden', '', ['response'=>403]);
        check_admin_referer('mwa_ignore');
        $key = isset($_POST['finding_key']) && is_string($_POST['finding_key']) ? wp_unslash($_POST['finding_key']) : '';
        $mode = isset($_POST['mode']) && is_string($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $key) || !in_array($mode, ['ignore','restore'], true)) self::redirect('invalid_action');
        $ignored = get_option(self::IGNORED, []);
        if (!is_array($ignored)) $ignored = [];
        if ($mode==='ignore') {
            // Only accept keys that exist in the currently visible inventory/feed.
            $valid = false;
            list($inventory,) = self::inventory();
            $index = self::cached_index();
            foreach ($inventory as $item) {
                foreach ($index[$item['type'].'/'.strtolower($item['slug'])]??[] as $v) {
                    if (hash_equals(self::finding_key($item,$v), $key)) { $valid=true; break 2; }
                }
            }
            if (!$valid) self::redirect('invalid_action');
            $ignored[$key] = ['timestamp'=>time(), 'site'=>$item['site'], 'type'=>$item['type'], 'slug'=>$item['slug'], 'name'=>$item['name'], 'version'=>$item['version'], 'title'=>(string)($v['row']['title']??$v['id'])];
        } else {
            unset($ignored[$key]);
        }
        update_option(self::IGNORED, $ignored, false);
        self::redirect($mode==='ignore'?'ignored':'restored');
    }
    private static function severity($v) {
        $row=$v['row']??[];
        $raw=$row['severity']??null;
        if (is_string($raw) && in_array(strtolower($raw),['critical','high','medium','low','informational'],true)) return ucfirst(strtolower($raw));
        // Wordfence may supply CVSS v3 as a number or nested object.
        $candidates=[$row['cvss']['score']??null,$row['cvss']['cvss3']['score']??null,$row['cvss3']['score']??null,$row['cvss_score']??null];
        foreach ($candidates as $score) {
            if (!is_numeric($score)) continue;
            $score=(float)$score;
            if ($score<0 || $score>10) continue;
            if ($score>=9) return 'Critical ('.number_format($score,1).')';
            if ($score>=7) return 'High ('.number_format($score,1).')';
            if ($score>=4) return 'Medium ('.number_format($score,1).')';
            return 'Low ('.number_format($score,1).')';
        }
        return 'Not rated';
    }
    private static function action_form($key,$mode,$label) {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">';
        echo '<input type="hidden" name="action" value="mwa_ignore">';
        echo '<input type="hidden" name="finding_key" value="'.esc_attr($key).'">';
        echo '<input type="hidden" name="mode" value="'.esc_attr($mode).'">';
        wp_nonce_field('mwa_ignore');
        submit_button($label,'small','submit',false);
        echo '</form>';
    }
    private static function feed_error($message) {
        update_option(self::ERROR_OPTION, sanitize_text_field($message), false);
        self::redirect('api_error');
    }
    public static function refresh($scheduled=false) {
        if (!$scheduled) {
            if (!self::allowed()) wp_die('Forbidden', '', ['response'=>403]);
            check_admin_referer('mwa_refresh');
        }
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
        // Preserve existing CVSS details for the same vulnerability ID after
        // scanner refreshes. The scanner feed intentionally lacks CVSS.
        $previous=self::cached_index();
        $previous_ratings=[];
        foreach ($previous as $entries) {
            if (!is_array($entries)) continue;
            foreach ($entries as $entry) {
                if (!is_array($entry) || !isset($entry['id']) || !is_array($entry['row']??null)) continue;
                $row=$entry['row'];
                if (is_array($row['cvss']??null) && isset($row['cvss']['score']) && is_numeric($row['cvss']['score'])) {
                    $previous_ratings[(string)$entry['id']]=$row['cvss'];
                }
            }
        }
        foreach ($index['matches'] as &$entries) {
            foreach ($entries as &$entry) {
                $id=(string)($entry['id']??'');
                if (isset($previous_ratings[$id])) $entry['row']['cvss']=$previous_ratings[$id];
            }
            unset($entry);
        }
        unset($entries);
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
    public static function enrich_severity($scheduled=false) {
        if (!$scheduled) {
            if (!self::allowed()) wp_die('Forbidden', '', ['response'=>403]);
            check_admin_referer('mwa_enrich');
        }
        $key = get_option(self::OPT_KEY, '');
        if (!$key) self::redirect('no_key');
        if (time() < (int)get_option(self::NEXT_ATTEMPT,0)) self::redirect('throttled');
        $old = self::cached_index();
        if (!$old) self::redirect('no_scanner_feed');
        // The shared cooldown applies to both Wordfence endpoints.
        update_option(self::NEXT_ATTEMPT,time()+self::MIN_INTERVAL,false);
        $dir=self::storage_dir();
        if (is_wp_error($dir)) self::feed_error('Cache directory unavailable: '.$dir->get_error_code());
        $tmp=wp_tempnam('mwa-wordfence-production.json');
        if (!$tmp) self::feed_error('Could not create a temporary file.');
        $res=wp_remote_get(self::PRODUCTION_ENDPOINT,[
            'timeout'=>180,'redirection'=>0,'stream'=>true,'filename'=>$tmp,
            'headers'=>['Authorization'=>'Bearer '.$key,'Accept'=>'application/json'],
        ]);
        if (is_wp_error($res)) {
            @unlink($tmp);
            self::feed_error('WordPress HTTP error: '.$res->get_error_code());
        }
        $code=(int)wp_remote_retrieve_response_code($res);
        if ($code===429) {
            $retry=wp_remote_retrieve_header($res,'retry-after');
            $seconds=is_numeric($retry)?max(0,(int)$retry):(is_string($retry)&&strtotime($retry)?max(0,strtotime($retry)-time()):0);
            update_option(self::NEXT_ATTEMPT,time()+max(3600,$seconds),false);
        }
        if ($code!==200) {
            @unlink($tmp);
            self::feed_error('Wordfence production feed HTTP '.$code);
        }
        $wanted=array_fill_keys(array_keys($old),true);
        $production=self::stream_index($tmp,$wanted);
        @unlink($tmp);
        if (is_wp_error($production)) self::feed_error('Production feed validation failed: '.$production->get_error_code());
        if (empty($production['seen'])) self::feed_error('Production feed contained no vulnerability records.');
        $details=[];
        foreach ($production['matches'] as $entries) {
            foreach ($entries as $entry) {
                $details[(string)$entry['id']]=$entry['row'];
            }
        }
        $enriched=0;
        foreach ($old as &$entries) {
            foreach ($entries as &$entry) {
                $record=$details[(string)$entry['id']]??null;
                if (!is_array($record)) continue;
                $cvss=$record['cvss']??null;
                if (!is_array($cvss) || !isset($cvss['score']) || !is_numeric($cvss['score'])) continue;
                $entry['row']['cvss']=$cvss;
                $enriched++;
            }
            unset($entry);
        }
        unset($entries);
        $payload=wp_json_encode($old);
        if (!is_string($payload)) self::feed_error('Could not encode enriched index.');
        $target=trailingslashit($dir).self::INDEX_FILE;
        $stage=$target.'.tmp-'.wp_generate_password(12,false,false);
        if (file_put_contents($stage,$payload,LOCK_EX)===false || !@rename($stage,$target)) {
            @unlink($stage);
            self::feed_error('Could not save enriched index.');
        }
        @chmod($target,0600);
        update_option(self::SEVERITY_UPDATED,time(),false);
        update_option('mwa_severity_enriched_count',$enriched,false);
        delete_option(self::ERROR_OPTION);
        self::redirect('severity_updated');
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
                        $matches[$type.'/'.strtolower($slug)][]=['id'=>$key,'row'=>['title'=>(string)($row['title']??$key),'severity'=>$row['severity']??null,'cvss'=>$row['cvss']??null,'cvss3'=>$row['cvss3']??null,'cvss_score'=>$row['cvss_score']??null],'software'=>$sw];
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
        $user_id=get_current_user_id();
        if (!$user_id) $user_id=(int)get_option(self::INVENTORY_USER,0);
        if (!$user_id) return [[], 'No MainWP inventory user configured for background jobs'];
        $sites=$db->get_websites_by_user_id($user_id);
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
    private static function severity_rank($v) {
        $severity=self::severity($v);
        if (strpos($severity,'Critical')===0) return 5;
        if (strpos($severity,'High')===0) return 4;
        if (strpos($severity,'Medium')===0) return 3;
        if (strpos($severity,'Low')===0) return 2;
        if (strpos($severity,'Informational')===0) return 1;
        return 0;
    }
    private static function sort_findings(&$rows, $order) {
        usort($rows, static function($a,$b) use ($order) {
            $a_rank=self::severity_rank($a[1]);
            $b_rank=self::severity_rank($b[1]);
            if ($a_rank!==$b_rank) return $order==='severity_asc' ? ($a_rank<=>$b_rank) : ($b_rank<=>$a_rank);
            $site=strcmp($a[0]['site'],$b[0]['site']);
            if ($site!==0) return $site;
            return strcmp((string)$a[1]['id'],(string)$b[1]['id']);
        });
    }
    private static function filter_findings($rows, $site, $severity) {
        return array_values(array_filter($rows, static function($pair) use ($site,$severity) {
            if ($site!=='' && $pair[0]['site']!==$site) return false;
            if ($severity!=='all') {
                $value=self::severity($pair[1]);
                if ($severity==='unknown') return $value==='Not rated';
                if (strtolower(strtok($value,' '))!==$severity) return false;
            }
            return true;
        }));
    }
    public static function page() {
        if (!self::allowed()) wp_die('Forbidden','',['response'=>403]);
        update_option(self::INVENTORY_USER, get_current_user_id(), false);
        $idx=self::cached_index();
        $when=(int)get_option(self::LAST,0);
        list($inventory,$error)=self::inventory();

        $hits=[];$unknown=[];$ignored_rows=[];
        $ignored=get_option(self::IGNORED,[]);
        if (!is_array($ignored)) $ignored=[];
        foreach ($inventory as $item) {
            foreach ($idx[$item['type'].'/'.strtolower($item['slug'])]??[] as $v) {
                $status=self::range_status($item['version'],$v['software']['affected_versions']??null);
                if ($status!=='affected' && $status!=='unknown') continue;
                $pair=[$item,$v];
                if (isset($ignored[self::finding_key($item,$v)])) $ignored_rows[]=$pair;
                elseif ($status==='affected') $hits[]=$pair;
                else $unknown[]=$pair;
            }
        }
        echo '<div class="wrap"><h1>MainWP Security Audit <small>v0.5.3</small></h1>';
        echo '<p class="description">Read-only vulnerability monitoring for MainWP-managed websites. No updates are performed on child sites.</p>';
        $next=(int)get_option(self::NEXT_ATTEMPT,0);
        $cooldown=time()<$next;
        $key_present=(bool)get_option(self::OPT_KEY,'');
        $last_error=(string)get_option(self::ERROR_OPTION,'');
        $tab=isset($_GET['mwa_tab']) && is_string($_GET['mwa_tab']) && sanitize_key(wp_unslash($_GET['mwa_tab']))==='settings' ? 'settings' : 'overview';
        $base=self::audit_url();
        echo '<nav class="nav-tab-wrapper" aria-label="Audit navigation" style="margin:20px 0 16px">';
        echo '<a class="nav-tab '.($tab==='overview'?'nav-tab-active':'').'" href="'.esc_url($base).'">Overview</a>';
        echo '<a class="nav-tab '.($tab==='settings'?'nav-tab-active':'').'" href="'.esc_url(add_query_arg('mwa_tab','settings',$base)).'">Settings</a></nav>';
        if ($tab==='settings') {
            echo '<div class="card" style="max-width:760px;padding:18px 24px">';
            echo '<h2>Wordfence Intelligence API</h2>';
            echo '<p><strong>API key:</strong> '.($key_present?'Configured (not validated)':'Not configured').'</p>';
            echo '<p class="description">Existing keys are never displayed. Leave the field empty to keep the current key.</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwa_save_key">';
            wp_nonce_field('mwa_save_key');
            echo '<label for="mwa-key">Replace API key</label><br><input id="mwa-key" class="regular-text" type="password" name="api_key" autocomplete="new-password" placeholder="New API key"> ';
            submit_button('Save API key','primary','submit',false);
            echo '</form>';
            echo '<p class="description">The standard API rate limit requires spacing requests. Feed refresh is available in Overview.</p></div>';
            $email_enabled=(bool)get_option(self::EMAIL_ENABLED,true);
            $email_recipient=(string)get_option(self::EMAIL_RECIPIENT,'');
            echo '<div class="card" style="max-width:760px;padding:18px 24px;margin-top:18px">';
            echo '<h2>Email notifications</h2>';
            echo '<p class="description">Only newly discovered, confirmed and non-ignored findings trigger an email. Existing findings do not generate repeat alerts.</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="mwa_save_notifications">';
            wp_nonce_field('mwa_save_notifications');
            echo '<p><label><input type="checkbox" name="email_enabled" value="1" '.checked($email_enabled,true,false).'> Enable email notifications</label></p>';
            echo '<p><label for="mwa-email-recipient"><strong>Recipient email address</strong></label><br>';
            echo '<input type="email" id="mwa-email-recipient" name="email_recipient" class="regular-text" value="'.esc_attr($email_recipient).'" placeholder="'.esc_attr((string)get_option('admin_email','')).'"></p>';
            echo '<p class="description">Leave blank to use the WordPress administrator email. Disabling notifications preserves the last known findings baseline.</p>';
            submit_button('Save notification settings','primary','submit',false);
            echo '</form>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:12px"><input type="hidden" name="action" value="mwa_test_email">';
            wp_nonce_field('mwa_test_email');
            submit_button('Send test email','secondary','submit',false);
            echo '</form></div>';
            echo '<div class="card" style="max-width:760px;padding:18px 24px;margin-top:18px">';
            echo '<h2>Dashboard visibility</h2><p class="description">Show a subtle finding count in the Tools menu and a summary widget on the WordPress dashboard. No pop-ups or Wordfence API requests.</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwa_save_visibility">';
            wp_nonce_field('mwa_save_visibility');
            echo '<p><label><input type="checkbox" name="visibility_enabled" value="1" '.checked(self::visibility_enabled(),true,false).'> Enable security indicators</label></p>';
            $menu_location=get_option(self::MENU_LOCATION,'tools');
            echo '<p><label for="mwa-menu-location"><strong>Menu location</strong></label><br><select id="mwa-menu-location" name="menu_location">';
            echo '<option value="tools" '.selected($menu_location,'tools',false).'>Tools submenu (default)</option>';
            echo '<option value="top" '.selected($menu_location,'top',false).'>Separate top-level menu</option></select></p>';
            echo '<p class="description">Only the menu placement changes. The audit page and notification settings remain the same.</p>';
            submit_button('Save visibility settings','secondary','submit',false);
            echo '</form></div>';
            echo '</div>';
            return;
        }
        echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin:18px 0">';
        $cards=[
            ['label'=>'Active vulnerabilities','value'=>(string)count($hits),'note'=>'Confirmed installed-version matches'],
            ['label'=>'Needs review','value'=>(string)count($unknown),'note'=>'Uncertain version matches'],
            ['label'=>'Inventory entries','value'=>(string)count($inventory),'note'=>'Last synced by MainWP'],
            ['label'=>'Ignored findings','value'=>(string)count($ignored),'note'=>'Hidden but not fixed'],
        ];
        foreach ($cards as $card) {
            echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:15px 19px;min-width:160px;flex:1 1 160px;max-width:260px">';
            echo '<div style="color:#50575e">'.esc_html($card['label']).'</div><div style="font-size:27px;font-weight:600;line-height:1.7">'.esc_html($card['value']).'</div><span class="description">'.esc_html($card['note']).'</span></div>';
        }
        echo '</div>';
        echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:16px 20px;margin:14px 0;max-width:1080px">';
        echo '<h2 style="margin:0 0 12px">Data sources</h2>';
        $mail_job=get_option(self::EMAIL_RESULT,[]);
        echo '<p><strong>New-finding email:</strong> '.(get_option(self::EMAIL_ENABLED,true)?'Enabled':'Disabled').' — '.esc_html((string)(get_option(self::EMAIL_RECIPIENT,'')?:get_option('admin_email',''))).'</p>';
        foreach (['scanner','severity'] as $job_type) {
            $cron_error=get_option('mwa_cron_'.$job_type.'_error',[]);
            if (is_array($cron_error) && !empty($cron_error['time'])) {
                echo '<p><strong>Last '.esc_html($job_type).' cron error:</strong> '.esc_html(($cron_error['type']??'Error').' at '.($cron_error['file']??'?').':'.($cron_error['line']??'?').' — '.($cron_error['detail']??'')).'</p>';
            }
        }
        echo '<p><strong>Last notification check:</strong> '.esc_html(is_array($mail_job)&&isset($mail_job['time'])?wp_date('Y-m-d H:i:s T',$mail_job['time']).' - '.($mail_job['status']??'unknown').' ('.(int)($mail_job['count']??0).' findings)':'Not run').'</p>';

        $scan_job=get_option('mwa_cron_scanner_result',[]);
        $severity_job=get_option('mwa_cron_severity_result',[]);
        echo '<p><strong>Automatic updates:</strong> Daily WP-Cron (scanner + severity, separated by at least one hour).</p>';
        echo '<p><strong>Next scanner job:</strong> '.esc_html(($t=wp_next_scheduled(self::CRON_SCANNER))?wp_date('Y-m-d H:i:s T',$t):'Not scheduled').'</p>';
        echo '<p><strong>Last scanner job:</strong> '.esc_html(is_array($scan_job)&&isset($scan_job['time'])?wp_date('Y-m-d H:i:s T',$scan_job['time']).' — '.($scan_job['status']??'unknown'):'Not run').'</p>';
        echo '<p><strong>Last severity job:</strong> '.esc_html(is_array($severity_job)&&isset($severity_job['time'])?wp_date('Y-m-d H:i:s T',$severity_job['time']).' — '.($severity_job['status']??'unknown'):'Not run').'</p>';
        echo '<p><strong>MainWP inventory:</strong> '.esc_html((string)count($inventory)).' installed items in the locally synced inventory.</p>';
        echo '<p><strong>Wordfence feed:</strong> '.($when?esc_html(wp_date('Y-m-d H:i:s T',$when)):'Not yet downloaded').'</p>';
        echo '<p><strong>API key:</strong> '.($key_present?'Configured (not validated)':'Missing').' · <a href="'.esc_url(add_query_arg('mwa_tab','settings',$base)).'">API settings</a></p>';
        echo '<p><strong>Next Wordfence request:</strong> '.($cooldown?esc_html(wp_date('Y-m-d H:i:s T',$next)).' ('.esc_html(human_time_diff(time(),$next)).' remaining)':'Available now').'</p>';
        $severity_when=(int)get_option(self::SEVERITY_UPDATED,0);
        echo '<p><strong>Severity data:</strong> '.($severity_when?esc_html(wp_date('Y-m-d H:i:s T',$severity_when)).' ('.esc_html((string)get_option('mwa_severity_enriched_count',0)).' records enriched)':'Not loaded (scanner feed does not contain CVSS)').'</p>';
        echo '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:14px">';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:0"><input type="hidden" name="action" value="mwa_inventory">';
        wp_nonce_field('mwa_inventory');
        submit_button('Recheck MainWP inventory','primary','submit',false);
        echo '</form>';
        $combined=get_option(self::COMBINED_STATE,[]);
        $in_progress=is_array($combined) && in_array($combined['status']??'', ['queued','scanner_running','waiting_severity','severity_running'],true);
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:0"><input type="hidden" name="action" value="mwa_combined">';
        wp_nonce_field('mwa_combined');
        submit_button('Refresh vulnerabilities and severity','secondary','submit',false,(!$key_present || $in_progress)?['disabled'=>'disabled']:[]);
        echo '</form></div>';
        $combined=get_option(self::COMBINED_STATE,[]);
        if (is_array($combined) && !empty($combined['status'])) {
            echo '<p><strong>Combined refresh:</strong> '.esc_html((string)$combined['status']);
            if (!empty($combined['next'])) echo ' · Next step '.esc_html(wp_date('Y-m-d H:i:s T',(int)$combined['next']));
            echo '</p>';
        }
        echo '<p class="description" style="margin-bottom:0">MainWP inventory is local. One combined refresh schedules the Scanner Feed first and the Production severity enrichment at least one hour later. Requires WP-Cron; it does not bypass Wordfence API limits.</p></div>';
        if (isset($_GET['mwa_status'])) {
            $status=sanitize_key(wp_unslash($_GET['mwa_status']));
            $messages=[
                'saved'=>'API key saved. Validity is checked on the next API request.',
                'updated'=>'Wordfence feed refreshed.',
                'throttled'=>'Request blocked by the local rate-limit cooldown. No API request was made.',
                'no_key'=>'Enter and save an API key before refreshing.',
                'invalid_key'=>'The API key contains invalid characters.',
                'api_error'=>'The last feed refresh failed.',
                'severity_updated'=>'Severity data updated from the Wordfence production feed.',
                'no_scanner_feed'=>'Refresh the scanner feed before loading severity data.',
                'inventory_refreshed'=>'MainWP inventory reloaded from the local dashboard database. No remote sync was triggered.',
                'ignored'=>'Finding ignored.',
                'restored'=>'Finding restored.',
                'invalid_action'=>'Invalid finding action.',
                'combined_queued'=>'Combined refresh scheduled. Scanner first; severity after the API cooldown.',
                'already_queued'=>'A combined refresh is already running.',
                'schedule_failed'=>'Could not schedule the background refresh.',
                'visibility_saved'=>'Visibility settings saved.',
                'notifications_saved'=>'Notification settings saved.',
                'invalid_email'=>'Please enter a valid recipient email address.',
            ];
            echo '<div class="notice '.($status==='api_error'?'notice-error':'notice-info').'"><p>'.esc_html($messages[$status]??'Request completed.').'</p></div>';
            if ($status==='api_error' && $last_error!=='') echo '<div class="notice notice-error"><p>'.esc_html($last_error).'</p></div>';
            elseif ($status==='api_error') echo '<p>No error details were saved by the previous plugin version. Wait until the cooldown ends before retrying.</p>';
        }
        if ($error) echo '<div class="notice notice-warning"><p>'.esc_html($error).'</p></div>';
        if ($when && time()-$when>86400) echo '<p><strong>Warning:</strong> The vulnerability feed is more than 24 hours old.</p>';
        // GET filters affect presentation only; they never trigger API requests.
        $site_filter=isset($_GET['mwa_site']) && is_string($_GET['mwa_site']) ? sanitize_text_field(wp_unslash($_GET['mwa_site'])) : '';
        $severity_filter=isset($_GET['mwa_severity']) && is_string($_GET['mwa_severity']) ? sanitize_key(wp_unslash($_GET['mwa_severity'])) : 'all';
        if (!in_array($severity_filter,['all','critical','high','medium','low','informational','unknown'],true)) $severity_filter='all';
        $sort=isset($_GET['mwa_sort']) && is_string($_GET['mwa_sort']) ? sanitize_key(wp_unslash($_GET['mwa_sort'])) : 'severity_desc';
        if (!in_array($sort,['severity_desc','severity_asc'],true)) $sort='severity_desc';
        $sites=[];
        foreach (array_merge($hits,$unknown,$ignored_rows) as $pair) $sites[$pair[0]['site']]=true;
        $sites=array_keys($sites);
        sort($sites,SORT_STRING);
        echo '<form method="get" action="'.esc_url(admin_url(self::audit_base_file())).'" style="margin:16px 0">';
        echo '<input type="hidden" name="page" value="mwa-audit">';
        echo '<label for="mwa-site">Website: </label><select name="mwa_site" id="mwa-site"><option value="">All websites</option>';
        foreach ($sites as $site) echo '<option value="'.esc_attr($site).'" '.selected($site_filter,$site,false).'>'.esc_html($site).'</option>';
        echo '</select> <label for="mwa-severity">Severity: </label><select name="mwa_severity" id="mwa-severity">';
        foreach (['all'=>'All','critical'=>'Critical','high'=>'High','medium'=>'Medium','low'=>'Low','informational'=>'Informational','unknown'=>'Unknown'] as $value=>$label) echo '<option value="'.esc_attr($value).'" '.selected($severity_filter,$value,false).'>'.esc_html($label).'</option>';
        echo '</select> <label for="mwa-sort">Sort: </label><select name="mwa_sort" id="mwa-sort">';
        foreach (['severity_desc'=>'Highest severity first','severity_asc'=>'Lowest severity first'] as $value=>$label) echo '<option value="'.esc_attr($value).'" '.selected($sort,$value,false).'>'.esc_html($label).'</option>';
        echo '</select> ';
        submit_button('Apply filters','secondary','submit',false);
        echo ' <a href="'.esc_url(self::audit_url()).'">Reset</a></form>';
        $hits=self::filter_findings($hits,$site_filter,$severity_filter);
        $unknown=self::filter_findings($unknown,$site_filter,$severity_filter);
        $ignored_rows=self::filter_findings($ignored_rows,$site_filter,$severity_filter);
        self::sort_findings($hits,$sort);
        self::sort_findings($unknown,$sort);
        self::sort_findings($ignored_rows,$sort);
        echo '<p><strong>Visible findings:</strong> '.esc_html((string)(count($hits)+count($unknown))).' (filtered view)</p>';
        echo '<h2>Bestätigte Treffer nach Versionsbereich</h2>';
        self::table($hits);
        echo '<h2>Unklar / manuell prüfen</h2><p>Unbekannte Versionsformate oder unvollständige Bereiche werden nicht als sicher eingestuft.</p>';
        self::table($unknown);
        echo '<h2>Ignored findings ('.esc_html((string)count($ignored)).')</h2>';
        self::table($ignored_rows, true);
        $visible=[];
        foreach ($ignored_rows as $pair) $visible[self::finding_key($pair[0],$pair[1])]=true;
        $archived=array_diff_key($ignored,$visible);
        if ($archived) {
            echo '<h3>Ignored findings no longer in the current results</h3>';
            echo '<table class="widefat striped"><thead><tr><th>Website</th><th>Plugin or Theme</th><th>Last known version</th><th>Vulnerability</th><th>Action</th></tr></thead><tbody>';
            foreach ($archived as $key=>$meta) {
                if (!is_array($meta)) $meta=[];
                echo '<tr><td>'.esc_html($meta['site']??'Unknown').'</td><td>'.esc_html(($meta['type']??'').' / '.($meta['name']??($meta['slug']??'Unknown'))).'</td><td>'.esc_html($meta['version']??'').'</td><td>'.esc_html($meta['title']??'Not in current results').'</td><td>';
                self::action_form($key,'restore','Unignore');
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p><em>Kein Treffer bedeutet nur: In dieser Feed-Version wurde keine passende bekannte Schwachstelle erkannt. Das ist keine Sicherheitsgarantie.</em></p></div>';
    }
    private static function table($rows, $restore=false) {
        echo '<table class="widefat striped"><thead><tr><th>Website</th><th>Type / Plugin or Theme</th><th>Version</th><th>Severity</th><th>Vulnerability</th><th>Actions</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="6">No entries</td></tr>';
        foreach ($rows as $pair) {
            list($item,$v)=$pair;
            echo '<tr><td>'.esc_html($item['site']).'</td><td>'.esc_html($item['type'].' / '.$item['name'].' ('.$item['slug'].')').'</td><td>'.esc_html($item['version']).'</td><td>'.esc_html(self::severity($v)).'</td><td>'.esc_html($v['row']['title']??(string)$v['id']).'</td><td>';
            self::action_form(self::finding_key($item,$v),$restore?'restore':'ignore',$restore?'Unignore':'Ignore');
            // Navigate to MainWP for updates; never perform remote updates here.
            echo ' <a class="button button-small" href="'.esc_url(admin_url('admin.php?page=managesites')).'">Open MainWP</a>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
MWA_Audit::init();
register_deactivation_hook(__FILE__, ['MWA_Audit','deactivate']);
