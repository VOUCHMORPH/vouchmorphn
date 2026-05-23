<?php
return [
    'adapters' => [
        'iso20022' => [
            'class' => \VouchMorph\Infrastructure\MessageAdapters\Iso20022Adapter::class,
            'version' => 'pacs.008.001.08'
        ],
        'legacy' => [
            'class' => \VouchMorph\Infrastructure\MessageAdapters\LegacyAdapter::class,
            'version' => '2.3'
        ],
        'mobile_money' => [
            'class' => \VouchMorph\Infrastructure\MessageAdapters\MobileMoneyAdapter::class,
            'version' => '1.1'
        ],
        'rtgs' => [
            'class' => \VouchMorph\Infrastructure\MessageAdapters\RTGSAdapter::class,
            'version' => '2024'
        ]
    ],
    
    // Country-specific format mappings
    'country_formats' => [
        // Each country config will be loaded from its own file
    ]
];
