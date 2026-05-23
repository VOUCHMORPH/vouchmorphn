<?php
return [
    'version' => '1987',
    'add_length_header' => true,
    
    // MTI Definitions (Message Type Indicators)
    'mti_definitions' => [
        '0100' => [
            'purposes' => ['AUTHORIZATION', 'AUTH_CHECK'],
            'function' => 'Request',
            'origin' => 'Acquirer',
            'purpose' => 'AUTHORIZATION'
        ],
        '0110' => [
            'purposes' => ['AUTHORIZATION_RESPONSE'],
            'function' => 'Response',
            'origin' => 'Issuer',
            'purpose' => 'AUTHORIZATION'
        ],
        '0200' => [
            'purposes' => ['PAYMENT', 'DISBURSEMENT', 'WITHDRAWAL'],
            'function' => 'Request',
            'origin' => 'Acquirer',
            'purpose' => 'PAYMENT',
            'default' => true
        ],
        '0210' => [
            'purposes' => ['PAYMENT_RESPONSE'],
            'function' => 'Response',
            'origin' => 'Issuer',
            'purpose' => 'PAYMENT'
        ],
        '0400' => [
            'purposes' => ['REVERSAL', 'CANCEL'],
            'function' => 'Request',
            'origin' => 'Acquirer',
            'purpose' => 'REVERSAL'
        ],
        '0800' => [
            'purposes' => ['NETWORK_CHECK', 'KEY_EXCHANGE'],
            'function' => 'Request',
            'origin' => 'Acquirer',
            'purpose' => 'ADMIN'
        ]
    ],
    
    // Field Definitions (DE-1 to DE-128)
    'field_definitions' => [
        2 => ['type' => 'n..', 'length' => 19, 'name' => 'PAN'],
        3 => ['type' => 'n', 'length' => 6, 'name' => 'Processing Code'],
        4 => ['type' => 'n', 'length' => 12, 'name' => 'Amount'],
        7 => ['type' => 'n', 'length' => 10, 'name' => 'Transmission Date & Time'],
        11 => ['type' => 'n', 'length' => 6, 'name' => 'System Trace Audit Number'],
        12 => ['type' => 'n', 'length' => 6, 'name' => 'Local Time'],
        13 => ['type' => 'n', 'length' => 4, 'name' => 'Local Date'],
        14 => ['type' => 'n', 'length' => 4, 'name' => 'Expiration Date'],
        18 => ['type' => 'n', 'length' => 4, 'name' => 'Merchant Type'],
        22 => ['type' => 'n', 'length' => 3, 'name' => 'POS Entry Mode'],
        25 => ['type' => 'n', 'length' => 2, 'name' => 'POS Condition Code'],
        32 => ['type' => 'n..', 'length' => 11, 'name' => 'Acquiring Institution Code'],
        33 => ['type' => 'n..', 'length' => 11, 'name' => 'Forwarding Institution Code'],
        35 => ['type' => 'n..', 'length' => 37, 'name' => 'Track 2 Data'],
        37 => ['type' => 'an', 'length' => 12, 'name' => 'Retrieval Reference Number'],
        38 => ['type' => 'an', 'length' => 6, 'name' => 'Authorization Code'],
        39 => ['type' => 'n', 'length' => 2, 'name' => 'Response Code'],
        41 => ['type' => 'ans', 'length' => 8, 'name' => 'Terminal ID'],
        42 => ['type' => 'ans', 'length' => 15, 'name' => 'Merchant ID'],
        43 => ['type' => 'ans', 'length' => 40, 'name' => 'Card Acceptor Name'],
        49 => ['type' => 'n', 'length' => 3, 'name' => 'Currency Code'],
        52 => ['type' => 'n', 'length' => 8, 'name' => 'PIN Block'],
        53 => ['type' => 'n', 'length' => 16, 'name' => 'Security Control Info'],
        55 => ['type' => 'n...', 'length' => 255, 'name' => 'EMV Data'],
        56 => ['type' => 'n...', 'length' => 999, 'name' => 'Reserved for ISO'],
        60 => ['type' => 'n...', 'length' => 999, 'name' => 'Private Reserved'],
        61 => ['type' => 'n...', 'length' => 999, 'name' => 'Private Reserved'],
        62 => ['type' => 'n...', 'length' => 999, 'name' => 'Private Reserved'],
        63 => ['type' => 'n...', 'length' => 999, 'name' => 'Private Reserved']
    ],
    
    // Mappings from InternalTransaction to ISO8583 fields
    'field_mappings' => [
        2 => [
            'source' => 'senderAccount',
            'transformations' => ['strip_non_numeric']
        ],
        3 => [
            'static_value' => '001000'  // Purchase transaction
        ],
        4 => [
            'source' => 'amount',
            'transformations' => ['pad_left_zeros']
        ],
        7 => [
            'composite' => [
                'fields' => ['timestamp'],
                'format' => 'yyyymmdd_hhmmss_to_mmddyyyyhhmmss'
            ]
        ],
        11 => [
            'source' => 'transactionId',
            'transformations' => ['extract_last_6']
        ],
        14 => [
            'source' => 'cardExpiry',
            'transformations' => ['yyyymmdd_to_yymm']
        ],
        18 => [
            'source' => 'merchantCategory',
            'default' => '5122'
        ],
        22 => [
            'static_value' => '051'  // Chip fallback
        ],
        37 => [
            'source' => 'reference',
            'transformations' => ['trim']
        ],
        39 => [
            'source' => 'responseCode'  // For responses
        ],
        41 => [
            'source' => 'terminalId'
        ],
        42 => [
            'source' => 'merchantId'
        ],
        49 => [
            'source' => 'currency',
            'transformations' => ['currency_to_numeric']
        ]
    ],
    
    // Mapping from ISO8583 fields to InternalTransaction
    'internal_mappings' => [
        2 => 'senderAccount',
        3 => 'processingCode',
        4 => 'amount',
        37 => 'reference',
        38 => 'authorizationCode',
        39 => 'responseCode',
        41 => 'terminalId',
        42 => 'merchantId',
        43 => 'merchantName',
        49 => 'currency'
    ],
    
    // Response codes and their meanings
    'response_codes' => [
        '00' => 'Approved',
        '01' => 'Refer to issuer',
        '02' => 'Refer to issuer special condition',
        '03' => 'Invalid merchant',
        '04' => 'Pick-up card',
        '05' => 'Do not honor',
        '06' => 'Error',
        '07' => 'Pick-up card special condition',
        '08' => 'Honor with ID',
        '09' => 'Request in progress',
        '10' => 'Approved partial',
        '11' => 'Approved VIP',
        '12' => 'Invalid transaction',
        '13' => 'Invalid amount',
        '14' => 'Invalid card number',
        '15' => 'No such issuer',
        '16' => 'Approved update track 3',
        '17' => 'Customer cancellation',
        '18' => 'Customer dispute',
        '19' => 'Re-enter transaction',
        '20' => 'Invalid response',
        '21' => 'No action taken',
        '22' => 'Suspected malfunction',
        '23' => 'Unacceptable transaction fee',
        '24' => 'File update not supported',
        '25' => 'Unable to locate record',
        '26' => 'Duplicate record',
        '27' => 'File update edit error',
        '28' => 'File update file locked',
        '29' => 'File update failed',
        '30' => 'Format error',
        '31' => 'Bank not supported',
        '32' => 'Completed partially',
        '33' => 'Expired card',
        '34' => 'Suspected fraud',
        '35' => 'Contact acquirer',
        '36' => 'Restricted card',
        '37' => 'Call acquirer security',
        '38' => 'PIN tries exceeded',
        '39' => 'No credit account',
        '40' => 'Function not supported',
        '41' => 'Lost card',
        '42' => 'No universal account',
        '43' => 'Stolen card',
        '44' => 'No investment account',
        '45' => 'Account closed',
        '46' => 'Identification required',
        '47' => 'Identification cross-check required',
        '48' => 'Network error - retry later',
        '49' => 'No checking account',
        '50' => 'No savings account',
        '51' => 'Insufficient funds',
        '52' => 'No checking account',
        '53' => 'No savings account',
        '54' => 'Expired card',
        '55' => 'Incorrect PIN',
        '56' => 'No card record',
        '57' => 'Transaction not permitted to cardholder',
        '58' => 'Transaction not permitted to terminal',
        '59' => 'Suspected fraud',
        '60' => 'Contact acquirer',
        '61' => 'Exceeds withdrawal limit',
        '62' => 'Restricted card',
        '63' => 'Security violation',
        '64' => 'Original amount incorrect',
        '65' => 'Exceeds withdrawal frequency',
        '66' => 'Call acquirer security',
        '67' => 'Hard capture',
        '68' => 'Response received too late',
        '69' => 'Advice received too late',
        '70' => 'Advice received too late',
        '71' => 'Advice received too late',
        '75' => 'PIN tries exceeded',
        '76' => 'Invalid/non-existent participant',
        '77' => 'Invalid/non-existent participant',
        '78' => 'Invalid/non-existent participant',
        '79' => 'Life cycle violation',
        '80' => 'Life cycle violation',
        '81' => 'Invalid card data',
        '82' => 'Invalid issuer',
        '83' => 'Invalid card data',
        '84' => 'Invalid card data',
        '85' => 'Card acceptor contact acquirer',
        '86' => 'Card acceptor contact acquirer',
        '87' => 'Card acceptor contact acquirer',
        '88' => 'Cryptographic failure',
        '89' => 'Cryptographic failure',
        '90' => 'Cut-off in progress',
        '91' => 'Issuer unavailable',
        '92' => 'Financial institution unavailable',
        '93' => 'Transaction cannot be completed',
        '94' => 'Duplicate transmission',
        '95' => 'Reconcile error',
        '96' => 'System malfunction',
        '97' => 'POS security alert',
        '98' => 'Exceeds cash limit',
        '99' => 'Exceeds cash limit'
    ],
    
    // Validation rules
    'validation_rules' => [
        ['type' => 'length', 'min' => 20, 'max' => 4000],
        ['type' => 'mti_valid']
    ]
];
