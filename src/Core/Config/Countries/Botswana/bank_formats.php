<?php
return [
    // Botswana bank message formats (no hardcoding in code)
    'bank_formats' => [
        'FNB_BOTSWANA' => 'legacy',
        'STANBIC_BOTSWANA' => 'iso20022',
        'ABSA_BOTSWANA' => 'iso20022',
        'BARCLAYS_BOTSWANA' => 'legacy',
        'BBS_BOTSWANA' => 'legacy',
        'ORANGE_BOTSWANA' => 'mobile_money',
        'MASCOM_BOTSWANA' => 'mobile_money'
    ],
    
    'default_format' => 'legacy'
];
