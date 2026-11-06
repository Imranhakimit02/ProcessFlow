<?php
/**
 * File: config/approval_history.php
 * Purpose: Load recorded request and finalization decisions for an approver.
 * Author: Imran IT Intern
 *
 * This helper loads the approver decisions matching the selected date and
 * status.
 */

// -------------------------------------------
// Load the Approver Decision History
// -------------------------------------------
function getApproverHistory(PDO $pdo, int $actor_id, string $history_date = '', string $history_status = ''): array {
    $sql = "SELECT history.* FROM (
        SELECT r.id AS record_id, r.id AS request_id, r.project_id,
               'Project Request' AS decision_stage, r.status AS decision_status,
               r.updated_at AS decision_date, r.decision_remarks AS decision_remarks,
               r.payload_json, p.project_code, p.title AS project_title,
               p.status AS project_status, u.full_name AS requester_name
        FROM bd_project_requests r
        LEFT JOIN bd_projects p ON p.id = r.project_id
        LEFT JOIN bd_users u ON u.id = r.requester_id
        WHERE r.reviewed_by_id = :request_actor_id AND r.status IN ('APPROVED', 'REJECTED')
        UNION ALL
        SELECT a.id AS record_id, NULL AS request_id, a.project_id,
               'Project Finalization' AS decision_stage,
               CASE WHEN a.event_type = 'FINALIZATION_APPROVED' THEN 'APPROVED' ELSE 'REJECTED' END AS decision_status,
               a.created_at AS decision_date, a.remarks_or_comments AS decision_remarks,
               NULL AS payload_json, p.project_code, p.title AS project_title,
               p.status AS project_status, u.full_name AS requester_name
        FROM bd_project_timeline_audits a
        JOIN bd_projects p ON p.id = a.project_id
        LEFT JOIN bd_users u ON u.id = p.requester_id
        WHERE a.actor_id = :finalization_actor_id
          AND a.event_type IN ('FINALIZATION_APPROVED', 'FINALIZATION_REJECTED')
          AND a.stage_title <> 'Project Finalization Confirmed by CEO PIC'
    ) history WHERE 1 = 1";
    $params = ['request_actor_id' => $actor_id, 'finalization_actor_id' => $actor_id];
    if ($history_date !== '') {
        $sql .= ' AND DATE(history.decision_date) = :history_date';
        $params['history_date'] = $history_date;
    }
    if ($history_status !== '') {
        $sql .= ' AND history.decision_status = :history_status';
        $params['history_status'] = $history_status;
    }
    $sql .= ' ORDER BY history.decision_date DESC, history.decision_stage ASC, history.record_id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
