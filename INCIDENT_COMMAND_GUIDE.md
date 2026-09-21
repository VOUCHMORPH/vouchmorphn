# Incident Command: deployment and daily use

Built on VM-GOV-001 (Sandbox Operating Model, Roles and Governance Map).

## Deploy (in this order)
1. Copy these files into the repo at the same paths, commit, push.
2. Run the migration with psql (not the Railway web console):
       psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_09_22_incident_command.sql
3. Schedule in Railway, every 5 minutes:  php src/cron/incident_monitor.php
4. Admin dashboard -> Incident Command -> Contacts: enter each role holder's phone and email,
   including the Bank of Botswana sandbox contact.
5. Email (so alarms and reports go out by themselves):
       composer require phpmailer/phpmailer
   and set SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD, SMTP_FROM_EMAIL on the vouchmorphn service.
   Until then every alarm and report is still recorded and visible, and marked PENDING.

## What it enforces
- Sandbox cap: P7,000 per transaction on every money path (app swaps, payments, agent claims,
  cash-out confirmations, enterprise batches, service endpoint). Before, only card top-ups were capped.
- Freezes: whole service, one institution, one flow, one agent, one client. One authorised person
  freezes immediately; lifting needs a second, different admin (enforced by the database).
- Customer notices: drafted by one admin, approved by the Incident Commander (never the drafter).
- Fee reversals: requested by the Accountant, approved by the Managing Director.

## Who can do what
| Action | Roles |
|---|---|
| Freeze service / institution / flow | Managing Director (Super Admin) |
| Freeze agent / client | Managing Director, Compliance Officer |
| Request lifting a freeze | Managing Director, Compliance Officer, Operations |
| Approve lifting (must be a different person) | Managing Director, Compliance Officer |
| Draft customer notice | Managing Director, Compliance Officer, Operations |
| Approve customer notice | Managing Director (Incident Commander) |
| Generate reports | Managing Director, Compliance Officer, Accountant |
| Send / record reports | Managing Director, Compliance Officer |
| Daily sign-off, reversal requests, month-end pack | Accountant |
| View only | Auditor, Regulator |

## Alarms (incident_monitor.php)
CAP_BREACH (SEV1, S11), AUDIT_CHAIN_BROKEN (SEV1, S9), BANK_NOTICE_OVERDUE (SEV1),
SETTLEMENT_FAILED (SEV2, S3), HOLDS_NEAR_EXPIRY (SEV2, S7), REPORT_48H_OVERDUE (SEV2),
SETTLEMENT_OVERDUE (SEV3, S2), ADVICE_UNDELIVERED (SEV3), ACTION_OVERDUE (SEV3),
DAILY_SIGNOFF_MISSING (SEV3, S2), SELF_BILLED_INVOICE (SEV4), CAP_BLOCKED (SEV4).
Each states what to do now and who to call; SEV1/SEV2 rules open an incident with the
VM-GOV-001 playbook steps, owners and deadlines. Alarms clear themselves when the condition goes.

## Reports
Bank notice (2 h), written report (48 h), closure report (5 business days), daily sign-off,
month-end pack. All built from the recorded steps and remedies; download as PDF, email, or
mark as sent (which stamps the incident's deadline as met).
