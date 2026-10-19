<?php
/**
 * GitHub Release update provider for publicly accessible repositories.
 *
 * No API tokens are accepted or stored. Private repositories return no updates.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class MWA_GitHub_Updater {
    const RELEASE_API = 'https://api.github.com/repos/joachimdaub/mainwp-wordfence-audit/releases/latest';
    const CACHE_KEY = 'mwa_public_github_release_v1';

    public static function init() {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check']);
    }

    private static function release() {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) {
            return $cached === 'unavailable' ? null : $cached;
        }

        $res = wp_remote_get(self::RELEASE_API, [
            'timeout' => 10,
            'redirection' => 0,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'MainWP-Wordfence-Audit-Update-Checker',
            ],
        ]);
        if (is_wp_error($res) || (int)wp_remote_retrieve_response_code($res) !== 200) {
            set_transient(self::CACHE_KEY, 'unavailable', HOUR_IN_SECONDS);
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($data) || !empty($data['draft']) || !empty($data['prerelease'])) {
            set_transient(self::CACHE_KEY, 'unavailable', HOUR_IN_SECONDS);
            return null;
        }
        $tag = (string)($data['tag_name'] ?? '');
        if (!preg_match('/^v?(\d+\.\d+\.\d+)$/D', $tag, $m)) {
            set_transient(self::CACHE_KEY, 'unavailable', HOUR_IN_SECONDS);
            return null;
        }

        $package = '';
        foreach ($data['assets'] ?? [] as $asset) {
            if (!is_array($asset) || ($asset['name'] ?? '') !== 'mainwp-wordfence-audit.zip') {
                continue;
            }
            $url = $asset['browser_download_url'] ?? '';
            if (is_string($url) && strpos($url, 'https://github.com/joachimdaub/mainwp-wordfence-audit/releases/download/') === 0) {
                $package = $url;
                break;
            }
        }
        if ($package === '') {
            set_transient(self::CACHE_KEY, 'unavailable', HOUR_IN_SECONDS);
            return null;
        }

        $release = [
            'version' => $m[1],
            'package' => $package,
            'url' => 'https://github.com/joachimdaub/mainwp-wordfence-audit/releases',
        ];
        set_transient(self::CACHE_KEY, $release, 12 * HOUR_IN_SECONDS);
        return $release;
    }

    public static function check($transient) {
        if (!is_object($transient) || empty($transient->checked) || !is_array($transient->checked)) {
            return $transient;
        }
        $file = plugin_basename(dirname(__DIR__).'/mainwp-wordfence-audit.php');
        if (!isset($transient->checked[$file])) {
            return $transient;
        }
        $release = self::release();
        if (!$release || !version_compare($release['version'], (string)$transient->checked[$file], '>')) {
            return $transient;
        }
        $transient->response[$file] = (object)[
            'id' => 'https://github.com/joachimdaub/mainwp-wordfence-audit',
            'slug' => 'mainwp-wordfence-audit',
            'plugin' => $file,
            'new_version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
        ];
        return $transient;
    }
}
MWA_GitHub_Updater::init();
