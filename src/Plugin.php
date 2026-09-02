<?php

namespace Ubnt\Plugins\MikrotikSync;

use DI\Container;
use Ubnt\Plugins\MikrotikSync\Service\ConfigManager;
use Ubnt\Plugins\MikrotikSync\Service\Logger;
use Ubnt\Plugins\MikrotikSync\Service\MikrotikApiService;
use Ubnt\Plugins\MikrotikSync\Service\ServiceHandler;
use Ubnt\Plugins\MikrotikSync\Service\StateManager;
use Ubnt\Plugins\MikrotikSync\Service\UcrmApiService;

class Plugin
{
    private $container;
    private $logger;
    private $configManager;
    private $serviceHandler;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->configManager = $container->get(ConfigManager::class);
        $this->logger = $container->get(Logger::class);
        $this->serviceHandler = $container->get(ServiceHandler::class);
    }

    /**
     * Main entry point for CLI/scheduled execution
     */
    public function run(): void
    {
        try {
            // Validate configuration
            if (!$this->configManager->validateConfiguration()) {
                $this->logger->error("Plugin configuration is incomplete. Please configure all required settings.");
                throw new \Exception("Invalid plugin configuration");
            }

            // Update PCQ script on Mikrotik (one of the first tasks)
            $this->updatePcqScript();

            // Execute daily sync
            $this->syncAllServices();

            // Clean old log entries (older than 48 hours)
            $this->logger->cleanOldLogs();

            // Run the update_pcq script on all Mikrotik hosts
            $this->runUpdatePcqScript();
        } catch (\Exception $e) {
            $this->logger->error("Plugin error: {$e->getMessage()}");
            $this->logger->error("Stack trace: {$e->getTraceAsString()}");
            throw $e;
        }
    }

    /**
     * Handle public HTTP requests (webhooks)
     */
    public function handlePublicRequest(): void
    {
        try {
            // Get the webhook payload
            $payload = json_decode(file_get_contents('php://input'), true);

            if (!$payload) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid payload']);
                return;
            }

            // Extract UUID if present
            $uuid = $payload['uuid'] ?? null;
            if ($uuid) {
                $this->logger->setUuid($uuid);
            }

            // Validate configuration
            if (!$this->configManager->validateConfiguration()) {
                $this->logger->error("Plugin configuration is incomplete");
                $this->logValidationDetails();
                http_response_code(500);
                echo json_encode(['error' => 'Plugin not configured']);
                return;
            }

            // Process the webhook
            $this->processWebhook($payload);

            // Return success
            http_response_code(200);
            echo json_encode(['status' => 'received']);
        } catch (\Exception $e) {
            $this->logger->error("Webhook processing error: {$e->getMessage()}");
            $this->logger->error("Exception stack trace:\n{$e->getTraceAsString()}");
            http_response_code(500);
            echo json_encode(['error' => 'Processing failed']);
        }
    }

    /**
     * Log validation failure details for debugging
     */
    private function logValidationDetails(): void
    {
        $hosts = $this->configManager->getMikrotikHosts();
        $username = $this->configManager->getMikrotikUsername();
        $password = $this->configManager->getMikrotikPassword();
        $port = $this->configManager->getMikrotikPort();

        $this->logger->error("Configuration validation failed. Details:");
        $this->logger->error("  - Mikrotik Hosts: " . (empty($hosts) ? "NOT SET" : implode(", ", $hosts)));
        $this->logger->error("  - Mikrotik Username: " . (empty($username) ? "NOT SET" : "***"));
        $this->logger->error("  - Mikrotik Password: " . (empty($password) ? "NOT SET" : "***"));
        $this->logger->error("  - Mikrotik Port: " . ($port <= 0 ? "NOT SET (invalid)" : $port));

        // Log the actual config object to see what keys/values are present
        $rawConfig = $this->configManager->getConfig();
        $this->logger->error("Raw config object: " . json_encode($rawConfig));
    }

    /**
     * Process a webhook payload
     *
     * @param array $payload
     */
    private function processWebhook(array $payload): void
    {
        // Extract event information from UCRM webhook structure
        $eventName = $payload['eventName'] ?? null;
        $entityId = $payload['entityId'] ?? null;
        $extraData = $payload['extraData'] ?? [];
        $entity = $extraData['entity'] ?? [];

        if (!$eventName || !$entityId) {
            $this->logger->warning("Webhook missing required fields (eventName, entityId)");
            return;
        }

        $clientId = $entity['clientId'] ?? null;
        $attributes = $entity['attributes'] ?? [];

        if (!$clientId) {
            $this->logger->warning("Webhook missing clientId in entity");
            return;
        }

        // Map UCRM webhook event types to our internal types
        $supportedEvents = [
            'service.add',
            'service.edit',
            'service.postpone',
            'service.suspend',
            'service.suspend_cancel',
            'service.end',
            'service.archive',
            'service.delete',
        ];

        if (!in_array($eventName, $supportedEvents)) {
            $this->logger->notice("Webhook event type '{$eventName}' is not supported, ignoring");
            return;
        }

        // Extract the configured search attribute value from the attributes list
        // searchStringKey is the numeric custom attribute ID (e.g., "3")
        $searchAttributeKey = $this->configManager->getSearchString();
        $this->logger->debug("Looking for customAttributeId: '{$searchAttributeKey}'");
        $searchValue = null;

        foreach ($attributes as $attr) {
            // Match against 'customAttributeId' which is the numeric attribute ID
            $attrId = isset($attr['customAttributeId']) ? $attr['customAttributeId'] : 'N/A';
            $attrName = isset($attr['name']) ? $attr['name'] : 'N/A';
            $attrKey = isset($attr['key']) ? $attr['key'] : 'N/A';
            $attrValue = isset($attr['value']) ? $attr['value'] : '';
            $this->logger->debug("Attribute in payload: customAttributeId='{$attrId}', key='{$attrKey}', name='{$attrName}', value='{$attrValue}'");

            if (isset($attr['customAttributeId']) && $attr['customAttributeId'] === (int)$searchAttributeKey) {
                $searchValue = $attr['value'] ?? '';
                $this->logger->debug("Matched! Using value: '{$searchValue}'");
                break;
            }
        }

        // Extract the service status
        $status = $entity['status'] ?? null;

        // Process the service event
        $this->serviceHandler->processServiceWebhook($eventName, $clientId, $entityId, $searchValue, $status);
    }

    /**
     * Update PCQ script on all Mikrotik hosts
     */
    private function updatePcqScript(): void
    {
        try {
            $mikrotikApiManager = $this->container->get(\Ubnt\Plugins\MikrotikSync\Service\MikrotikApiManager::class);

            // Get the plugin's public URL and append the script file
            $publicUrl = $this->configManager->getPluginPublicUrl();
            if (empty($publicUrl)) {
                $this->logger->warning("Could not determine plugin public URL, skipping PCQ script update");
                return;
            }

            $scriptUrl = $publicUrl . 'update_pcq_script.txt';

            $this->logger->debug("Updating PCQ script on Mikrotik from: {$scriptUrl}");

            $hosts = $this->configManager->getMikrotikHosts();
            foreach ($hosts as $host) {
                try {
                    $apiService = $mikrotikApiManager->getMikrotikApiService($host);
                    if (!$apiService) {
                        $this->logger->warning("Could not get API service for host {$host}");
                        continue;
                    }

                    // Step 1: Fetch the script file with error handling
                    $fetchScript = ':onerror e in={ /tool fetch url="' . $scriptUrl . '" dst-path=update_pcq.rsc } do={ :log error ("PCQ FETCH FAILED: " . $e) }';
                    $this->logger->debug("Executing fetch step on {$host}");
                    if (!$apiService->executeScript($fetchScript)) {
                        $this->logger->warning("PCQ fetch step failed on {$host}");
                        continue;
                    }

                    // Step 2: Import the fetched script with error handling
                    $importScript = ':onerror e in={ /import update_pcq.rsc } do={ :log error ("PCQ IMPORT FAILED: " . $e) }';
                    $this->logger->debug("Executing import step on {$host}");
                    if (!$apiService->executeScript($importScript)) {
                        $this->logger->warning("PCQ import step failed on {$host}");
                        continue;
                    }

                    $this->logger->info("PCQ script update completed successfully on {$host}");
                } catch (\Exception $e) {
                    $this->logger->warning("Error updating PCQ script on {$host}: {$e->getMessage()}");
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning("Error updating PCQ script: {$e->getMessage()}, continuing with sync");
        }
    }

    /**
     * Execute daily service sync
     */
    private function syncAllServices(): void
    {
        $this->logger->info("===== Daily Service Sync Started =====");
        $this->serviceHandler->syncAllServices();
        $this->logger->info("===== Daily Service Sync Completed =====");
    }

    /**
     * Run the update_pcq script on all Mikrotik hosts
     */
    private function runUpdatePcqScript(): void
    {
        $this->logger->info("Running update_pcq script on all Mikrotik hosts");

        $mikrotikApi = $this->container->get(\Ubnt\Plugins\MikrotikSync\Service\MikrotikApiManager::class);
        $hosts = $this->configManager->getMikrotikHosts();

        foreach ($hosts as $host) {
            try {
                $apiService = $mikrotikApi->getMikrotikApiService($host);
                if (!$apiService) {
                    $this->logger->warning("Could not get API service for host {$host}");
                    continue;
                }

                $script = '/system/script/run update_pcq';
                $this->logger->info("Executing script on {$host}: {$script}");
                if ($apiService->executeScript($script)) {
                    $this->logger->info("Successfully ran update_pcq script on {$host}");
                } else {
                    $this->logger->error("Failed to run update_pcq script on {$host}");
                }
            } catch (\Exception $e) {
                $this->logger->error("Failed to run update_pcq script on {$host}: {$e->getMessage()}");
            }
        }
    }
}
