<?php
declare(strict_types=1);

/**
 * VOUCHMORPH UNIVERSAL FEE TYPE REGISTRY
 *
 * PURPOSE:
 * -------
 * Standardize fee classifications across all countries,
 * banks, mobile money operators and future participants.
 *
 * Fee codes NEVER change.
 * Fee names MAY be overridden by countries or participants.
 *
 * RANGES:
 * ------
 * F1-F20     = VouchMorph Core Fees
 * F21-F30    = Banking Industry Standard Fees
 * F31-F50    = Country Specific Fees
 * F51-F80    = Participant Specific Fees
 * F81-F90    = Tax Fees
 * F91-F100   = Reserved Future Use
 *
 * This file is the MASTER DICTIONARY.
 * Country fees.json only REFERENCE these codes.
 *
 * CORRECTION (2026-08-05): F7 'owner' was previously 'REGULATORY',
 * implying it was a government-mandated levy remitted to a regulator.
 * Confirmed with VouchMorph: F7 is VouchMorph's own internal fee --
 * "levy" is just its name, not its legal character. Owner corrected
 * to 'VOUCHMORPH' to match reality and the country config
 * (Countries/Botswana/fees.json's 'regulatory' section, which already
 * had this right). Description corrected to stop implying government
 * mandate. If this is ever presented to a central bank or auditor,
 * this correction matters: the previous wording could have been read
 * as VouchMorph retaining money legally owed to a regulator.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | CORE VOUCHMORPH FEES (F1-F20)
    |--------------------------------------------------------------------------
    */
    'F1' => [
        'name' => 'Swap Base Fee',
        'owner' => 'VOUCHMORPH',
        'type' => 'flat',
        'mandatory' => true,
        'description' => 'Core transaction fee for swap execution'
    ],
    'F2' => [
        'name' => 'FX Fee',
        'owner' => 'VOUCHMORPH',
        'type' => 'percentage',
        'mandatory' => false,
        'description' => 'Foreign exchange conversion fee'
    ],
    'F3' => [
        'name' => 'Cross Border Fee',
        'owner' => 'VOUCHMORPH',
        'type' => 'percentage',
        'mandatory' => false,
        'description' => 'Fee for cross-border transactions'
    ],
    'F4' => [
        'name' => 'Processing Fee',
        'owner' => 'VOUCHMORPH',
        'type' => 'flat',
        'mandatory' => true,
        'description' => 'Transaction processing and handling fee'
    ],
    'F5' => [
        'name' => 'Settlement Fee',
        'owner' => 'VOUCHMORPH',
        'type' => 'percentage',
        'mandatory' => true,
        'description' => 'Final settlement and reconciliation fee'
    ],
    'F6' => [
        'name' => 'VAT',
        'owner' => 'REGULATORY',
        'type' => 'vat',
        'mandatory' => false,
        'description' => 'Value Added Tax'
    ],
    'F7' => [
        'name' => 'Swap Levy',
        'owner' => 'VOUCHMORPH',  // CORRECTED: was 'REGULATORY'
        'type' => 'flat',
        'mandatory' => false,
        'description' => "VouchMorph's own internal swap levy. NOT government-mandated -- retained by VouchMorph, not remitted to a regulator, despite the name."
    ],
    'F8' => [
        'name' => 'Multi Source Fee',
        'owner' => 'VOUCHMORPH',
        'type' => 'per_extra_source',
        'mandatory' => false,
        'description' => 'Additional fee for multi-source transactions'
    ],
    'F9' => [
        'name' => 'Card Issuance Fee',
        'owner' => 'PARTICIPANT',
        'type' => 'flat',
        'mandatory' => false,
        'description' => 'One-time fee for card issuance'
    ],
    'F10' => [
        'name' => 'Retry Processing Fee',
        'owner' => 'VOUCHMORPH',
        'type' => 'flat',
        'mandatory' => false,
        'description' => 'Fee for processing retry attempts'
    ],
    'F11' => ['name' => 'Reserved Core 11', 'owner' => 'RESERVED'],
    'F12' => ['name' => 'Reserved Core 12', 'owner' => 'RESERVED'],
    'F13' => ['name' => 'Reserved Core 13', 'owner' => 'RESERVED'],
    'F14' => ['name' => 'Reserved Core 14', 'owner' => 'RESERVED'],
    'F15' => ['name' => 'Reserved Core 15', 'owner' => 'RESERVED'],
    'F16' => ['name' => 'Reserved Core 16', 'owner' => 'RESERVED'],
    'F17' => ['name' => 'Reserved Core 17', 'owner' => 'RESERVED'],
    'F18' => ['name' => 'Reserved Core 18', 'owner' => 'RESERVED'],
    'F19' => ['name' => 'Reserved Core 19', 'owner' => 'RESERVED'],
    'F20' => ['name' => 'Reserved Core 20', 'owner' => 'RESERVED'],

    /*
    |--------------------------------------------------------------------------
    | BANKING INDUSTRY STANDARD FEES (F21-F30)
    |--------------------------------------------------------------------------
    */
    'F21' => ['name' => 'Regulatory Fee', 'owner' => 'BANKING'],
    'F22' => ['name' => 'Central Bank Levy', 'owner' => 'BANKING'],
    'F23' => [
        'name' => 'National Switch Fee',
        'owner' => 'SWITCH',  // was implicitly 'BANKING' with no real definition; now used, see switch_routing section
        'type' => 'flat',
        'mandatory' => false,
        'description' => 'Fee charged by the national switch when a swap could not settle DIRECT and had to route through it. Allocated between source/destination institutions, never VouchMorph -- see country fees.json switch_routing block.'
    ],
    'F24' => ['name' => 'AML Screening Fee', 'owner' => 'BANKING'],
    'F25' => ['name' => 'Sanctions Screening Fee', 'owner' => 'BANKING'],
    'F26' => ['name' => 'Interchange Fee', 'owner' => 'BANKING'],
    'F27' => ['name' => 'Assessment Fee', 'owner' => 'BANKING'],
    'F28' => ['name' => 'Scheme Fee', 'owner' => 'BANKING'],
    'F29' => ['name' => 'Liquidity Fee', 'owner' => 'BANKING'],
    'F30' => ['name' => 'Treasury Fee', 'owner' => 'BANKING'],

    /*
    |--------------------------------------------------------------------------
    | COUNTRY SPECIFIC FEES (F31-F50)
    |--------------------------------------------------------------------------
    */
    'F31' => null, 'F32' => null, 'F33' => null, 'F34' => null, 'F35' => null,
    'F36' => null, 'F37' => null, 'F38' => null, 'F39' => null, 'F40' => null,
    'F41' => null, 'F42' => null, 'F43' => null, 'F44' => null, 'F45' => null,
    'F46' => null, 'F47' => null, 'F48' => null, 'F49' => null, 'F50' => null,

    /*
    |--------------------------------------------------------------------------
    | PARTICIPANT SPECIFIC FEES (F51-F80)
    |--------------------------------------------------------------------------
    */
    'F51' => null, 'F52' => null, 'F53' => null, 'F54' => null, 'F55' => null,
    'F56' => null, 'F57' => null, 'F58' => null, 'F59' => null, 'F60' => null,
    'F61' => null, 'F62' => null, 'F63' => null, 'F64' => null, 'F65' => null,
    'F66' => null, 'F67' => null, 'F68' => null, 'F69' => null, 'F70' => null,
    'F71' => null, 'F72' => null, 'F73' => null, 'F74' => null, 'F75' => null,
    'F76' => null, 'F77' => null, 'F78' => null, 'F79' => null, 'F80' => null,

    /*
    |--------------------------------------------------------------------------
    | TAX FEES (F81-F90)
    |--------------------------------------------------------------------------
    */
    'F81' => ['name' => 'VAT', 'owner' => 'TAX'],
    'F82' => ['name' => 'GST', 'owner' => 'TAX'],
    'F83' => ['name' => 'Withholding Tax', 'owner' => 'TAX'],
    'F84' => ['name' => 'Digital Service Tax', 'owner' => 'TAX'],
    'F85' => null, 'F86' => null, 'F87' => null, 'F88' => null, 'F89' => null, 'F90' => null,

    /*
    |--------------------------------------------------------------------------
    | FUTURE RESERVED (F91-F100)
    |--------------------------------------------------------------------------
    */
    'F91' => null, 'F92' => null, 'F93' => null, 'F94' => null, 'F95' => null,
    'F96' => null, 'F97' => null, 'F98' => null, 'F99' => null, 'F100' => null
];
