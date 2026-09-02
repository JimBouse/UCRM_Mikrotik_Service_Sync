<?php

namespace Ubnt\Plugins\MikrotikSync\Service;

class StateManager
{
    private $stateFile;
    private $state;
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->stateFile = __DIR__ . '/../../data/plugin.json';
        $this->load();
    }

    /**
     * Load state from file
     */
    private function load(): void
    {
        if (file_exists($this->stateFile)) {
            $json = file_get_contents($this->stateFile);
            $this->state = json_decode($json, true) ?? $this->getDefaultState();
        } else {
            $this->state = $this->getDefaultState();
        }
    }

    /**
     * Get default state structure
     *
     * @return array
     */
    private function getDefaultState(): array
    {
        return [
            'lastSyncTime' => null,
            'lastWebhookProcessTime' => null,
            'processedWebhooks' => [],
            'serviceStates' => [],
        ];
    }

    /**
     * Save state to file
     */
    public function save(): void
    {
        $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->stateFile, $json, LOCK_EX);
        $this->logger->debug("State saved");
    }

    /**
     * Get the last sync time
     *
     * @return int|null
     */
    public function getLastSyncTime(): ?int
    {
        return $this->state['lastSyncTime'];
    }

    /**
     * Set the last sync time
     *
     * @param int $timestamp
     */
    public function setLastSyncTime(int $timestamp): void
    {
        $this->state['lastSyncTime'] = $timestamp;
        $this->save();
    }

    /**
     * Record a processed webhook to prevent duplicate processing
     *
     * @param string $eventType
     * @param int $serviceId
     * @param int $timestamp
     */
    public function recordProcessedWebhook(string $eventType, int $serviceId, int $timestamp): void
    {
        $key = "{$eventType}:{$serviceId}";
        $this->state['processedWebhooks'][$key] = $timestamp;

        // Keep only recent webhooks (last 24 hours)
        $cutoff = time() - (24 * 60 * 60);
        foreach ($this->state['processedWebhooks'] as $k => $v) {
            if ($v < $cutoff) {
                unset($this->state['processedWebhooks'][$k]);
            }
        }

        $this->save();
    }

    /**
     * Check if webhook was already processed
     *
     * @param string $eventType
     * @param int $serviceId
     * @return bool
     */
    public function isWebhookProcessed(string $eventType, int $serviceId): bool
    {
        $key = "{$eventType}:{$serviceId}";
        return isset($this->state['processedWebhooks'][$key]);
    }

    /**
     * Record service state
     *
     * @param int $serviceId
     * @param string $status
     * @param string $listName
     * @param int $timestamp
     */
    public function recordServiceState(int $serviceId, string $status, string $listName, int $timestamp): void
    {
        $this->state['serviceStates'][$serviceId] = [
            'status' => $status,
            'listName' => $listName,
            'timestamp' => $timestamp,
        ];
        $this->save();
    }

    /**
     * Get service state
     *
     * @param int $serviceId
     * @return array|null
     */
    public function getServiceState(int $serviceId): ?array
    {
        return $this->state['serviceStates'][$serviceId] ?? null;
    }

    /**
     * Get all service states
     *
     * @return array
     */
    public function getAllServiceStates(): array
    {
        return $this->state['serviceStates'] ?? [];
    }
}
