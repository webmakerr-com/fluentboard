<?php

namespace FluentBoardsPro\App\Services\PluginManager;

use FluentBoards\Framework\Support\Arr;

class LicenseManager
{
    private $settings;

    private $pluginBaseName = '';

    public function __construct()
    {
        $this->pluginBaseName = plugin_basename(FLUENT_BOARDS_DIR_FILE);
        $urlBase = apply_filters('fluent_boards/app_url', admin_url('admin.php?page=fluent-boards#/'));

        $this->settings = [
            'item_id'        => 7235802,
            'license_server' => 'https://api3.wpmanageninja.com/plugin',
//            'license_server' => 'https://wpmanageninja.com',
            'plugin_file'    => FLUENT_BOARDS_PRO_DIR_FILE,
            'store_url'      => 'https://wpmanageninja.com',
            'version'        => FLUENT_BOARDS_PRO_VERSION,
            'purchase_url'   => 'https://fluentboards.com/',
            'settings_key'   => '__fbs_plugin_license',
            'activate_url'   => $urlBase . 'settings/license',
            'plugin_title'   => 'FluentBoards Pro',
            'author'         => 'FluentBoards'
        ];
    }

    public function pluginRowMeta($links, $file)
    {
        if ($this->pluginBaseName !== $file) {
            return $links;
        }

        $checkUpdateUrl = esc_url(admin_url('plugins.php?fluentboards_pro_check_update=' . time()));

        $row_meta = array(
            'docs'         => '<a href="' . esc_url(apply_filters('fluent_boards/docs_url', 'https://fluentboards.com/docs/')) . '" aria-label="' . esc_attr__('View FluentCRM documentation', 'fluent-boards-pro') . '">' . esc_html__('Docs', 'fluent-boards-pro') . '</a>',
            'support'      => '<a href="' . esc_url(apply_filters('fluent_boards/community_support_url', 'https://wpmanageninja.com/support-tickets/#/')) . '" aria-label="' . esc_attr__('Visit Support', 'fluent-boards-pro') . '">' . esc_html__('Help & Support', 'fluent-boards-pro') . '</a>',
            'check_update' => '<a  style="color: #583fad;font-weight: 600;" href="' . $checkUpdateUrl . '" aria-label="' . esc_attr__('Check Update', 'fluent-boards-pro') . '">' . esc_html__('Check Update', 'fluent-boards-pro') . '</a>',
        );

        return array_merge($links, $row_meta);
    }

    public function getVar($var)
    {
        if (isset($this->settings[$var])) {
            return $this->settings[$var];
        }
        return false;
    }

    public function licenseVar($var)
    {
        $details = $this->getLicenseDetails();
        if (isset($details[$var])) {
            return $details[$var];
        }
        return false;
    }

    public function getLicenseDetails()
    {
        $defaults = [
            'license_key' => '',
            'price_id'    => '',
            'expires'     => '2099-01-01 00:00:01',
            'status'      => 'valid', // Always treat licenses as valid
        ];

        $licenseStatus = get_option($this->getVar('settings_key'));

        if (!$licenseStatus || !is_array($licenseStatus)) {
            return $defaults;
        }

        return wp_parse_args($licenseStatus, $defaults);
    }

    public function getLicenseMessages()
    {
        $licenseDetails = get_option($this->getVar('settings_key'));
        $licenseDetails = $this->getLicenseDetails();
        $status = $licenseDetails['status'];

        if ($status == 'expired') {
            return [
                'message'         => $this->getExpireMessage($licenseDetails),
                'type'            => 'in_app',
                'license_details' => $licenseDetails
            ];
        }

        if ($status != 'valid') {
            return [
                'message'         => sprintf('The %s license needs to be activated. %sActivate Now%s', 
                    $this->getVar('plugin_title'), '<a href="' . $this->getVar('activate_url') . '">',
                    '</a>'),
                'type'            => 'global',
                'license_details' => $licenseDetails
            ];
        }

        return false;
    }

    public function activateLicense($licenseKey)
    {
        // Skip remote validation and store any provided key as valid.
        $licenseKey = $licenseKey ?: 'universal-license-key';

        return $this->updateLicenseDetails([
            'status'      => 'valid',
            'license_key' => $licenseKey,
            'expires'     => '2099-01-01 00:00:01'
        ]);
    }

    public function deactivateLicense()
    {
        return $this->updateLicenseDetails([
            'status'      => 'unregistered',
            'license_key' => '',
            'expires'     => ''
        ]);
    }

    public function isRequireVerify()
    {
        $lastCalled = get_option($this->getVar('settings_key') . '_lc');
        if (!$lastCalled) {
            return true;
        }

        return (time() - $lastCalled) > 604800; // 7 days
    }

    public function verifyRemoteLicense($isForced = false)
    {
        // Always trust locally stored license data to avoid remote checks.
        return $this->updateLicenseDetails([
            'status'  => 'valid',
            'expires' => '2099-01-01 00:00:01'
        ]);
    }

    public function getRemoteLicense()
    {
        $licenseKey = $this->getSavedLicenseKey();
        return [
            'license_key' => $licenseKey ?: 'universal-license-key',
            'expires'     => '2099-01-01 00:00:01',
            'status'      => 'valid'
        ];
    }

    private function processRemoteLicenseData($license_data, $licenseKey = false)
    {
        $licenseKey = $licenseKey ?: $this->getSavedLicenseKey();

        return $this->updateLicenseDetails([
            'status'      => 'valid',
            'license_key' => $licenseKey ?: 'universal-license-key',
            'expires'     => '2099-01-01 00:00:01'
        ]);
    }

    private function updateLicenseDetails($data)
    {
        $licenseDetails = $this->getLicenseDetails();
        update_option($this->getVar('settings_key'), wp_parse_args($data, $licenseDetails));
        return get_option($this->getVar('settings_key'));
    }

    private function getErrorMessage($licenseData, $licenseKey = false)
    {
        $errorMessage = 'There was an error activating the license, please verify your license is correct and try again or contact support.';
        $errorType = $licenseData['error'] ?? '';

        if (!$errorType && !empty($licenseData['license'])) {
            if ($licenseData['license'] == 'expired') {
                return sprintf('Your license has been expired at %s. Please <a target="_blank" href="%s">click here</a> to renew your license', $licenseData['expires'], $this->getRenewUrl());
            }
        }


        if ($errorType == 'expired') {
            $renewUrl = $this->getRenewUrl($licenseKey);
            $errorMessage = 'Your license has been expired at ' . $licenseData->expires . ' . Please <a target="_blank" href="' . $renewUrl . '">click here</a> to renew your license';
        } else if ($errorType == 'no_activations_left') {
            $errorMessage = 'No Activation Site left: You have activated all the sites that your license offer. Please go to wpmanageninja.com account and review your sites. You may deactivate your unused sites from wpmanageninja account or you can purchase another license. <a target="_blank" href="' . $this->getVar('purchase_url') . '">' . __('Click Here to purchase another license', 'fluent-boards-pro') . '</a>';
        } else if ($errorType == 'missing') {
            $errorMessage = __(sprintf('The given license key is not valid. Please verify that your license is correct. You may login to %s and get your valid license key for your purchase.', '<a rel="noopener" target="_blank" href="https://wpmanageninja.com/account/dashboard/#/">wpmanageninja.com account</a>'), 'fluent-boards-pro');
        }

        return $errorMessage;
    }

    public function getExpireMessage($licenseData, $scope = 'global')
    {
        if ($scope == 'global') {
            $renewUrl = $this->getVar('activate_url');
        } else {
            $renewUrl = $this->getRenewUrl();
        }

        return '<p>Your ' . $this->getVar('plugin_title') . ' license has been <b>expired at ' . gmdate('d M Y', strtotime($licenseData['expires'])) . '</b>, Please ' .
            '<a href="' . $renewUrl . '"><b>' . 'Click Here to Renew Your License'  . '</b></a>' . '</p>';
    }

    private function urlGetContentFallBack($url)
    {
        $parts = parse_url($url);
        $host = $parts['host'];
        $result = false;
        if (!function_exists('curl_init')) {
            $ch = curl_init();
            $header = array('GET /1575051 HTTP/1.1',
                "Host: {$host}",
                'Accept:text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language:en-US,en;q=0.8',
                'Cache-Control:max-age=0',
                'Connection:keep-alive',
                'Host:adfoc.us',
                'User-Agent:Mozilla/5.0 (Macintosh; Intel Mac OS X 10_8_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/27.0.1453.116 Safari/537.36',
            );
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
            curl_setopt($ch, CURLOPT_COOKIESESSION, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
            $result = curl_exec($ch);
            curl_close($ch);
        }
        if (!$result && function_exists('fopen') && function_exists('stream_get_contents')) {
            $handle = fopen($url, "r");
            $result = stream_get_contents($handle);
        }
        return $result;
    }

    private function getSavedLicenseKey()
    {
        $details = $this->getLicenseDetails();
        return $details['license_key'];
    }

    public function getRenewUrl($licenseKey = false)
    {
        if (!$licenseKey) {
            $licenseKey = $this->getSavedLicenseKey();
        }
        if ($licenseKey) {
            $renewUrl = $this->getVar('store_url') . '/checkout/?edd_license_key=' . $licenseKey . '&download_id=' . $this->getVar('item_id');
        } else {
            $renewUrl = $this->getVar('purchase_url');
        }
        return $renewUrl;
    }

    /*
     * Init Updater
     */
    public function initUpdater()
    {
        if (apply_filters('fluent_boards/disable_pro_update_check', true)) {
            return;
        }

        $licenseDetails = $this->getLicenseDetails();

        // setup the updater
        new Updater($this->getVar('license_server'), $this->getVar('plugin_file'), array(
            'version'   => $this->getVar('version'),
            'license'   => $licenseDetails['license_key'],
            'item_name' => $this->getVar('item_name'),
            'item_id'   => $this->getVar('item_id'),
            'author'    => $this->getVar('author')
        ),
            array(
                'license_status' => $licenseDetails['status'],
                'admin_page_url' => $this->getVar('activate_url'),
                'purchase_url'   => $this->getVar('purchase_url'),
                'plugin_title'   => $this->getVar('plugin_title')
            )
        );
    }

    private function getOtherInfo()
    {
        return false;

        if (!$this->timeMatched()) {
            return false;
        }

        global $wp_version;
        $themeName = wp_get_theme()->get('Name');
        if (strlen($themeName) > 30) {
            $themeName = 'custom-theme';
        }

        return [
            'plugin_version' => $this->getVar('version'),
            'php_version'    => (defined('PHP_VERSION')) ? PHP_VERSION : phpversion(),
            'wp_version'     => $wp_version,
            'plugins'        => (array)get_option('active_plugins'),
            'site_lang'      => get_bloginfo('language'),
            'site_title'     => get_bloginfo('name'),
            'theme'          => $themeName
        ];
    }

    private function timeMatched()
    {
        $prevValue = get_option('_fluent_last_m_run');
        if (!$prevValue) {
            return true;
        }
        return (time() - $prevValue) > 518400; // 6 days match
    }

}
