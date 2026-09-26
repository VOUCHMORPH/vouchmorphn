<?php
/**
 * partials/batch_display.php — what a batch's status and destinations
 * mean, for every page that lists or shows batches.
 *
 * STATUS. Each role's batch list used to carry its own hand-written
 * IN (...) list, written when a batch went straight from 'approved' to
 * 'completed'. Asynchronous execution (BatchExecutionQueueService) added
 * 'executing' and 'partially_completed', and none of the lists learned
 * them; imports/source_input.php also writes 'DRAFT' in capitals while the
 * lists spelled 'draft'. So a batch vanished from every view except the
 * Owner's the moment it was executed, and never came back. The groups
 * below are complete, and vm_batch_status_in() compares case-insensitively.
 *
 * DESTINATIONS. A batch's recipients live in disbursement_destinations;
 * the lists showed only a count. vm_destination_summary() turns the
 * institutions a batch pays into into one short line.
 */

if (!function_exists('vm_batch_statuses')) {
    /**
     * 'before_approval' — not yet decided.
     * 'approved_onward' — approved, and everything an approved batch can
     *                     turn into once released for payment.
     * 'released'        — released for payment: still paying out, or done.
     * 'finished'        — paying out has ended, fully or with failures.
     */
    function vm_batch_statuses(string $group): array
    {
        $groups = [
            'before_approval' => ['draft', 'pending', 'pending_approval'],
            'approved_onward' => ['approved', 'executing', 'executed', 'completed', 'partially_completed', 'partial_success', 'failed'],
            'released'        => ['executing', 'executed', 'completed', 'partially_completed', 'partial_success', 'failed'],
            'finished'        => ['executed', 'completed', 'partially_completed', 'partial_success'],
        ];
        if (!isset($groups[$group])) {
            throw new InvalidArgumentException("Unknown batch status group: {$group}");
        }
        return $groups[$group];
    }

    /**
     * "LOWER(status) IN ('a', 'b')" for a fixed list of statuses. Only ever
     * pass the constant lists above — never request input, which must stay
     * a bound parameter.
     */
    function vm_batch_status_in(array $statuses, string $column = 'status'): string
    {
        $quoted = [];
        foreach ($statuses as $status) {
            $quoted[] = "'" . str_replace("'", "''", strtolower($status)) . "'";
        }
        return $quoted ? "LOWER({$column}) IN (" . implode(', ', array_unique($quoted)) . ')' : '1=0';
    }

    /** Where one destination's money goes, in words a person reads. */
    function vm_destination_label(?string $institution): string
    {
        $institution = trim((string)$institution);
        if ($institution === '') {
            return 'Unknown';
        }
        // Identity recipients have no account yet: they claim at an agent.
        return $institution === 'IDENTITY_RECIPIENT' ? 'ID claim' : $institution;
    }

    /**
     * "ZURUBANK, ID claim · 3 recipients" from the comma-joined institutions
     * and the recipient count a list query aggregated per batch.
     */
    function vm_destination_summary(?string $institutions, $recipients): string
    {
        $recipients = (int)$recipients;
        if ($recipients === 0) {
            return 'No recipients yet';
        }
        $labels = [];
        foreach (explode(',', (string)$institutions) as $institution) {
            if (trim($institution) !== '') {
                $labels[vm_destination_label($institution)] = true;
            }
        }
        $where = $labels ? implode(', ', array_keys($labels)) : 'Unknown';
        return $where . ' · ' . $recipients . ' recipient' . ($recipients === 1 ? '' : 's');
    }
}
