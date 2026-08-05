#!/usr/bin/env python3
"""
reconcile_swap_fees.py

Independently re-derives the fee math for one or more VouchMorph
swap/execute.php responses and asserts it matches what the API returned.

This does NOT trust fee_calculation_details' own internal totals -- it
recomputes every number from first principles (original_amount + the
individual slot amounts + the stated percentages) and flags any drift,
including sub-cent/sub-thebe rounding drift.

Usage:
    python3 reconcile_swap_fees.py response1.json response2.json ...
    python3 reconcile_swap_fees.py --dir /path/to/responses/

Exit code is non-zero if any file fails reconciliation, so this can be
wired into CI or a batch test runner.
"""

import sys
import json
import argparse
from pathlib import Path
from decimal import Decimal, ROUND_HALF_UP

# Tolerance for float/decimal drift in currency amounts (in currency units,
# e.g. 0.005 BWP). Adjust if your ledger uses a different minor-unit rounding.
EPSILON = Decimal("0.01")


def d(x):
    """Coerce a JSON number (int/float/None) to Decimal safely."""
    if x is None:
        return Decimal("0")
    return Decimal(str(x))


def close(a, b, eps=EPSILON):
    return abs(d(a) - d(b)) <= eps


class Reconciliation:
    def __init__(self, filepath):
        self.filepath = filepath
        self.errors = []
        self.warnings = []
        self.data = None
        self.fee = None

    def fail(self, msg):
        self.errors.append(msg)

    def warn(self, msg):
        self.warnings.append(msg)

    def load(self):
        with open(self.filepath) as f:
            raw = json.load(f)
        # Accept either the full curl response or just the .data payload
        self.data = raw.get("data", raw)
        self.fee = self.data.get("fee_calculation_details")
        if self.fee is None:
            self.fail("No fee_calculation_details found in response")
            return False
        return True

    def check_top_level_conservation(self):
        """original_amount == amount (net) + fee, and matches data.amount/original_amount."""
        orig = d(self.fee.get("original_amount"))
        net = d(self.fee.get("net_amount_source_currency"))
        fee_total = d(self.fee.get("total_fee"))

        if not close(orig, net + fee_total):
            self.fail(
                f"Conservation violated: original_amount ({orig}) != "
                f"net_amount_source_currency ({net}) + total_fee ({fee_total}) "
                f"[diff={orig - (net + fee_total)}]"
            )

        # Cross-check against the swap-level fields, if present
        top_amount = self.data.get("amount")
        top_orig = self.data.get("original_amount")
        top_fee = self.data.get("fee")
        if top_amount is not None and not close(top_amount, net):
            self.fail(f"data.amount ({top_amount}) != net_amount_source_currency ({net})")
        if top_orig is not None and not close(top_orig, orig):
            self.fail(f"data.original_amount ({top_orig}) != fee_calculation_details.original_amount ({orig})")
        if top_fee is not None and not close(top_fee, fee_total):
            self.fail(f"data.fee ({top_fee}) != fee_calculation_details.total_fee ({fee_total})")

    def check_breakdown_sums_to_total_fee(self):
        """Sum of all non-SYSTEM-pool slots in 'breakdown' should equal total_fee,
        since POOL_DIST is a derived/virtual line (F1 - F7), not new money."""
        breakdown = self.fee.get("breakdown", [])
        if not breakdown:
            self.warn("No 'breakdown' array present; skipping breakdown checks")
            return

        by_slot = {row["slot"]: d(row.get("amount")) for row in breakdown}

        # F1 should be the gross fee = total_fee
        f1 = by_slot.get("F1")
        total_fee = d(self.fee.get("total_fee"))
        if f1 is not None and not close(f1, total_fee):
            self.fail(f"Breakdown slot F1 ({f1}) != total_fee ({total_fee})")

        # POOL_DIST should equal F1 - F7 (levy), per the documented formula
        f7 = by_slot.get("F7", Decimal("0"))
        pool = by_slot.get("POOL_DIST")
        if pool is not None and f1 is not None:
            expected_pool = f1 - f7
            if not close(pool, expected_pool):
                self.fail(
                    f"POOL_DIST ({pool}) != F1 - F7 ({f1} - {f7} = {expected_pool})"
                )

        # The three revenue-split slots should sum to POOL_DIST exactly.
        # If a switch fee (F23) was charged on this transaction, CUT_SOURCE and
        # SHARE_DEST_BASE are expected to already be NET of their switch-fee
        # allocation, so F23 itself must be included in the sum to reconcile
        # against the pool (see check_switch_fee_allocation for the deeper check).
        split_slots = [s for s in ("CUT_PLATFORM", "CUT_SOURCE", "SHARE_DEST_BASE") if s in by_slot]
        split_sum = sum((by_slot[s] for s in split_slots), Decimal("0"))
        f23 = by_slot.get("F23")
        if f23 is not None:
            split_sum += f23
            split_slots = split_slots + ["F23"]
        if pool is not None and split_slots and not close(split_sum, pool):
            self.fail(
                f"Sum of {split_slots} ({split_sum}) != POOL_DIST ({pool})"
            )

        # Sanity: every slot amount should be non-negative
        for slot, amt in by_slot.items():
            if amt < 0:
                self.fail(f"Breakdown slot '{slot}' has negative amount: {amt}")

    def check_switch_fee_allocation(self):
        """When a swap was routed via the national switch (F23 present), the
        switch fee must be allocated between source and destination in
        proportion to their normal revenue_split percentages -- never a flat
        50/50 split, and never taken out of the platform's share.

        Expects (when present) revenue_split.source_institution and
        .destination_institution to carry 'gross_amount' (pre-switch-fee,
        i.e. percent * pool) and 'switch_fee_share', with 'amount' being the
        net figure actually settled (gross_amount - switch_fee_share).
        """
        breakdown = self.fee.get("breakdown", [])
        by_slot = {row["slot"]: d(row.get("amount")) for row in breakdown}
        f23 = by_slot.get("F23")
        rs = self.fee.get("revenue_split")

        if f23 is None:
            return  # DIRECT-mode swap, no switch fee involved -- nothing to check

        if not rs:
            self.warn("F23 present but no 'revenue_split' to validate allocation against")
            return

        src = rs.get("source_institution", {})
        dst = rs.get("destination_institution", {})
        src_pct = d(src.get("percent"))
        dst_pct = d(dst.get("percent"))
        combined_pct = src_pct + dst_pct

        if combined_pct == 0:
            self.fail("F23 present but source/destination percentages are both 0; cannot validate proportional allocation")
            return

        expected_src_share = (f23 * src_pct / combined_pct).quantize(Decimal("0.0001"), rounding=ROUND_HALF_UP)
        expected_dst_share = (f23 * dst_pct / combined_pct).quantize(Decimal("0.0001"), rounding=ROUND_HALF_UP)

        actual_src_share = d(src.get("switch_fee_share")) if src.get("switch_fee_share") is not None else None
        actual_dst_share = d(dst.get("switch_fee_share")) if dst.get("switch_fee_share") is not None else None

        if actual_src_share is not None and not close(actual_src_share, expected_src_share, eps=Decimal("0.001")):
            self.fail(
                f"source_institution.switch_fee_share ({actual_src_share}) != proportional "
                f"share of F23 ({src_pct}/{combined_pct} of {f23} = {expected_src_share})"
            )
        if actual_dst_share is not None and not close(actual_dst_share, expected_dst_share, eps=Decimal("0.001")):
            self.fail(
                f"destination_institution.switch_fee_share ({actual_dst_share}) != proportional "
                f"share of F23 ({dst_pct}/{combined_pct} of {f23} = {expected_dst_share})"
            )

        # Confirm net amount = gross - switch_fee_share for each party, and that
        # neither party's switch_fee_share was silently taken from platform instead.
        for label, entry in (("source_institution", src), ("destination_institution", dst)):
            gross = entry.get("gross_amount")
            share = entry.get("switch_fee_share")
            net = entry.get("amount")
            if gross is not None and share is not None and net is not None:
                if not close(d(net), d(gross) - d(share)):
                    self.fail(
                        f"{label}: amount ({net}) != gross_amount - switch_fee_share "
                        f"({gross} - {share} = {d(gross) - d(share)})"
                    )

        platform = rs.get("platform", {})
        if platform.get("switch_fee_share") not in (None, 0, "0"):
            self.fail(
                f"platform.switch_fee_share is {platform.get('switch_fee_share')}, expected 0/absent "
                "-- platform's cut must not absorb the switch fee"
            )

        # Total allocated to source + destination should equal F23 exactly
        if actual_src_share is not None and actual_dst_share is not None:
            total_allocated = actual_src_share + actual_dst_share
            if not close(total_allocated, f23, eps=Decimal("0.001")):
                self.fail(
                    f"source_institution.switch_fee_share + destination_institution.switch_fee_share "
                    f"({total_allocated}) != F23 total ({f23})"
                )

    def check_revenue_split_percentages(self):
        """Recompute platform/source/destination amounts from their stated
        percentages against net_distributable_pool and compare to the amounts
        the API actually returned."""
        rs = self.fee.get("revenue_split")
        if not rs:
            self.warn("No 'revenue_split' present; skipping percentage checks")
            return

        pool = d(rs.get("net_distributable_pool"))

        for key in ("platform", "source_institution", "destination_institution"):
            entry = rs.get(key)
            if not entry:
                continue
            pct = d(entry.get("percent"))
            expected = (pool * pct / Decimal("100")).quantize(
                Decimal("0.01"), rounding=ROUND_HALF_UP
            )
            # On switch-routed swaps, source/destination 'amount' is NET of their
            # switch_fee_share, so percent*pool should be checked against
            # 'gross_amount' if present, falling back to 'amount' otherwise
            # (i.e. DIRECT-mode swaps, or platform, which is never reduced by F23).
            compare_field = "gross_amount" if entry.get("gross_amount") is not None else "amount"
            stated_amt = d(entry.get(compare_field))
            if not close(stated_amt, expected, eps=Decimal("0.02")):
                self.fail(
                    f"revenue_split.{key}: {pct}% of pool {pool} should be "
                    f"~{expected}, but API returned {compare_field}={stated_amt}"
                )

        # Percentages (excluding levy, which sits outside the pool) should sum to 100
        pct_sum = sum(
            d(rs[k]["percent"])
            for k in ("platform", "source_institution", "destination_institution")
            if rs.get(k) and rs[k].get("percent") is not None
        )
        if pct_sum and not close(pct_sum, Decimal("100"), eps=Decimal("0.01")):
            self.fail(f"revenue_split percentages sum to {pct_sum}, expected 100")

    def check_levy(self):
        """levy_fees_total should equal the sum of the named levy_slots' amounts."""
        rs = self.fee.get("revenue_split")
        breakdown = self.fee.get("breakdown", [])
        if not rs or not breakdown:
            return
        by_slot = {row["slot"]: d(row.get("amount")) for row in breakdown}
        levy_slots = rs.get("levy_slots", [])
        levy_total_stated = d(rs.get("levy_fees_total"))
        levy_total_computed = sum((by_slot.get(s, Decimal("0")) for s in levy_slots), Decimal("0"))
        if not close(levy_total_stated, levy_total_computed):
            self.fail(
                f"levy_fees_total ({levy_total_stated}) != sum of levy_slots "
                f"{levy_slots} ({levy_total_computed})"
            )

    def check_atomic_commit(self):
        commit = self.data.get("atomic_commit", {})
        if commit and commit.get("status") != "committed":
            self.fail(f"atomic_commit.status is '{commit.get('status')}', expected 'committed'")

        settlement = self.data.get("settlement", {})
        net = d(self.fee.get("net_amount_source_currency"))
        if settlement and settlement.get("amount") is not None:
            if not close(settlement["amount"], net):
                self.fail(
                    f"settlement.amount ({settlement['amount']}) != "
                    f"net_amount_source_currency ({net})"
                )

    def check_precision(self):
        """Flag any split amount with more than 2 decimal places, since that's
        a common source of silent rounding drift downstream in a 2-decimal
        ledger (e.g. BWP thebe)."""
        breakdown = self.fee.get("breakdown", [])
        for row in breakdown:
            amt = row.get("amount")
            if amt is None:
                continue
            amt_dec = d(amt)
            # more than 2 decimal digits?
            if amt_dec != amt_dec.quantize(Decimal("0.01")):
                self.warn(
                    f"Slot '{row['slot']}' has sub-cent precision ({amt_dec}); "
                    "confirm downstream ledger rounding is deterministic"
                )

    def run(self):
        if not self.load():
            return self
        self.check_top_level_conservation()
        self.check_breakdown_sums_to_total_fee()
        self.check_switch_fee_allocation()
        self.check_revenue_split_percentages()
        self.check_levy()
        self.check_atomic_commit()
        self.check_precision()
        return self


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("files", nargs="*", help="swap response JSON files")
    parser.add_argument("--dir", help="directory of *.json response files to check")
    args = parser.parse_args()

    filepaths = [Path(f) for f in args.files]
    if args.dir:
        filepaths += sorted(Path(args.dir).glob("*.json"))

    if not filepaths:
        parser.error("Provide at least one file, or --dir with *.json files in it")

    any_failed = False
    for fp in filepaths:
        r = Reconciliation(fp).run()
        status = "PASS" if not r.errors else "FAIL"
        print(f"\n=== {fp}  [{status}] ===")
        for e in r.errors:
            print(f"  ERROR:   {e}")
        for w in r.warnings:
            print(f"  WARNING: {w}")
        if not r.errors and not r.warnings:
            print("  All checks passed, no warnings.")
        any_failed = any_failed or bool(r.errors)

    print()
    sys.exit(1 if any_failed else 0)


if __name__ == "__main__":
    main()
