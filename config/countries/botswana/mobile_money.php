<?php
return [
    'version' => '1.0',
    'default_provider' => 'mobile_money_provider_1', // Generic name from your database
    
    'provider_mappings' => [
        // Provider 1 (e.g., Orange Botswana - but no hardcoded name in code)
        'mobile_money_provider_1' => [
            'type' => 'json',
            'bank_codes' => ['MM_BOTSWANA_001'], // These come from your banks config
            'field_mappings' => [
                'transaction_id' => 'transactionId',
                'customer_msisdn' => 'receiverAccount',
                'transaction_amount' => 'amount',
                'currency_code' => 'currency',
                'customer_reference' => 'reference'
            ],
            'static_fields' => [
                'api_version' => '2.0',
                'service_type' => 'P2P'
            ],
            'wrapper' => 'MobileMoneyRequest',
            'timestamp_field' => 'request_time',
            'timestamp_format' => 'Y-m-d\TH:i:sP'
        ],
        
        // Provider 2 (e.g., Mascom Botswana - still no hardcoded name)
        'mobile_money_provider_2' => [
            'type' => 'xml',
            'bank_codes' => ['MM_BOTSWANA_002'],
            'root_element' => 'MoneyTransfer',
            'field_mappings' => [
                'TransID' => 'transactionId',
                'Msisdn' => 'receiverAccount',
                'Amt' => 'amount',
                'Ccy' => 'currency',
                'Ref' => 'reference'
            ],
            'attributes' => [
                'version' => '{version}',
                'provider' => '{provider}'
            ]
        ]
    ],
    
    'reverse_mappings' => [
        'transaction_id' => 'transactionId',
        'customer_msisdn' => 'receiverAccount',
        'transaction_amount' => 'amount',
        'currency_code' => 'currency',
        'customer_reference' => 'reference',
        'TransID' => 'transactionId',
        'Msisdn' => 'receiverAccount',
        'Amt' => 'amount',
        'Ccy' => 'currency',
        'Ref' => 'reference'
    ]
];
