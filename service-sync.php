<?php

declare(strict_types=1);

const ADDRESS_LISTS = [
    'active' => 'Service_Active',
    'suspended' => 'Service_Suspend',
    'ended' => 'Service_End',
];

final class ApiClient
{
    public function __construct(
        private string $baseUrl,
        private array $headers = [],
        private ?string $username = null,
        private ?string $password = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function request(string $method, string $path, ?array $body = null): array
    {
        $curl = curl_init($this->baseUrl . '/' . ltrim($path, '/'));
        $headers = array_merge(['Accept: application/json'], $this->headers);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_USERPWD => $this->username === null ? null : $this->username . ':' . $this->password,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($response === false || $status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('%s %s failed: %s', $method, $path, curl_error($curl) ?: "HTTP $status"));
        }
        curl_close($curl);

        return $response === '' ? [] : json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }
}

final class ServiceSynchronizer
{
    public function __construct(private ApiClient $router, private string $leaseAttribute) {}

    public function sync(array $service): void
    {
        $state = $this->state($service['status'] ?? '');
        if ($state === null) {
            return;
        }

        $addresses = $this->addresses($service);
        foreach ($addresses as $address) {
            $this->removeFromManagedLists($address);
            $this->router->request('PUT', 'rest/ip/firewall/address-list', [
                'list' => ADDRESS_LISTS[$state],
                'address' => $address,
                'comment' => 'UCRM service ' . ($service['id'] ?? ''),
            ]);
        }
    }

    private function state(string $status): ?string
    {
        return match (strtolower($status)) {
            'active' => 'active',
            'suspended' => 'suspended',
            'ended', 'cancelled', 'canceled' => 'ended',
            default => null,
        };
    }

    private function addresses(array $service): array
    {
        $addresses = array_filter([$service['ipAddress'] ?? null, $service['ip_address'] ?? null]);
        $identifier = $this->attribute($service);
        if ($identifier !== null) {
            foreach ($this->router->request('GET', 'rest/ip/dhcp-server/lease') as $lease) {
                if ($this->leaseMatches($lease, $identifier)) {
                    $addresses[] = $lease['address'] ?? $lease['mac-address'] ?? null;
                }
            }
        }

        return array_values(array_unique(array_filter($addresses, 'is_string')));
    }

    private function attribute(array $service): ?string
    {
        foreach (($service['attributes'] ?? []) as $attribute) {
            if (($attribute['name'] ?? null) === $this->leaseAttribute && !empty($attribute['value'])) {
                return (string) $attribute['value'];
            }
        }
        return $service[$this->leaseAttribute] ?? null;
    }

    private function leaseMatches(array $lease, string $identifier): bool
    {
        return in_array($identifier, [
            $lease['comment'] ?? null,
            $lease['mac-address'] ?? null,
            $lease['macAddress'] ?? null,
        ], true);
    }

    private function removeFromManagedLists(string $address): void
    {
        foreach ($this->router->request('GET', 'rest/ip/firewall/address-list') as $entry) {
            if (($entry['address'] ?? null) === $address && in_array($entry['list'] ?? null, ADDRESS_LISTS, true)) {
                $this->router->request('DELETE', 'rest/ip/firewall/address-list/' . rawurlencode($entry['.id']));
            }
        }
    }
}

function configuration(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException("$name must be configured");
    }
    return $value;
}

function ucrmClient(): ApiClient
{
    return new ApiClient(
        configuration('UCRM_URL') . '/api/v1.0',
        ['X-Auth-App-Key: ' . configuration('UCRM_API_TOKEN')],
    );
}

function serviceFromRequest(ApiClient $ucrm): array
{
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    if (isset($payload['service']) && is_array($payload['service'])) {
        return $payload['service'];
    }
    $id = $payload['serviceId'] ?? $payload['id'] ?? null;
    if ($id === null) {
        throw new InvalidArgumentException('Webhook payload must include service or serviceId');
    }
    return $ucrm->request('GET', 'services/' . rawurlencode((string) $id));
}

$router = new ApiClient(
    configuration('MIKROTIK_URL'),
    [],
    configuration('MIKROTIK_USERNAME'),
    configuration('MIKROTIK_PASSWORD'),
);
$sync = new ServiceSynchronizer($router, getenv('UCRM_LEASE_ATTRIBUTE') ?: 'serial');
$ucrm = ucrmClient();

try {
    if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'daily') {
        foreach ($ucrm->request('GET', 'services') as $service) {
            $sync->sync($service);
        }
    } else {
        $sync->sync(serviceFromRequest($ucrm));
    }
    http_response_code(204);
} catch (Throwable $error) {
    error_log($error->getMessage());
    http_response_code(500);
    echo 'Service synchronization failed';
}
