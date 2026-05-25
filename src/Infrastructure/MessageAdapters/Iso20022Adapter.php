<?php
namespace Infrastructure\MessageAdapters;

use Core\Transaction\InternalTransaction;

class Iso20022Adapter implements MessageAdapterInterface {
    private string $countryCode;
    private array $countryConfig;
    private array $defaultConfig;
    private string $version;
    
    public function __construct(string $countryCode = null) {
        $this->countryCode = strtolower($countryCode ?? 'bw');
        
        // Load country-specific ISO mappings
        $configPath = __DIR__ . "/../../Config/countries/{$this->countryCode}/iso_mappings.php";
        
        if (file_exists($configPath)) {
            $this->countryConfig = require $configPath;
            $this->version = $this->countryConfig['version'] ?? 'pacs.008.001.08';
        } else {
            // Load default mappings (created above)
            $defaultPath = __DIR__ . '/../../Config/iso_default_mappings.php';
            
            if (!file_exists($defaultPath)) {
                throw new \Exception("Default ISO mappings not found at: {$defaultPath}");
            }
            
            $this->countryConfig = require $defaultPath;
            $this->version = $this->countryConfig['version'] ?? 'pacs.008.001.08';
        }
    }
    
    public function toExternal(InternalTransaction $transaction): string {
        $namespace = $this->countryConfig['namespace'] ?? 'urn:iso:std:iso:20022:tech:xsd:pacs.008.001.08';
        
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        
        $doc = $xml->appendChild($xml->createElementNS($namespace, 'Document'));
        $ftc = $doc->appendChild($xml->createElement('FIToFICstmrCdtTrf'));
        
        // Group Header
        $grpHdr = $ftc->appendChild($xml->createElement('GrpHdr'));
        $grpHdr->appendChild($xml->createElement('MsgId', $transaction->getTransactionId()));
        $grpHdr->appendChild($xml->createElement('CreDtTm', date('Y-m-d\TH:i:s.uP')));
        
        // Payment Info
        $pmtInf = $ftc->appendChild($xml->createElement('CdtTrfTxInf'));
        
        // Amount
        $amt = $pmtInf->appendChild($xml->createElement('IntrBkSttlmAmt', number_format($transaction->getAmount(), 2)));
        $amt->setAttribute('Ccy', $transaction->getCurrency());
        
        // Debtor (Sender)
        $dbtr = $pmtInf->appendChild($xml->createElement('Dbtr'));
        $dbtr->appendChild($xml->createElement('Nm', $transaction->getSenderName() ?: 'Unknown'));
        
        // Creditor (Receiver)
        $cdtr = $pmtInf->appendChild($xml->createElement('Cdtr'));
        $cdtr->appendChild($xml->createElement('Nm', $transaction->getReceiverName() ?: 'Unknown'));
        
        // Account info
        $cdtrAcct = $pmtInf->appendChild($xml->createElement('CdtrAcct'));
        $id = $cdtrAcct->appendChild($xml->createElement('Id'));
        
        // Use Othr (Other) for account ID (works for all countries)
        $othr = $id->appendChild($xml->createElement('Othr'));
        $othr->appendChild($xml->createElement('Id', $transaction->getReceiverAccount()));
        
        // Reference
        if ($transaction->getReference()) {
            $rmtInf = $pmtInf->appendChild($xml->createElement('RmtInf'));
            $rmtInf->appendChild($xml->createElement('Ustrd', substr($transaction->getReference(), 0, 140)));
        }
        
        return $xml->saveXML();
    }
    
    public function toInternal(string $message): InternalTransaction {
        $dom = new \DOMDocument();
        $dom->loadXML($message);
        $xpath = new \DOMXPath($dom);
        
        // Register namespace if present
        $ns = $this->detectNamespace($dom);
        if ($ns) {
            $xpath->registerNamespace('ns', $ns);
            $prefix = 'ns:';
        } else {
            $prefix = '';
        }
        
        // Extract data using mappings
        $mappings = $this->countryConfig['mappings'] ?? [];
        
        $data = [];
        foreach ($mappings as $fieldName => $xpathExpr) {
            // Add namespace prefix if needed
            if ($ns && strpos($xpathExpr, '//') === 0) {
                $xpathExpr = '//' . $prefix . substr($xpathExpr, 2);
            }
            
            $nodes = $xpath->query($xpathExpr);
            if ($nodes && $nodes->length > 0) {
                $value = $nodes->item(0)->nodeValue;
                
                // Special handling for amount
                if ($fieldName === 'amount') {
                    $value = (float)$value;
                }
                
                $data[$fieldName] = $value;
            }
        }
        
        // Map bank codes if needed
        if (isset($this->countryConfig['bank_codes']) && isset($data['senderBankCode'])) {
            $bankCodes = $this->countryConfig['bank_codes'];
            if (isset($bankCodes[$data['senderBankCode']])) {
                $data['senderBankCode'] = $bankCodes[$data['senderBankCode']];
            }
        }
        
        $transactionData = [
            'transactionId' => $data['transactionId'] ?? $this->generateTransactionId(),
            'amount' => $data['amount'] ?? 0,
            'currency' => $data['currency'] ?? 'BWP',
            'reference' => $data['reference'] ?? '',
            'senderName' => $data['senderName'] ?? '',
            'receiverName' => $data['receiverName'] ?? '',
            'senderAccount' => $data['senderAccount'] ?? '',
            'receiverAccount' => $data['receiverAccount'] ?? '',
            'senderBankCode' => $data['senderBankCode'] ?? '',
            'receiverBankCode' => $data['receiverBankCode'] ?? '',
            'purpose' => $data['purpose'] ?? 'PAYMENT',
            'messageFormat' => 'iso20022',
            'messageVersion' => $this->version,
            'countryCode' => $this->countryCode
        ];
        
        return new InternalTransaction($transactionData);
    }
    
    public function validate(string $message): bool {
        // Basic XML validation
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($message);
            
            // Check required fields from config
            $requiredFields = $this->countryConfig['required_fields'] ?? ['transactionId', 'amount'];
            
            $xpath = new \DOMXPath($dom);
            $ns = $this->detectNamespace($dom);
            if ($ns) {
                $xpath->registerNamespace('ns', $ns);
                $prefix = 'ns:';
            } else {
                $prefix = '';
            }
            
            foreach ($requiredFields as $field) {
                $mapping = $this->countryConfig['mappings'][$field] ?? null;
                if ($mapping) {
                    if ($ns && strpos($mapping, '//') === 0) {
                        $mapping = '//' . $prefix . substr($mapping, 2);
                    }
                    $nodes = $xpath->query($mapping);
                    if (!$nodes || $nodes->length === 0) {
                        return false;
                    }
                }
            }
            
            // Validate amount limits
            $amountNodes = $xpath->query('//*[local-name()="IntrBkSttlmAmt"]');
            if ($amountNodes && $amountNodes->length > 0) {
                $amount = (float)$amountNodes->item(0)->nodeValue;
                $maxAmount = $this->countryConfig['validation']['max_amount'] ?? 999999999;
                $minAmount = $this->countryConfig['validation']['min_amount'] ?? 0;
                
                if ($amount > $maxAmount || $amount < $minAmount) {
                    return false;
                }
            }
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function getVersion(): string {
        return $this->version;
    }
    
    private function detectNamespace(\DOMDocument $dom): ?string {
        $root = $dom->documentElement;
        if ($root && $root->namespaceURI) {
            return $root->namespaceURI;
        }
        
        // Try to find from config
        return $this->countryConfig['namespace'] ?? null;
    }
    
    private function generateTransactionId(): string {
        return 'ISO_' . $this->countryCode . '_' . time() . '_' . bin2hex(random_bytes(4));
    }
}
