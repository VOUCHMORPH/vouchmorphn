<?php
return [
    'version' => 'pacs.008.001.08',
    'namespace' => 'urn:iso:std:iso:20022:tech:xsd:pacs.008.001.08',
    
    // Botswana-specific field mappings
    'mappings' => [
        'transactionId' => '//Document/FIToFICstmrCdtTrf/GrpHdr/MsgId',
        'creationDate' => '//Document/FIToFICstmrCdtTrf/GrpHdr/CreDtTm',
        'amount' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/IntrBkSttlmAmt',
        'currency' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/IntrBkSttlmAmt/@Ccy',
        'senderName' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/Dbtr/Nm',
        'senderAccount' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/DbtrAcct/Id/Othr/Id',
        'receiverName' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/Cdtr/Nm',
        'receiverAccount' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/CdtrAcct/Id/Othr/Id',
        'reference' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/RmtInf/Ustrd',
        'purpose' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/Purp/Prtry',
        'senderBankCode' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/DbtrAgt/FinInstnId/BICFI',
        'receiverBankCode' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/CdtrAgt/FinInstnId/BICFI',
        'settlementDate' => '//Document/FIToFICstmrCdtTrf/CdtTrfTxInf/SttlmDt'
    ],
    
    // Botswana bank codes mapping
    'bank_codes' => [
        'FIRNBWGX' => 'FNB_BOTSWANA',
        'SBICBWGX' => 'STANBIC_BOTSWANA',
        'ABSA BW' => 'ABSA_BOTSWANA',
        'BARCBWGX' => 'BARCLAYS_BOTSWANA'
    ],
    
    // Botswana-specific validation
    'validation' => [
        'max_amount' => 5000000.00, // BWP 5 million limit
        'min_amount' => 1.00,
        'allowed_currencies' => ['BWP', 'ZAR', 'USD'],
        'requires_purpose_code' => true,
        'purpose_codes' => [
            'SALA' => 'Salary Payment',
            'SUPP' => 'Supplier Payment',
            'DIVI' => 'Dividend Payment',
            'TAXS' => 'Tax Payment',
            'GOVT' => 'Government Payment'
        ]
    ],
    
    // Optional Botswana-specific elements
    'botswana_specific' => [
        'add_instruction_for_botswana_bank' => true,
        'settlement_method' => 'INDA', // Individual settlement
        'charge_bearer' => 'SLEV' // Service level
    ]
];
