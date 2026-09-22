<?php
declare(strict_types=1);

namespace Application\Enterprise;

/**
 * InductionCurriculum — what every enterprise user must read, prove they
 * understood, and can always come back to.
 *
 * Structure
 *   foundation()      six sections every role completes first
 *   role($code)       the role's own sections
 *   manual($code)     the one-page manual that stays available forever
 *
 * Each section has a comprehension check. "Understood" only unlocks when
 * the person has read to the end and answered it correctly. A wrong answer
 * explains why and sends them back to the section and their manual.
 *
 * Editing any section changes its fingerprint. Only the changed sections
 * must be re-acknowledged — nobody re-reads what did not change.
 *
 * Markup in body text: **bold**. Nothing else is interpreted.
 */
final class InductionCurriculum
{
    public const VERSION_LABEL = '2026.09';

    public const ROLES = [
        'owner'                 => 'Owner',
        'it_manager_enterprise' => 'IT Manager',
        'it_officer_enterprise' => 'IT Officer',
        'it_support'            => 'IT Support',
        'department_head'       => 'Department Head',
        'program_officer'       => 'Uploader',
        'finance_officer'       => 'Finance Officer',
        'approver'              => 'Approver',
        'senior_approver'       => 'Senior Approver',
        'supervisor'            => 'Supervisor',
        'beneficiary_registrar' => 'Beneficiary Registrar',
        'auditor'               => 'Auditor',
        'viewer'                => 'Viewer',
    ];

    // =================================================================
    // FOUNDATION — every role, first
    // =================================================================
    public static function foundation(): array
    {
        return [
            [
                'id' => 'F1', 'title' => 'Where the money actually is', 'minutes' => 3,
                'body' => [
                    'VouchMorph **never holds your organisation’s money**. Your funds stay with your own bank or mobile money provider until each beneficiary is paid, and every payment is completed by licensed institutions.',
                    'A payment either reaches the beneficiary or it does not leave your account. If anything stops a payment part-way, it is reversed automatically. No one at VouchMorph can move, pause or redirect your funds.',
                    'This matters for you in one way above all: **if something looks wrong, the answer is never to “fix” money yourself.** You stop, record what you saw, and escalate. The institutions and VouchMorph resolve it together, with a record of every step.',
                ],
                'points' => [
                    'VouchMorph holds no money at any time.',
                    'A payment is either completed or reversed — never left half-done.',
                    'Never try to correct a payment by making another one.',
                ],
                'check' => [
                    'q' => 'A beneficiary says they were not paid, but the batch shows “Executed”. What do you do?',
                    'options' => [
                        'Send them the amount again from the same source so they are not left waiting.',
                        'Record the details, trace the payment, and escalate if the trace does not explain it.',
                        'Ask VouchMorph to move the money from their holding account to the beneficiary.',
                    ],
                    'answer' => 1,
                    'why' => 'Paying again risks paying twice, and VouchMorph holds no money to move. Trace the payment first; the trace shows its exact status.',
                ],
            ],
            [
                'id' => 'F2', 'title' => 'How a disbursement moves', 'minutes' => 4,
                'body' => [
                    'Every batch follows the same six stages, and each stage belongs to a different person: **Prepare** (the uploader builds the beneficiary list), **Review** (the system validates every row; you correct what it flags), **Approve** (an approver who did not prepare it checks and approves it), **Confirm source** (finance confirms the paying account holds the funds), **Execute** (an authorised person releases it), and **Trace** (anyone permitted follows each payment to its end state).',
                    'Some beneficiaries have no bank account. They are paid **to their identity**: their Omang number and the name on it. They collect with a one-time code or at an agent with their ID. If a grant or pension addressed to an identity is not collected within 24 hours, it does **not** come back — it stays reserved for that person, who can still collect it. Personal payments that go uncollected are returned instead.',
                    'In the sandbox period, amounts are capped: **P5,000 per payment**, and **P1,400 per social grant or pension payment**. The system blocks anything above these limits.',
                ],
                'points' => [
                    'Six stages; no one person may do all of them.',
                    'Uncollected grants stay reserved for the recipient; uncollected personal payments return.',
                    'Sandbox caps: P5,000 per payment, P1,400 per grant.',
                ],
                'check' => [
                    'q' => 'A pension of P900 is sent to an Omang number and nobody collects it within 24 hours. What happens?',
                    'options' => [
                        'It returns to the organisation’s account and must be re-sent.',
                        'It is cancelled and the fee is refunded.',
                        'It stays reserved for the recipient, who can still collect it.',
                    ],
                    'answer' => 2,
                    'why' => 'A grant or pension paid to an identity belongs to the recipient once it is sent. It waits for them; it never goes back to the payer.',
                ],
            ],
            [
                'id' => 'F3', 'title' => 'Four eyes, always', 'minutes' => 3,
                'body' => [
                    '**The person who prepares a batch can never approve it.** The system enforces this, and it also refuses rejection by the preparer, so no one can quietly bury their own mistake.',
                    'Above a set amount, a batch needs **two different approvers**. The threshold is set by your organisation’s Owner and shown on every batch that needs it.',
                    'Your login is yours alone. Sharing it, or approving from someone else’s session “because they were away”, defeats every control above and is a disciplinary matter in most organisations. If you are going to be away, a backup is assigned — that is what backups are for.',
                ],
                'points' => [
                    'Preparer never approves or rejects their own batch.',
                    'Large batches need two different approvers.',
                    'Never share a login or act in someone else’s session.',
                ],
                'check' => [
                    'q' => 'Your manager is travelling and asks you to log in as them to approve an urgent batch. What do you do?',
                    'options' => [
                        'Do it, since the batch is urgent and your manager has authorised it.',
                        'Decline, and ask for the assigned backup approver to approve under their own login.',
                        'Approve it under your own login even though you prepared it.',
                    ],
                    'answer' => 1,
                    'why' => 'Acting in someone else’s session makes the audit trail false. Approving your own batch is blocked. The backup approver exists for exactly this.',
                ],
            ],
            [
                'id' => 'F4', 'title' => 'People’s information is not yours', 'minutes' => 3,
                'body' => [
                    'Beneficiary lists contain names, identity numbers, phone numbers and amounts. Under the **Data Protection Act, 2024**, you may use them only for the disbursement they were collected for, and see only what your role needs.',
                    'Do not download lists to personal devices, email them to personal addresses, photograph screens, or share them in messaging groups. Exports are logged with who, what and when.',
                    'If you think personal data has gone somewhere it should not — a list emailed to the wrong person, a laptop lost — report it **at once**. The law gives the organisation hours, not days, to act.',
                ],
                'points' => [
                    'Use data only for the disbursement and only as your role needs.',
                    'No personal devices, personal email or screenshots.',
                    'Report a suspected data leak immediately.',
                ],
                'check' => [
                    'q' => 'You accidentally emailed a beneficiary export to the wrong external address. What is the first thing to do?',
                    'options' => [
                        'Ask the recipient to delete it and say nothing further.',
                        'Report it immediately to your supervisor and the compliance contact.',
                        'Wait to see whether anything goes wrong before reporting.',
                    ],
                    'answer' => 1,
                    'why' => 'A possible personal-data breach must be reported straight away so the organisation can meet its legal deadlines and limit harm.',
                ],
            ],
            [
                'id' => 'F5', 'title' => 'Fraud looks ordinary', 'minutes' => 3,
                'body' => [
                    'Most disbursement fraud does not look dramatic. It looks like a list with a few extra names, a phone number that appears against several beneficiaries, an amount just under a limit, or someone pressing you to approve before you have had time to check.',
                    '**Watch for:** the same phone number or account against different people; beneficiaries you cannot trace to a programme; round-number totals that do not match the programme budget; last-minute additions; pressure to skip a step.',
                    'Raising a concern in good faith is always protected, even if it turns out to be innocent. Staying silent about something you noticed is not.',
                ],
                'points' => [
                    'Duplicates, unknown names, last-minute additions, pressure.',
                    'Pressure to rush is itself a warning sign.',
                    'Speaking up in good faith is protected.',
                ],
                'check' => [
                    'q' => 'While reviewing a batch you notice one phone number listed against four different beneficiaries. What do you do?',
                    'options' => [
                        'Approve it — families often share a phone.',
                        'Remove those four rows yourself and approve the rest.',
                        'Hold the batch, flag the rows with your reason, and escalate for verification.',
                    ],
                    'answer' => 2,
                    'why' => 'Shared phones can be innocent, but they are also a classic ghost-beneficiary pattern. Flag and escalate; do not decide alone, and do not silently edit someone else’s batch.',
                ],
            ],
            [
                'id' => 'F6', 'title' => 'When something goes wrong', 'minutes' => 2,
                'body' => [
                    'Stop, record, escalate. **Stop** — do not retry, re-send or work around it. **Record** — the batch reference, the time, what you saw, a screenshot inside the platform if needed. **Escalate** — to your supervisor, and for anything involving money or data, to your organisation’s compliance contact.',
                    'If the platform itself is unavailable, your money is safe: nothing is ever left half-paid, and anything that cannot be completed is returned to your account automatically. Wait for the service notice; do not try another route to pay the same people.',
                    'Your manual — the button at the bottom of every page — always shows who to call for your role.',
                ],
                'points' => [
                    'Stop. Record. Escalate.',
                    'An outage never locks your funds.',
                    'Your manual lists exactly who to call.',
                ],
                'check' => [
                    'q' => 'The platform is down mid-execution. What should you do?',
                    'options' => [
                        'Pay the remaining beneficiaries through internet banking so they are not delayed.',
                        'Record what happened, escalate, and wait for the service notice before doing anything else.',
                        'Execute the batch again as soon as the page loads.',
                    ],
                    'answer' => 1,
                    'why' => 'Paying another way or re-executing risks paying people twice. Anything unfinished is reversed automatically, and every payment is checked before service resumes.',
                ],
            ],
        ];
    }

    // =================================================================
    // ROLE MODULES
    // =================================================================
    public static function role(string $code): array
    {
        $m = self::roleModules();
        return $m[$code] ?? [];
    }

    private static function roleModules(): array
    {
        return [

        'owner' => [
            self::s('OW1', 'You are accountable, not the platform', 3,
                ['The Owner answers for how your organisation uses VouchMorph. The platform lets the Owner see everything and change anything — which is exactly why the Owner should use that reach rarely, and never to bypass the people who do the work.',
                 'Your job is to set the structure: who holds which role, what the approval thresholds are, and whether the controls are working. It is not to prepare, approve and execute batches yourself.'],
                ['Set the structure; do not do the work alone.', 'Your reach is wide; use it sparingly.'],
                'Why should the Owner avoid preparing and approving batches personally?',
                ['Because the Owner lacks permission to do so.', 'Because concentrating stages in one person defeats segregation of duties, even if the system would allow it.', 'Because only finance officers may approve.'], 1,
                'The Owner can see and change a great deal. Controls only protect the organisation if no single person runs a payment end to end.'),
            self::s('OW2', 'Roles and segregation', 4,
                ['Appoint at least one uploader, two approvers and one finance officer, held by **different people**. Assign a backup for each approver so absence never tempts anyone to share a login.',
                 'Review role assignments monthly. People change jobs; their access must change the same day. The IT Manager executes changes; you approve them.'],
                ['Different people in each stage.', 'A named backup for every approver.', 'Monthly access review.'],
                'A department has one staff member who prepares and approves all its batches. What should you do?',
                ['Nothing, if they are trustworthy.', 'Assign a second approver so preparation and approval sit with different people.', 'Give them the Owner role so the system stops blocking them.'], 1,
                'Trust is not a control. The system blocks self-approval; the fix is a second person, not wider access.'),
            self::s('OW3', 'Thresholds and dual control', 3,
                ['Set the amount above which a batch needs two approvers. Pick it so that a single approver never releases more than your organisation would accept losing to one mistake.',
                 'Changing a threshold is recorded in the audit trail with your name. Lowering controls during a busy period to “get payments out” is precisely what auditors look for.'],
                ['Threshold = the most one mistake may cost.', 'Every change is recorded against you.'],
                'Payment season is busy. Someone asks you to raise the dual-control threshold for a week. What is the right response?',
                ['Raise it — the backlog matters more.', 'Keep the threshold and add approver capacity instead.', 'Remove dual control entirely for the week.'], 1,
                'Controls matter most under pressure. Add people, not risk.'),
            self::s('OW4', 'Reading the evidence', 3,
                ['Each month, read three things: the audit trail for role and threshold changes, the list of rejected and failed batches with their reasons, and any unresolved exceptions in the Attention inbox.',
                 'If you would be uncomfortable explaining an entry to your auditor, ask about it now.'],
                ['Monthly: role changes, rejections and failures, open exceptions.'],
                'Which of these belongs in your monthly review?',
                ['Only batches over the threshold.', 'Role changes, rejected or failed batches, and open exceptions.', 'Nothing — the auditor handles it.'], 1,
                'The Owner’s review is what makes the controls real between audits.'),
        ],

        'it_manager_enterprise' => [
            self::s('IM1', 'Joiners, movers, leavers — same day', 4,
                ['Access follows the job, on the day it changes. A leaver’s access is removed on their last working day, not at month-end. A mover loses their old role before gaining the new one.',
                 'Every change needs an Owner’s approval and is recorded against both of you.'],
                ['Same-day removal for leavers.', 'Old role removed before new role granted.', 'Owner approves; you execute.'],
                'A finance officer moves to the programme team. What order do you change their access in?',
                ['Add the uploader role first, remove finance later.', 'Remove the finance role, then grant uploader after Owner approval.', 'Leave both — they may need finance access for handover.'], 1,
                'Holding both roles even briefly lets one person confirm funding for batches they prepared.'),
            self::s('IM2', 'Least privilege', 3,
                ['Give each person the narrowest role that lets them do their job. “Just in case” access is the most common way controls fail. Never grant yourself a business role such as approver.',
                 'Per-user permission overrides are powerful and invisible from the role list. Use them only with written Owner approval and review them monthly.'],
                ['Narrowest role that works.', 'Never grant yourself business roles.', 'Overrides need written approval.'],
                'Why are per-user permission overrides risky?',
                ['They slow the system down.', 'They grant access that does not show in the role list, so reviews can miss it.', 'They expire automatically.'], 1,
                'Hidden access escapes review. That is why overrides need approval and a monthly check.'),
            self::s('IM3', 'Source accounts and linked wallets', 3,
                ['Linking a paying account or wallet gives the platform authority to make payments from it on your organisation’s instruction. Treat a link like a signing mandate: approved by the Owner and finance, documented, and removed as soon as it is no longer needed.',
                 'Never link a personal account, and never link an account without confirming with the bank that the organisation owns it.'],
                ['A link is a mandate.', 'Owner and finance approve.', 'Never personal accounts.'],
                'Someone asks you to link an account quickly so a batch can go out today. The paperwork is not complete. What do you do?',
                ['Link it now and finish the paperwork tomorrow.', 'Wait until the Owner and finance have approved and ownership is confirmed.', 'Link your own account temporarily.'], 1,
                'An unverified link could let funds be drawn from an account the organisation does not control.'),
        ],

        'it_officer_enterprise' => [
            self::s('IO1', 'Working under approval', 3,
                ['You carry out user administration that the IT Manager or Owner has approved. You do not decide who gets which role. Every action you take is recorded under your name.',
                 'If a request arrives without approval — even from someone senior — ask for the approval first.'],
                ['Execute approved changes only.', 'Everything is recorded under your name.'],
                'A department head emails you asking to make a colleague an approver. There is no Owner approval. What do you do?',
                ['Make the change; department heads are senior.', 'Ask for Owner approval before making any change.', 'Grant it temporarily and remove it later.'], 1,
                'Role changes need Owner approval regardless of who asks.'),
            self::s('IO2', 'Password resets and identity', 3,
                ['Before resetting anyone’s password, confirm who they are through a channel you already trust — a call back to the number on file, never a number they give you in the request.',
                 'Never ask for, and never accept, anyone’s current password or PIN. No legitimate process needs it.'],
                ['Call back on the number on file.', 'Never ask for a password or PIN.'],
                'A caller says they are the finance officer, locked out, and gives you a new number to call back. What do you do?',
                ['Call the number they gave.', 'Call the number already on file for the finance officer.', 'Reset the password by email to the address they provide.'], 1,
                'Account takeover usually starts with a convincing call. Verify through details you already hold.'),
        ],

        'it_support' => [
            self::s('IS1', 'Help without seeing', 3,
                ['You help people use the platform. You do not need to see beneficiary lists or amounts to do that, and your role does not show them. If someone shares their screen with personal data on it, ask them to close it.',
                 'Never log in as a user to “see what they see”.'],
                ['You do not need personal data to help.', 'Never log in as a user.'],
                'A user wants you to log in as them to see an error. What do you do?',
                ['Log in with their password.', 'Ask them to describe or share the error message without personal data, and escalate if needed.', 'Reset their password so you can log in.'], 1,
                'Logging in as someone else falsifies the audit trail.'),
            self::s('IS2', 'Escalate, do not improvise', 2,
                ['If a problem involves money — a batch stuck in processing, a payment someone says they did not receive — escalate to the supervisor. Do not suggest re-executing, re-uploading or paying another way.'],
                ['Money problems go to the supervisor.', 'Never suggest re-executing.'],
                'A user reports a batch stuck in “Processing”. What do you advise?',
                ['Execute it again.', 'Do not re-execute; escalate to the supervisor with the batch reference.', 'Upload a new batch for the same people.'], 1,
                'Re-executing or re-uploading risks double payment.'),
        ],

        'department_head' => [
            self::s('DH1', 'Your department, your list', 3,
                ['You see and act only within your department. You own the quality of its beneficiary lists: that every name belongs to a real programme participant and that nothing has been added without cause.',
                 'You may prepare batches. When you do, someone else approves them.'],
                ['Department-scoped access.', 'You own list quality.', 'You prepare; others approve.'],
                'You prepared a batch for your department. Who can approve it?',
                ['You, as department head.', 'An approver other than you.', 'Any uploader.'], 1,
                'Self-approval is blocked for everyone, including department heads.'),
            self::s('DH2', 'Borrowing between departments', 3,
                ['If your department draws on another’s funding source, the request needs approval from the lending side, and the reason is recorded. Borrowing is visible in reports; treat it as exceptional.'],
                ['Borrowing needs the lender’s approval.', 'Always recorded with a reason.'],
                'Your department needs to use another department’s source for one batch. What must happen?',
                ['Use it — sources are shared.', 'The lending department approves the borrowing, with a recorded reason.', 'Ask IT to move the source to your department.'], 1,
                'Borrowing moves financial responsibility; it must be approved and visible.'),
        ],

        'program_officer' => [
            self::s('PO1', 'Building a clean list', 4,
                ['Use the official template. Every row needs the beneficiary’s full name **exactly as on their Omang**, the Omang number, the phone number and the amount. For identity payments, the name and number must match the national record or the payment will be refused.',
                 'The Review stage flags invalid rows. Fix them at source; never change a name to make it “pass”.'],
                ['Official template only.', 'Name exactly as on the Omang.', 'Fix at source; never force a match.'],
                'A row fails because the name does not match the Omang number. What do you do?',
                ['Change the name until it passes.', 'Check the beneficiary’s record with the registrar and correct it at source.', 'Delete the identity number and send to the phone instead.'], 1,
                'Forcing a match defeats the identity control that stops payments reaching the wrong person.'),
            self::s('PO2', 'Limits and duplicates', 3,
                ['In the sandbox period, no single payment may exceed **P5,000**, and no grant or pension payment may exceed **P1,400**. The system blocks them; splitting a payment to get under a limit is prohibited.',
                 'Check for duplicates before submitting: the same person twice, or one phone number against several names.'],
                ['Caps: P5,000 per payment, P1,400 per grant.', 'Never split payments to beat a limit.', 'Check for duplicates.'],
                'A beneficiary is owed P2,000 from a grant programme. What do you do?',
                ['Split it into two payments of P1,000.', 'Escalate — it exceeds the P1,400 grant cap and cannot be sent as is.', 'Send it as a personal payment instead.'], 1,
                'Splitting or relabelling to beat a limit is prohibited and will be flagged.'),
            self::s('PO3', 'After rejection', 2,
                ['If an approver rejects your batch, read the reason, correct the list, and submit a new batch. You cannot approve or reject your own batch; the system will refuse.'],
                ['Read the reason, correct, resubmit.'],
                'Your batch was rejected. What is the correct next step?',
                ['Approve it yourself.', 'Correct the issue and submit a new batch for approval.', 'Ask IT to change its status to Approved.'], 1,
                'Corrections go back through the full approval route.'),
        ],

        'finance_officer' => [
            self::s('FO1', 'Confirming the source', 3,
                ['Before execution, you confirm that the paying account holds enough funds for the batch and its fees. Confirm against the bank balance, not against what someone tells you.',
                 'Confirmation is recorded against your name. If funds are short, the batch waits; it is never partially executed.'],
                ['Confirm against the actual balance.', 'Short funds mean the batch waits.'],
                'The source is P3,000 short of a batch total. What happens?',
                ['Execute the first part now and the rest later.', 'The batch waits until the source is funded; you do not confirm it.', 'Move money from another department without approval.'], 1,
                'Partial execution creates reconciliation gaps and unfair outcomes between beneficiaries.'),
            self::s('FO2', 'Reconciling executed batches', 3,
                ['After execution, reconcile: the amount debited from your account equals the payments delivered plus fees, and every row has an end state — delivered, returned, or reserved for the recipient.',
                 'Any row without an end state after 24 hours is an exception. Raise it; do not write it off.'],
                ['Debited = delivered + fees.', 'Every row reaches an end state.', 'Unresolved after 24 h = exception.'],
                'A row still shows “Pending identity” 30 hours after execution. What is it?',
                ['Normal — leave it.', 'An exception to raise, because every identity payment should resolve within 24 hours.', 'A failed payment to re-send.'], 1,
                'Identity payments are collected, reserved or returned within 24 hours. Anything older needs investigating.'),
            self::s('FO3', 'Exports and filings', 2,
                ['Exports for reconciliation or statutory filing contain personal data. Store them only in the organisation’s approved location and delete working copies when the filing is done.'],
                ['Approved storage only.', 'Delete working copies after use.'],
                'Where should a reconciliation export be kept?',
                ['On your personal laptop for convenience.', 'In the organisation’s approved storage, with working copies deleted afterwards.', 'In a shared messaging group for the team.'], 1,
                'Exports are personal data under the Data Protection Act.'),
        ],

        'approver' => [
            self::s('AP1', 'What approving means', 4,
                ['When you approve, you are stating that you checked the batch and believe it is correct. Check at least: the total against the programme budget; the number of beneficiaries against what was expected; duplicates; amounts against the sandbox caps; and anything added late.',
                 'If you have not checked it, you have not approved it — whatever the button says.'],
                ['Approval is a statement that you checked.', 'Total, count, duplicates, caps, late additions.'],
                'You are asked to approve a batch of 400 rows in two minutes before a meeting. What do you do?',
                ['Approve it — the uploader is reliable.', 'Approve only once you have checked it, even if that means after the meeting.', 'Approve half now and half later.'], 1,
                'Approval without review is the single most common control failure.'),
            self::s('AP2', 'Rejecting well', 3,
                ['Reject with a specific reason the uploader can act on: which rows, and why. “Incorrect” helps no one. Rejection is recorded and is not a mark against anyone; it is the control working.'],
                ['Specific reasons: which rows and why.', 'Rejection is the control working.'],
                'Which rejection reason is best?',
                ['“Wrong.”', '“Rows 12, 48 and 91 share one phone number; please verify with the registrar.”', '“Please redo.”'], 1,
                'A precise reason lets the uploader fix the right thing quickly.'),
            self::s('AP3', 'Dual control', 2,
                ['Above your organisation’s threshold, a batch needs two different approvers. The second approver checks independently; they do not rely on the first approval.'],
                ['Above threshold: two independent approvals.'],
                'You are the second approver on a large batch. The first approver has already approved. How much do you check?',
                ['Nothing — it is already approved.', 'Check it fully and independently.', 'Only the total.'], 1,
                'Dual control only works if the second check is independent.'),
        ],

        'senior_approver' => [
            self::s('SA1', 'Above the threshold', 3,
                ['You approve the batches that carry the most risk. Your approval is usually the second one. Check independently, as though the first approval did not exist.',
                 'You may be asked to decide escalations. Record your reasoning in the batch notes.'],
                ['Independent second check.', 'Record reasoning on escalations.'],
                'Why must a senior approver check a batch independently of the first approval?',
                ['Because the first approver may be junior.', 'Because a second check that relies on the first adds no protection.', 'Because the system requires a comment.'], 1,
                'Two approvals are only two controls if each is a real check.'),
            self::s('SA2', 'Saying no under pressure', 2,
                ['Large batches arrive under the most pressure. Deadlines are real, but a wrong payment to thousands of people costs far more than a delay of hours. You have the authority to hold a batch; use it.'],
                ['You may hold any batch you are not satisfied with.'],
                'A senior official insists a large batch must be approved immediately, but you have not finished checking. What do you do?',
                ['Approve it to avoid conflict.', 'Finish checking before approving, and record the pressure in the notes.', 'Pass it to another approver who has not checked it.'], 1,
                'Pressure is a red flag in its own right. Record it.'),
        ],

        'supervisor' => [
            self::s('SU1', 'The Attention inbox', 3,
                ['You watch the Attention inbox: batches awaiting a step, failed rows, payments pending identity for more than 24 hours, and anything stuck in processing. Each item has an owner; your job is to make sure someone is moving it.'],
                ['Every exception has an owner.', 'Nothing sits unmoved.'],
                'A batch has shown “Processing” for three hours. What do you do?',
                ['Execute it again.', 'Escalate with the batch reference; do not re-execute.', 'Cancel it and upload a new one.'], 1,
                'A stuck batch is investigated, never repeated.'),
            self::s('SU2', 'Exceptions that need judgement', 3,
                ['Some exceptions need a decision: a beneficiary disputing a payment, an identity claim refused, a death reported. Decide within your authority, record why, and escalate what exceeds it. **If a beneficiary is reported deceased, stop further payments to that identity immediately** and notify the programme and compliance contacts.'],
                ['Decide, record, escalate.', 'Reported death: stop payments at once.'],
                'A family member reports that a beneficiary has died. What do you do first?',
                ['Let them collect the next payment for funeral costs.', 'Stop further payments to that identity and notify the programme and compliance contacts.', 'Change the beneficiary’s phone number to the family member’s.'], 1,
                'Payments to a deceased identity are a known fraud route. The estate is handled through proper channels.'),
        ],

        'beneficiary_registrar' => [
            self::s('BR1', 'Registering a person properly', 4,
                ['Record the name **exactly as it appears on the Omang**, including spacing and order, and the Omang number. The identity payment control compares both; a single character wrong means the person cannot collect.',
                 'Tell each beneficiary how they will be paid and how to collect, and record that they were told.'],
                ['Name exactly as on the Omang.', 'Tell them how they will be paid; record it.'],
                'A beneficiary’s Omang says “Kgomotso Neo Molefe” but they call themselves “Neo Molefe”. What do you record?',
                ['“Neo Molefe”, as they prefer.', '“Kgomotso Neo Molefe”, exactly as on the Omang.', 'Both, in one field.'], 1,
                'The identity match uses the official record. The preferred name can be noted separately.'),
            self::s('BR2', 'Changes to a record', 3,
                ['Name changes (for example after marriage), new phone numbers and deaths are the changes most often used for fraud. Verify each against a document, record who verified it, and never change a record on a phone call alone.',
                 'A reported death stops payments immediately; the record is marked, not deleted.'],
                ['Verify every change against a document.', 'Never change a record on a phone call alone.', 'Deaths are marked, not deleted.'],
                'Someone calls asking to change a beneficiary’s phone number “because she lost her phone”. What do you do?',
                ['Change it — lost phones are common.', 'Require the beneficiary in person with her Omang, or verification through an approved channel.', 'Change it and tell the supervisor later.'], 1,
                'Phone number changes redirect payments. Verify the person, not the story.'),
        ],

        'auditor' => [
            self::s('AU1', 'Read-only, organisation-wide', 3,
                ['You see across all departments so that you can test the controls independently. You cannot change anything, and you should never ask anyone to change something on your behalf while you are reviewing it.'],
                ['See everything; change nothing.', 'Independence first.'],
                'During a review you find a batch you believe is wrong. What do you do?',
                ['Ask the uploader to edit it quietly.', 'Record the finding and report it through your audit reporting line.', 'Ask IT to delete it.'], 1,
                'An auditor who fixes things is no longer independent.'),
            self::s('AU2', 'What good evidence looks like', 3,
                ['The audit trail records every role change, approval, rejection, execution and export, with who and when. Sample batches end to end: prepared by one person, approved by another, funded by finance, executed by an authorised person, every row with an end state.',
                 'Look for the absence of things too: batches with no rejection ever, approvers who approve in seconds, overrides nobody reviewed.'],
                ['Sample end to end.', 'Look for what is missing, not only what is there.'],
                'Which pattern deserves a closer look?',
                ['An approver who sometimes rejects batches.', 'An approver whose approvals consistently happen seconds after submission.', 'A batch with a detailed rejection reason.'], 1,
                'Very fast approvals suggest review is not actually happening.'),
        ],

        'viewer' => [
            self::s('VW1', 'Seeing is a responsibility', 2,
                ['You can view information to do your work but cannot change anything. What you see is confidential: do not copy, photograph or share it outside the people who need it for the same purpose.'],
                ['View only.', 'Confidential; do not share.'],
                'A colleague outside the programme asks you to send them a list you can see. What do you do?',
                ['Send it — they work for the same organisation.', 'Decline and refer them to their own manager to request access properly.', 'Send a screenshot instead.'], 1,
                'Access is by role and purpose, not by organisation.'),
        ],

        ];
    }

    // =================================================================
    // MINI MANUALS — always available
    // =================================================================
    public static function manual(string $code): array
    {
        $common = [
            'escalate' => [
                ['When' => 'Anything involving money that looks wrong', 'Who' => 'Your supervisor, then your compliance contact'],
                ['When' => 'Personal data may have been exposed', 'Who' => 'Your compliance contact — immediately'],
                ['When' => 'The platform is unavailable', 'Who' => 'Wait for the service notice; tell your supervisor'],
                ['When' => 'You suspect fraud', 'Who' => 'Your compliance contact; you are protected when acting in good faith'],
            ],
            'never_all' => [
                'Share your login or act in someone else’s session.',
                'Pay anyone again to “fix” a missing payment.',
                'Move personal data to personal devices, email or messaging groups.',
            ],
        ];

        $m = [
            'owner' => ['one' => 'Set the structure and prove the controls work; do not run payments yourself.',
                'can' => ['Appoint and change roles (executed by IT)', 'Set dual-control thresholds', 'See every department and report'],
                'cannot' => ['Approve a batch you prepared'],
                'tasks' => [
                    ['Review access each month', ['Open Settings → Users.', 'Compare each role against the organisation chart.', 'Ask IT to remove anything that no longer matches, the same day.']],
                    ['Change the approval threshold', ['Open Settings.', 'Set the amount above which two approvers are required.', 'Record the reason; the change is logged against you.']],
                    ['Monthly evidence review', ['Reports → audit trail: role and threshold changes.', 'Batches → rejected and failed, with reasons.', 'Attention → anything unresolved.']],
                ],
                'never' => ['Lower controls to clear a backlog.', 'Prepare, approve and execute the same batch.']],
            'it_manager_enterprise' => ['one' => 'Keep access exactly matched to each person’s job, on the day it changes.',
                'can' => ['Create, change and deactivate users', 'Link and unlink source accounts with approval'],
                'cannot' => ['Grant yourself a business role', 'Approve or execute batches'],
                'tasks' => [
                    ['Offboard a leaver', ['Settings → Users → find the person.', 'Deactivate on their last working day.', 'Confirm no per-user overrides remain.']],
                    ['Move someone to a new role', ['Obtain Owner approval.', 'Remove the old role first.', 'Grant the new role; note the approval reference.']],
                ],
                'never' => ['Link an account without confirmed ownership and approval.', 'Leave overrides unreviewed.']],
            'it_officer_enterprise' => ['one' => 'Carry out approved user changes, carefully and on the record.',
                'can' => ['Create and update users on approval', 'Reset passwords after verifying identity'],
                'cannot' => ['Decide who gets which role'],
                'tasks' => [['Reset a password', ['Call back on the number already on file.', 'Confirm identity.', 'Reset; never ask for the old password.']]],
                'never' => ['Act on a role request without Owner approval.', 'Call back on a number given in the request.']],
            'it_support' => ['one' => 'Help people use the platform without handling their data.',
                'can' => ['Guide users', 'Escalate issues'],
                'cannot' => ['See beneficiary lists', 'Change batches'],
                'tasks' => [['Handle a stuck batch report', ['Note the batch reference and time.', 'Tell the user not to re-execute.', 'Escalate to the supervisor.']]],
                'never' => ['Log in as a user.', 'Suggest re-executing or re-uploading.']],
            'department_head' => ['one' => 'Own your department’s lists and act only within your department.',
                'can' => ['Prepare batches for your department', 'Approve borrowing requests to your source'],
                'cannot' => ['Approve batches you prepared', 'See other departments'],
                'tasks' => [['Request to use another department’s source', ['Raise the borrowing request with the reason.', 'Wait for the lending department’s approval.', 'Proceed only once approved.']]],
                'never' => ['Add names you cannot trace to a programme.']],
            'program_officer' => ['one' => 'Prepare accurate beneficiary lists, exactly as the official records show.',
                'can' => ['Create batches', 'Correct rows the Review stage flags'],
                'cannot' => ['Approve or reject your own batch'],
                'tasks' => [
                    ['Prepare a batch', ['Use the official template.', 'Name exactly as on the Omang; Omang number; phone; amount.', 'Upload, then fix every row the Review stage flags.', 'Submit for approval.']],
                    ['Handle a rejection', ['Read the reason.', 'Correct at source with the registrar if needed.', 'Submit a new batch.']],
                ],
                'never' => ['Change a name to force a match.', 'Split a payment to get under a limit (P5,000 per payment; P1,400 per grant).']],
            'finance_officer' => ['one' => 'Confirm funds before execution and reconcile every batch after it.',
                'can' => ['Confirm source funding', 'Export for reconciliation and filing'],
                'cannot' => ['Confirm a source that is short'],
                'tasks' => [
                    ['Confirm a source', ['Check the actual bank balance.', 'Compare with batch total plus fees.', 'Confirm only if fully covered.']],
                    ['Reconcile after execution', ['Debited amount = delivered + fees.', 'Every row: delivered, returned or reserved.', 'Raise any row unresolved after 24 hours.']],
                ],
                'never' => ['Confirm on someone’s word instead of the balance.', 'Keep exports outside approved storage.']],
            'approver' => ['one' => 'Approve only what you have actually checked.',
                'can' => ['Approve or reject batches you did not prepare'],
                'cannot' => ['Approve or reject your own batch'],
                'tasks' => [['Review a batch', ['Total vs programme budget.', 'Row count vs expected.', 'Duplicates: names, phones, accounts.', 'Amounts within caps.', 'Anything added late.', 'Approve, or reject with row numbers and reasons.']]],
                'never' => ['Approve without checking because of time pressure.', 'Rely on another approver’s check.']],
            'senior_approver' => ['one' => 'Provide the independent second check on the largest batches.',
                'can' => ['Approve above-threshold batches', 'Decide escalations'],
                'cannot' => ['Approve your own batch'],
                'tasks' => [['Second approval', ['Ignore the first approval while checking.', 'Run the full review.', 'Record reasoning; note any pressure received.']]],
                'never' => ['Treat the first approval as your check.']],
            'supervisor' => ['one' => 'Make sure every exception has an owner and keeps moving.',
                'can' => ['See the Attention inbox', 'Decide exceptions within your authority'],
                'cannot' => ['Re-execute a stuck batch'],
                'tasks' => [
                    ['Stuck in processing', ['Do not re-execute.', 'Escalate with the reference.', 'Track to resolution.']],
                    ['Reported death', ['Stop payments to that identity immediately.', 'Notify programme and compliance contacts.', 'Record who reported it and when.']],
                ],
                'never' => ['Let a family member collect a deceased person’s payment.']],
            'beneficiary_registrar' => ['one' => 'Keep every beneficiary record exact and every change verified.',
                'can' => ['Register beneficiaries', 'Update records against documents'],
                'cannot' => ['Change a record on a phone call alone'],
                'tasks' => [
                    ['Register someone', ['Name exactly as on the Omang.', 'Omang number.', 'Tell them how they will be paid; record that you did.']],
                    ['Change a phone number or name', ['See the person with their Omang, or use an approved verification channel.', 'Record the document and who verified it.']],
                ],
                'never' => ['Delete a deceased person’s record — mark it.']],
            'auditor' => ['one' => 'Test the controls independently; change nothing.',
                'can' => ['See every department and the audit trail'],
                'cannot' => ['Change anything, or ask others to change things during review'],
                'tasks' => [['Sample a batch end to end', ['Preparer ≠ approver ≠ executor.', 'Finance confirmation present.', 'Every row has an end state.', 'Timing: approvals not suspiciously fast.']]],
                'never' => ['Fix what you find — report it.']],
            'viewer' => ['one' => 'Use what you see for your work only.',
                'can' => ['View information your role allows'],
                'cannot' => ['Change anything', 'Share outside the people who need it'],
                'tasks' => [['Someone asks for data', ['Do not send it.', 'Refer them to their manager to request access.']]],
                'never' => ['Photograph or forward screens.']],
        ];

        $r = $m[$code] ?? null;
        if (!$r) {
            return [];
        }
        $r['escalate'] = $common['escalate'];
        $r['never'] = array_merge($r['never'], $common['never_all']);
        return $r;
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** Section builder so role modules read cleanly. */
    private static function s(string $id, string $title, int $minutes, array $body, array $points,
                              string $q, array $options, int $answer, string $why): array
    {
        return ['id' => $id, 'title' => $title, 'minutes' => $minutes, 'body' => $body, 'points' => $points,
                'check' => ['q' => $q, 'options' => $options, 'answer' => $answer, 'why' => $why]];
    }

    /** Every section a role must complete, foundation first. */
    public static function forRole(string $code): array
    {
        if (!isset(self::ROLES[$code])) {
            return [];
        }
        return array_merge(self::foundation(), self::role($code));
    }

    /** Fingerprint of one section. Editing a section invalidates only that section. */
    public static function sectionHash(array $s): string
    {
        return hash('sha256', json_encode([$s['title'], $s['body'], $s['points'], $s['check']], JSON_UNESCAPED_UNICODE));
    }

    /** Fingerprint of a role's whole curriculum. */
    public static function curriculumHash(string $code): string
    {
        return hash('sha256', implode('|', array_map([self::class, 'sectionHash'], self::forRole($code))));
    }

    /** Minimum honest reading time in seconds (≈200 words a minute, floor 20 s). */
    public static function minSeconds(array $s): int
    {
        $words = str_word_count(strip_tags(implode(' ', $s['body']) . ' ' . implode(' ', $s['points'])));
        return max(20, (int)ceil($words / 200 * 60 * 0.6));   // 60% of full reading time: skimming allowed, skipping not
    }
}
