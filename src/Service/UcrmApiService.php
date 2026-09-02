<?php

namespace Ubnt\Plugins\MikrotikSync\Service;

use Ubnt\UcrmPluginSdk\Service\UcrmApi;

class UcrmApiService
{
    private $ucrmApi;
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->ucrmApi = UcrmApi::create();
        $this->logger = $logger;
    }

    /**
     * Get a service by ID
     *
     * @param int $serviceId
     * @return array|null
     */
    public function getService(int $serviceId): ?array
    {
        try {
            $result = $this->ucrmApi->get('clients/services/' . $serviceId);
            return $result[0] ?? null;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get service {$serviceId}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Get a client by ID
     *
     * @param int $clientId
     * @return array|null
     */
    public function getClient(int $clientId): ?array
    {
        try {
            $result = $this->ucrmApi->get('clients/' . $clientId);
            return $result[0] ?? null;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get client {$clientId}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Get all services for a client
     *
     * @param int $clientId
     * @return array
     */
    public function getClientServices(int $clientId): array
    {
        try {
            return $this->ucrmApi->get("clients/{$clientId}/services");
        } catch (\Exception $e) {
            $this->logger->error("Failed to get services for client {$clientId}: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Get all services with optional filters
     *
     * @param array $params Query parameters
     * @return array
     */
    public function getAllServices(array $params = []): array
    {
        try {
            $query = 'clients/services';
            if (!empty($params)) {
                $query .= '?' . http_build_query($params);
            }
            return $this->ucrmApi->get($query);
        } catch (\Exception $e) {
            $this->logger->error("Failed to get all services: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Get custom attributes for a service
     *
     * @param int $serviceId
     * @return array
     */
    public function getServiceCustomAttributes(int $serviceId): array
    {
        try {
            $service = $this->getService($serviceId);
            return $service['customAttributes'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error("Failed to get custom attributes for service {$serviceId}: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Get a custom attribute value by name
     *
     * @param int $serviceId
     * @param string $attributeName
     * @return string|null
     */
    public function getServiceCustomAttribute(int $serviceId, string $attributeName): ?string
    {
        try {
            $service = $this->getService($serviceId);
            if (!$service || !isset($service['customAttributes'])) {
                return null;
            }

            foreach ($service['customAttributes'] as $attr) {
                if (($attr['key'] ?? null) === $attributeName) {
                    return $attr['value'] ?? null;
                }
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get custom attribute '{$attributeName}' for service {$serviceId}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Get service status
     *
     * @param int $serviceId
     * @return string|null
     */
    public function getServiceStatus(int $serviceId): ?string
    {
        $service = $this->getService($serviceId);
        return $service['status'] ?? null;
    }

}

