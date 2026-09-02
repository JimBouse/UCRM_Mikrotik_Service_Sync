<?php

chdir(__DIR__);
require 'vendor/autoload.php';

(function () {
    $builder = new \DI\ContainerBuilder();
    
    // Define services
    $builder->addDefinitions([
        \Ubnt\Plugins\MikrotikSync\Service\ConfigManager::class => 
            \DI\create(\Ubnt\Plugins\MikrotikSync\Service\ConfigManager::class),
        
        \Ubnt\Plugins\MikrotikSync\Service\Logger::class => 
            \DI\factory(function () {
                $configManager = new \Ubnt\Plugins\MikrotikSync\Service\ConfigManager();
                return new \Ubnt\Plugins\MikrotikSync\Service\Logger($configManager->isDebugEnabled());
            }),
        
        \Ubnt\Plugins\MikrotikSync\Service\UcrmApiService::class => 
            \DI\create(\Ubnt\Plugins\MikrotikSync\Service\UcrmApiService::class)
                ->constructor(\DI\get(\Ubnt\Plugins\MikrotikSync\Service\Logger::class)),
        
        \Ubnt\Plugins\MikrotikSync\Service\MikrotikApiService::class => 
            \DI\factory(function () {
                $configManager = new \Ubnt\Plugins\MikrotikSync\Service\ConfigManager();
                $logger = new \Ubnt\Plugins\MikrotikSync\Service\Logger($configManager->isDebugEnabled());
                // Single instance created by MikrotikApiManager, but kept for backward compat
                return new \Ubnt\Plugins\MikrotikSync\Service\MikrotikApiService(
                    $configManager->getMikrotikHost(),
                    $configManager->getMikrotikPort(),
                    $configManager->getMikrotikUsername(),
                    $configManager->getMikrotikPassword(),
                    $logger,
                    $configManager->isSSLEnabled(),
                    $configManager->isIgnoreCertificateErrors()
                );
            }),
        
        \Ubnt\Plugins\MikrotikSync\Service\MikrotikApiManager::class => 
            \DI\create(\Ubnt\Plugins\MikrotikSync\Service\MikrotikApiManager::class)
                ->constructor(
                    \DI\get(\Ubnt\Plugins\MikrotikSync\Service\Logger::class),
                    \DI\get(\Ubnt\Plugins\MikrotikSync\Service\ConfigManager::class)
                ),
        
        \Ubnt\Plugins\MikrotikSync\Service\StateManager::class => 
            \DI\create(\Ubnt\Plugins\MikrotikSync\Service\StateManager::class)
                ->constructor(\DI\get(\Ubnt\Plugins\MikrotikSync\Service\Logger::class)),
        
        \Ubnt\Plugins\MikrotikSync\Service\ServiceHandler::class => 
            \DI\create(\Ubnt\Plugins\MikrotikSync\Service\ServiceHandler::class)
                ->constructor(
                    \DI\get(\Ubnt\Plugins\MikrotikSync\Service\Logger::class),
                    \DI\get(\Ubnt\Plugins\MikrotikSync\Service\UcrmApiService::class),
                    \DI\get(\Ubnt\Plugins\MikrotikSync\Service\MikrotikApiManager::class),
                    \DI\get(\Ubnt\Plugins\MikrotikSync\Service\ConfigManager::class),
                    \DI\get(\Ubnt\Plugins\MikrotikSync\Service\StateManager::class)
                ),
        
    ]);

    $container = $builder->build();
    $plugin = new \Ubnt\Plugins\MikrotikSync\Plugin($container);

    try {
        $plugin->handlePublicRequest();
    } catch (Exception $exception) {
        $logger = $container->get(\Ubnt\Plugins\MikrotikSync\Service\Logger::class);
        $logger->error('Webhook processing error: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Processing failed']);
    }
})();
