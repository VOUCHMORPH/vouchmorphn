<?php
namespace Infrastructure\MessageAdapters;

use RuntimeException;

class MessageAdapterFactory
{
    private array $globalConfig = [];
    private array $countryConfigs = [];
    private array $instances = [];
    private ?string $currentCountry = null;

    public function __construct(?string $countryCode = null)
    {
        // src/Infrastructure/MessageAdapters
        // -> up to src
        // -> Core/Config/message_adapters.php

        $configFile = dirname(__DIR__, 2)
            . '/Core/Config/message_adapters.php';

        if (!file_exists($configFile)) {
            throw new RuntimeException(
                "Missing config: {$configFile}"
            );
        }

        $config = require $configFile;

        if (!is_array($config)) {
            throw new RuntimeException(
                "Invalid config file: {$configFile}"
            );
        }

        $this->globalConfig = $config;

        if ($countryCode !== null) {
            $this->setCountry($countryCode);
        }
    }

    public function setCountry(string $countryCode): self
    {
        /*
         * Railway/Linux is case-sensitive.
         *
         * Your folders are:
         *   Countries/Botswana
         *   Countries/South Africa
         *
         * So do NOT lowercase them.
         */

        $this->currentCountry = trim($countryCode);

        $configPath = dirname(__DIR__, 2)
            . '/Core/Config/Countries/'
            . $this->currentCountry
            . '/bank_formats.php';

        if (!file_exists($configPath)) {
            throw new RuntimeException(
                "Country config not found: {$configPath}"
            );
        }

        $countryConfig = require $configPath;

        if (!is_array($countryConfig)) {
            throw new RuntimeException(
                "Invalid country config: {$configPath}"
            );
        }

        $this->countryConfigs[$this->currentCountry] = $countryConfig;

        return $this;
    }

    public function getAdapter(string $formatType): MessageAdapterInterface
    {
        if ($this->currentCountry === null) {
            throw new RuntimeException(
                'Country not set'
            );
        }

        $cacheKey =
            $this->currentCountry . '_' . $formatType;

        if (isset($this->instances[$cacheKey])) {
            return $this->instances[$cacheKey];
        }

        if (
            !isset(
                $this->globalConfig['adapters'][$formatType]
            )
        ) {
            throw new RuntimeException(
                "Adapter not configured: {$formatType}"
            );
        }

        $adapterClass =
            $this->globalConfig['adapters'][$formatType]['class'];

        if (!class_exists($adapterClass)) {
            throw new RuntimeException(
                "Adapter class not found: {$adapterClass}"
            );
        }

        $this->instances[$cacheKey] =
            new $adapterClass($this->currentCountry);

        return $this->instances[$cacheKey];
    }

    public function getSupportedBanks(): array
    {
        if ($this->currentCountry === null) {
            return [];
        }

        return array_keys(
            $this->countryConfigs[$this->currentCountry]['bank_formats']
                ?? []
        );
    }
}
