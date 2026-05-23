<?php
return [
    'version' => 'MT103',
    'format_type' => 'swift', // swift, block, delimited, fixed_width
    
    'format_detection' => [
        'swift' => ['/\{1:[A-Z0-9]+\}/', '/\{4:\n/'], 
        'delimited' => ['/^\|/', '/\|/'],
        'fixed_width' => ['/^[A-Z0-9]{100,}$/']
    ],
    
    'message_types' => [
        'MT103' => [
            'purposes' => ['PAYMENT', 'DISBURSEMENT', 'TRANSFER'],
            'default' => true
        ],
        'MT192' => [
            'purposes' => ['CANCEL', 'REVERSAL']
        ],
        'MT195' => [
            'purposes' => ['STATUS', 'INQUIRY']
        ]
    ],
    
    'field_definitions' => [
        'transaction_id' => [
            'source' => 'transactionId',
            'transformations' => ['trim'],
            'pad_length' => 16,
            'pad_side' => 'right'
        ],
        'amount' => [
            'source' => 'amount',
            'formatting' => 'amount_no_decimal',
            'transformations' => ['trim'],
            'pad_length' => 12,
            'pad_side' => 'right',
            'pad_char' => '0'
        ],
        'currency' => [
            'source' => 'currency',
            'transformations' => ['trim', 'upper']
        ],
        'sender_reference' => [
            'source' => 'reference',
            'transformations' => ['trim']
        ],
        'date' => [
            'source' => 'timestamp',
            'formatting' => 'date_format:Ymd'
        ]
    ],
    
    'block_definitions' => [
        '1' => [
            'fields' => [
                'basic_header' => [
                    'tag' => 'F01',
                    'composite' => [
                        'fields' => ['senderBankCode', 'receiverBankCode'],
                        'separator' => ''
                    ]
                ]
            ]
        ],
        '2' => [
            'fields' => [
                'message_type' => [
                    'tag' => 'I',
                    'source' => 'purpose'
                ]
            ]
        ],
        '4' => [
            'field_separator' => "\n",
            'tag_separator' => ':',
            'fields' => [
                'transaction_id' => ['tag' => '20'],
                'amount' => ['tag' => '32A'],
                'sender_name' => ['tag' => '50K'],
                'receiver_account' => ['tag' => '59']
            ]
        ]
    ],
    
    'internal_mappings' => [
        'transaction_id' => 'transactionId',
        'amount' => 'amount',
        'currency' => 'currency',
        'sender_name' => 'senderName',
        'receiver_account' => 'receiverAccount',
        'reference' => 'reference'
    ],
    
    'validation_rules' => [
        'swift' => [
            ['type' => 'regex', 'pattern' => '/\{1:.*\}/'],
            ['type' => 'contains', 'value' => '{4:']
        ],
        'delimited' => [
            ['type' => 'regex', 'pattern' => '/^[^|]+\|[^|]+\|[\d.]+/']
        ]
    ]
];
