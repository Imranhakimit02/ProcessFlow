<?php
/**
 * File: department_chat.php
 * Purpose: Share progress messages with assigned staff, their HOD and the project's BD PIC.
 * Author: Imran IT Intern
 *
 * This file prepares the account or project records used by the current
 * workflow.
 */

// -----------------------------------
// Load the page dependencies
// -----------------------------------
require_once __DIR__ . '/config/portal_session.php';
require_once __DIR__ . '/config/requester_access.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/page_helpers.php';

// ---------------------------
// Authorize the Room
// ---------------------------
$workspace = $_GET['workspace'] ?? '';
$scopes = ['staff' => 'STAFF', 'department' => 'HOD', 'requester' => 'REQUESTER', 'viewer' => 'VIEWER'];
if (!is_string($workspace) || !isset($scopes[$workspace])) {
    http_response_code(404);
    endWithFeedback('Choose a department chat from your workspace.');
}
startPortalSession($scopes[$workspace]);

$projectId = filter_input(INPUT_GET, 'project', FILTER_VALIDATE_INT);
$departmentId = filter_input(INPUT_GET, 'department', FILTER_VALIDATE_INT);
$actorId = (int) match ($workspace) {
    'staff' => $_SESSION['staff_id'] ?? 0,
    'department' => $_SESSION['approver_user_id'] ?? ($_SESSION['hod_id'] ?? 0),
    'requester' => $_SESSION['bd_requester_id'] ?? 0,
    'viewer' => $_SESSION['approver_user_id'] ?? 0,
};
$permission = match ($workspace) {
    'staff' => "u.role = 'STAFF' AND u.department_id = pid.department_id AND EXISTS (
        SELECT 1 FROM bd_department_tasks t WHERE t.project_id = p.id
          AND t.department_id = pid.department_id AND t.assigned_to_staff_id = u.id AND t.status <> 'UNASSIGNED'
    )",
    'department' => "u.role = 'HOD' AND u.department_id = pid.department_id",
    'viewer' => "u.role IN ('VIEWER','GROUP_CEO')",
    'requester'
        => "p.requester_id = u.id AND (ud.is_bd_department = 1 OR (u.role = 'GROUP_CEO' AND u.department_id IS NULL)) AND u.role IN ('STAFF', 'HOD', 'C_LEVEL', 'GROUP_CEO')",
};
$roomQuery = $pdo->prepare("SELECT p.project_code, p.title, p.status AS project_status, d.name AS department_name, u.full_name, u.role,
        u.department_id, ud.is_bd_department
    FROM bd_project_involved_departments pid
    JOIN bd_projects p ON p.id = pid.project_id
    JOIN bd_departments d ON d.id = pid.department_id
    JOIN bd_users u ON u.id = ? AND u.is_active = 1
    LEFT JOIN bd_departments ud ON ud.id = u.department_id
    WHERE pid.project_id = ? AND pid.department_id = ? AND ($permission)");
$roomQuery->execute([$actorId, (int) $projectId, (int) $departmentId]);
$room = $roomQuery->fetch();
if (!$room) {
    http_response_code(403);
    endWithFeedback('This department chat is unavailable in your workspace.');
}
$permissions = accessRoleForUser($pdo, $actorId);
$workspacePermission = match ($workspace) {
    'staff' => 'can_work',
    'department' => 'can_manage_department',
    'requester' => 'can_request',
    'viewer' => 'can_view_all',
};
if (($workspace !== 'viewer' && empty($permissions['can_chat'])) || empty($permissions[$workspacePermission])) {
    http_response_code(403);
    endWithFeedback('Your role does not have access to this chat.');
}
header('Cache-Control: no-store');

$url = portalUrl(
    'department_chat.php?' .
        http_build_query([
            'workspace' => $workspace,
            'project' => $projectId,
            'department' => $departmentId,
        ]),
);
if (isset($_GET['attachment'])) {
    $q = $pdo->prepare('SELECT attachment_path, attachment_name FROM bd_department_chat_messages WHERE id=? AND project_id=? AND department_id=?');
    $q->execute([(int) $_GET['attachment'], $projectId, $departmentId]);
    $file = $q->fetch();
    $path = $file ? __DIR__ . '/database/chat_uploads/' . basename($file['attachment_path'] ?? '') : '';
    if (!$file || !$file['attachment_path'] || !is_file($path)) {
        http_response_code(404);
        exit('Attachment unavailable.');
    }
    header('Content-Type: application/octet-stream');
    header('X-Content-Type-Options: nosniff');
    header("Content-Disposition: attachment; filename=attachment; filename*=UTF-8''" . rawurlencode($file['attachment_name']));
    header('Content-Length: ' . filesize($path));
    session_write_close();
    readfile($path);
    exit();
}
$error = '';
$storedUpload = null;
$closed = $room['project_status'] === 'COMPLETED' || $workspace === 'viewer';
$liveRequest = isset($_GET['thread']);
try {
    // -------------------------------
    // SECTION: Post and Read Messages
    // -------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyRequesterPost();
        $message = is_string($_POST['message'] ?? null) ? trim($_POST['message']) : '';
        if ($closed) {
            $error = 'This project is complete. Chat is read-only.';
        } elseif (($message === '' && (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] === UPLOAD_ERR_NO_FILE)) || mb_strlen($message) > 2000) {
            $error = 'Add a message or attachment. Messages can contain up to 2,000 characters.';
        } else {
            $attachmentName = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
                $upload = $_FILES['attachment'];
                if (is_array($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 50 * 1024 * 1024) {
                    throw new RuntimeException('Upload failed. Choose a file up to 50 MB, subject to server upload limits.');
                }
                $attachmentName = mb_substr(basename(str_replace(chr(92), '/', $upload['name'])), 0, 255);
                $directory = __DIR__ . '/database/chat_uploads';
                if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
                    throw new RuntimeException('Attachment storage is unavailable.');
                }
                $storedUpload = bin2hex(random_bytes(24));
                if (!move_uploaded_file($upload['tmp_name'], $directory . '/' . $storedUpload)) {
                    $storedUpload = null;
                    throw new RuntimeException('Could not save attachment.');
                }
            }
            $senderRole = ['staff' => 'Staff', 'department' => 'HOD', 'requester' => 'BD PIC'][$workspace];
            $pdo->beginTransaction();
            $projectStatus = $pdo->prepare('SELECT status FROM bd_projects WHERE id = ? FOR UPDATE');
            $projectStatus->execute([$projectId]);
            $status = $projectStatus->fetchColumn();
            if ($status === false || $status === 'COMPLETED') {
                $pdo->rollBack();
                if ($storedUpload) unlink(__DIR__ . '/database/chat_uploads/' . $storedUpload);
                $closed = true;
                $error = 'This project is complete. Chat is read-only.';
            } else {
                $insert = $pdo->prepare('INSERT INTO bd_department_chat_messages
                    (project_id, department_id, sender_id, sender_role, message, attachment_path, attachment_name) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $insert->execute([$projectId, $departmentId, $actorId, $senderRole, $message, $storedUpload, $attachmentName]);
                $pdo->commit();
                if ($liveRequest) {
                    http_response_code(204);
                } else {
                    header('Location: ' . $url, true, 303);
                }
                exit();
            }
        }
        http_response_code($closed ? 409 : 422);
        if ($liveRequest) {
            header('Content-Type: text/plain; charset=UTF-8');
            echo $error;
            exit();
        }
    }
    $messagesQuery = $pdo->prepare('SELECT m.*, u.full_name FROM bd_department_chat_messages m
        JOIN bd_users u ON u.id = m.sender_id
        WHERE m.project_id = ? AND m.department_id = ? ORDER BY m.id DESC LIMIT 100');
    $messagesQuery->execute([$projectId, $departmentId]);
    $messages = array_reverse($messagesQuery->fetchAll());
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($storedUpload) unlink(__DIR__ . '/database/chat_uploads/' . $storedUpload);
    if (!($exception instanceof PDOException)) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=UTF-8');
        exit($exception->getMessage());
    }
    if (!in_array($exception->getCode(), ['42S02', '42S22'], true)) {
        throw $exception;
    }
    http_response_code(503);
    endWithFeedback('Progress chat is unavailable until the database migration is installed.');
}

$reminders = [];
if ($room['project_status'] !== 'COMPLETED') {
    $due = $pdo->prepare("SELECT u.full_name,
        FLOOR(TIMESTAMPDIFF(HOUR, t.assignment_started_at, NOW()) / 48) AS reminder_number
        FROM bd_department_tasks t JOIN bd_users u ON u.id = t.assigned_to_staff_id
        WHERE t.project_id = ? AND t.department_id = ?
          AND t.status IN ('ASSIGNED_TO_STAFF', 'REJECTED_BY_HOD')
          AND t.assignment_started_at <= DATE_SUB(NOW(), INTERVAL 2 DAY)");
    $due->execute([$projectId, $departmentId]);
    $reminders = $due->fetchAll();
}
ob_start();
foreach ($reminders as $reminder): ?>
<article role="status" class="chat-reminder">
    <strong>Progress update reminder #<?= (int) $reminder['reminder_number'] ?></strong>
    <p><?= htmlspecialchars($reminder['full_name']) ?>, please share your work progress. A reminder is due every two days from assignment while work is pending.</p>
</article>
<?php endforeach;

if (!$messages): ?>
<p>No progress messages yet.</p>

<?php endif;
foreach ($messages as $entry):

    $own = (int) $entry['sender_id'] === $actorId;
    $roleColor = ['Staff' => 'success', 'HOD' => 'dark', 'BD PIC' => 'primary'][$entry['sender_role']];
    ?>
<article>
    <div>
        <strong><?= $own ? 'You' : htmlspecialchars($entry['full_name']) ?></strong>
        <span><?= htmlspecialchars($entry['sender_role']) ?></span>
        <small><?= htmlspecialchars($entry['created_at']) ?></small>
    </div>
    <p><?= nl2br(htmlspecialchars($entry['message'])) ?></p>
    <?php if (!empty($entry['attachment_path'])): ?>
        <a href="<?= htmlspecialchars($url . '&attachment=' . (int) $entry['id']) ?>">Download <?= htmlspecialchars($entry['attachment_name']) ?></a>
    <?php endif; ?>
</article>

<?php
endforeach;
$thread = ob_get_clean();
if ($liveRequest) {
    header('X-Chat-Closed: ' . ($closed ? '1' : '0'));
    echo $thread;
    exit();
}
$token = requesterCsrfToken();
session_write_close();
$back = match ($workspace) {
    'staff' => 'staff/staff_dashboard.php#my-assigned-tasks',
    'department' => 'approver/department_tasks.php#incoming-projects',
    'requester' => 'tracking.php?' . http_build_query(['code' => $room['project_code']]) . '#department-progress',
    'viewer' => 'viewer.php?' . http_build_query(['project' => $projectId]),
};

// -------------------------
// Display the page
// -------------------------
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Department progress chat · ProcessFlow</title>

    <?php renderPageScripts(); ?>
    <script src="assets/department-chat.js" defer></script>
</head>

<body>

    <?php startPortalPage(
        $workspace,
        $room['department_name'] . ' progress chat',
        $room['project_code'] . ' · ' . $room['title'],
        $room,
    ); ?>
    <div>
        <a href="<?= htmlspecialchars($back) ?>">Back to project work</a>
        <section aria-label="Department progress chat">
            <div>
                <strong>Department conversation</strong>
                <span>Staff</span>
                <span>HOD</span>
                <span>BD PIC</span>
            </div>
            <p>Staff should share an update every two days. Showing the latest 100 messages.</p>
            <div id="department-chat-thread" tabindex="0" role="log" aria-label="Messages"
                data-thread-url="<?= htmlspecialchars($url . '&thread=1') ?>">
                <?= $thread ?> </div>
            <p id="chat-closed" <?= $closed ? '' : ' hidden' ?>><?= $workspace === 'viewer' ? 'You have read-only access to this conversation.' : 'This project is complete. Chat history is read-only.' ?></p>
            <p id="chat-error" role="alert" data-feedback><?= htmlspecialchars($error) ?></p>

            <?php if (!$closed): ?>
                <form id="chat-composer" method="POST" enctype="multipart/form-data" action="<?= htmlspecialchars($url) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <label for="chat-message">Progress message</label>
                    <div>
                        <textarea id="chat-message" name="message" rows="2" maxlength="2000" placeholder="Write a progress message…"><?= $error !== '' ? htmlspecialchars($message) : '' ?></textarea>
                        <label for="chat-attachment">Image, video or file (up to 50 MB)</label>
                        <input id="chat-attachment" type="file" name="attachment">
                        <button type="submit">Send</button>
                    </div>
                </form>

            <?php endif; ?>
        </section>
    </div>

    <?php endPortalPage(); ?>
</body>

</html>
