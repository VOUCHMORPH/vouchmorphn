#!/usr/bin/env bash
# =====================================================================
# VouchMorph — run every test suite.
#
#   ./run-all-tests.sh                     # everything it can find
#   ./run-all-tests.sh cron enterprise     # only those groups
#
# Creates throwaway databases (vmt_*), loads the tables each suite needs,
# runs the suites, drops nothing of yours. It never touches production.
#
# Prerequisites
#   php 8.1+ with pdo_pgsql, gd, pcntl      postgresql client + a local server
#   sudo apt install php-cli php-pgsql php-gd postgresql poppler-utils
#
# Where things are
#   PACKS  folder holding the unpacked packs (default: this script's folder)
#   VMREPO your vouchmorphn checkout, for suites that need the real code
#   PGUSER / PGPASSWORD / PGHOST / PGPORT   how to reach PostgreSQL
# =====================================================================
set -uo pipefail

PACKS="${PACKS:-$(cd "$(dirname "$0")" && pwd)}"
VMREPO="${VMREPO:-}"
export PGHOST="${PGHOST:-127.0.0.1}" PGPORT="${PGPORT:-5432}"
export PGUSER="${PGUSER:-postgres}" PGPASSWORD="${PGPASSWORD:-}"
WANT=("$@")

command -v php  >/dev/null || { echo "php not found"; exit 1; }
command -v psql >/dev/null || { echo "psql not found"; exit 1; }
[ -z "$PGPASSWORD" ] && { echo "Set PGPASSWORD (and PGUSER if not postgres) for your local PostgreSQL."; exit 1; }
for ext in pdo_pgsql gd pcntl; do
    php -m | grep -qi "^$ext$" || echo "warning: php extension $ext missing — some checks will be skipped"
done

TOTAL_PASS=0 TOTAL_FAIL=0 SUMMARY=""

# Suites that exercise the real code run against an OVERLAY: a copy of your
# checkout with the packs laid over it — the same shape as a deployment.
SEARCH="$PACKS"
OVERLAY=""
if [ -n "$VMREPO" ]; then
    OVERLAY="$(mktemp -d)/repo"
    echo "== building overlay: your checkout + the packs (this is also how you deploy)"
    cp -a "$VMREPO" "$OVERLAY"
    for pack in "$PACKS"/*/; do
        if [ -d "$pack/vouchmorphn" ]; then cp -a "$pack/vouchmorphn/." "$OVERLAY/"; fi
        for d in src scripts tests database; do
            [ -d "$pack/$d" ] && cp -a "$pack/$d" "$OVERLAY/"
        done
    done
    SEARCH="$OVERLAY"
    [ -d "$OVERLAY/vendor" ] || echo "   note: no vendor/ in the checkout — run composer install for the signing suite"
fi

want() { [ ${#WANT[@]} -eq 0 ] && return 0; for w in "${WANT[@]}"; do [ "$w" = "$1" ] && return 0; done; return 1; }
find1() { find "$SEARCH" -name "$1" -not -path '*/originals/*' 2>/dev/null | head -1; }
findsql() { find "$PACKS" -name "$1" 2>/dev/null | head -1; }
db()    { psql -v ON_ERROR_STOP=1 -q -d "$1" "${@:2}"; }
fresh() { dropdb --if-exists "$1" >/dev/null 2>&1; createdb "$1" >/dev/null; }

run_suite() {                       # run_suite <label> <php file> <db> [extra env]
    local label="$1" file="$2" dbname="$3"; shift 3
    if [ -z "$file" ] || [ ! -f "$file" ]; then
        SUMMARY+=$(printf '\n  %-44s %s' "$label" "not found — pack missing"); return
    fi
    local out
    out=$(TEST_DSN="pgsql:host=$PGHOST;port=$PGPORT;dbname=$dbname" \
          TEST_DB_USER="$PGUSER" TEST_DB_PASS="$PGPASSWORD" \
          TEST_DATABASE_URL="postgresql://$PGUSER:$PGPASSWORD@$PGHOST:$PGPORT/$dbname" \
          env "$@" php "$file" 2>&1)
    local line pass fail
    line=$(echo "$out" | grep -E '^[0-9]+ passed' | tail -1)
    pass=$(echo "$line" | awk '{print $1}'); fail=$(echo "$line" | awk '{print $3}')
    if [ -z "$line" ]; then
        SUMMARY+=$(printf '\n  %-44s %s' "$label" "ERROR — see below")
        echo "----- $label -----"; echo "$out" | tail -15; echo
        TOTAL_FAIL=$((TOTAL_FAIL + 1)); return
    fi
    TOTAL_PASS=$((TOTAL_PASS + pass)); TOTAL_FAIL=$((TOTAL_FAIL + fail))
    [ "$fail" -gt 0 ] && echo "$out" | grep '^FAIL' | sed "s/^/  [$label] /"
    SUMMARY+=$(printf '\n  %-44s %3d passed, %d failed' "$label" "$pass" "$fail")
}

# ---------------------------------------------------------------- cron
if want cron; then
    echo "== scheduler, fee ledger and job health"
    fresh vmt_cron
    db vmt_cron -f "$(findsql '2026_09_23_04_fee_ledger.sql')" >/dev/null
    run_suite "fee ledger (identity rule, idempotency)" "$(find1 fee_ledger_test.php)"  vmt_cron VM_ROOT="${OVERLAY:-$PACKS}"
    run_suite "scheduler (heartbeat, lock, hourly)"     "$(find1 scheduler_test.php)"   vmt_cron VM_ROOT="${OVERLAY:-$PACKS}"
    run_suite "job health (four alarm states)"          "$(find1 job_health_test.php)"  vmt_cron VM_ROOT="${OVERLAY:-$(dirname "$(dirname "$(find1 job_health_test.php)")")}"
fi

# ---------------------------------------------------------- enterprise
if want enterprise; then
    echo "== enterprise dashboard: induction, money guards, limits, fees"
    fresh vmt_ent
    db vmt_ent <<'SQL' >/dev/null
CREATE TABLE users (user_id BIGSERIAL PRIMARY KEY, full_name TEXT, email TEXT);
CREATE TABLE organizations (id BIGINT PRIMARY KEY, status TEXT);
INSERT INTO organizations VALUES (1,'active');
CREATE TABLE organization_users (id BIGSERIAL PRIMARY KEY, organization_id BIGINT, user_id BIGINT, role TEXT, department_id BIGINT, is_active BOOLEAN DEFAULT TRUE);
CREATE TABLE import_batches (id BIGSERIAL PRIMARY KEY, organization_id BIGINT, department_id BIGINT, status TEXT, uploaded_by BIGINT, approved_by BIGINT, approved_at TIMESTAMPTZ, total_amount NUMERIC);
CREATE TABLE import_rows (id BIGSERIAL PRIMARY KEY, batch_id BIGINT, amount NUMERIC, validation_status TEXT);
CREATE TABLE batch_approvals (id BIGSERIAL PRIMARY KEY, batch_id BIGINT, approver_user_id BIGINT, decision TEXT, reason TEXT, created_at TIMESTAMPTZ, UNIQUE (batch_id, approver_user_id));
CREATE TABLE approval_thresholds (id BIGSERIAL PRIMARY KEY, department_id BIGINT, min_amount NUMERIC, max_amount NUMERIC, required_approver_count INT, required_role TEXT);
CREATE TABLE swap_requests (swap_id BIGSERIAL PRIMARY KEY, amount NUMERIC, status TEXT, created_at TIMESTAMPTZ DEFAULT now());
CREATE TABLE hold_transactions (hold_id BIGSERIAL PRIMARY KEY, amount NUMERIC, status TEXT, created_at TIMESTAMPTZ DEFAULT now());
INSERT INTO users (full_name, email) VALUES
 ('Kgomotso Neo Molefe','k@gov.bw'),('Thabo Approver','t@gov.bw'),('Lesego Senior','l@gov.bw'),('Mpho Finance','m@gov.bw'),('Onalenna Viewer','o@gov.bw');
INSERT INTO organization_users (organization_id, user_id, role, department_id) VALUES
 (1,1,'program_officer',10),(1,2,'approver',10),(1,3,'senior_approver',10),(1,4,'finance_officer',10),(1,5,'viewer',10);
INSERT INTO approval_thresholds (department_id, min_amount, max_amount, required_approver_count, required_role) VALUES
 (10,0,49999.99,1,'approver'),(10,50000,NULL,2,'senior_approver');
SQL
    for m in enterprise_induction enterprise_permission_catalogue identity_fee_allocations; do
        f="$(findsql "*_$m.sql")"; [ -n "$f" ] && db vmt_ent -f "$f" >/dev/null
    done
    ENT_ROOT="${OVERLAY:-$(dirname "$(dirname "$(dirname "$(find1 induction_test.php)")")")}"
    run_suite "induction (read, prove, declare)"        "$(find1 induction_test.php)"             vmt_ent VM_ROOT="$ENT_ROOT"
    run_suite "gate + money guards"                     "$(find1 gate_and_money_test.php)"        vmt_ent VM_ROOT="$ENT_ROOT"
    run_suite "sandbox limits"                          "$(find1 sandbox_limits_test.php)"        vmt_ent VM_ROOT="$ENT_ROOT"
fi

# --------------------------------------------------------- legacy (opt-in)
# The identity-fee patch set written before HEAD grew its own FeeLedger.
# Superseded: these assert the old 15% source share, the repo now uses the
# filed 13%. Kept for reference — run with:  ./run-all-tests.sh legacy
if [ ${#WANT[@]} -gt 0 ] && want legacy && [ -n "$VMREPO" ]; then
    echo "== legacy identity-fee patch set (superseded by FeeLedger)"
    run_suite "swap fee rules (superseded)"             "$(find1 swap_fee_rules_test.php)"        vmt_ent VM_ROOT="${OVERLAY:-$PACKS}"
    run_suite "identity fee allocation (superseded)"    "$(find1 identity_fee_allocation_test.php)" vmt_ent VM_ROOT="${OVERLAY:-$PACKS}"
fi

# ------------------------------------------------------------- signing
if want signing; then
    echo "== signed regulatory reports"
    fresh vmt_sign
    db vmt_sign <<'SQL' >/dev/null
CREATE TABLE admins (admin_id BIGSERIAL PRIMARY KEY, username TEXT, email TEXT, full_name TEXT, role_id INT,
  mfa_enabled BOOLEAN DEFAULT FALSE, mfa_secret TEXT, deleted_at TIMESTAMPTZ, created_at TIMESTAMPTZ DEFAULT now());
CREATE TABLE swap_requests (swap_id BIGSERIAL PRIMARY KEY, amount NUMERIC, status TEXT, created_at TIMESTAMPTZ DEFAULT now());
CREATE TABLE hold_transactions (hold_id BIGSERIAL PRIMARY KEY, amount NUMERIC, status TEXT);
INSERT INTO admins (username, full_name, role_id, mfa_enabled, mfa_secret) VALUES
 ('marvin','Marvin Sehunelo',999,true,'X'),('mooketsi','Mooketsi Junior Lorato',10,true,'X'),
 ('magdeline','Magdeline Lewis',999,true,'X'),('zetu','Zetu Mabusa',4,true,'X'),('bob','BoB Supervisor',3,true,'X');
SQL
    for m in supervisory_registers signature_workflow; do
        f="$(findsql "*_$m.sql")"; [ -n "$f" ] && db vmt_sign -f "$f" >/dev/null
    done
    if [ -n "$OVERLAY" ] && [ -d "$OVERLAY/vendor" ]; then
        run_suite "report signing (chain, certificate)" "$(find1 signing_workflow_test.php)"  vmt_sign VM_ROOT="$OVERLAY"
    else
        SUMMARY+=$'\n  report signing (chain, certificate)          skipped — needs VMREPO with vendor/'
    fi
fi

# ----------------------------------------------------------------- dpa
if want dpa; then
    echo "== data protection layer"
    fresh vmt_dpa
    db vmt_dpa <<'SQL' >/dev/null
CREATE TABLE swap_requests (swap_id BIGSERIAL PRIMARY KEY, amount NUMERIC, status TEXT, created_at TIMESTAMPTZ DEFAULT now());
CREATE TABLE hold_transactions (hold_id BIGSERIAL PRIMARY KEY, amount NUMERIC, status TEXT, created_at TIMESTAMPTZ DEFAULT now());
INSERT INTO swap_requests (amount,status,created_at) VALUES (100,'completed', now()), (50,'completed', now() - interval '8 years');
SQL
    for f in "$(findsql 01_schema.sql)" "$(findsql 02_evidence_checks.sql)"; do [ -n "$f" ] && db vmt_dpa -f "$f" >/dev/null; done
    run_suite "data protection (consent, audit, DSAR)"  "$(find1 dpa_compliance_test.php)"        vmt_dpa
fi

# ----------------------------------------------------------- the repo
if want repo && [ -n "$VMREPO" ]; then
    echo "== repository syntax check"
    bad=0
    while IFS= read -r f; do php -l "$f" >/dev/null 2>&1 || { echo "  PARSE ERROR: ${f#$VMREPO/}"; bad=$((bad+1)); }; done \
        < <(find "$VMREPO/src" "$VMREPO/public" "$VMREPO/scripts" -name '*.php' 2>/dev/null | grep -v /vendor/)
    SUMMARY+=$(printf '\n  %-44s %s' "repository syntax" "$([ $bad -eq 0 ] && echo 'clean' || echo "$bad file(s) will not parse")")
    TOTAL_FAIL=$((TOTAL_FAIL + bad))
fi

echo
[ -n "$OVERLAY" ] && rm -rf "$(dirname "$OVERLAY")"
echo "================ results ================$SUMMARY"
printf '\n  %-44s %3d passed, %d failed\n' "TOTAL" "$TOTAL_PASS" "$TOTAL_FAIL"
echo "========================================="
[ "$TOTAL_FAIL" -eq 0 ] || exit 1
