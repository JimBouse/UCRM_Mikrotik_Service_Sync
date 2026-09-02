<?php

namespace Ubnt\Plugins\MikrotikSync\Service;

class MikrotikApiManager
{
    private $logger;
    private $mikrotikInstances = [];
    private $hosts;
    private $port;
    private $username;
    private $password;
    private $useSSL;
    private $ignoreCertErrors;

    public function __construct(
        Logger $logger,
        ConfigManager $configManager
    ) {
        $this->logger = $logger;
        $this->hosts = $configManager->getMikrotikHosts();
        $this->port = $configManager->getMikrotikPort();
        $this->username = $configManager->getMikrotikUsername();
        $this->password = $configManager->getMikrotikPassword();
        $this->useSSL = $configManager->isSSLEnabled();
        $this->ignoreCertErrors = $configManager->isIgnoreCertificateErrors();

        // Initialize API instances for each host
        foreach ($this->hosts as $host) {
            $this->mikrotikInstances[$host] = new MikrotikApiService(
                $host,
                $this->port,
                $this->username,
                $this->password,
                $logger,
                $this->useSSL,
                $this->ignoreCertErrors
            );
        }
    }

    /**
     * Query DHCP lease by searching a specified column with failover
     * Returns both IP and the host where it was found
     *
     * @param string $searchValue The value to search for (serial, MAC, etc.)
     * @param string $searchColumn The column to search in (activeMac, activeIp, circuitId, remoteId)
     * @param string $dhcpServerName
     * @return array|null Array with 'ip' and 'host' keys, or null if not found
     */
    public function queryDhcpLeaseByColumnWithHost(string $searchValue, string $searchColumn, string $dhcpServerName): ?array
    {
        foreach ($this->hosts as $host) {
            $this->logger->debug("Querying {$host} for {$searchColumn}={$searchValue}");

            try {
                $result = $this->mikrotikInstances[$host]->queryDhcpLeaseByColumn(
                    $searchValue,
                    $searchColumn,
                    $dhcpServerName
                );

                if ($result !== null) {
                    $this->logger->info("Found IP {$result} at {$host} for {$searchColumn}={$searchValue}");
                    return ['ip' => $result, 'host' => $host];
                }
            } catch (\Exception $e) {
                $this->logger->warning("Exception querying {$host}: {$e->getMessage()}\n{$e->getTraceAsString()}");
                // Continue to next host on failover
            }
        }

        $this->logger->warning("No DHCP lease found for {$searchColumn}={$searchValue} on any Mikrotik");
        return null;
    }

    /**
     * Query DHCP lease by searching a specified column with failover
     *
     * @param string $searchValue The value to search for (serial, MAC, etc.)
     * @param string $searchColumn The column to search in (activeMac, activeIp, circuitId, remoteId)
     * @param string $dhcpServerName
     * @return string|null
     */
    public function queryDhcpLeaseByColumn(string $searchValue, string $searchColumn, string $dhcpServerName): ?string
    {
        $result = $this->queryDhcpLeaseByColumnWithHost($searchValue, $searchColumn, $dhcpServerName);
        return $result['ip'] ?? null;
    }

    /**
     * Query DHCP lease by serial number with failover (backward compatibility)
     *
     * @param string $serialNumber
     * @param string $dhcpServerName
     * @return string|null
     */
    public function queryDhcpLeaseByOption82(string $serialNumber, string $dhcpServerName): ?string
    {
        return $this->queryDhcpLeaseByColumn($serialNumber, 'circuitId', $dhcpServerName);
    }

    /**
     * Add to address list on all Mikrotik instances
     *
     * @param string $listName
     * @param string $address
     * @param string $comment
     * @return bool
     */
    public function addToAddressList(string $listName, string $address, string $comment = ''): bool
    {
        $successCount = 0;
        $failureCount = 0;

        foreach ($this->hosts as $host) {
            $this->logger->debug("Adding {$address} to {$listName} on {$host}");

            if ($this->mikrotikInstances[$host]->addToAddressList($listName, $address, $comment)) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        // Consider success if added to at least one Mikrotik
        $success = $successCount > 0;

        if ($success) {
            $this->logger->info(
                "Added {$address} to {$listName}: {$successCount} success(es), {$failureCount} failure(s)"
            );
        } else {
            $this->logger->error(
                "Failed to add {$address} to {$listName} on all Mikrotik instances"
            );
        }

        return $success;
    }

    /**
     * Remove from address list on all Mikrotik instances
     *
     * @param string $listName
     * @param string $address
     * @return bool
     */
    public function removeFromAddressList(string $listName, string $address): bool
    {
        $successCount = 0;
        $failureCount = 0;

        foreach ($this->hosts as $host) {
            $this->logger->debug("Removing {$address} from {$listName} on {$host}");

            if ($this->mikrotikInstances[$host]->removeFromAddressList($listName, $address)) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        // Consider partial success acceptable (some Mikrotik instances may not have the entry)
        $success = $successCount > 0 || $failureCount > 0;

        if ($successCount > 0) {
            $this->logger->info(
                "Removed {$address} from {$listName}: {$successCount} success(es), {$failureCount} failure(s)"
            );
        }

        return $success;
    }

    /**
     * Test connection to Mikrotik instances
     *
     * @return bool
     */
    public function testConnections(): bool
    {
        $successCount = 0;

        foreach ($this->hosts as $host) {
            $this->logger->info("Testing connection to {$host}:{$this->port}");

            if ($this->mikrotikInstances[$host]->testConnection()) {
                $this->logger->info("Connection test successful for {$host}");
                $successCount++;
            } else {
                $this->logger->error("Connection test failed for {$host}");
            }
        }

        $totalCount = count($this->hosts);
        $success = $successCount > 0;

        if ($success) {
            $this->logger->notice("Connected to {$successCount}/{$totalCount} Mikrotik instances");
        } else {
            $this->logger->critical("Failed to connect to all Mikrotik instances");
        }

        return $success;
    }

    /**
     * Get list of configured hosts
     *
     * @return array
     */
    public function getHosts(): array
    {
        return $this->hosts;
    }

    /**
     * Get MikrotikApiService instance for a specific host
     *
     * @param string $host
     * @return MikrotikApiService|null
     */
    public function getMikrotikApiService(string $host): ?MikrotikApiService
    {
        return $this->mikrotikInstances[$host] ?? null;
    }

    /**
     * Get MikrotikApiService instance for the primary (first) host
     *
     * @return MikrotikApiService|null
     */
    public function getPrimaryMikrotikApiService(): ?MikrotikApiService
    {
        if (!empty($this->hosts)) {
            return $this->mikrotikInstances[$this->hosts[0]] ?? null;
        }
        return null;
    }

    /**
     * Get all address lists containing a specific IP (with failover)
     *
     * @param string $address IP address
     * @return array List names containing this IP
     */
    public function getAddressListsForIp(string $address): array
    {
        foreach ($this->hosts as $host) {
            try {
                $lists = $this->mikrotikInstances[$host]->getAddressListsForIp($address);
                if (!empty($lists)) {
                    $this->logger->debug("Found {$address} in lists on {$host}: " . implode(', ', $lists));
                    return $lists;
                }
            } catch (\Exception $e) {
                $this->logger->debug("Exception querying {$host} for address lists: {$e->getMessage()}");
                // Continue to next host on failover
            }
        }

        $this->logger->debug("Address {$address} not found in any lists on any Mikrotik");
        return [];
    }

    /**
     * Execute Mikrotik script on primary host
     *
     * @param string $script
     * @return bool
     */
    public function executeScriptOnPrimary(string $script): bool
    {
        $primaryService = $this->getPrimaryMikrotikApiService();
        if ($primaryService === null) {
            $this->logger->error("Could not get primary Mikrotik service for script execution");
            return false;
        }

        return $primaryService->executeScript($script);
    }
}
