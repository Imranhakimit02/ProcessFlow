<?php
/**
 * File: config/project_creation.php
 * Purpose: Create a project from an approved request.
 * Author: Imran IT Intern
 *
 * This helper creates the project, department assignments and audit record from
 * an approved request.
 */

// --------------------------------------
// Load Requester Access Helpers
// --------------------------------------
require_once __DIR__ . '/requester_access.php';

// ------------------------------------
// Create the approved project
// ------------------------------------
function createApprovedProject(PDO $pdo, array $request, int $approverId, string $approvalRemarks = ''): array {
    $payload = json_decode($request['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $departmentIds = array_values(
        array_unique(array_map('intval', json_decode($request['department_ids_json'], true, 512, JSON_THROW_ON_ERROR))),
    );
    if (!$departmentIds) {
        throw new RuntimeException('The request has no bd_departments.');
    }
    $requester = $pdo->prepare(
        'SELECT u.* FROM bd_users u LEFT JOIN bd_departments d ON d.id=u.department_id WHERE u.id=? AND u.is_active=1 AND d.can_request_projects = 1 AND u.is_deleted = 0',
    );
    $requester->execute([(int) $request['requester_id']]);
    $requesterData = $requester->fetch(PDO::FETCH_ASSOC);
    if (!$requesterData || empty(accessRoleForUser($pdo, (int) $request['requester_id'])['can_request'])) {
        throw new RuntimeException('The requester no longer has project request access.');
    }
    $approverPermissions = accessRoleForUser($pdo, $approverId);
    if ((int) $request['approver_id'] !== $approverId || empty($approverPermissions['can_approve'])) {
        throw new RuntimeException('This user is not the selected eligible request approver.');
    }
    $finalApproverId = $approverId;

    $pdo->beginTransaction();
    try {
        $prefix =
            strtoupper(($payload['country_code'] ?? '') . ($payload['renewable_energy_type'] ?? '')) .
            '-' .
            date('y') .
            '-';
        $codeStmt = $pdo->prepare('SELECT COUNT(*) FROM bd_projects WHERE project_code LIKE ?');
        $codeStmt->execute([$prefix . '%']);
        $code = $prefix . str_pad((string) ((int) $codeStmt->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("INSERT INTO bd_projects
            (project_code,title,project_ref_no,description,requester_id,registration_date,project_deadline_date,
             source_of_lead,lead_company_name,lead_pic_name,lead_pic_contact,lead_pic_email,client_company_name,
             project_nature,sector,renewable_energy_type,country_code,region,contract_type,capacity_unit,est_mw,
             est_cod,remarks,status,final_approver_id,final_approval_status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'IN_PROGRESS',?,'NOT_SUBMITTED')");
        $stmt->execute([
            $code,
            $payload['title'],
            $payload['project_ref_no'],
            $payload['description'],
            (int) $request['requester_id'],
            $payload['registration_date'],
            $payload['project_deadline_date'],
            $payload['source_of_lead'],
            $payload['lead_company_name'],
            $payload['lead_pic_name'],
            $payload['lead_pic_contact'],
            $payload['lead_pic_email'],
            $payload['client_company_name'],
            $payload['project_nature'],
            $payload['sector'],
            $payload['renewable_energy_type'],
            $payload['country_code'],
            $payload['region'],
            $payload['contract_type'],
            $payload['capacity_unit'],
            $payload['est_mw'],
            $payload['est_cod'],
            $payload['remarks'],
            $finalApproverId,
        ]);
        $projectId = (int) $pdo->lastInsertId();
        $flags = $pdo->query('SELECT id,is_consolidation_department FROM bd_departments')->fetchAll(PDO::FETCH_ASSOC);
        $consolidators = [];
        foreach ($flags as $row) {
            if ((int) $row['is_consolidation_department'] === 1) {
                $consolidators[] = (int) $row['id'];
            }
        }
        $conditional = $pdo->prepare(
            'SELECT DISTINCT required_involved_department_id, from_department_id FROM bd_department_workflow_rules WHERE is_active=1 AND required_involved_department_id IS NOT NULL',
        );
        $conditional->execute();
        $waitingIds = [];
        foreach ($conditional->fetchAll(PDO::FETCH_ASSOC) as $route) {
            if (in_array((int) $route['from_department_id'], $departmentIds, true)) {
                $waitingIds[] = (int) $route['required_involved_department_id'];
            }
        }
        $insertDept = $pdo->prepare(
            'INSERT INTO bd_project_involved_departments (project_id,department_id,status) VALUES (?,?,?)',
        );
        foreach ($departmentIds as $departmentId) {
            $status = in_array($departmentId, $consolidators, true)
                ? 'WAITING_FOR_GROUP'
                : (in_array($departmentId, $waitingIds, true)
                    ? 'WAITING_FOR_HANDOFF'
                    : 'IN_PROGRESS');
            $insertDept->execute([$projectId, $departmentId, $status]);

            $notificationNote = match ($status) {
                'WAITING_FOR_GROUP'
                    => 'Department notified about the project; work is waiting for the included departments to submit to the consolidation stage.',
                'WAITING_FOR_HANDOFF'
                    => 'Department notified about the project; work is waiting for the required upstream department handoff.',
                default => 'Department notified that the project is active and work may begin.',
            };
            $notificationAudit = $pdo->prepare("INSERT INTO bd_project_timeline_audits
                (token, project_id, actor_id, from_department_id, to_department_id, event_type, stage_title, work_snapshot, remarks_or_comments)
                VALUES (?, ?, ?, ?, ?, 'PROJECT_DEPARTMENT_NOTIFIED', 'Project Notification Sent to Department', ?, ?)");
            $notificationAudit->execute([
                strtoupper(bin2hex(random_bytes(8))),
                $projectId,
                $approverId,
                $requesterData['department_id'],
                $departmentId,
                'Project notification only, department task assignment is pending.',
                $notificationNote,
            ]);
        }
        $audit = $pdo->prepare(
            "INSERT INTO bd_project_timeline_audits (token,project_id,actor_id,event_type,stage_title,work_snapshot,remarks_or_comments) VALUES (?,?,?, 'PROJECT_CREATED','Project Approved and Activated',?,?)",
        );
        $audit->execute([
            strtoupper(bin2hex(random_bytes(8))),
            $projectId,
            $approverId,
            'Project-level activation, department notifications are recorded separately.',
            "Project [$code] activated " .
            'after selected upper level approval' .
            ' and assigned to ' .
            count($departmentIds) .
            ' department(s).',
        ]);
        $update = $pdo->prepare(
            "UPDATE bd_project_requests SET status='APPROVED', reviewed_by_id=?, project_id=?, decision_remarks=? WHERE id=? AND status='PENDING'",
        );
        $update->execute([$approverId, $projectId, trim($approvalRemarks) ?: 'Approved', (int) $request['id']]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('This request has already been reviewed.');
        }
        $pdo->commit();
        return ['id' => $projectId, 'code' => $code];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
