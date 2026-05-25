<?php
declare(strict_types=1);

namespace Infrastructure\MessageAdapters;

use Core\Transaction\InternalTransaction;

class Iso20022Adapter implements MessageAdapterInterface
{
    private string $countryCode;
    private array $countryConfig;
    private string $version;
    
    public function __construct(?string $countryCode = null) 
    {
        $this->countryCode = strtolower($countryCode ?? 'bw');
        
        // Load country-specific ISO mappings
        $configPath = __DIR__ . "/../../Core/Config/Countries/{$this->countryCode}/iso_mappings.php";
        
        if (file_exists($configPath)) {
            $this->countryConfig = require $configPath;
            $this->version = $this->countryConfig['version'] ?? 'pacs.008.001.08';
        } else {
            // Load default mappings
            $defaultPath = __DIR__ . '/../../Core/Config/iso_default_mappings.php';
            
            if (!file_exists($defaultPath)) {
                $this->countryConfig = [];
                $this->version = 'pacs.008.001.08';
            } else {
                $this->countryConfig = require $defaultPath;
                $this->version = $this->countryConfig['version'] ?? 'pacs.008.001.08';
            }
        }
    }
    
    public function toExternal(InternalTransaction $transaction): string 
    {
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
        
        $othr = $id->appendChild($xml->createElement('Othr'));
        $othr->appendChild($xml->createElement('Id', $transaction->getReceiverAccount()));
        
        // Reference
        if ($transaction->getReference()) {
            $rmtInf = $pmtInf->appendChild($xml->createElement('RmtInf'));
            $rmtInf->appendChild($xml->createElement('Ustrd', substr($transaction->getReference(), 0, 140)));
        }
        
        return $xml->saveXML();
    }
    
    public function toInternal(string $message): InternalTransaction 
    {
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
        
        // Extract data
        $transactionId = $this->extractNodeValue($xpath, $prefix . 'GrpHdr/ns:MsgId', $prefix);
        $amountNode = $xpath->query('//' . $prefix . 'IntrBkSttlmAmt');
        $amount = $amountNode && $amountNode->length > 0 ? (float)$amountNode->item(0)->nodeValue : 0;
        
        $currency = '';
        if ($amountNode && $amountNode->length > 0) {
            $currency = $amountNode->item(0)->getAttribute('Ccy');
        }
        
        $senderName = $this->extractNodeValue($xpath, '//' . $prefix . 'Dbtr/ns:Nm', $prefix);
        $receiverName = $this->extractNodeValue($xpath, '//' . $prefix . 'Cdtr/ns:Nm', $prefix);
        $receiverAccount = $this->extractNodeValue($xpath, '//' . $prefix . 'CdtrAcct/ns:Id/ns:Othr/ns:Id', $prefix);
        $reference = $this->extractNodeValue($xpath, '//' . $prefix . 'RmtInf/ns:Ustrd', $prefix);
        
        $transactionData = [
            'transactionId' => $transactionId ?: $this->generateTransactionId(),
            'amount' => $amount,
            'currency' => $currency ?: 'BWP',
            'reference' => $reference,
            'senderName' => $senderName,
            'receiverName' => $receiverName,
            'receiverAccount' => $receiverAccount,
            'messageFormat' => 'iso20022',
            'messageVersion' => $this->version,
            'countryCode' => $this->countryCode
        ];
        
        return new InternalTransaction($transactionData);
    }
    
    public function validate(string $message): bool 
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($message);
            
            $xpath = new \DOMXPath($dom);
            $ns = $this->detectNamespace($dom);
            if ($ns) {
                $xpath->registerNamespace('ns', $ns);
            }
            
            // Check required elements
            $msgIdNodes = $xpath->query('//*[local-name()="MsgId"]');
            if (!$msgIdNodes || $msgIdNodes->length === 0) {
                return false;
            }
            
            $amountNodes = $xpath->query('//*[local-name()="IntrBkSttlmAmt"]');
            if (!$amountNodes || $amountNodes->length === 0) {
                return false;
            }
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function getVersion(): string 
    {
        return $this->version;
    }
    
    private function detectNamespace(\DOMDocument $dom): ?string 
    {
        $root = $dom->documentElement;
        if ($root && $root->namespaceURI) {
            return $root->namespaceURI;
        }
        return $this->countryConfig['namespace'] ?? null;
    }
    
    private function extractNodeValue(\DOMXPath $xpath, string $query, string $prefix): string 
    {
        $nodes = $xpath->query($query);
        if ($nodes && $nodes->length > 0) {
            return $nodes->item(0)->nodeValue;
        }
        
        // Try without namespace prefix
        $altQuery = str_replace('ns:', '', $query);
        $nodes = $xpath->query($altQuery);
        if ($nodes && $nodes->length > 0) {
            return $nodes->item(0)->nodeValue;
        }
        
        return '';
    }
    
    private function generateTransactionId(): string 
    {
        return 'ISO_' . $this->countryCode . '_' . time() . '_' . bin2hex(random_bytes(4));
    }
}
