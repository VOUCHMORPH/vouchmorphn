╔════════════════════════════════════════════════════════════╗
║     ATM ROUNDING LOGIC TEST - calculateFeesWithDetails    ║
╚════════════════════════════════════════════════════════════╝

========================================
TEST: VOUCHER + CASHOUT (Full delivery, no rounding)
========================================
Input: feeType=CASHOUT, amount=1000, asset_type=VOUCHER
  [FULL DELIVERY] VOUCHER - amount: 890 BWP

  Mathematical calculation:
    Amount_1: 1000 BWP
    F1 (fee): 10 BWP
    Amount_2: 990 BWP
    M (multiplier): N/A (FULL DELIVERY)
    Amount_4 (dispensable): 890
    Remainder_1: 0

Result:
  dispensable_amount: 890
  remainder_balance: 0
  is_full_delivery: true
  is_voucher: true
  is_deposit: false

Expected: dispensable=890, remainder=0
Status: ✅ PASSED

... (more tests) ...

╔════════════════════════════════════════════════════════════╗
║                     TEST SUMMARY                          ║
╚════════════════════════════════════════════════════════════╝
  Passed: 10 / 10
  ✅ ALL TESTS PASSED
