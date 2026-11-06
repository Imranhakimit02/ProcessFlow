<?php
/**
 * File: config/audit_log.php
 * Purpose: Capture task and deliverable snapshots for the audit trail.
 * Author: Imran IT Intern
 *
 * This helper combines the recorded task instructions and deliverables for the
 * project audit history.
 */

// --------------------------------------
// Build the Audit Work Snapshot
// --------------------------------------
if (!function_exists('buildAuditWorkSnapshot')) {
    /** Keep a point-in-time copy of the task and submitted work in the audit trail. */
    function buildAuditWorkSnapshot(?string $taskDescription, ?string $deliverablesSummary = null): ?string {
        $lines = [];
        $taskDescription = trim((string) $taskDescription);
        $deliverablesSummary = trim((string) $deliverablesSummary);

        if ($taskDescription !== '') {
            $lines[] = 'Task: ' . $taskDescription;
        }
        if ($deliverablesSummary !== '') {
            $lines[] = 'Submitted work: ' . $deliverablesSummary;
        }

        return $lines ? implode("\n", $lines) : null;
    }
}
