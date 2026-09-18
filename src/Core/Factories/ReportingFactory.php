<?php


// 6. FACTORY_LAYER/ReportingFactory.php

namespace FACTORY_LAYER;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

require_once __DIR__ . '/../INTEGRATION_LAYER/interfaces/ReportingProviderInterface.php';
require_once __DIR__ . '/../INTEGRATION_LAYER/clients/ReportingClients/FATFClient.php';
require_once __DIR__ . '/../INTEGRATION_LAYER/clients/ReportingClients/LocalRegulatorClient.php';

use INTEGRATION_LAYER\interfaces\ReportingProviderInterface;
use INTEGRATION_LAYER\clients\ReportingClients\FATFClient;
use INTEGRATION_LAYER\clients\ReportingClients\LocalRegulatorClient;
use Exception;

class ReportingFactory
{
    /**
     * Create a reporting client (regulators, AML, FATF)
     *
     * @param string $provider
     * @return ReportingProviderInterface
     * @throws Exception
     */
    public static function create(string $provider): ReportingProviderInterface
    {
        return match (strtolower($provider)) {
            'fatf'  => new FATFClient(),
            'local' => new LocalRegulatorClient(),
            default => throw new Exception("Unsupported reporting provider: $provider"),
        };
    }
}
