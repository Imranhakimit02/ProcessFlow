<?php
/**
 * File: viewer.php
 * Purpose: Provide authorized read-only access to all project work.
 * Author: Imran IT Intern
 *
 * This page gathers project details, department work and audit events for
 * authorized viewers.
 */

// -----------------------------------
// Load the page dependencies
// -----------------------------------
require_once __DIR__ . '/config/portal_session.php';
startPortalSession('VIEWER');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/access_roles.php';
require_once __DIR__ . '/config/page_helpers.php';
require_once __DIR__ . '/config/custom_project_fields.php';
$customLabels = customProjectFieldLabels($pdo);

// -------------------------------------
// Verify project viewer access
// -------------------------------------
$actorId = (int) ($_SESSION['approver_user_id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT u.*, d.name AS department_name FROM bd_users u LEFT JOIN bd_departments d ON d.id=u.department_id WHERE u.id=? AND u.role IN ('VIEWER','GROUP_CEO') AND u.is_active=1",
);
$stmt->execute([$actorId]);
$actor = $stmt->fetch();
if (!$actor) {
    header('Location: approver/approver_login.php');
    exit();
}
requireWorkspacePermission($pdo, $actorId, 'can_view_all');

require_once __DIR__ . '/config/viewer_filters.php';
$filterDepartments = $pdo->query('SELECT id,name FROM bd_departments ORDER BY name')->fetchAll();
$filters = viewerFilters(['department' => $_GET['department'] ?? 0], $filterDepartments);
$sectionFilters = [];
foreach (['projects', 'requests', 'work', 'activity'] as $scope) {
    $sectionFilters[$scope] = viewerFilters(
        [
            'department' => $filters['department'],
            'date_from' => $_GET[$scope . '_from'] ?? '',
            'date_to' => $_GET[$scope . '_to'] ?? '',
        ],
        $filterDepartments,
    );
    $sectionFilters[$scope]['errors'] = array_merge($filters['errors'], $sectionFilters[$scope]['errors']);
    $filters[$scope . '_from'] = $sectionFilters[$scope]['date_from'];
    $filters[$scope . '_to'] = $sectionFilters[$scope]['date_to'];
}

$departmentId = $filters['department'];
$projectId = filter_var($_GET['project'] ?? 0, FILTER_VALIDATE_INT);
if ($projectId === false || $projectId < 0) {
    http_response_code(404);
    endWithFeedback('Project not found.');
}
$project = null;
$projects = $requests = $departments = $tasks = $events = [];
$statusText = fn($value) => str_replace(
    ['Hod', 'Ceo'],
    ['HOD', 'CEO'],
    ucwords(strtolower(str_replace('_', ' ', (string) $value))),
);
$escape = fn($value) => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
if ($projectId) {
    $query = $pdo->prepare(
        'SELECT p.*,u.full_name AS requester_name FROM bd_projects p LEFT JOIN bd_users u ON u.id=p.requester_id WHERE p.id=?',
    );
    $query->execute([$projectId]);
    $project = $query->fetch();
    if (!$project) {
        http_response_code(404);
        endWithFeedback('Project not found.');
    }
    $params = ['project' => $projectId];
    $where = 'pid.project_id=:project';
    if ($departmentId) {
        $where .= ' AND pid.department_id=:department';
        $params['department'] = $departmentId;
    }
    if ($filters['errors']) {
        $where .= ' AND 1=0';
    }
    $query = $pdo->prepare(
        "SELECT pid.department_id,d.name,pid.status FROM bd_project_involved_departments pid JOIN bd_departments d ON d.id=pid.department_id WHERE $where ORDER BY d.name",
    );
    $query->execute($params);
    $departments = $query->fetchAll();
    $params = ['project' => $projectId];
    $where = 't.project_id=:project';
    if ($departmentId) {
        $where .= ' AND t.department_id=:department';
        $params['department'] = $departmentId;
    }
    $where .= viewerDateSql($sectionFilters['work'], 't.updated_at', $params);
    $query = $pdo->prepare(
        "SELECT t.*,d.name AS department_name,u.full_name AS staff_name FROM bd_department_tasks t JOIN bd_departments d ON d.id=t.department_id LEFT JOIN bd_users u ON u.id=t.assigned_to_staff_id WHERE $where ORDER BY t.updated_at DESC,t.id DESC",
    );
    $query->execute($params);
    $tasks = $query->fetchAll();
    $params = ['project' => $projectId];
    $where = 'a.project_id=:project';
    if ($departmentId) {
        $where .=
            ' AND (a.from_department_id=:source OR a.to_department_id=:target OR u.department_id=:actor_department)';
        $params += ['source' => $departmentId, 'target' => $departmentId, 'actor_department' => $departmentId];
    }
    $where .= viewerDateSql($sectionFilters['activity'], 'a.created_at', $params);
    $query = $pdo->prepare(
        "SELECT a.*,u.full_name AS actor_name,d.name AS actor_department FROM bd_project_timeline_audits a LEFT JOIN bd_users u ON u.id=a.actor_id LEFT JOIN bd_departments d ON d.id=u.department_id WHERE $where ORDER BY a.created_at DESC,a.id DESC",
    );
    $query->execute($params);
    $events = $query->fetchAll();
} else {
    $params = [];
    $where = '1=1';
    if ($departmentId) {
        $where .=
            ' AND EXISTS (SELECT 1 FROM bd_project_involved_departments pid WHERE pid.project_id=p.id AND pid.department_id=:department)';
        $params['department'] = $departmentId;
    }
    $where .= viewerDateSql($sectionFilters['projects'], 'p.created_at', $params);
    $query = $pdo->prepare(
        "SELECT p.id,p.project_code,p.title,p.status,p.created_at,u.full_name AS requester_name,(SELECT COUNT(*) FROM bd_project_involved_departments pid WHERE pid.project_id=p.id) AS department_count FROM bd_projects p LEFT JOIN bd_users u ON u.id=p.requester_id WHERE $where ORDER BY p.created_at DESC,p.id DESC",
    );
    $query->execute($params);
    $projects = $query->fetchAll();
    $params = [];
    $where = '1=1';
    if ($departmentId) {
        $where .= ' AND JSON_CONTAINS(r.department_ids_json, :department)';
        $params['department'] = (string) $departmentId;
    }
    $where .= viewerDateSql($sectionFilters['requests'], 'r.created_at', $params);
    $query = $pdo->prepare(
        "SELECT r.id,r.status,r.created_at,r.payload_json,u.full_name AS requester_name FROM bd_project_requests r LEFT JOIN bd_users u ON u.id=r.requester_id WHERE $where ORDER BY r.created_at DESC,r.id DESC",
    );
    $query->execute($params);
    $requests = $query->fetchAll();
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Project viewer · ProcessFlow</title>
    <?php renderPageScripts(); ?>
</head>

<body>
    <?php startPortalPage(
        'viewer',
        'Project viewer',
        'An overview of projects, department delivery and recorded activity.',
        $actor,
    ); ?>
    <form method="get" action="viewer.php" class="viewer-filters viewer-department-filter" data-viewer-autofilter>

        <?php viewerFilterHiddenFields($filters, (int) $projectId, ['department']); ?>
        <div>
            <label for="viewer-department">Department</label>
            <select id="viewer-department" name="department">
                <option value="0">All departments</option>
                <?php foreach ($filterDepartments as $department): ?>
                    <option value="<?= (int) $department['id'] ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= $escape($department['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <noscript>
            <button type="submit">Update department</button>
        </noscript>
    </form>

    <?php foreach ($filters['errors'] as $error): ?>
        <p role="alert"><?= $escape($error) ?></p>
    <?php endforeach; ?>

    <?php if ($project): ?>
        <a class="viewer-back" href="<?= $escape(viewerUrl($filters)) ?>">Back to all projects</a>
        <section class="viewer-project-heading" id="project-overview">
            <div>
                <p class="viewer-code"><?= $escape($project['project_code']) ?></p>
                <h2><?= $escape($project['title']) ?></h2>
                <p>Requested by <?= $escape($project['requester_name']) ?></p>
            </div>
            <span class="status-label" data-status="<?= $project['status'] === 'COMPLETED' ? 'active' : 'progress' ?>"><?= $escape($statusText($project['status'])) ?></span>
            <p class="viewer-description"><?= nl2br($escape($project['description'])) ?></p>
            <details class="viewer-project-details">
                <summary>Project information</summary>
                <table>
                    <tbody>
                        <?php foreach (
                            $project
                            as $key => $value
                        ):
                            if ((!is_scalar($value) && $value !== null) || $key === 'id' || str_ends_with($key, '_id')) {
                                continue;
                            } ?>
                            <tr>
                                <th><?= $escape($customLabels[$key] ?? ucwords(str_replace('_', ' ', $key))) ?></th>
                                <td><?= nl2br($escape($value)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php renderCustomProjectValues(
                    $pdo,
                    loadProjectRequestPayload($pdo, $projectId),
                ); ?>
            </details>
        </section>
        <div data-dashboard-metrics>
            <div>
                <p>Departments shown</p><strong><?= count($departments) ?></strong>
            </div>
            <div>
                <p>Departments approved</p><strong><?= count(array_filter($departments, fn($d) => in_array($d['status'], ['APPROVED', 'CONSOLIDATION_APPROVED'], true))) ?></strong>
            </div>
            <div>
                <p>Work items matching filters</p><strong><?= count($tasks) ?></strong>
            </div>
            <div>
                <p>Activity matching filters</p><strong><?= count($events) ?></strong>
            </div>
        </div>
        <section id="viewer-departments">
            <div class="viewer-section-heading">
                <h2>Department chat</h2><span><?= count($departments) ?> departments shown</span>
            </div>
            <div class="viewer-department-grid">
                <?php foreach ($departments as $department):
                $approved = in_array(
                    $department['status'],
                    ['APPROVED', 'CONSOLIDATION_APPROVED'],
                    true,
                ); ?>
                    <article class="viewer-department-card">
                        <h3><?= $escape($department['name']) ?></h3><span class="status-label"
                            data-status="<?= $approved ? 'active' : 'progress' ?>"><?= $escape($statusText($department['status'])) ?></span><a
                            class="action-button viewer-chat-button"
                            href="department_chat.php?workspace=viewer&amp;project=<?= $projectId ?>&amp;department=<?= (int) $department[ 'department_id' ] ?>">View
                            department chat</a>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if (!$departments): ?>
                <p class="viewer-empty">No involved departments match the selected filter.</p>
            <?php endif; ?>
        </section>
        <section id="viewer-work">
            <div class="viewer-section-heading">
                <h2>Department work</h2><span><?= count($tasks) ?> matching work items</span>
            </div>
            <?php renderViewerSectionDates(
                'work',
                'viewer-work',
                'Work last updated',
                $filters,
                $sectionFilters['work'],
                (int) $projectId,
            ); ?>
            <div class="viewer-work-grid">

                <?php foreach ($tasks as $task): ?>
                    <article class="viewer-work-card">
                        <div class="viewer-work-heading">
                            <h3><?= $escape($task['department_name']) ?></h3><span class="status-label"
                                data-status="<?= $task['status'] === 'APPROVED_BY_HOD' ? 'active' : 'progress' ?>"><?= $escape($statusText($task['status'])) ?></span>
                        </div>
                        <p class="viewer-assignee"><?= $escape($task['staff_name'] ?? 'Unassigned') ?></p>
                        <dl>
                            <dt>Assigned work</dt>
                            <dd><?= nl2br($escape($task['task_description'])) ?></dd>
                            <dt>Submitted deliverables</dt>
                            <dd><?= nl2br($escape($task['deliverables_summary'] ?: 'No deliverables submitted yet.')) ?></dd>
                        </dl>
                        <div class="viewer-work-footer"><small>Updated <?= $escape($task['updated_at']) ?></small>
                            <?php if ($task['attachment_path']): ?>
                                <a href="<?= $escape($task['attachment_path']) ?>" target="_blank" rel="noopener">View attachment</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if (!$tasks): ?>
                <p class="viewer-empty">No work items match this department and date range.</p>
            <?php endif; ?>
        </section>
        <section id="viewer-activity">
            <div class="viewer-section-heading">
                <h2>Project activity</h2><span><?= count($events) ?> matching records</span>
            </div>
            <?php renderViewerSectionDates(
                'activity',
                'viewer-activity',
                'Activity recorded',
                $filters,
                $sectionFilters['activity'],
                (int) $projectId,
            ); ?>

            <?php if ($events): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Recorded at</th>
                            <th>Actor / department</th>
                            <th>Activity</th>
                            <th>Work snapshot</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?= $escape($event['created_at']) ?></td>
                                <td><strong><?= $escape($event['actor_name'] ?? 'System') ?></strong>
                                    <br><small><?= $escape($event['actor_department']) ?></small>
                                </td>
                                <td><?= $escape($event['stage_title']) ?></td>
                                <td><?= nl2br($escape($event['work_snapshot'])) ?></td>
                                <td><?= nl2br($escape($event['remarks_or_comments'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="viewer-empty">No activity matches this department and date range.</p>

            <?php endif; ?>
        </section>

    <?php else: ?>
        <div data-dashboard-metrics>
            <div>
                <p>Matching projects</p><strong><?= count($projects) ?></strong>
            </div>
            <div>
                <p>Projects in progress</p><strong><?= count(array_filter($projects, fn($p) => $p['status'] !== 'COMPLETED')) ?></strong>
            </div>
            <div>
                <p>Completed projects</p><strong><?= count(array_filter($projects, fn($p) => $p['status'] === 'COMPLETED')) ?></strong>
            </div>
            <div>
                <p>Matching requests</p><strong><?= count($requests) ?></strong>
            </div>
        </div>
        <section data-workspace-panel id="all-projects">
            <div class="viewer-section-heading">
                <h2>Project portfolio</h2><span><?= count($projects) ?> projects shown</span>
            </div>
            <?php renderViewerSectionDates(
                'projects',
                'all-projects',
                'Project created',
                $filters,
                $sectionFilters['projects'],
                (int) $projectId,
            ); ?>

            <?php if ($projects): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Project / title</th>
                            <th>Requester</th>
                            <th>Created</th>
                            <th>Departments</th>
                            <th>Status</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projects as $item): ?>
                            <tr>
                                <td><strong><?= $escape($item['project_code']) ?></strong>
                                    <br><?= $escape($item['title']) ?>
                                </td>
                                <td><?= $escape($item['requester_name']) ?></td>
                                <td><?= $escape($item['created_at']) ?></td>
                                <td><?= (int) $item['department_count'] ?></td>
                                <td><span class="status-label" data-status="<?= $item[ 'status' ] === 'COMPLETED' ? 'active' : 'progress' ?>"><?= $escape($statusText($item['status'])) ?></span></td>
                                <td><a href="<?= $escape(viewerUrl($filters, (int) $item['id'])) ?>">View project</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="viewer-empty">No projects match the selected department and dates.</p>

            <?php endif; ?>
        </section>
        <section data-workspace-panel id="requests">
            <div class="viewer-section-heading">
                <h2>Project requests</h2><span><?= count($requests) ?> requests shown</span>
            </div>
            <?php renderViewerSectionDates(
                'requests',
                'requests',
                'Request created',
                $filters,
                $sectionFilters['requests'],
                (int) $projectId,
            ); ?>

            <?php foreach ($requests as $request): $payload = json_decode($request['payload_json'], true) ?: []; ?>
                <details>
                    <summary><?= $escape($payload['title'] ?? 'Project request') ?> <span class="status-label"><?= $escape($statusText($request['status'])) ?></span></summary>
                    <p>Requested by <?= $escape($request['requester_name']) ?> on <?= $escape($request['created_at']) ?></p>
                    <table>
                        <tbody>
                            <?php foreach ($payload as $key => $value):
                            if (!is_scalar($value) && $value !== null) {
                                continue;
                            } ?>
                                <tr>
                                    <th><?= $escape($customLabels[$key] ?? ucwords(str_replace('_', ' ', $key))) ?></th>
                                    <td><?= nl2br($escape($value)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endforeach; ?>

            <?php if (!$requests): ?>
                <p class="viewer-empty">No requests match the selected department and dates.</p>
            <?php endif; ?>
        </section>

    <?php endif; ?>

    <?php endPortalPage(); ?>
</body>

</html>
