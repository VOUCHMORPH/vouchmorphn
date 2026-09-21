<?php
declare(strict_types=1);

namespace Application\Incident;

/**
 * Playbooks and alarm rules, taken from VM-GOV-001 (Sandbox Operating
 * Model, Roles and Governance Map), Part 3. Each playbook is the relay of
 * handoffs for a scenario: who acts, what they do, who they hand to, and the
 * deadline in minutes from the moment the incident is opened.
 *
 * When VM-GOV-001 changes, change it here; the Incident Command page, the
 * alarms and the reports all read from this one place.
 */
final class Playbooks
{
    /** Severity rules from the BCP: SEV1/SEV2 need the Bank told within 2 hours. */
    public const BANK_NOTICE_MINUTES = ['SEV1' => 120, 'SEV2' => 120];
    public const REPORT_48H_MINUTES = 48 * 60;

    public static function all(): array
    {
        return [
            'S2' => [
                'title' => 'Settlement not confirmed (normal-day chase)',
                'steps' => [
                    ['ACCOUNTANT', 'Review the unconfirmed settlements on Invoices & Settlement; list each overdue line with its reference.', 'OPERATIONS_MANAGER', 12 * 60],
                    ['OPERATIONS_MANAGER', "Chase each overdue line with the receiving institution's settlement team, quoting the line reference (the same reference they see at the central bank).", 'COMPLIANCE_OFFICER', 24 * 60],
                    ['COMPLIANCE_OFFICER', 'If still unresolved after the chase, escalate to the institution in writing and record it for the monthly report.', null, 48 * 60],
                ],
            ],
            'S3' => [
                'title' => 'Reconciliation does not balance / settlement failed',
                'steps' => [
                    ['ACCOUNTANT', 'Identify the unmatched items and which institution disagrees.', 'HEAD_OF_PRODUCTS', 60],
                    ['HEAD_OF_PRODUCTS', 'Trace each item through the message log; confirm whether any customer is affected.', 'INCIDENT_COMMANDER', 120],
                    ['INCIDENT_COMMANDER', 'If accuracy is below 99.5% or a customer is affected, declare SEV-2 and pause new transactions (Controls tab).', 'COMPLIANCE_OFFICER', 125],
                    ['COMPLIANCE_OFFICER', 'Notify the Bank of the pause (Bank notice report).', 'ACCOUNTANT', 245],
                    ['ACCOUNTANT', 'Clear every item with the institution; confirm two clean reconciliation cycles.', 'INCIDENT_COMMANDER', 24 * 60],
                    ['INCIDENT_COMMANDER', 'Resume (a second admin approves); Compliance Officer sends the written report.', null, 48 * 60],
                ],
            ],
            'S5' => [
                'title' => 'Sanctions or PIP match',
                'steps' => [
                    ['COMPLIANCE_OFFICER', 'Compare identifiers. False positive: lift the client freeze (second admin approves) and record why.', 'INCIDENT_COMMANDER', 120],
                    ['COMPLIANCE_OFFICER', 'Confirmed sanctions match: keep the client frozen, instruct the holding institution to keep the value held, report to the FIA.', 'INCIDENT_COMMANDER', 125],
                    ['INCIDENT_COMMANDER', 'Confirm the freeze is in place across all channels.', null, 24 * 60],
                ],
            ],
            'S7' => [
                'title' => 'A partner institution is offline or must be removed from routing',
                'steps' => [
                    ['ON_CALL', 'Confirm the outage; triage.', 'INCIDENT_COMMANDER', 15],
                    ['INCIDENT_COMMANDER', 'Declare SEV-2; freeze the institution (Controls tab) so it is removed as source and destination.', 'TECH_RECOVERY_LEAD', 20],
                    ['TECH_RECOVERY_LEAD', 'Queue release and settlement instructions for it; no blind retries.', 'OPERATIONS_MANAGER', 45],
                    ['OPERATIONS_MANAGER', "Call the institution's operations contact; confirm the state of its holds.", 'COMPLIANCE_OFFICER', 60],
                    ['COMPLIANCE_OFFICER', 'Notify the Bank if customers are affected.', 'HEAD_OF_PRODUCTS', 120],
                    ['HEAD_OF_PRODUCTS', 'If the outage passes 20 hours, agree with the institution how each hold resolves before the 24-hour mark.', 'INCIDENT_COMMANDER', 20 * 60],
                    ['TECH_RECOVERY_LEAD', 'On restoration, replay the queue; request the unfreeze after three clean health checks (a second admin approves).', 'ACCOUNTANT', 24 * 60],
                ],
            ],
            'S8' => [
                'title' => 'Primary hosting lost / whole service down',
                'steps' => [
                    ['ON_CALL', 'Confirm with the host; escalate.', 'INCIDENT_COMMANDER', 15],
                    ['INCIDENT_COMMANDER', 'Declare SEV-1; freeze the whole service (Controls tab).', 'OPERATIONS_MANAGER', 15],
                    ['OPERATIONS_MANAGER', 'Publish the service notice to customers (Broadcasts; Incident Commander approves).', 'TECH_RECOVERY_LEAD', 30],
                    ['TECH_RECOVERY_LEAD', "Promote the replica; confirm the last committed transaction; restore the Bank's supervisory view.", 'HEAD_OF_PRODUCTS', 60],
                    ['COMPLIANCE_OFFICER', 'Notify the Bank (Bank notice report).', 'BOARD_CHAIR', 120],
                    ['HEAD_OF_PRODUCTS', 'Resolve every in-flight transaction by its disposition.', 'ACCOUNTANT', 90],
                    ['ACCOUNTANT', 'Reconcile against institution records; sign off zero loss.', 'INCIDENT_COMMANDER', 120],
                    ['INCIDENT_COMMANDER', 'Request resumption; a second admin approves.', 'COMPLIANCE_OFFICER', 150],
                    ['COMPLIANCE_OFFICER', 'Written report (48 h) and closure report (5 business days).', null, 48 * 60],
                ],
            ],
            'S9' => [
                'title' => 'Cyber incident, audit tampering or personal-data breach',
                'steps' => [
                    ['ON_CALL', 'Escalate at the first indication. Do not wipe or restart anything.', 'INCIDENT_COMMANDER', 5],
                    ['INCIDENT_COMMANDER', 'Declare SEV-1; freeze the service; call Security.', 'SECURITY', 15],
                    ['SECURITY', 'Isolate systems; capture evidence; lead containment.', 'TECH_RECOVERY_LEAD', 60],
                    ['TECH_RECOVERY_LEAD', 'Rotate every key, credential and certificate; restore from a clean backup.', 'SECURITY', 12 * 60],
                    ['COMPLIANCE_OFFICER', 'Notify the Bank. Assess personal-data exposure.', 'IDPC', 120],
                    ['COMPLIANCE_OFFICER', 'If personal data is affected, notify the IDPC; notify affected customers where the risk to them is high.', 'BOARD_CHAIR', 72 * 60],
                    ['SECURITY', 'Sign off that the threat is removed before resumption.', 'INCIDENT_COMMANDER', 24 * 60],
                ],
            ],
            'S10' => [
                'title' => 'Agent failed a spot check or is suspended',
                'steps' => [
                    ['COMPLIANCE_OFFICER', "Suspend the agent's authorisation (Controls tab: freeze agent).", 'OPERATIONS_MANAGER', 0],
                    ['OPERATIONS_MANAGER', 'Notify the institution that appointed the agent.', 'HEAD_OF_PRODUCTS', 8 * 60],
                    ['HEAD_OF_PRODUCTS', 'Review every claim the agent completed; flag any paid in error.', 'COMPLIANCE_OFFICER', 48 * 60],
                    ['COMPLIANCE_OFFICER', 'If fraud is suspected, notify the Bank immediately and consider an STR; otherwise report monthly. Repeat or serious failure: revoke permanently.', null, 48 * 60],
                ],
            ],
            'S11' => [
                'title' => 'A test limit or boundary was breached',
                'steps' => [
                    ['ON_CALL', 'Confirm whether the control worked (blocked) or failed (value moved).', 'INCIDENT_COMMANDER', 15],
                    ['INCIDENT_COMMANDER', 'If the control failed and value moved outside the boundary, declare SEV-1 and pause the affected scope (Controls tab).', 'COMPLIANCE_OFFICER', 20],
                    ['COMPLIANCE_OFFICER', 'Notify the Bank of the breach (Bank notice report).', 'TECH_RECOVERY_LEAD', 120],
                    ['TECH_RECOVERY_LEAD', 'Fix the control; prove it with a re-test.', 'INCIDENT_COMMANDER', 24 * 60],
                    ['ACCOUNTANT', 'Confirm the breaching transactions and their settlement treatment (settle, reverse or close with documentation).', 'COMPLIANCE_OFFICER', 24 * 60],
                    ['COMPLIANCE_OFFICER', 'Written report with root cause (48 h).', null, 48 * 60],
                ],
            ],
            'ACC' => [
                'title' => 'Accounting exception',
                'steps' => [
                    ['ACCOUNTANT', 'Review the items; correct or cancel with a documented reason.', 'INCIDENT_COMMANDER', 24 * 60],
                    ['INCIDENT_COMMANDER', 'Approve any correction that changes amounts owed (four-eyes).', null, 48 * 60],
                ],
            ],
        ];
    }

    public static function get(string $code): array
    {
        return self::all()[$code] ?? self::all()['ACC'];
    }

    /**
     * Alarm rules raised by the monitor or by live controls.
     *   severity, playbook, open_incident (bool), notify (roles), now: what to do immediately.
     */
    public static function rules(): array
    {
        return [
            'CAP_BREACH' => ['SEV1', 'S11', true, ['INCIDENT_COMMANDER', 'COMPLIANCE_OFFICER', 'ON_CALL'],
                'A completed transaction exceeded the P7,000 sandbox cap: the control failed and value moved outside the boundary. Declare the incident, pause the affected scope, and have the Compliance Officer notify the Bank within 2 hours.'],
            'CAP_BLOCKED' => ['SEV4', 'S11', false, ['ON_CALL'],
                'A transaction above the sandbox cap was blocked. The control worked; no value moved. Review for repeated attempts by the same customer.'],
            'CONTROL_FROZEN' => ['SEV2', null, false, ['INCIDENT_COMMANDER', 'COMPLIANCE_OFFICER', 'OPERATIONS_MANAGER'],
                'A freeze is in force. Make sure it is linked to an incident, customers have been told (Broadcasts), and the Bank is notified if customers are affected.'],
            'RESUME_AWAITING' => ['SEV3', null, false, ['INCIDENT_COMMANDER', 'COMPLIANCE_OFFICER'],
                'A request to lift a freeze is waiting for a second admin to approve it.'],
            'SETTLEMENT_OVERDUE' => ['SEV3', 'S2', false, ['ACCOUNTANT', 'OPERATIONS_MANAGER'],
                'Settlements are past noon of the next business day without confirmation. Chase each line with the receiving institution, quoting the line reference.'],
            'SETTLEMENT_FAILED' => ['SEV2', 'S3', true, ['ACCOUNTANT', 'HEAD_OF_PRODUCTS', 'INCIDENT_COMMANDER'],
                'A receiving institution reports a settlement as not received. Trace the items and reconcile; pause if customers are affected.'],
            'ADVICE_UNDELIVERED' => ['SEV3', 'S2', false, ['OPERATIONS_MANAGER', 'TECH_RECOVERY_LEAD'],
                'A settlement advice could not be delivered to a paying bank after several attempts. Check the bank is reachable and contact its settlement team.'],
            'HOLDS_NEAR_EXPIRY' => ['SEV2', 'S7', true, ['HEAD_OF_PRODUCTS', 'OPERATIONS_MANAGER'],
                'Holds have been open more than 20 hours. Agree with each holding institution how every hold resolves before the 24-hour mark.'],
            'AUDIT_CHAIN_BROKEN' => ['SEV1', 'S9', true, ['INCIDENT_COMMANDER', 'SECURITY', 'COMPLIANCE_OFFICER'],
                'The tamper-evident audit trail fails verification. Treat as a security incident: do not modify the database, capture evidence, call Security.'],
            'BANK_NOTICE_OVERDUE' => ['SEV1', null, false, ['COMPLIANCE_OFFICER', 'INCIDENT_COMMANDER', 'BOARD_CHAIR'],
                'The 2-hour Bank of Botswana notification deadline has passed for an open incident. Send the Bank notice now and record it.'],
            'REPORT_48H_OVERDUE' => ['SEV2', null, false, ['COMPLIANCE_OFFICER', 'INCIDENT_COMMANDER'],
                'The 48-hour written report to the Bank is overdue. Generate it from the incident, review, send and record.'],
            'ACTION_OVERDUE' => ['SEV3', null, false, [],
                'A playbook step is past its deadline. The owner must complete it or record why not; the next person in the relay is waiting.'],
            'SELF_BILLED_INVOICE' => ['SEV4', 'ACC', false, ['ACCOUNTANT'],
                'Fee invoices are addressed to VouchMorph itself. They can never be collected; cancel them with a documented reason and report the billing fault.'],
            'DAILY_SIGNOFF_MISSING' => ['SEV3', 'S2', false, ['ACCOUNTANT', 'OPERATIONS_MANAGER'],
                "Yesterday's reconciliation has not been signed off. The Accountant signs the daily sheet by the next morning (VM-GOV-001 S2)."],
        ];
    }

    public static function rule(string $code): array
    {
        $r = self::rules()[$code] ?? ['SEV3', null, false, [], 'Review this alarm.'];
        return ['severity' => $r[0], 'playbook' => $r[1], 'open_incident' => $r[2], 'notify' => $r[3], 'now' => $r[4]];
    }
}
