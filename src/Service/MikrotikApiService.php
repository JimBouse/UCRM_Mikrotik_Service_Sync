<?php

namespace Ubnt\Plugins\MikrotikSync\Service;

class MikrotikApiService
{
    private $host;
    private $port;
    private $username;
    private $password;
    private $logger;
    private $useSSL;
    private $ignoreCertErrors;

    public function __construct(
        string $host,
        int $port,
        string $username,
        string $password,
        Logger $logger,
        bool $useSSL = true,
        bool $ignoreCertErrors = false
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->logger = $logger;
        $this->useSSL = $useSSL;
        $this->ignoreCertErrors = $ignoreCertErrors;
    }

    /**
     * Query DHCP lease by searching a specified column
     *
     * @param string $searchValue The value to search for (serial, MAC, etc.)
     * @param string $searchColumn The column to search in (activeMac, activeIp, circuitId, remoteId)
     * @param string $dhcpServerName
     * @return string|null IP address if found, null otherwise
     */
    public function queryDhcpLeaseByColumn(string $searchValue, string $searchColumn, string $dhcpServerName = ''): ?string
    {
        $this->logger->debug("Starting DHCP REST API query for {$searchColumn}={$searchValue}, server={$dhcpServerName}");
        
        // Map search column to DHCP field names
        $searchField = $this->mapSearchColumnToField($searchColumn);
        $this->logger->debug("Mapped search column '{$searchColumn}' to REST field '{$searchField}'");

        try {
            // Build REST API URL
            $protocol = $this->useSSL ? 'https' : 'http';
            $url = "{$protocol}://{$this->host}:{$this->port}/rest/ip/dhcp-server/lease";
            
            // Add query filters
            $queryParams = [];
            if (!empty($dhcpServerName)) {
                $queryParams['server'] = $dhcpServerName;
            }
            // Only return bound leases
            $queryParams['status'] = 'bound';
            // Add search filter - search for the value in the mapped field
            $queryParams[$searchField] = $searchValue;
            
            if (!empty($queryParams)) {
                $url .= '?' . http_build_query($queryParams);
            }

            $this->logger->debug("Querying REST API: {$url}");
            $this->logger->debug("Query parameters: " . json_encode($queryParams));

            // Make REST API call
            $leases = $this->makeRequest('GET', $url);
            
            if (!is_array($leases)) {
                $this->logger->warning("Unexpected response format from Mikrotik REST API");
                return null;
            }

            $this->logger->debug("REST API response: " . json_encode($leases));

            if (empty($leases)) {
                $this->logger->warning("No DHCP lease found for {$searchColumn}={$searchValue} (searched for field: {$searchField})");
                return null;
            }

            // Return the active-address (IP) from the first matching lease
            if (isset($leases[0]['active-address'])) {
                $ip = $leases[0]['active-address'];
                $this->logger->info("Found DHCP lease for {$searchColumn}={$searchValue}: IP={$ip}");
                $this->logger->debug("Full lease entry: " . json_encode($leases[0]));
                return $ip;
            }

            $this->logger->warning("Lease found but no active-address field in response. Available fields: " . implode(', ', array_keys($leases[0] ?? [])));
            $this->logger->debug("First lease entry: " . json_encode($leases[0] ?? []));
            return null;

        } catch (\Exception $e) {
            $this->logger->error("DHCP query exception: {$e->getMessage()}");
            $this->logger->error("Stack trace:\n{$e->getTraceAsString()}");
            return null;
        }
    }

    /**
     * Legacy method for backward compatibility - query by option 82
     *
     * @param string $serialNumber
     * @param string $dhcpServerName
     * @return string|null
     */
    public function queryDhcpLeaseByOption82(string $serialNumber, string $dhcpServerName = ''): ?string
    {
        return $this->queryDhcpLeaseByColumn($serialNumber, 'circuitId', $dhcpServerName);
    }

    /**
     * Add IP address to a Mikrotik address list
     *
     * @param string $listName
     * @param string $address
     * @param string $comment Optional comment
     * @return bool True if successful
     */
    public function addToAddressList(string $listName, string $address, string $comment = ''): bool
    {
        try {
            $protocol = $this->useSSL ? 'https' : 'http';
            $url = "{$protocol}://{$this->host}:{$this->port}/rest/ip/firewall/address-list";
            
            $data = [
                'list' => $listName,
                'address' => $address,
            ];
            
            if (!empty($comment)) {
                $data['comment'] = $comment;
            }

            $this->logger->debug("Adding {$address} to address list '{$listName}' via REST API");

            $result = $this->makeRequest('PUT', $url, $data);
            
            if (isset($result['.id'])) {
                $this->logger->debug("Successfully added {$address} to {$listName}");
                return true;
            }

            $this->logger->error("Failed to add {$address} to {$listName}: No ID returned");
            return false;

        } catch (\Exception $e) {
            // Check if it's "already exists" error - treat as success (idempotent)
            if (strpos($e->getMessage(), 'already have such entry') !== false) {
                $this->logger->debug("Address {$address} already in {$listName} (no action needed)");
                return true;
            }
            $this->logger->error("Add to address list exception: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Get all address lists containing a specific IP
     *
     * @param string $address IP address to search for
     * @return array List of list names containing this IP (e.g., ['Service_Active', 'Service_Unknown'])
     */
    public function getAddressListsForIp(string $address): array
    {
        try {
            $protocol = $this->useSSL ? 'https' : 'http';
            $url = "{$protocol}://{$this->host}:{$this->port}/rest/ip/firewall/address-list";
            
            // Query for all entries with this address
            $queryParams = ['address' => $address];
            $searchUrl = $url . '?' . http_build_query($queryParams);
            
            $this->logger->debug("Querying for all lists containing {$address}");
            
            $results = $this->makeRequest('GET', $searchUrl);
            
            if (empty($results)) {
                $this->logger->debug("Address {$address} not found in any list");
                return [];
            }

            // Extract list names
            $lists = [];
            foreach ($results as $entry) {
                if (isset($entry['list'])) {
                    $lists[] = $entry['list'];
                }
            }

            $this->logger->debug("Address {$address} found in lists: " . implode(', ', $lists));
            return $lists;

        } catch (\Exception $e) {
            $this->logger->error("Get address lists exception: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Remove IP address from specified lists only (if it's actually in those lists)
     *
     * @param string $address IP address
     * @param array $listsToRemoveFrom List names to remove from (e.g., ['Service_Suspend', 'Service_End', 'Service_Unknown'])
     * @return int Number of lists successfully removed from
     */
    public function removeFromSpecificLists(string $address, array $listsToRemoveFrom): int
    {
        try {
            $protocol = $this->useSSL ? 'https' : 'http';
            $url = "{$protocol}://{$this->host}:{$this->port}/rest/ip/firewall/address-list";
            
            // Get current lists for this IP
            $currentLists = $this->getAddressListsForIp($address);
            
            if (empty($currentLists)) {
                $this->logger->debug("Address {$address} not in any lists, nothing to remove");
                return 0;
            }

            // Find intersection: which lists we want to remove from AND the IP is actually in
            $listsToActuallyRemoveFrom = array_intersect($listsToRemoveFrom, $currentLists);
            
            if (empty($listsToActuallyRemoveFrom)) {
                $this->logger->debug("Address {$address} not in any of the target lists (" . implode(', ', $listsToRemoveFrom) . "), no removal needed");
                return 0;
            }

            $removedCount = 0;

            // Now find and delete entries for each list we need to remove from
            foreach ($listsToActuallyRemoveFrom as $listName) {
                $queryParams = [
                    'list' => $listName,
                    'address' => $address,
                ];
                $searchUrl = $url . '?' . http_build_query($queryParams);
                
                $this->logger->debug("Searching for {$address} in list '{$listName}' for deletion");
                
                $results = $this->makeRequest('GET', $searchUrl);
                
                if (!empty($results) && isset($results[0]['.id'])) {
                    $deleteUrl = $url . '/' . $results[0]['.id'];
                    $this->logger->debug("Deleting {$address} from '{$listName}' (ID: {$results[0]['.id']})");
                    $this->makeRequest('DELETE', $deleteUrl);
                    $this->logger->info("Successfully removed {$address} from {$listName}");
                    $removedCount++;
                }
            }

            return $removedCount;

        } catch (\Exception $e) {
            $this->logger->error("Remove from specific lists exception: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Remove IP address from a Mikrotik address list
     *
     * @param string $listName
     * @param string $address
     * @return bool True if successful or not found
     */
    public function removeFromAddressList(string $listName, string $address): bool
    {
        try {
            $protocol = $this->useSSL ? 'https' : 'http';
            $url = "{$protocol}://{$this->host}:{$this->port}/rest/ip/firewall/address-list";
            
            // First, find the entry
            $queryParams = [
                'list' => $listName,
                'address' => $address,
            ];
            $searchUrl = $url . '?' . http_build_query($queryParams);
            
            $this->logger->debug("Searching for {$address} in address list '{$listName}'");
            
            $results = $this->makeRequest('GET', $searchUrl);
            
            if (empty($results)) {
                $this->logger->debug("Address {$address} not found in {$listName} (already removed)");
                return true;
            }

            // Delete the first matching entry
            if (isset($results[0]['.id'])) {
                $deleteUrl = $url . '/' . $results[0]['.id'];
                $this->logger->debug("Deleting address list entry {$results[0]['.id']}");
                $this->makeRequest('DELETE', $deleteUrl);
                $this->logger->info("Successfully removed {$address} from {$listName}");
                return true;
            }

            return true;

        } catch (\Exception $e) {
            $this->logger->error("Remove from address list exception: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Execute a Mikrotik script via REST API
     *
     * @param string $script Mikrotik script to execute
     * @return bool True if successful
     */
    public function executeScript(string $script): bool
    {
        try {
            $protocol = $this->useSSL ? 'https' : 'http';
            $url = "{$protocol}://{$this->host}:{$this->port}/rest/execute";
            
            $this->logger->info("Executing Mikrotik script on {$this->host}");
            $this->logger->debug("Script: {$script}");

            $data = ['script' => $script];
            $result = $this->makeRequest('POST', $url, $data);
            
            $this->logger->debug("Script execution result: " . json_encode($result));
            $this->logger->info("Script executed successfully on {$this->host}");
            return true;

        } catch (\Exception $e) {
            $this->logger->error("Script execution exception: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Clear all address lists matching a pattern using Mikrotik script (bulk operation)
     *
     * @param string $pattern Pattern for list names to clear (e.g., "Service_" will match Service_Active, Service_Suspend, etc.)
     * @return int Number of entries cleared (approximate - script reports total)
     */
    public function clearAddressListsByPattern(string $pattern): int
    {
        try {
            $this->logger->info("Clearing all address lists matching pattern '{$pattern}' using bulk script on {$this->host}");
            
            // Build Mikrotik script to remove all entries from lists matching the pattern
            $script = sprintf(
                '/ip/firewall/address-list/remove [/ip/firewall/address-list/find where list~"%s"]',
                $pattern
            );

            $this->logger->debug("Bulk removal script: {$script}");
            
            if ($this->executeScript($script)) {
                $this->logger->info("Bulk removal script completed for pattern '{$pattern}'");
                return 1; // Script handles all matching lists at once
            }

            $this->logger->error("Failed to execute bulk removal script for pattern '{$pattern}'");
            return 0;

        } catch (\Exception $e) {
            $this->logger->error("Clear by pattern exception: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Test connection to Mikrotik REST API
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        try {
            $protocol = $this->useSSL ? 'https' : 'http';
            $url = "{$protocol}://{$this->host}:{$this->port}/rest/system/resource";
            $this->logger->debug("Testing connection to {$url}");
            
            $result = $this->makeRequest('GET', $url);
            
            if (!empty($result)) {
                $this->logger->info("Successfully connected to Mikrotik REST API");
                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->error("Connection test failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Make an HTTP request to the Mikrotik REST API
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE, PATCH)
     * @param string $url Full REST API URL
     * @param array $data Optional data for PUT/POST requests
     * @return array Decoded JSON response
     * @throws \Exception
     */
    private function makeRequest(string $method, string $url, array $data = []): array
    {
        $this->logger->debug("Making {$method} request to REST API");

        $ch = curl_init($url);
        if (!$ch) {
            throw new \Exception("Failed to initialize cURL");
        }

        try {
            // Set basic authentication
            curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

            // SSL settings
            if ($this->useSSL) {
                if ($this->ignoreCertErrors) {
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                }
            }

            // Set content-type header
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);

            // Send JSON data for PUT/POST requests
            if (!empty($data) && in_array($method, ['PUT', 'POST', 'PATCH'])) {
                $jsonData = json_encode($data, JSON_UNESCAPED_SLASHES);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
                $this->logger->debug("Request data: {$jsonData}");
            }

            // Execute request
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($curlError) {
                throw new \Exception("cURL error: {$curlError}");
            }

            $this->logger->debug("HTTP {$method} returned status {$httpCode}");
            $this->logger->debug("Raw response body: {$response}");

            // Handle HTTP errors
            if ($httpCode >= 400) {
                // Only log as error if it's not an expected condition (like "already have such entry")
                if (strpos($response, 'already have such entry') === false) {
                    $this->logger->error("REST API error (HTTP {$httpCode}): {$response}");
                } else {
                    $this->logger->debug("REST API returned HTTP {$httpCode} with expected condition: {$response}");
                }
                if ($httpCode === 404) {
                    return []; // Not found
                }
                throw new \Exception("HTTP {$httpCode}: {$response}");
            }

            // Parse JSON response
            if (empty($response)) {
                $this->logger->debug("Empty response (successful operation)");
                return [];
            }

            $decoded = json_decode($response, true);
            if ($decoded === null) {
                throw new \Exception("Invalid JSON response: {$response}");
            }

            $this->logger->debug("Parsed JSON response: " . json_encode($decoded));

            // Ensure we return an array (even if single object)
            if (!is_array($decoded)) {
                return [$decoded];
            }

            return $decoded;

        } finally {
            curl_close($ch);
        }
    }

    /**
     * Get all DHCP leases, optionally filtered by server
     *
     * @param string $dhcpServerName Optional filter by DHCP server name
     * @return array Array of lease objects
     */
    public function getAllDhcpLeases(string $dhcpServerName = ''): array
    {
        try {
            $protocol = $this->useSSL ? 'https' : 'http';
            $url = "{$protocol}://{$this->host}:{$this->port}/rest/ip/dhcp-server/lease";
            
            // Add query filter if server name specified
            if (!empty($dhcpServerName)) {
                $url .= '?server=' . urlencode($dhcpServerName);
            }

            $this->logger->debug("Fetching all DHCP leases from {$url}");
            
            $leases = $this->makeRequest('GET', $url);
            
            if (!is_array($leases)) {
                $this->logger->warning("Unexpected response format from getAllDhcpLeases");
                return [];
            }

            $leaseCount = count($leases);
            $this->logger->info("Retrieved {$leaseCount} DHCP leases" . (!empty($dhcpServerName) ? " from server: {$dhcpServerName}" : ""));
            
            // Warn if extremely large number of leases
            if ($leaseCount > 5000) {
                $this->logger->warning("Large number of DHCP leases ({$leaseCount}). Processing may take a long time.");
            }
            
            return $leases;

        } catch (\Exception $e) {
            $this->logger->error("Failed to get all DHCP leases: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Clear all entries from an address list
     *
     * @param string $listName
     * @return int Number of entries deleted
     */
    public function clearAddressList(string $listName): int
    {
        try {
            $protocol = $this->useSSL ? 'https' : 'http';
            $url = "{$protocol}://{$this->host}:{$this->port}/rest/ip/firewall/address-list";
            
            $this->logger->debug("Fetching all entries from address list '{$listName}'");
            
            // Get all entries in this list
            $searchUrl = $url . '?list=' . urlencode($listName);
            $entries = $this->makeRequest('GET', $searchUrl);
            
            if (empty($entries)) {
                $this->logger->debug("Address list '{$listName}' is empty, skipping clear");
                return 0;
            }

            $entryCount = count($entries);
            $this->logger->info("Clearing {$entryCount} entries from address list '{$listName}' (this may take a while)");

            $deletedCount = 0;
            $startTime = time();
            
            foreach ($entries as $index => $entry) {
                if (isset($entry['.id'])) {
                    $deleteUrl = $url . '/' . $entry['.id'];
                    try {
                        $this->makeRequest('DELETE', $deleteUrl);
                        $deletedCount++;
                        
                        // Log progress every 50 deletions
                        if ($deletedCount % 50 === 0) {
                            $elapsed = time() - $startTime;
                            $this->logger->debug("Progress: Deleted {$deletedCount}/{$entryCount} from '{$listName}' ({$elapsed}s elapsed)");
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning("Failed to delete entry {$entry['.id']} from {$listName}: {$e->getMessage()}");
                    }
                }
            }

            $totalTime = time() - $startTime;
            $this->logger->info("Cleared {$deletedCount} entries from address list '{$listName}' in {$totalTime}s");
            return $deletedCount;

        } catch (\Exception $e) {
            $this->logger->error("Failed to clear address list '{$listName}': {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Map search column names to Mikrotik DHCP lease field names
     *
     * The searchColumn values from config are already the actual REST API field names,
     * so this function can return them directly
     *
     * @param string $searchColumn The REST API field name from config
     * @return string Mikrotik REST API field name
     */
    private function mapSearchColumnToField(string $searchColumn): string
    {
        // Config values are already REST API field names (active-mac-address, active-address, agent-circuit-id, agent-remote-id)
        return $searchColumn;
    }
}
