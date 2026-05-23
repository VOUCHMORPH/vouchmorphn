<?php
namespace Infrastructure\MessageAdapters;

use Core\Transaction\InternalTransaction;

class Iso20022Adapter implements MessageAdapterInterface {
    private string $countryCode;
    private array $countryConfig;
    
    public function __construct(string $countryCode = null) {
        $this->countryCode = $countryCode ?? 'BW';
        
        // Load country-specific ISO mappings
        $configPath = __DIR__ . "/../../config/countries/{$this->countryCode}/iso_mappings.php";
        if (file_exists($configPath)) {
            $this->countryConfig = require $configPath;
        } else {
            $this->countryConfig = require __DIR__ . '/../../config/iso_default_mappings.php';
        }
    }
    
    public function toExternal(InternalTransaction $transaction): string {
        // Build ISO message using country-specific configurations
        // No hardcoded bank names - all from config
        $xml = new \DOMDocument('1.0', 'UTF-8');
        
        // Use country-specific namespace
        $namespace = $this->countryConfig['namespace'] ?? 'urn:iso:std:iso:20022:tech:xsd:pacs.008.001.08';
        
        $doc = $xml->appendChild($xml->createElementNS($namespace, 'Document'));
        
        // Build based on config, not hardcoded banks
        // ... rest of ISO building logic
        
        return $xml->saveXML();
    }
    
    public function toInternal(string $message): InternalTransaction {
        // Parse based on country config
        // ... parsing logic
        
        return new InternalTransaction([
            'messageFormat' => 'iso20022',
            'messageVersion' => $this->getVersion()
        ]);
    }
    
    public function validate(string $message): bool {
        // Use country-specific validation rules
        return true;
    }
    
    public function getVersion(): string {
        return $this->countryConfig['version'] ?? 'pacs.008.001.08';
    }
}
