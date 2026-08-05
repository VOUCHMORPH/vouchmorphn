#!/usr/bin/env python3
"""
swap_test_harness.py
=====================
Sends real requests to your swap/execute.php endpoint covering every swap
type in the TEST_MATRIX.md, validates response shape, and reports pass/fail.

This is a HARNESS, not a mock -- it makes real HTTP calls against whatever
BASE_URL you point it at. Point it at a STAGING environment, never
production, unless you specifically want to move real test money through
real institution accounts.

USAGE
-----
    export VOUCHMORPH_BASE_URL="https://staging.vouchmorph.example.com"
    export VOUCHMORPH_API_KEY="your-staging-api-key"
    export VOUCHMORPH_COUNTRY_CODE="BW"

    python3 swap_test_harness.py                  # run everything
    python3 swap_test_harness.py --dry-run         # print requests, send nothing
    python3 swap_test_harness.py --only standard_deposit,cashout_atm
    python3 swap_test_harness.py --list            # show all test IDs
    python3 swap_test_harness.py --report-json results.json

CONFIGURATION
-------------
Edit the INSTITUTIONS / IDENTIFIERS block below to match real (staging!)
institution codes and account/wallet identifiers you actually have test
funds in. The placeholders will fail immediately against a real backend.
"""

import argparse
import json
import os
import sys
import time
import uuid
from dataclasses import dataclass, field
from typing import Any, Callable, Optional
from urllib import request, error

# ============================================================
# CONFIGURATION -- edit these for your actual staging environment
# ============================================================
BASE_URL = os.environ.get("VOUCHMORPH_BASE_URL", "https://CHANGE_ME.example.com")
API_KEY = os.environ.get("VOUCHMORPH_API_KEY", "CHANGE_ME")
COUNTRY_CODE = os.environ.get("VOUCHMORPH_COUNTRY_CODE", "BW")
CURRENCY = os.environ.get("VOUCHMORPH_CURRENCY", "BWP")

# Institution codes -- must be real participants in your country config
INSTITUTION_A = os.environ.get("VOUCHMORPH_TEST_INST_A", "ZURUBANK")
INSTITUTION_B = os.environ.get("VOUCHMORPH_TEST_INST_B", "SACCUSSALIS")

# Identifiers -- must correspond to real (staging) accounts with test funds
SOURCE_IDENTIFIER = os.environ.get("VOUCHMORPH_TEST_SOURCE_ID", "10000001")
DEST_IDENTIFIER = os.environ.get("VOUCHMORPH_TEST_DEST_ID", "10000002")
TEST_PHONE = os.environ.get("VOUCHMORPH_TEST_PHONE", "+26771234567")
TEST_NATIONAL_ID = os.environ.get("VOUCHMORPH_TEST_NATIONAL_ID", "123456789")

TIMEOUT_SECONDS = 30


# ============================================================
# HTTP helper
# ============================================================
def send(payload: dict, dry_run: bool = False, skip_country_header: bool = False) -> dict:
    """POST to execute.php, return {'http_code', 'body', 'error'}."""
    url = f"{BASE_URL.rstrip('/')}/api/v1/swap/execute.php"
    body = json.dumps(payload).encode("utf-8")

    if dry_run:
        return {"http_code": None, "body": None, "error": None, "dry_run_payload": payload}

    headers = {
        "Content-Type": "application/json",
        "X-API-Key": API_KEY,
    }
    if not skip_country_header:
        headers["X-Country-Code"] = COUNTRY_CODE

    req = request.Request(url, data=body, method="POST", headers=headers)
    try:
        with request.urlopen(req, timeout=TIMEOUT_SECONDS) as resp:
            raw = resp.read().decode("utf-8")
            return {"http_code": resp.status, "body": json.loads(raw) if raw else {}, "error": None}
    except error.HTTPError as e:
        raw = e.read().decode("utf-8")
        try:
            body = json.loads(raw) if raw else {}
        except json.JSONDecodeError:
            body = {"_raw": raw}
        return {"http_code": e.code, "body": body, "error": str(e)}
    except Exception as e:  # network errors, timeouts, etc.
        return {"http_code": None, "body": None, "error": str(e)}


def ref(prefix: str) -> str:
    return f"{prefix}_{int(time.time())}_{uuid.uuid4().hex[:8]}"


# ============================================================
# Validation helpers
# ============================================================
def require_keys(body: dict, keys: list[str]) -> list[str]:
    """Returns list of missing keys (dotted paths supported, e.g. 'data.status')."""
    missing = []
    for key in keys:
        node = body
        for part in key.split("."):
            if isinstance(node, dict) and part in node:
                node = node[part]
            else:
                missing.append(key)
                break
    return missing


def get_path(body: dict, path: str, default=None):
    node = body
    for part in path.split("."):
        if isinstance(node, dict) and part in node:
            node = node[part]
        else:
            return default
    return node


# ============================================================
# Test case definitions
# ============================================================
@dataclass
class TestCase:
    id: str
    name: str
    matrix_ref: str
    build_payload: Callable[[dict], dict]  # takes context dict of prior results, returns payload
    validate: Callable[[dict, dict], list[str]]  # (response, context) -> list of failure reasons
    depends_on: Optional[str] = None  # another test id whose result must be in context
    skip_reason: Optional[str] = None  # set to skip with an explanation instead of running


def basic_success_checks(body: dict, expected_status_values: list[str]) -> list[str]:
    failures = []
    if body.get("success") is not True:
        failures.append(f"expected success=true, got {body.get('success')!r} (error: {body.get('error')})")
        return failures  # no point checking further fields
    data = body.get("data", {})
    status = data.get("status")
    if expected_status_values and status not in expected_status_values:
        failures.append(f"expected status in {expected_status_values}, got {status!r}")
    if not body.get("swap_reference"):
        failures.append("missing top-level swap_reference")
    return failures


TESTS: list[TestCase] = []


def register(tc: TestCase):
    TESTS.append(tc)
    return tc


# --- 1/2: STANDARD -> resolves to DEPOSIT / CASHOUT ---
register(TestCase(
    id="standard_resolves_deposit",
    name="STANDARD swap, delivery_method=DEPOSIT -> resolves to DEPOSIT",
    matrix_ref="#1",
    build_payload=lambda ctx: {
        "swap_type": "STANDARD",
        "reference": ref("TEST_STD_DEP"),
        "from_institution": INSTITUTION_A,
        "to_institution": INSTITUTION_B,
        "source_identifier": SOURCE_IDENTIFIER,
        "destination_identifier": DEST_IDENTIFIER,
        "destination_identifier_type": "account_number",
        "destination_asset_type": "ACCOUNT",
        "amount": 100,
        "currency": CURRENCY,
        "delivery_method": "DEPOSIT",
    },
    validate=lambda body, ctx: (
        basic_success_checks(body, ["success"])
        + require_keys(body, ["data.fee_calculation_details", "data.signature_chain.verification", "data.signature_chain.hold"])
    ),
))

register(TestCase(
    id="standard_resolves_cashout",
    name="STANDARD swap, delivery_method=ATM -> resolves to CASHOUT",
    matrix_ref="#2",
    build_payload=lambda ctx: {
        "swap_type": "STANDARD",
        "reference": ref("TEST_STD_CO"),
        "from_institution": INSTITUTION_A,
        "to_institution": INSTITUTION_B,
        "source_identifier": SOURCE_IDENTIFIER,
        "beneficiary_phone": TEST_PHONE,
        "amount": 100,
        "currency": CURRENCY,
        "delivery_method": "ATM",
    },
    validate=lambda body, ctx: (
        basic_success_checks(body, ["pending"])
        + require_keys(body, ["data.code_expiry"])
        + ([] if (get_path(body, "data.atm_code") or get_path(body, "data.swap_code")) else ["missing atm_code/swap_code"])
    ),
))

# --- 3/4: DEPOSIT ---
register(TestCase(
    id="deposit_account_to_account",
    name="DEPOSIT ACCOUNT -> ACCOUNT",
    matrix_ref="#3",
    build_payload=lambda ctx: {
        "swap_type": "DEPOSIT",
        "reference": ref("TEST_DEP_AA"),
        "from_institution": INSTITUTION_A,
        "to_institution": INSTITUTION_B,
        "source_identifier": SOURCE_IDENTIFIER,
        "destination_identifier": DEST_IDENTIFIER,
        "destination_identifier_type": "account_number",
        "destination_asset_type": "ACCOUNT",
        "amount": 50,
        "currency": CURRENCY,
    },
    validate=lambda body, ctx: (
        basic_success_checks(body, ["success"])
        + require_keys(body, ["data.deposit_reference", "data.settlement"])
        + ([] if get_path(body, "data.destination_asset_type") == "ACCOUNT" else ["destination_asset_type mismatch"])
    ),
))

register(TestCase(
    id="deposit_account_to_wallet",
    name="DEPOSIT ACCOUNT -> WALLET",
    matrix_ref="#4",
    build_payload=lambda ctx: {
        "swap_type": "DEPOSIT",
        "reference": ref("TEST_DEP_AW"),
        "from_institution": INSTITUTION_A,
        "to_institution": INSTITUTION_B,
        "source_identifier": SOURCE_IDENTIFIER,
        "destination_identifier": TEST_PHONE,
        "destination_identifier_type": "phone",
        "destination_asset_type": "WALLET",
        "amount": 50,
        "currency": CURRENCY,
    },
    validate=lambda body, ctx: (
        basic_success_checks(body, ["success"])
        + ([] if get_path(body, "data.destination_asset_type") == "WALLET" else ["destination_asset_type mismatch"])
    ),
))

# --- 5/6: CASHOUT ---
register(TestCase(
    id="cashout_atm",
    name="CASHOUT via ATM, normal amount",
    matrix_ref="#5",
    build_payload=lambda ctx: {
        "swap_type": "CASHOUT",
        "reference": ref("TEST_CO_ATM"),
        "from_institution": INSTITUTION_A,
        "to_institution": INSTITUTION_B,
        "source_identifier": SOURCE_IDENTIFIER,
        "beneficiary_phone": TEST_PHONE,
        "amount": 100,
        "currency": CURRENCY,
        "delivery_method": "ATM",
    },
    validate=lambda body, ctx: (
        basic_success_checks(body, ["pending"])
        + (lambda fcd: [] if fcd is None else (
            [] if round((fcd.get("dispensable_amount", 0) + fcd.get("remainder_balance", 0)), 2) == round(fcd.get("net_amount_destination_currency", fcd.get("dispensable_amount", 0) + fcd.get("remainder_balance", 0)), 2)
            else [f"dispensable+remainder ({fcd.get('dispensable_amount')}+{fcd.get('remainder_balance')}) doesn't reconcile against net destination amount"]
        ))(get_path(body, "data.fee_calculation_details"))
    ),
))

register(TestCase(
    id="cashout_below_min_denomination",
    name="CASHOUT amount below smallest ATM note -> should auto-switch to AGENT",
    matrix_ref="#6",
    build_payload=lambda ctx: {
        "swap_type": "CASHOUT",
        "reference": ref("TEST_CO_SMALL"),
        "from_institution": INSTITUTION_A,
        "to_institution": INSTITUTION_B,
        "source_identifier": SOURCE_IDENTIFIER,
        "beneficiary_phone": TEST_PHONE,
        "amount": 3,  # adjust below your actual smallest denomination + fee
        "currency": CURRENCY,
        "delivery_method": "ATM",
    },
    validate=lambda body, ctx: (
        basic_success_checks(body, ["pending"])
        + ([] if get_path(body, "data.delivery_method") == "AGENT" else
           [f"expected auto-switch to AGENT, got delivery_method={get_path(body, 'data.delivery_method')!r} -- adjust test amount to be below your actual smallest denomination"])
    ),
))

# --- 7/8: IDENTITY ---
register(TestCase(
    id="identity_phone",
    name="IDENTITY swap to phone (self-service claimable)",
    matrix_ref="#7",
    build_payload=lambda ctx: {
        "swap_type": "IDENTITY",
        "reference": ref("TEST_ID_PHONE"),
        "from_institution": INSTITUTION_A,
        "source_identifier": SOURCE_IDENTIFIER,
        "amount": 40,
        "currency": CURRENCY,
        "identity_type": "phone",
        "identity_value": TEST_PHONE,
        "beneficiary_phone": TEST_PHONE,
        "notification_phone": TEST_PHONE,
    },
    validate=lambda body, ctx: (
        basic_success_checks(body, ["pending_identity_confirmation"])
        + require_keys(body, ["data.expires_at"])
        + ([] if get_path(body, "data.claim_pin") else ["missing claim_pin in response"])
    ),
))

register(TestCase(
    id="identity_national_id",
    name="IDENTITY swap to national_id (agent-verifiable)",
    matrix_ref="#8",
    build_payload=lambda ctx: {
        "swap_type": "IDENTITY",
        "reference": ref("TEST_ID_NID"),
        "from_institution": INSTITUTION_A,
        "source_identifier": SOURCE_IDENTIFIER,
        "amount": 40,
        "currency": CURRENCY,
        "identity_type": "national_id",
        "identity_value": TEST_NATIONAL_ID,
        "notification_phone": TEST_PHONE,
    },
    validate=lambda body, ctx: (
        basic_success_checks(body, ["pending_identity_confirmation"])
        + ([] if any(m.get("type") == "agent_portal" for m in get_path(body, "data.access_methods", []) or [])
           else ["expected 'agent_portal' in access_methods for a document-based identity type"])
    ),
))

# --- 9/10: CONFIRM_IDENTITY (depends on identity_phone) ---
register(TestCase(
    id="confirm_identity_to_deposit",
    name="CONFIRM_IDENTITY -> DEPOSIT destination",
    matrix_ref="#9",
    depends_on="identity_phone",
    build_payload=lambda ctx: {
        "swap_type": "CONFIRM_IDENTITY",
        "swap_reference": get_path(ctx["identity_phone"], "body.data.swap_reference"),
        "pin": get_path(ctx["identity_phone"], "body.data.claim_pin"),
        "confirmed_by_type": "user",
        "destination_type": "DEPOSIT",
        "destination_institution": INSTITUTION_B,
        "destination_identifier": DEST_IDENTIFIER,
        "destination_identifier_type": "account_number",
        "destination_asset_type": "ACCOUNT",
    },
    validate=lambda body, ctx: (
        basic_success_checks(body, ["completed"])
        + ([] if get_path(body, "data.destination_type") == "DEPOSIT" else ["destination_type mismatch"])
    ),
))

register(TestCase(
    id="identity_phone_for_cashout_confirm",
    name="(setup) IDENTITY swap to phone, for the CASHOUT-confirm test below",
    matrix_ref="#10 setup",
    build_payload=lambda ctx: {
        "swap_type": "IDENTITY",
        "reference": ref("TEST_ID_PHONE2"),
        "from_institution": INSTITUTION_A,
        "source_identifier": SOURCE_IDENTIFIER,
        "amount": 40,
        "currency": CURRENCY,
        "identity_type": "phone",
        "identity_value": TEST_PHONE,
        "beneficiary_phone": TEST_PHONE,
        "notification_phone": TEST_PHONE,
    },
    validate=lambda body, ctx: basic_success_checks(body, ["pending_identity_confirmation"]),
))

register(TestCase(
    id="confirm_identity_to_cashout",
    name="CONFIRM_IDENTITY -> CASHOUT destination",
    matrix_ref="#10",
    depends_on="identity_phone_for_cashout_confirm",
    build_payload=lambda ctx: {
        "swap_type": "CONFIRM_IDENTITY",
        "swap_reference": get_path(ctx["identity_phone_for_cashout_confirm"], "body.data.swap_reference"),
        "pin": get_path(ctx["identity_phone_for_cashout_confirm"], "body.data.claim_pin"),
        "confirmed_by_type": "user",
        "destination_type": "CASHOUT",
        "destination_institution": INSTITUTION_B,
    },
    validate=lambda body, ctx: (
        basic_success_checks(body, ["completed"])
        + ([] if get_path(body, "data.destination_type") == "CASHOUT" else ["destination_type mismatch"])
    ),
))

register(TestCase(
    id="identity_phone_for_wrong_pin",
    name="(setup) IDENTITY swap to phone, for the wrong-PIN lockout test below",
    matrix_ref="#11 setup",
    build_payload=lambda ctx: {
        "swap_type": "IDENTITY",
        "reference": ref("TEST_ID_PHONE3"),
        "from_institution": INSTITUTION_A,
        "source_identifier": SOURCE_IDENTIFIER,
        "amount": 40,
        "currency": CURRENCY,
        "identity_type": "phone",
        "identity_value": TEST_PHONE,
        "beneficiary_phone": TEST_PHONE,
        "notification_phone": TEST_PHONE,
    },
    validate=lambda body, ctx: basic_success_checks(body, ["pending_identity_confirmation"]),
))

register(TestCase(
    id="confirm_identity_wrong_pin",
    name="CONFIRM_IDENTITY with deliberately wrong PIN -> expect rejection",
    matrix_ref="#11",
    depends_on="identity_phone_for_wrong_pin",
    build_payload=lambda ctx: {
        "swap_type": "CONFIRM_IDENTITY",
        "swap_reference": get_path(ctx["identity_phone_for_wrong_pin"], "body.data.swap_reference"),
        "pin": "000000",  # deliberately wrong
        "confirmed_by_type": "user",
        "destination_type": "DEPOSIT",
        "destination_institution": INSTITUTION_B,
        "destination_identifier": DEST_IDENTIFIER,
        "destination_identifier_type": "account_number",
        "destination_asset_type": "ACCOUNT",
    },
    validate=lambda body, ctx: (
        [] if body.get("success") is False and "pin" in json.dumps(body).lower()
        else [f"expected a PIN-related rejection, got success={body.get('success')}, body={body}"]
    ),
))

# --- 15-18: MULTI_DESTINATION ---
register(TestCase(
    id="multi_dest_all_bank",
    name="MULTI_DESTINATION -> all bank destinations",
    matrix_ref="#15",
    build_payload=lambda ctx: {
        "swap_type": "MULTI_DESTINATION",
        "reference": ref("TEST_MD_BANK"),
        "from_institution": INSTITUTION_A,
        "source_identifier": SOURCE_IDENTIFIER,
        "currency": CURRENCY,
        "destinations": [
            {"to_institution": INSTITUTION_B, "destination_identifier": DEST_IDENTIFIER,
             "destination_identifier_type": "account_number", "amount": 30, "delivery_method": "DEPOSIT"},
            {"to_institution": INSTITUTION_B, "destination_identifier": TEST_PHONE,
             "destination_identifier_type": "phone", "amount": 30, "delivery_method": "DEPOSIT"},
        ],
    },
    validate=lambda body, ctx: (
        basic_success_checks(body, ["success", "partial_success"])
        + ([] if get_path(body, "data.successful_destinations") == get_path(body, "data.total_destinations")
           else [f"not all destinations succeeded: {get_path(body, 'data.successful_destinations')}/{get_path(body, 'data.total_destinations')}"])
    ),
))

register(TestCase(
    id="multi_dest_all_identity",
    name="MULTI_DESTINATION -> all identity destinations",
    matrix_ref="#16",
    build_payload=lambda ctx: {
        "swap_type": "MULTI_DESTINATION",
        "reference": ref("TEST_MD_ID"),
        "from_institution": INSTITUTION_A,
        "source_identifier": SOURCE_IDENTIFIER,
        "currency": CURRENCY,
        "destinations": [
            {"identity_type": "phone", "identity_value": TEST_PHONE, "amount": 20, "delivery_method": "DEPOSIT"},
            {"identity_type": "national_id", "identity_value": TEST_NATIONAL_ID, "amount": 20, "delivery_method": "DEPOSIT"},
        ],
    },
    validate=lambda body, ctx: (
        basic_success_checks(body, ["success", "partial_success"])
        + ([] if all(d.get("status") == "pending_identity_confirmation" for d in get_path(body, "data.destinations", []) or [])
           else ["not all identity destinations show pending_identity_confirmation"])
    ),
))

register(TestCase(
    id="multi_dest_mixed",
    name="MULTI_DESTINATION -> mixed bank + identity (the specific scenario asked for)",
    matrix_ref="#17",
    build_payload=lambda ctx: {
        "swap_type": "MULTI_DESTINATION",
        "reference": ref("TEST_MD_MIX"),
        "from_institution": INSTITUTION_A,
        "source_identifier": SOURCE_IDENTIFIER,
        "currency": CURRENCY,
        "destinations": [
            {"to_institution": INSTITUTION_B, "destination_identifier": DEST_IDENTIFIER,
             "destination_identifier_type": "account_number", "amount": 30, "delivery_method": "DEPOSIT"},
            {"identity_type": "phone", "identity_value": TEST_PHONE, "amount": 20, "delivery_method": "DEPOSIT"},
        ],
    },
    validate=lambda body, ctx: (
        basic_success_checks(body, ["success", "partial_success"])
        + (lambda dests: [] if (
            any(d.get("type") == "bank" and d.get("status") == "success" for d in dests)
            and any(d.get("type") == "identity" and d.get("status") == "pending_identity_confirmation" for d in dests)
        ) else [f"expected one successful bank destination and one pending identity destination, got: {dests}"])(get_path(body, "data.destinations", []) or [])
    ),
))

register(TestCase(
    id="multi_dest_one_invalid",
    name="MULTI_DESTINATION with one deliberately invalid destination -> expect partial_success",
    matrix_ref="#18",
    build_payload=lambda ctx: {
        "swap_type": "MULTI_DESTINATION",
        "reference": ref("TEST_MD_BAD"),
        "from_institution": INSTITUTION_A,
        "source_identifier": SOURCE_IDENTIFIER,
        "currency": CURRENCY,
        "destinations": [
            {"to_institution": INSTITUTION_B, "destination_identifier": DEST_IDENTIFIER,
             "destination_identifier_type": "account_number", "amount": 30, "delivery_method": "DEPOSIT"},
            {"to_institution": "NONEXISTENT_INSTITUTION_XYZ", "destination_identifier": "999999",
             "destination_identifier_type": "account_number", "amount": 20, "delivery_method": "DEPOSIT"},
        ],
    },
    validate=lambda body, ctx: (
        [] if get_path(body, "data.status") == "partial_success" and get_path(body, "data.failed_destinations") == 1
        else [f"expected status=partial_success with 1 failed destination, got status={get_path(body, 'data.status')} failed={get_path(body, 'data.failed_destinations')}"]
    ),
))

# --- 25/26/27: auth / country resolution edge cases ---
register(TestCase(
    id="missing_country_header",
    name="Missing X-Country-Code header -> expect 400",
    matrix_ref="#25",
    build_payload=lambda ctx: {
        "swap_type": "DEPOSIT",
        "reference": ref("TEST_NOCOUNTRY"),
        "from_institution": INSTITUTION_A,
        "to_institution": INSTITUTION_B,
        "source_identifier": SOURCE_IDENTIFIER,
        "destination_identifier": DEST_IDENTIFIER,
        "amount": 10,
        "currency": CURRENCY,
        "_skip_country_header": True,  # harness special-cases this below
    },
    validate=lambda body, ctx: (
        [] if body.get("success") is False and "country" in json.dumps(body).lower()
        else [f"expected a country-related 400 error, got {body}"]
    ),
))


# ============================================================
# Runner
# ============================================================
def run(test_ids: Optional[list[str]] = None, dry_run: bool = False) -> dict:
    context: dict[str, dict] = {}
    results = []

    to_run = [t for t in TESTS if not test_ids or t.id in test_ids]

    for tc in to_run:
        print(f"\n{'='*70}\n[{tc.matrix_ref}] {tc.name}  (id={tc.id})")

        if tc.skip_reason:
            print(f"  SKIPPED: {tc.skip_reason}")
            results.append({"id": tc.id, "status": "skipped", "reason": tc.skip_reason})
            continue

        if tc.depends_on and tc.depends_on not in context:
            print(f"  SKIPPED: depends on '{tc.depends_on}' which did not run or did not succeed")
            results.append({"id": tc.id, "status": "skipped", "reason": f"missing dependency {tc.depends_on}"})
            continue

        payload = tc.build_payload(context)
        skip_country_header = payload.pop("_skip_country_header", False)

        print(f"  Request: {json.dumps(payload, indent=2)[:500]}")

        if dry_run:
            print("  DRY RUN -- not sent")
            results.append({"id": tc.id, "status": "dry_run"})
            continue

        response = send(payload, dry_run=False, skip_country_header=skip_country_header)
        body = response.get("body") or {}
        context[tc.id] = {"payload": payload, "response": response, "body": body}

        if response.get("error") and response.get("http_code") is None:
            print(f"  NETWORK ERROR: {response['error']}")
            results.append({"id": tc.id, "status": "error", "reason": response["error"]})
            continue

        failures = tc.validate(body, context)

        if failures:
            print(f"  FAIL: {failures}")
            print(f"  Full response: {json.dumps(body, indent=2)[:1000]}")
            results.append({"id": tc.id, "status": "fail", "failures": failures, "http_code": response.get("http_code")})
        else:
            swap_ref = body.get("swap_reference", "?")
            print(f"  PASS  (swap_reference={swap_ref}, http={response.get('http_code')})")
            results.append({"id": tc.id, "status": "pass", "swap_reference": swap_ref})

        time.sleep(0.3)  # be gentle on the API

    return {"results": results, "context": {k: v["body"] for k, v in context.items()}}


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--dry-run", action="store_true", help="Print requests, send nothing")
    parser.add_argument("--only", type=str, default=None, help="Comma-separated test IDs to run")
    parser.add_argument("--list", action="store_true", help="List all test IDs and exit")
    parser.add_argument("--report-json", type=str, default=None, help="Write full results to this JSON file")
    args = parser.parse_args()

    if args.list:
        for tc in TESTS:
            dep = f" (depends on: {tc.depends_on})" if tc.depends_on else ""
            print(f"{tc.id:35s} [{tc.matrix_ref}] {tc.name}{dep}")
        return

    if BASE_URL.startswith("https://CHANGE_ME") or API_KEY == "CHANGE_ME":
        print("ERROR: set VOUCHMORPH_BASE_URL and VOUCHMORPH_API_KEY (env vars) before running.")
        print("       See the top of this file for all configurable env vars.")
        sys.exit(1)

    test_ids = args.only.split(",") if args.only else None
    output = run(test_ids=test_ids, dry_run=args.dry_run)

    passed = sum(1 for r in output["results"] if r["status"] == "pass")
    failed = sum(1 for r in output["results"] if r["status"] == "fail")
    errored = sum(1 for r in output["results"] if r["status"] == "error")
    skipped = sum(1 for r in output["results"] if r["status"] == "skipped")

    print(f"\n{'='*70}")
    print(f"SUMMARY: {passed} passed, {failed} failed, {errored} errored, {skipped} skipped")
    print(f"{'='*70}")

    if args.report_json:
        with open(args.report_json, "w") as f:
            json.dump(output, f, indent=2, default=str)
        print(f"Full report written to {args.report_json}")

    sys.exit(1 if (failed or errored) else 0)


if __name__ == "__main__":
    main()
