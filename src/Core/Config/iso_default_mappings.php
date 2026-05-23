<?php
return [
    'version' => 'pacs.008.001.08',
    'namespace' => 'urn:iso:std:iso:20022:tech:xsd:pacs.008.001.08',
    
    // Default field mappings (can be overridden by country)
    'mappings' => [
        'transactionId' => '//Document/FIToFICstmrCdtTrf/GrpHdr/MsgId',
        'creationDate' => '//Document/FIToFICstmrCdtTrf/GrpHdr/CreDtTm',
        'amount' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/IntrBkSttlmAmt',
        'currency' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/IntrBkSttlmAmt/@Ccy',
        'senderName' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/Dbtr/Nm',
        'senderAccount' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/DbtrAcct/Id/IBAN',
        'receiverName' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/Cdtr/Nm',
        'receiverAccount' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/CdtrAcct/Id/IBAN',
        'reference' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/RmtInf/Ustrd',
        'purpose' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/Purp/Prtry',
        'senderBankCode' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/DbtrAgt/FinInstnId/BICFI',
        'receiverBankCode' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/CdtrAgt/FinInstnId/BICFI'
    ],
    
    // Required fields for validation
    'required_fields' => [
        'transactionId',
        'amount',
        'currency',
        'receiverAccount'
    ],
    
    // Optional fields
    'optional_fields' => [
        'reference',
        'purpose',
        'senderName',
        'receiverName'
    ],
    
    // XML validation rules
    'validation' => [
        'max_amount' => 999999999.99,
        'min_amount' => 0.01,
        'allowed_currencies' => ['BWP', 'ZAR', 'USD', 'EUR', 'GBP', 'NGN', 'KES', 'GHS']
    ]
];
