<?php
/**
 * File: config/workflow_engine.php
 * Purpose: Describe workflow stages and validate department handoffs.
 * Author: Imran IT Intern
 *
 * These helpers describe department progress and validate configured handoff
 * destinations.
 */

// ----------------------------------------------
// Display the Department Progress Stage
// ----------------------------------------------
// Display the stored workflow stage instead of treating every waiting state as active work.

// --------------------------------------
// Get Department Progress Label
// --------------------------------------
function getDepartmentProgressLabel(string $status): string {
    return match ($status) {
        'AWAITING_CONSOLIDATION' => 'Work approved. Awaiting consolidation review',
        'FORWARDED' => 'Forwarded. Awaiting receiving department review',
        'WAITING_FOR_REVIEW' => 'Awaiting review of previous department work',
        'WAITING_FOR_HANDOFF' => 'Waiting for previous department handoff',
        'WAITING_FOR_GROUP' => 'Waiting for consolidation group submissions',
        'CONSOLIDATION_APPROVED' => 'Approved in consolidation package',
        'APPROVED' => 'Approved by BD',
        'COMPLETED' => 'Department work completed. Awaiting BD review',
        'REJECTED' => 'Rejected. Department rework required',
        'IN_PROGRESS' => 'IN_PROGRESS',
        default => str_replace('_', ' ', $status),
    };
}

// --------------------------------------------
// Load available handoff destinations
// --------------------------------------------
function getAvailableHandoffOptions($pdo, $project_id, $current_dept_id) {
    $stmt = $pdo->prepare("
        SELECT r.to_department_id, target.name AS target_name, target.is_bd_department AS target_is_bd,
               required.name AS required_name, r.is_mandatory
        FROM bd_department_workflow_rules r
        LEFT JOIN bd_departments target ON target.id = r.to_department_id
        LEFT JOIN bd_departments required ON required.id = r.required_involved_department_id
        WHERE r.from_department_id = :dept_id
          AND r.is_active = 1
          AND EXISTS (
              SELECT 1
              FROM bd_project_involved_departments source_pid
              WHERE source_pid.project_id = :source_project_id
                AND source_pid.department_id = :source_dept_id
          )
          AND (
              r.required_involved_department_id IS NULL
              OR EXISTS (
                  SELECT 1
                  FROM bd_project_involved_departments pid
                  WHERE pid.project_id = :project_id
                    AND pid.department_id = r.required_involved_department_id
              )
          )
        ORDER BY r.id ASC
    ");
    $stmt->execute([
        'dept_id' => $current_dept_id,
        'source_project_id' => $project_id,
        'source_dept_id' => $current_dept_id,
        'project_id' => $project_id,
    ]);

    $routes = $stmt->fetchAll();
    $mandatoryMatched = false;
    foreach ($routes as $route) {
        if ((int) $route['is_mandatory'] === 1 && $route['required_name']) {
            $mandatoryMatched = true;
        }
    }
    $options = [];
    foreach ($routes as $route) {
        if ($mandatoryMatched && (int) $route['is_mandatory'] !== 1) {
            continue;
        }
        $is_bd = $route['to_department_id'] === null;
        if (!$is_bd && $route['target_is_bd']) {
            continue;
        }
        $destination = $is_bd ? 'Business Development (BD)' : $route['target_name'];
        if (!$destination) {
            continue;
        }

        $options[] = [
            'type' => $is_bd ? 'BD' : 'DEPT',
            'id' => $route['to_department_id'],
            'name' => 'Submit to: ' . $destination . ' department',
            'condition' => $route['required_name']
                ? 'Only available when ' . $route['required_name'] . ' is included.'
                : null,
        ];
    }

    return $options;
}

// ------------------------------------------------
// Verify the selected handoff destination
// ------------------------------------------------
function isAllowedHandoff($pdo, $project_id, $current_dept_id, $target_route) {
    foreach (getAvailableHandoffOptions($pdo, $project_id, $current_dept_id) as $option) {
        $value = $option['type'] === 'BD' ? 'BD' : (string) $option['id'];
        if ($value === (string) $target_route) {
            return true;
        }
    }
    return false;
}
