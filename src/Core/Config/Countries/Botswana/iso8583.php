<?php
return [
    'version' => '1993',  // Botswana uses 1993 version
    'add_length_header' => true,
    
    'mti_definitions' => [
        '0200' => [
            'purposes' => ['ATM_WITHDRAWAL', 'POS_PURCHASE', 'CARD_PAYMENT'],
            'function' => 'Request',
            'origin' => 'Acquirer',
            'purpose' => 'PAYMENT',
            'default' => true
        ],
        '0210' => [
            'purposes' => ['ATM_WITHDRAWAL_RESPONSE'],
            'function' => 'Response',
            'origin' => 'Issuer',
            'purpose' => 'PAYMENT'
        ]
    ],
    
    'field_definitions' => [
        // Same as default, but with Botswana-specific variations
        2 => ['type' => 'n..', 'length' => 19, 'name' => 'PAN'],
        3 => ['type' => 'n', 'length' => 6, 'name' => 'Processing Code'],
        4 => ['type' => 'n', 'length' => 12, 'name' => 'Amount (BWP)'],
        7 => ['type' => 'n', 'length' => 10, 'name' => 'Transmission Date & Time'],
        11 => ['type' => 'n', 'length' => 6, 'name' => 'System Trace Audit Number'],
        12 => ['type' => 'n', 'length' => 6, 'name' => 'Local Time (Botswana)'],
        13 => ['type' => 'n', 'length' => 4, 'name' => 'Local Date (Botswana)'],
        14 => ['type' => 'n', 'length' => 4, 'name' => 'Expiration Date'],
        39 => ['type' => 'n', 'length' => 2, 'name' => 'Response Code'],
        41 => ['type' => 'ans', 'length' => 8, 'name' => 'Terminal ID (Botswana)'],
        42 => ['type' => 'ans', 'length' => 15, 'name' => 'Merchant ID'],
        49 => ['type' => 'n', 'length' => 3, 'name' => 'Currency Code BWP=072']
    ],
    
    'field_mappings' => [
        2 => ['source' => 'cardNumber', 'transformations' => ['strip_non_numeric']],
        3 => ['static_value' => '001000'],  // Purchase with cash
        4 => ['source' => 'amount', 'transformations' => ['pad_left_zeros']],
        7 => ['composite' => ['fields' => ['timestamp'], 'format' => 'yyyymmddhhmmss']],
        11 => ['source' => 'transactionId', 'transformations' => ['extract_last_6']],
        41 => ['source' => 'terminalId'],
        49 => ['static_value' => '072']  // BWP currency code
    ],
    
    'internal_mappings' => [
        2 => 'cardNumber',
        4 => 'amount',
        11 => 'transactionId',
        37 => 'referenceNumber',
        39 => 'responseCode',
        41 => 'terminalId',
        49 => 'currency'
    ],
    
    'response_codes' => [
        '00' => 'Approved',
        '51' => 'Insufficient funds in BWP',
        '54' => 'Card expired',
        '55' => 'PIN incorrect',
        '91' => 'Bank of Botswana offline'
    ],
    
    'validation_rules' => [
        ['type' => 'length', 'min' => 25, 'max' => 3000],
        ['type' => 'mti_valid'],
        ['type' => 'currency_valid', 'allowed' => ['072']]  // Only BWP
    ]
];
