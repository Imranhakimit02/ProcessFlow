<?php
/**
 * File: index.php
 * Purpose: Project registration portal with Admin-configured requester access & checklist inputs.
 * Author: Imran IT Intern
 *
 * This file prepares the account or project records used by the current
 * workflow.
 */

// ----------------------------------------------------
// Load the Requester and Project Form Options
// ----------------------------------------------------
require_once __DIR__ . '/config/portal_session.php';
startPortalSession('REQUESTER');
require_once 'config/db.php';
require_once __DIR__ . '/config/requester_access.php';
require_once __DIR__ . '/config/project_options.php';
require_once __DIR__ . '/config/custom_project_fields.php';
$requester = requireBdRequester($pdo);
$projectOptions = loadProjectOptions($pdo);
$customProjectFields = loadCustomProjectFields($pdo);
$customProjectChoices = loadCustomFieldChoices($pdo);
$request_approvers = getRequestApprovers($pdo, $requester);

// Fetch department list
$deptStmt = $pdo->query('SELECT id, name FROM bd_departments WHERE is_bd_department = 0 ORDER BY id ASC');
$departments = $deptStmt->fetchAll();

// -------------------------
// Display the page
// -------------------------
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <title>ProcessFlow - Technical and Commercial Registration</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <?php
    require_once __DIR__ . '/config/page_helpers.php';
    renderPageScripts();
    ?>
</head>

<body>

    <?php startPortalPage(
        'requester',
        'Create a project request',
        'Prepare the project brief and choose the departments and upper level approver.',
        $requester,
    ); ?>
    <section class="request-guide" aria-label="Project request steps">
        <h2>How to request and track a project</h2>
        <ol>
            <li>
                <div>
                    <span>Step 1</span>
                    <h3>Complete the project details</h3>
                    <p>Select an approver, enter the required project information, and choose the departments involved.
                        Then select “Proceed to Confirmation”.</p>
                </div>
            </li>
            <li>
                <div>
                    <span>Step 2</span>
                    <h3>Review and submit</h3>
                    <p>Check your details and confirm the request. It will be sent to your selected approver. The
                        project is created after approval.</p>
                </div>
            </li>
            <li>
                <div>
                    <span>Step 3</span>
                    <h3>Track the project</h3>
                    <p>Open Project Tracking to follow the request, review department work, join progress chats, and
                        view project activity.</p>
                </div>
            </li>
        </ol>
    </section>
    <!-- STEP 1: Confirmed Requester Account -->
    <section class="request-pic"><strong>Project PIC:</strong> <?= htmlspecialchars($requester['full_name']) ?> · <?= htmlspecialchars($requester['email']) ?>
        <br>You remain the PIC for every request submitted from this account.
    </section>

    <!-- STEP 2: Hidden Checklist Form -->
    <section id="form_section">
        <h3>Project Information Checklist</h3>
        <form action="user_email_verification.php" method="POST" onsubmit="return validateForm()">
            <input type="hidden" name="requester_id" id="requester_id" value="<?= (int) $requester['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
            <p>
                <label for="approver_id">Upper Level Request Approver *</label>
                <br>
                <select name="approver_id" id="approver_id" required>
                    <option value="">-- Select Upper Level Approver --</option>

                    <?php foreach ($request_approvers as $person): ?>
                        <option value="<?= (int) $person['id'] ?>"><?= htmlspecialchars($person['full_name'] . ' (' . $person['role'] . ') - ' . $person['email']) ?></option>

                    <?php endforeach; ?>
                </select>

                <?php if (!$request_approvers): ?>
                    <br><small data-feedback>No eligible approver is configured. Contact the system
                        administrator.</small>
                <?php endif; ?>
            </p>

            <p>
                <label for="registration_date">1. Registration Date *</label>
                <br>
                <input type="date" id="registration_date" name="registration_date" value="<?= date('Y-m-d') ?>"
                    aria-describedby="registration-date-help" required>
                <small id="registration-date-help">Today’s date is selected. Change it if needed using the calendar or
                    keyboard.</small>
            </p>

            <p>
                <label for="project_deadline_date">2. Project Deadline Date *</label>
                <br>
                <input type="date" id="project_deadline_date" name="project_deadline_date"
                    aria-describedby="deadline-date-help" required>
                <small id="deadline-date-help">Select a deadline using the calendar or enter a date.</small>
            </p>

            <p>
                <label>3. Source of Lead *</label>
                <br>
                <select name="source_of_lead" id="source_of_lead" onchange="toggleLeadDetails()" required>
                    <option value=""> -- Choose a source of lead -- </option>

                    <?php renderProjectOptions($projectOptions, 'source_of_lead'); ?>
                </select>
            </p>

            <div id="lead_details_div" hidden>
                <p>
                    <label>4. Lead's Company Name</label>
                    <br>
                    <input type="text" name="lead_company_name">
                </p>
                <p>
                    <label>5. Lead's PIC Name</label>
                    <br>
                    <input type="text" name="lead_pic_name">
                </p>
                <p>
                    <label>6. Lead's PIC Contact Number</label>
                    <br>
                    <input type="text" name="lead_pic_contact">
                </p>
                <p>
                    <label>7. Lead's PIC Email</label>
                    <br>
                    <input type="email" name="lead_pic_email">
                </p>
            </div>

            <p>
                <label>8. Client's Company Name * (Full name without dots)</label>
                <br>
                <input type="text" name="client_company_name" required>
            </p>

            <p>
                <label>9. Project Nature *</label>
                <br>
                <select name="project_nature" id="project_nature" onchange="toggleEstimatedCod()" required>
                    <?php renderProjectOptions(
                        $projectOptions,
                        'project_nature',
                    ); ?>
                </select>
            </p>

            <p>
                <label>10. Sector *</label>
                <br>
                <select name="sector" required>
                    <?php renderProjectOptions($projectOptions, 'sector'); ?>
                </select>
            </p>

            <p>
                <label>11. Type of Renewable Energy *</label>
                <br>
                <select name="renewable_energy_type" required>
                    <?php renderProjectOptions(
                        $projectOptions,
                        'renewable_energy_type',
                    ); ?>
                </select>
            </p>

            <p>
                <label>12. Project Location - Country *</label>
                <br>
                <select name="country_code" required>
                    <?php renderProjectOptions(
                        $projectOptions,
                        'country_code',
                    ); ?>
                </select>
            </p>

            <p>
                <label>13. Project Location – Region (optional)</label>
                <br>
                <input type="text" name="region">
            </p>

            <p>
                <label>14. Type of Contract *</label>
                <br>
                <select name="contract_type" required>
                    <?php renderProjectOptions(
                        $projectOptions,
                        'contract_type',
                    ); ?>
                </select>
            </p>

            <p>
                <label>15. Project Name *</label>
                <br>
                <input type="text" name="title" required>
            </p>

            <p>
                <label>16. Project Reference No</label>
                <br>
                <input type="text" name="project_ref_no">
            </p>

            <p>
                <label>17. Project Description *</label>
                <br>
                <textarea name="description" rows="3" cols="50" required></textarea>
            </p>

            <p>
                <label>18. Capacity Unit *</label>
                <br>
                <select name="capacity_unit" required>
                    <?php renderProjectOptions(
                        $projectOptions,
                        'capacity_unit',
                    ); ?>
                </select>
            </p>

            <p>
                <label>19. Est. MW * (Rounded to 2 decimal places)</label>
                <br>
                <input type="number" step="0.01" name="est_mw" required>
            </p>

            <p>
                <label for="est_cod" id="est_cod_label">20. Estimated COD</label>
                <br>
                <input type="date" name="est_cod" id="est_cod" aria-describedby="est-cod-help">
                <small id="est-cod-help">Select a date using the calendar or keyboard, when applicable.</small>
            </p>

            <p>
                <label>21. Remarks (optional)</label>
                <br>
                <textarea name="remarks" rows="2" cols="50"></textarea>
            </p>

            <?php foreach ($customProjectFields as $customField):
            if (!$customField['is_active']) {
                continue;
            } ?>
                <p>
                    <label for="custom-field-<?= (int) $customField['id'] ?>"><?= htmlspecialchars($customField['label']) . ($customField['is_required'] ? ' *' : '') ?></label>

                    <?php if ($customField['field_type'] === 'dropdown'): ?>
                        <select id="custom-field-<?= (int) $customField['id'] ?>" name="custom_field_<?= (int) $customField[ 'id' ] ?>"
                            <?= $customField['is_required'] ? 'required' : '' ?>>
                            <option value="">Choose an option</option>

                            <?php foreach ($customProjectChoices[(int) $customField['id']] ?? [] as $choice): ?>
                                <option value="<?= htmlspecialchars($choice) ?>"><?= htmlspecialchars($choice) ?></option>

                            <?php endforeach; ?>
                        </select>

                    <?php else: ?>
                        <input type="text" id="custom-field-<?= (int) $customField[ 'id' ] ?>" name="custom_field_<?= (int) $customField['id'] ?>"
                            maxlength="1000" <?= $customField[ 'is_required' ] ? 'required' : '' ?>>

                    <?php endif; ?>
                </p>

            <?php endforeach; ?>
            <hr>
            <h3>Departments Involved (Parallel Execution)</h3>
            <p>Select all departments required for execution:</p>
            <div id="departments_container">
                <label>
                    <input type="checkbox" id="select_all_departments" onchange="toggleAllDepartments(this.checked)">
                    Select all available departments
                </label>
                <br>
                <br>

                <?php foreach ($departments as $dept): ?>
                    <div data-dept-id="<?= $dept['id'] ?>" data-dept-name="<?= htmlspecialchars($dept['name']) ?>">
                        <label>
                            <input type="checkbox" name="departments[]" value="<?= $dept[ 'id' ] ?>"
                                onchange="updateSelectAllDepartments()">
                            <?= htmlspecialchars($dept['name']) ?>
                        </label>
                    </div>

                <?php endforeach; ?>
            </div>

            <br>
            <p id="project-form-feedback" data-feedback role="alert" hidden></p>
            <button type="submit">Proceed to Confirmation &rarr;</button>
        </form>
    </section>

    <!-- SECTION: JavaScript Handlers -->
    <script>
    function toggleAllDepartments(checked) {
        document.querySelectorAll('[data-dept-id]').forEach(item => {
            if (!item.hidden) {
                item.querySelector('input[name="departments[]"]').checked = checked;
            }
        });
        updateSelectAllDepartments();
    }

    function updateSelectAllDepartments() {
        const selectable = Array.from(document.querySelectorAll('[data-dept-id]'))
            .filter(item => !item.hidden)
            .map(item => item.querySelector('input[name="departments[]"]'));
        const selectAll = document.getElementById('select_all_departments');
        const checkedCount = selectable.filter(checkbox => checkbox.checked).length;

        selectAll.checked = selectable.length > 0 && checkedCount === selectable.length;
        selectAll.indeterminate = checkedCount > 0 && checkedCount < selectable.length;
        selectAll.disabled = selectable.length === 0;
    }

    function toggleLeadDetails() {
        const source = document.getElementById('source_of_lead');
        const details = document.getElementById('lead_details_div');
        const showDetails = source.selectedOptions[0]?.dataset.extra === '1';
        details.hidden = !showDetails;
        details.querySelectorAll('input').forEach(input => {
            input.disabled = !showDetails;
        });
    }

    function toggleEstimatedCod() {
        const estCodInput = document.getElementById('est_cod');
        const estCodLabel = document.getElementById('est_cod_label');

        if (document.getElementById('project_nature').selectedOptions[0]?.dataset.extra === '1') {
            estCodInput.required = true;
            estCodLabel.innerHTML = '<strong>20. Estimated COD * Required</strong>';
        } else {
            estCodInput.required = false;
            estCodLabel.innerHTML = '20. Estimated COD';
            estCodInput.value = '';
        }
    }

    function validateForm() {
        const estCod = document.getElementById('est_cod').value;

        if (document.getElementById('project_nature').selectedOptions[0]?.dataset.extra === '1' && !estCod) {
            document.getElementById('project-form-feedback').textContent = "Estimated COD is required for the selected project nature.";
            return false;
        }

        const checkedDepts = document.querySelectorAll('input[name="departments[]"]:checked');
        if (checkedDepts.length === 0) {
            document.getElementById('project-form-feedback').textContent = "Error: Please select at least one department for execution.";
            return false;
        }

        return true;
    }
    toggleLeadDetails();
    toggleEstimatedCod();
    updateSelectAllDepartments();
    </script>

    <?php endPortalPage(); ?>
</body>

</html>
