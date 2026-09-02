<?php

namespace Ubnt\Plugins\MikrotikSync\Service;

class ServiceHandler
{
    private $logger;
    private $ucrmApi;
    private $mikrotikApi;
    private $configManager;
    private $stateManager;

    // UCRM Service status constants
    private const STATUS_ACTIVE = 1;
    private const STATUS_SUSPENDED = 3;
    private const STATUS_ENDED = 2;

    public function __construct(
        Logger $logger,
        UcrmApiService $ucrmApi,
        MikrotikApiManager $mikrotikApi,
        ConfigManager $configManager,
        StateManager $stateManager
    ) {
        $this->logger = $logger;
        $this->ucrmApi = $ucrmApi;
        $this->mikrotikApi = $mikrotikApi;
        $this->configManager = $configManager;
        $this->stateManager = $stateManager;
    }

    /**
     * Process a service event webhook
     *
     * @param string $eventType (e.g., 'service.add', 'service.suspend', 'service.end')
     * @param int $clientId
     * @param int $serviceId
     * @param string|null $searchValue The value of the configured search attribute from webhook payload
     * @param int|null $status The service status (1=Active, 2=Suspended, 3=Ended)
     * @return bool
     */
    public function processServiceWebhook(string $eventType, int $clientId, int $serviceId, ?string $searchValue = null, ?int $status = null): bool
    {
        $this->logger->info("Processing webhook {$eventType} for service {$serviceId}");

        if (!$searchValue) {
            $this->logger->warning("Service {$serviceId} does not have the configured search attribute");
            return false;
        }

        $this->logger->debug("Service {$serviceId} has search value: {$searchValue}");

        // Log the status in human-readable format
        $statusLabel = match($status) {
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_SUSPENDED => 'Suspended',
            self::STATUS_ENDED => 'Ended',
            default => 'Unknown',
        };
        $this->logger->debug("Service {$serviceId} has status [{$statusLabel}]");

        // Query Mikrotik for the IP address and the host where it was found
        $queryResult = $this->mikrotikApi->queryDhcpLeaseByColumnWithHost(
            $searchValue,
            $this->configManager->getSearchColumn(),
            $this->configManager->getDhcpServerName()
        );

        if (!$queryResult) {
            $this->logger->warning("No DHCP lease found for search value {$searchValue}");
            return false;
        }

        $ipAddress = $queryResult['ip'];
        $foundHost = $queryResult['host'];

        $this->logger->debug("Found IP {$ipAddress} on host {$foundHost} for service {$serviceId}");

        // Determine the target list based on event type
        $targetList = $this->getTargetListForEvent($eventType, []);
        if (!$targetList) {
            $this->logger->error("Could not determine target address list for event {$eventType}");
            return false;
        }

        // Build comment
        $comment = sprintf(
            'Client ID: %d, Service ID: %d, Search Value: %s',
            $clientId,
            $serviceId,
            $searchValue
        );

        // Get Mikrotik API service for the specific host where IP was found
        $hostApi = $this->mikrotikApi->getMikrotikApiService($foundHost);
        if (!$hostApi) {
            $this->logger->error("Could not get API service for host {$foundHost}");
            return false;
        }

        // Remove from other lists on this host only
        $this->removeFromOtherListsOnHost($hostApi, $ipAddress, $targetList);

        // Add to target list on this host only (check first to avoid unnecessary operations)
        $currentLists = $hostApi->getAddressListsForIp($ipAddress);

        if (in_array($targetList, $currentLists, true)) {
            // Already in target list (likely after removeFromOtherLists)
            $this->logger->info("IP {$ipAddress} already in '{$targetList}' on {$foundHost}, skipping add (optimization)");
            $success = true;
        } else {
            // Not in target list, add it on this host only
            $success = $hostApi->addToAddressList($targetList, $ipAddress, $comment);
        }

        // Handle Speed_Package lists for active services
        if ($success && $status === self::STATUS_ACTIVE) {
            $this->logger->debug("Service {$serviceId} is active, handling Speed_Package lists");

            // Fetch full service data to get speed information
            $fullService = $this->ucrmApi->getService($serviceId);
            if ($fullService) {
                $downSpeed = $fullService['downloadSpeedOverride'] ?? $fullService['downloadSpeed'] ?? null;
                $upSpeed = $fullService['uploadSpeedOverride'] ?? $fullService['uploadSpeed'] ?? null;

                if ($downSpeed !== null && $upSpeed !== null) {
                    $speedPackageList = $this->buildSpeedPackageListName($downSpeed, $upSpeed);

                    // Remove from any other Speed_Package lists on this host only
                    $this->removeFromSpeedPackageListsOnHost($hostApi, $ipAddress, $speedPackageList);

                    // Add to the correct Speed_Package list on this host only
                    $speedComment = sprintf(
                        'Speed Package %dMbps down / %dMbps up - Client ID: %d, Service ID: %d',
                        $downSpeed,
                        $upSpeed,
                        $clientId,
                        $serviceId
                    );
                    $this->logger->debug("Adding {$ipAddress} to '{$speedPackageList}' on {$foundHost} for service {$serviceId}");
                    $hostApi->addToAddressList($speedPackageList, $ipAddress, $speedComment);
                } else {
                    $this->logger->warning("Service {$serviceId} missing speed data: down={$downSpeed}, up={$upSpeed}");
                }
            } else {
                $this->logger->warning("Could not fetch full service data for service {$serviceId}");
            }
        }

        // Record service state
        if ($success) {
            $this->stateManager->recordServiceState($serviceId, $eventType, $targetList, time());
        }

        return $success;
    }

    /**
     * Process all services and sync their states
     *
     * This method:
     * 1. Fetches all services from UCRM with paging (100 at a time)
     * 2. Builds a searchable array indexed by searchColumn value
     * 3. For each Mikrotik:
     *    - Clears all Service_* address lists
     *    - Fetches all DHCP leases
     *    - For each lease, matches against services or marks as Service_Unknown
     *
     * @return int Number of services processed
     */
    public function syncAllServices(): int
    {
        $this->logger->info("===== Starting nightly service sync =====");

        // Step 1: Fetch all services with paging
        $servicesBySearchValue = $this->fetchAllServicesAsArray();
        $this->logger->info("Loaded " . count($servicesBySearchValue) . " services from UCRM");

        if (empty($servicesBySearchValue)) {
            $this->logger->warning("No services found in UCRM, aborting sync");
            return 0;
        }

        // Step 2: Sync each Mikrotik
        $totalProcessed = 0;
        $hosts = $this->configManager->getMikrotikHosts();

        foreach ($hosts as $host) {
            $this->logger->info("Syncing Mikrotik host: {$host}");
            $processed = $this->syncMikrotikHost($host, $servicesBySearchValue);
            $totalProcessed += $processed;
        }

        // Update last sync time
        $this->stateManager->setLastSyncTime(time());

        $this->logger->info("===== Nightly service sync completed, {$totalProcessed} IPs processed =====");
        return $totalProcessed;
    }

    /**
     * Fetch all services from UCRM and return indexed by searchColumn value
     *
     * @return array Keyed by searchValue, contains serviceId, clientId, status
     */
    private function fetchAllServicesAsArray(): array
    {
        $servicesBySearchValue = [];
        $searchAttributeKey = $this->configManager->getSearchString();

        // Fetch all services in one query
        $this->logger->info("Fetching all services from UCRM API...");
        $allRawServices = $this->ucrmApi->getAllServices([]);

        if (empty($allRawServices)) {
            $this->logger->warning("No services returned from API");
            return [];
        }

        $this->logger->info("API returned " . count($allRawServices) . " services");

        // Filter services to find those with the required search attribute
        $this->logger->info("Filtering services to find those with search attribute '{$searchAttributeKey}'...");
        $matchCount = 0;
        $skipCount = 0;

        foreach ($allRawServices as $service) {
            $serviceId = $service['id'] ?? null;
            $clientId = $service['clientId'] ?? null;
            $status = $service['status'] ?? null;

            if (!$serviceId || !$clientId || !$status) {
                $this->logger->debug("Skipping service {$serviceId}, missing required fields");
                $skipCount++;
                continue;
            }

            // Extract search attribute from service attributes array
            $attributes = $service['attributes'] ?? [];
            $searchValue = null;

            if (empty($attributes)) {
                $this->logger->debug("Service {$serviceId} has no attributes");
                $skipCount++;
                continue;
            }

            // Log first service's attributes for debugging (to see actual structure)
            if ($matchCount + $skipCount === 1) {
                $this->logger->debug("Sample attributes from service {$serviceId}: " . json_encode($attributes));
            }

            foreach ($attributes as $attr) {
                // Try multiple possible attribute ID fields
                $attrId = $attr['customAttributeId'] ?? $attr['id'] ?? null;
                $attrKey = $attr['key'] ?? 'N/A';

                if ($attrId !== null) {
                    // Try both strict type comparison and loose comparison
                    if ($attrId === (int)$searchAttributeKey || (string)$attrId === $searchAttributeKey) {
                        $searchValue = $attr['value'] ?? '';
                        $this->logger->debug("Service {$serviceId}: MATCHED attribute (customAttributeId={$attrId}, key={$attrKey}) -> value: {$searchValue}");
                        break;
                    }
                }
            }

            if (!$searchValue) {
                $skipCount++;
                continue;
            }

            // Index by searchValue
            $servicesBySearchValue[$searchValue] = [
                'serviceId' => $serviceId,
                'clientId' => $clientId,
                'status' => $status,
                'searchValue' => $searchValue,
                'downloadSpeed' => $service['downloadSpeed'] ?? null,
                'uploadSpeed' => $service['uploadSpeed'] ?? null,
                'downloadSpeedOverride' => $service['downloadSpeedOverride'] ?? null,
                'uploadSpeedOverride' => $service['uploadSpeedOverride'] ?? null,
            ];

            $matchCount++;
        }

        $this->logger->info("Filtering complete: Found {$matchCount} services with search attribute, skipped {$skipCount}");
        return $servicesBySearchValue;
    }

    /**
     * Sync a single Mikrotik host
     *
     * @param string $host
     * @param array $servicesBySearchValue Services indexed by search value
     * @return int Number of IPs processed
     */
    private function syncMikrotikHost(string $host, array $servicesBySearchValue): int
    {
        $processedCount = 0;
        $activeList = $this->configManager->getServiceActiveListName();
        $suspendList = $this->configManager->getServiceSuspendListName();
        $endList = $this->configManager->getServiceEndListName();
        $unknownList = 'Service_Unknown';

        // Get Mikrotik API client for this host
        $mikrotikApi = $this->mikrotikApi->getMikrotikApiService($host);
        if (!$mikrotikApi) {
            $this->logger->error("Failed to get Mikrotik API service for {$host}");
            return 0;
        }

        // Step 1: Clear all Service_* and Speed_Package-* address lists using bulk script (much faster)
        $this->logger->info("Clearing all Service_* address lists on {$host} using bulk removal script");
        $mikrotikApi->clearAddressListsByPattern('Service_');
        $this->logger->debug("Service_* cleanup completed");

        $this->logger->info("Clearing all Speed_Package-* address lists on {$host} using bulk removal script");
        $mikrotikApi->clearAddressListsByPattern('Speed_Package-');
        $this->logger->debug("Speed_Package-* cleanup completed");

        // Alternative (slower, commented out): Clear individually
        // $listsToClear = [$activeList, $suspendList, $endList, $unknownList];
        // foreach ($listsToClear as $list) {
        //     $this->logger->info("Clearing address list '{$list}' on {$host}");
        //     $cleared = $mikrotikApi->clearAddressList($list);
        //     $this->logger->debug("Cleared {$cleared} entries from '{$list}'");
        // }

        // Step 2: Get all DHCP leases
        $dhcpServerName = $this->configManager->getDhcpServerName();
        $dhcpLeases = $mikrotikApi->getAllDhcpLeases($dhcpServerName);
        $this->logger->info("Retrieved " . count($dhcpLeases) . " DHCP leases from {$host}");

        if (empty($dhcpLeases)) {
            $this->logger->warning("No DHCP leases found on {$host}");
            return 0;
        }

        // Step 3: Process each lease
        $searchColumn = $this->configManager->getSearchColumn();
        $leaseCount = count($dhcpLeases);
        $processedCount = 0;
        $startTime = time();
        $ipCountPerList = []; // Track count of IPs added per list

        foreach ($dhcpLeases as $index => $lease) {
            $ipAddress = $lease['active-address'] ?? null;
            if (!$ipAddress) {
                continue;
            }

            // Extract searchColumn value from this lease
            $leaseSearchValue = $lease[$searchColumn] ?? null;
            if (!$leaseSearchValue) {
                $this->logger->debug("Lease {$ipAddress} missing search column '{$searchColumn}', adding to Service_Unknown");
                $comment = $this->buildLeaseComment("Empty {$searchColumn}", $lease);
                if ($this->addToAddressListIfNeeded($mikrotikApi, $unknownList, $ipAddress, $comment)) {
                    $ipCountPerList[$unknownList] = ($ipCountPerList[$unknownList] ?? 0) + 1;
                }
                $processedCount++;
                continue;
            }

            // Check if this searchValue matches any service
            if (isset($servicesBySearchValue[$leaseSearchValue])) {
                $service = $servicesBySearchValue[$leaseSearchValue];
                $targetList = $this->getTargetListForStatus($service['status']);

                if ($targetList) {
                    $comment = sprintf(
                        'Client ID: %d, Service ID: %d, Search Value: %s',
                        $service['clientId'],
                        $service['serviceId'],
                        $leaseSearchValue
                    );
                    $comment = $this->buildLeaseComment($comment, $lease);
                    $this->logger->debug("Adding {$ipAddress} to '{$targetList}' for service {$service['serviceId']}");
                    if ($this->addToAddressListIfNeeded($mikrotikApi, $targetList, $ipAddress, $comment)) {
                        $ipCountPerList[$targetList] = ($ipCountPerList[$targetList] ?? 0) + 1;
                    }

                    // If service is active, also add to Speed_Package list
                    if ($service['status'] === self::STATUS_ACTIVE) {
                        $downSpeed = $this->getEffectiveDownloadSpeed($service);
                        $upSpeed = $this->getEffectiveUploadSpeed($service);

                        if ($downSpeed !== null && $upSpeed !== null) {
                            $speedPackageList = $this->buildSpeedPackageListName($downSpeed, $upSpeed);
                            $speedComment = sprintf(
                                'Speed Package %dMbps down / %dMbps up - Client ID: %d, Service ID: %d',
                                $downSpeed,
                                $upSpeed,
                                $service['clientId'],
                                $service['serviceId']
                            );
                            $this->logger->debug("Adding {$ipAddress} to '{$speedPackageList}' for service {$service['serviceId']}");
                            if ($this->addToAddressListIfNeeded($mikrotikApi, $speedPackageList, $ipAddress, $speedComment)) {
                                $ipCountPerList[$speedPackageList] = ($ipCountPerList[$speedPackageList] ?? 0) + 1;
                            }
                        } else {
                            $this->logger->warning("Service {$service['serviceId']} missing speed data: down={$downSpeed}, up={$upSpeed}");
                        }
                    }
                } else {
                    $this->logger->warning("Service {$service['serviceId']} has unknown status {$service['status']}");
                    $comment = $this->buildLeaseComment("Service {$service['serviceId']} - unknown status", $lease);
                    if ($this->addToAddressListIfNeeded($mikrotikApi, $unknownList, $ipAddress, $comment)) {
                        $ipCountPerList[$unknownList] = ($ipCountPerList[$unknownList] ?? 0) + 1;
                    }
                }
            } else {
                // No matching service found
                $this->logger->debug("No service found for search value '{$leaseSearchValue}', adding IP {$ipAddress} to Service_Unknown");
                $comment = $this->buildLeaseComment("No service for {$leaseSearchValue} found in UCRM", $lease);
                if ($this->addToAddressListIfNeeded($mikrotikApi, $unknownList, $ipAddress, $comment)) {
                    $ipCountPerList[$unknownList] = ($ipCountPerList[$unknownList] ?? 0) + 1;
                }
            }

            $processedCount++;

            // Log progress every 100 leases
            if ($processedCount % 100 === 0) {
                $elapsed = time() - $startTime;
                $this->logger->info("Progress: Processed {$processedCount}/{$leaseCount} leases in {$elapsed}s");
            }
        }

        // Log summary of IPs added per list
        foreach ($ipCountPerList as $listName => $count) {
            $this->logger->info("Successfully added {$count} IP address(es) to {$listName}");
        }

        $totalTime = time() - $startTime;
        $this->logger->info("Completed processing {$processedCount} leases from {$host} in {$totalTime}s");

        return $processedCount;
    }

    /**
     * Get target address list based on event type
     *
     * @param string $eventType
     * @param array $service
     * @return string|null
     */
    private function getTargetListForEvent(string $eventType, array $service): ?string
    {
        $status = $service['status'] ?? null;

        return match ($eventType) {
            'service.add', 'service.edit', 'service.postpone', 'service.suspend_cancel' =>
                $this->configManager->getServiceActiveListName(),
            'service.suspend' =>
                $this->configManager->getServiceSuspendListName(),
            'service.end', 'service.archive', 'service.delete' =>
                $this->configManager->getServiceEndListName(),
            default => null,
        };
    }

    /**
     * Get target address list based on service status
     *
     * @param int $status
     * @return string|null
     */
    private function getTargetListForStatus(int $status): ?string
    {
        return match ($status) {
            self::STATUS_ACTIVE => $this->configManager->getServiceActiveListName(),
            self::STATUS_SUSPENDED => $this->configManager->getServiceSuspendListName(),
            self::STATUS_ENDED => $this->configManager->getServiceEndListName(),
            default => null,
        };
    }

    /**
     * Get effective download speed (override if present, else default)
     *
     * @param array $service Service data array
     * @return int|null
     */
    private function getEffectiveDownloadSpeed(array $service): ?int
    {
        return $service['downloadSpeedOverride'] ?? $service['downloadSpeed'] ?? null;
    }

    /**
     * Get effective upload speed (override if present, else default)
     *
     * @param array $service Service data array
     * @return int|null
     */
    private function getEffectiveUploadSpeed(array $service): ?int
    {
        return $service['uploadSpeedOverride'] ?? $service['uploadSpeed'] ?? null;
    }

    /**
     * Build Speed_Package list name from download and upload speeds
     *
     * @param int $downloadSpeed
     * @param int $uploadSpeed
     * @return string
     */
    private function buildSpeedPackageListName(int $downloadSpeed, int $uploadSpeed): string
    {
        return "Speed_Package-{$downloadSpeed}/{$uploadSpeed}";
    }

    /**
     * Remove IP from other address lists on a specific host
     *
     * @param MikrotikApiService $hostApi
     * @param string $ipAddress
     * @param string $excludeList
     */
    private function removeFromOtherListsOnHost(MikrotikApiService $hostApi, string $ipAddress, string $excludeList): void
    {
        $allLists = [
            $this->configManager->getServiceActiveListName(),
            $this->configManager->getServiceSuspendListName(),
            $this->configManager->getServiceEndListName(),
            'Service_Unknown',
        ];

        // Build list of lists to remove from (exclude the target list)
        $listsToRemoveFrom = [];
        foreach ($allLists as $listName) {
            if ($listName !== $excludeList) {
                $listsToRemoveFrom[] = $listName;
            }
        }

        $this->logger->debug("Checking which of these lists contain {$ipAddress}: " . implode(', ', $listsToRemoveFrom));
        $removedCount = $hostApi->removeFromSpecificLists($ipAddress, $listsToRemoveFrom);
        $this->logger->debug("Removed {$ipAddress} from {$removedCount} lists");

        if ($removedCount === 0) {
            $this->logger->debug("IP {$ipAddress} was not in any of the specified lists");
        }
    }

    /**
     * Remove IP from Speed_Package lists on a specific host (except the one to keep)
     *
     * @param MikrotikApiService $hostApi
     * @param string $ipAddress
     * @param string $keepList The Speed_Package list to keep the IP in
     */
    private function removeFromSpeedPackageListsOnHost(MikrotikApiService $hostApi, string $ipAddress, string $keepList): void
    {
        $currentLists = $hostApi->getAddressListsForIp($ipAddress);

        // Find all Speed_Package lists the IP is in
        $speedPackageLists = array_filter($currentLists, fn($list) => strpos($list, 'Speed_Package-') === 0);

        if (empty($speedPackageLists)) {
            $this->logger->debug("IP {$ipAddress} is not in any Speed_Package lists");
            return;
        }

        // Remove from Speed_Package lists except the one to keep
        $listsToRemoveFrom = array_filter(
            $speedPackageLists,
            fn($list) => $list !== $keepList
        );

        if (!empty($listsToRemoveFrom)) {
            $this->logger->debug("Removing {$ipAddress} from Speed_Package lists: " . implode(', ', $listsToRemoveFrom));
            $removedCount = $hostApi->removeFromSpecificLists($ipAddress, $listsToRemoveFrom);
            $this->logger->debug("Removed {$ipAddress} from {$removedCount} Speed_Package lists");
        }
    }

    /**
     * Remove IP from other address lists
     *
     * @param string $ipAddress
     * @param string $excludeList
     */
    private function removeFromOtherLists(string $ipAddress, string $excludeList): void
    {
        $allLists = [
            $this->configManager->getServiceActiveListName(),
            $this->configManager->getServiceSuspendListName(),
            $this->configManager->getServiceEndListName(),
            'Service_Unknown',  // Also clear from Unknown when webhook processes it
        ];

        // Build list of lists to remove from (exclude the target list)
        $listsToRemoveFrom = [];
        foreach ($allLists as $listName) {
            if ($listName !== $excludeList) {
                $listsToRemoveFrom[] = $listName;
            }
        }

        // Try each Mikrotik host (IP could be on any of them)
        $hosts = $this->configManager->getMikrotikHosts();
        $totalRemoved = 0;

        foreach ($hosts as $host) {
            $mikrotikApi = $this->mikrotikApi->getMikrotikApiService($host);
            if (!$mikrotikApi) {
                $this->logger->warning("Could not get API service for host {$host}");
                continue;
            }

            $this->logger->debug("Checking which of these lists contain {$ipAddress}: " . implode(', ', $listsToRemoveFrom));
            $removedCount = $mikrotikApi->removeFromSpecificLists($ipAddress, $listsToRemoveFrom);
            $this->logger->debug("Removed {$ipAddress} from {$removedCount} lists on {$host}");
            $totalRemoved += $removedCount;

            // If we found and removed the IP from this host, we're done
            if ($removedCount > 0) {
                break;
            }
        }

        if ($totalRemoved === 0) {
            $this->logger->debug("IP {$ipAddress} was not in any of the specified lists");
        }
    }

    /**
     * Remove IP from Speed_Package lists (except the one to keep)
     *
     * @param string $ipAddress
     * @param string $keepList The Speed_Package list to keep the IP in
     */
    private function removeFromSpeedPackageLists(string $ipAddress, string $keepList): void
    {
        // Try each Mikrotik host (IP could be on any of them)
        $hosts = $this->configManager->getMikrotikHosts();

        foreach ($hosts as $host) {
            $mikrotikApi = $this->mikrotikApi->getMikrotikApiService($host);
            if (!$mikrotikApi) {
                $this->logger->warning("Could not get API service for host {$host}");
                continue;
            }

            $currentLists = $mikrotikApi->getAddressListsForIp($ipAddress);

            // Find all Speed_Package lists the IP is in
            $speedPackageLists = array_filter($currentLists, fn($list) => strpos($list, 'Speed_Package-') === 0);

            if (empty($speedPackageLists)) {
                $this->logger->debug("IP {$ipAddress} is not in any Speed_Package lists");
                return;
            }

            // Remove from Speed_Package lists except the one to keep
            $listsToRemoveFrom = array_filter(
                $speedPackageLists,
                fn($list) => $list !== $keepList
            );

            if (!empty($listsToRemoveFrom)) {
                $this->logger->debug("Removing {$ipAddress} from Speed_Package lists: " . implode(', ', $listsToRemoveFrom));
                $removedCount = $mikrotikApi->removeFromSpecificLists($ipAddress, $listsToRemoveFrom);
                $this->logger->debug("Removed {$ipAddress} from {$removedCount} Speed_Package lists on {$host}");
            }

            // Found the IP, done with this operation
            return;
        }

        $this->logger->debug("IP {$ipAddress} not found on any Mikrotik host");
    }

    /**
     * Add IP to address list only if not already present (checks before adding)
     *
     * @param MikrotikApiService $mikrotikApi
     * @param string $listName Target address list
     * @param string $ipAddress IP address to add
     * @param string $comment Optional comment
     * @return bool True if successful or already present
     */
    private function addToAddressListIfNeeded(MikrotikApiService $mikrotikApi, string $listName, string $ipAddress, string $comment = ''): bool
    {
        // Check which lists currently contain this IP
        $currentLists = $mikrotikApi->getAddressListsForIp($ipAddress);

        // If already in target list, skip the add (optimization)
        if (in_array($listName, $currentLists, true)) {
            $this->logger->info("IP {$ipAddress} already in '{$listName}', skipping add (optimization)");
            return true;
        }

        // Not in target list, add it
        return $mikrotikApi->addToAddressList($listName, $ipAddress, $comment);
    }

    /**
     * Build enriched comment by appending DHCP lease MAC address and hostname
     *
     * @param string $baseComment Base comment text
     * @param array $lease DHCP lease object
     * @return string Enriched comment with MAC address and hostname
     */
    private function buildLeaseComment(string $baseComment, array $lease): string
    {
        $macAddress = $lease['active-mac-address'] ?? null;
        $hostname = $lease['host-name'] ?? null;

        $enrichedComment = $baseComment;

        if ($macAddress) {
            $enrichedComment .= ", MAC: {$macAddress}";
        }

        if ($hostname) {
            $enrichedComment .= ", Hostname: {$hostname}";
        }

        return $enrichedComment;
    }
}
