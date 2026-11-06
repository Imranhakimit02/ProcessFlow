<?php
/**
 * File: admin/project_record.php
 * Purpose: View project records and workflow history.
 * Author: Imran IT Intern
 *
 * This page validates administrator actions and displays the corresponding
 * application records.
 */

// -----------------------------------
// Load the page dependencies
// -----------------------------------
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/admin_access.php';
require_once __DIR__ . '/../config/page_helpers.php';
require_once __DIR__ . '/../config/custom_project_fields.php';

requireAdmin($pdo);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    endWithFeedback('Project records are read-only in the admin workspace.');
}
$id = (int) filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$lookup = $pdo->prepare('SELECT p.*, u.full_name AS requester_name
    FROM bd_projects p JOIN bd_users u ON u.id = p.requester_id WHERE p.id = ?');
$lookup->execute([$id]);
$project = $lookup->fetch();
if (!$project) {
    http_response_code(404);
    endWithFeedback('This project record could not be found.');
}

$departments = $pdo->prepare('SELECT d.name, pid.status FROM bd_project_involved_departments pid
    JOIN bd_departments d ON d.id = pid.department_id WHERE pid.project_id = ? ORDER BY d.name');
$departments->execute([$id]);
$audit = $pdo->prepare('SELECT a.created_at, a.stage_title, a.remarks_or_comments, u.full_name
    FROM bd_project_timeline_audits a LEFT JOIN bd_users u ON u.id = a.actor_id
    WHERE a.project_id = ? ORDER BY a.id DESC LIMIT 100');
$audit->execute([$id]);

// -------------------------
// Display the page
// -------------------------
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Project record · ProcessFlow</title>

    <?php renderPageScripts(); ?>
</head>

<body>

    <?php startAdminPage($project['project_code'] . ' · Project record'); ?>
    <div>
        <section>
            <div>
                <h2>Project details</h2>

                <?php renderCustomProjectValues($pdo, loadProjectRequestPayload($pdo, $id)); ?>
                <p><?= htmlspecialchars($project['requester_name']) ?> · <?= htmlspecialchars($project['status']) ?></p>
                <dl>
                    <dt>Title</dt>
                    <dd><?= htmlspecialchars($project['title']) ?></dd>
                    <dt>Client company</dt>
                    <dd><?= htmlspecialchars($project['client_company_name']) ?></dd>
                    <dt>Description</dt>
                    <dd><?= nl2br(htmlspecialchars($project['description'])) ?></dd>
                </dl>
            </div>
        </section>
        <section>
            <div>
                <h2>Department progress</h2>
                <ul>

                    <?php foreach ($departments as $department): ?>
                        <li><?= htmlspecialchars($department['name']) ?> · <?= htmlspecialchars($department['status']) ?></li>

                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    </div>
    <section>
        <h2>Recent project activity</h2>
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Actor</th>
                    <th>Stage</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>

                <?php foreach ($audit as $event): ?>
                    <tr>
                        <td><?= htmlspecialchars($event['created_at']) ?></td>
                        <td><?= htmlspecialchars($event['full_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($event['stage_title'] ?? '') ?></td>
                        <td><?= htmlspecialchars($event['remarks_or_comments'] ?? '') ?></td>
                    </tr>

                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    </main>
</body>

</html>
