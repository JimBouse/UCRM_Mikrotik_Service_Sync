<?php

namespace Ubnt\Plugins\MikrotikSync\Service;

use Ubnt\UcrmPluginSdk\Service\PluginConfigManager;
use Ubnt\UcrmPluginSdk\Service\UcrmOptionsManager;

class ConfigManager
{
    private $configManager;
    private $config;

    public function __construct()
    {
        $this->configManager = PluginConfigManager::create();
        $this->config = $this->configManager->loadConfig();
    }

    /**
     * Safe getter for config values - handles both array and object access
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function getConfigValue(string $key, $default = null)
    {
        // Handle if config is an array
        if (is_array($this->config)) {
            return $this->config[$key] ?? $default;
        }
        // Handle if config is an object
        return $this->config->$key ?? $default;
    }

    /**
     * Check if a config key exists - handles both array and object
     *
     * @param string $key
     * @return bool
     */
    private function hasConfigKey(string $key): bool
    {
        if (is_array($this->config)) {
            return isset($this->config[$key]);
        }
        return isset($this->config->$key);
    }

    public function getMikrotikHosts(): array
    {
        $hostsString = $this->getConfigValue('mikrotikHosts', '');
        if (empty($hostsString)) {
            return [];
        }

        // Split by comma and trim whitespace
        $hosts = array_map('trim', explode(',', $hostsString));
        // Filter out empty strings
        return array_filter($hosts, function ($host) {
            return !empty($host);
        });
    }

    public function getMikrotikHost(): string
    {
        // Return first host for backward compatibility
        $hosts = $this->getMikrotikHosts();
        return $hosts[0] ?? '';
    }

    public function getMikrotikUsername(): string
    {
        return $this->getConfigValue('mikrotikUsername', '');
    }

    public function getMikrotikPassword(): string
    {
        return $this->getConfigValue('mikrotikPassword', '');
    }

    public function getMikrotikPort(): int
    {
        return (int)($this->getConfigValue('mikrotikPort', 443));
    }

    public function isSSLEnabled(): bool
    {
        return (bool)($this->getConfigValue('enableSsl', true));
    }

    public function isIgnoreCertificateErrors(): bool
    {
        return (bool)($this->getConfigValue('ignoreCertificateErrors', false));
    }

    public function getDhcpServerName(): string
    {
        // Return empty string if not configured, which will search all DHCP servers
        return $this->getConfigValue('dhcpServerName', '');
    }

    public function getServiceActiveListName(): string
    {
        return $this->getConfigValue('serviceActiveListName', 'Service_Active');
    }

    public function getServiceSuspendListName(): string
    {
        return $this->getConfigValue('serviceSuspendListName', 'Service_Suspend');
    }

    public function getServiceEndListName(): string
    {
        return $this->getConfigValue('serviceEndListName', 'Service_End');
    }

    /**
     * Backward compatibility method - returns searchString
     *
     * @return string
     */
    public function getServiceCustomAttributeName(): string
    {
        // Backward compatibility: if old config key exists, use it, otherwise use new key
        if ($this->hasConfigKey('serviceCustomAttributeName')) {
            return $this->getConfigValue('serviceCustomAttributeName', '');
        }
        return $this->getSearchString();
    }

    public function getSearchString(): string
    {
        return $this->getConfigValue('searchStringKey', 'ontSerial');
    }

    public function getSearchColumn(): string
    {
        return $this->getConfigValue('searchColumn', 'circuitId');
    }

    public function isDebugEnabled(): bool
    {
        return (bool)($this->getConfigValue('enableDebugLogging', false));
    }

    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Get the plugin's public URL for accessing files in the public folder
     *
     * @return string The plugin's public directory URL
     */
    public function getPluginPublicUrl(): string
    {
        try {
            $optionsManager = new UcrmOptionsManager();
            $pluginPublicUrl = $optionsManager->loadOptions()->pluginPublicUrl;
            // Remove /public.php if present and append /public/
            $pluginPublicUrl = str_replace('/public.php', '', $pluginPublicUrl);
            return rtrim($pluginPublicUrl, '/') . '/public/';
        } catch (\Exception $e) {
            return '';
        }
    }

    public function validateConfiguration(): bool
    {
        $hosts = $this->getMikrotikHosts();
        $required = [
            'mikrotikHosts' => !empty($hosts),
            'mikrotikUsername' => !empty($this->getMikrotikUsername()),
            'mikrotikPassword' => !empty($this->getMikrotikPassword()),
            'mikrotikPort' => $this->getMikrotikPort() > 0,
        ];

        foreach ($required as $key => $value) {
            if (!$value) {
                return false;
            }
        }

        return true;
    }
}
