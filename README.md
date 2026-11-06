# ProcessFlow

ProcessFlow is a PHP web application for managing technical and commercial project requests, departmental work, and approval workflows. It brings project registration, task assignments, deliverables, progress discussions, and activity tracking into role-based workspaces.

## Trying ProcessFlow locally

This PHP application needs a local web server, a database, and configured accounts. GitHub will host the source code so visitors can download it and follow the setup instructions below.

**The public demo setup is not ready yet.** The current `database/processflowdb.sql` contains internal records and is excluded from Git. Before visitors can run a fresh public download independently, a separate synthetic demo database and documented sample accounts must be supplied. The setup below currently applies to authorized internal installations with access to the existing export.

## Features

- **Project requests:** Submit project details, select participating departments and an approver, review requests, and track progress.
- **Approval workflows:** Review requests and final approvals using configurable approval rules and department routes.
- **Department operations:** Assign work, manage department handoffs, and review deliverables.
- **Staff workspace:** View assigned tasks and submit work with file attachments.
- **Project monitoring:** Browse project records, filter activity, and follow approval history.
- **Progress chat:** Discuss project work within eligible departments and workspaces.
- **Administration:** Manage users, departments, access roles, project form options, custom fields, and workflow rules.

Workspace access depends on the user's active account, department, configured role, and permissions.

## Technology

- PHP 8.0 or later (the code uses PHP 8 features)
- MariaDB / MySQL, accessed through PDO
- HTML, CSS, and JavaScript
- Apache / XAMPP for local development

The supplied database export was generated with MariaDB 10.4.32. No Composer or npm installation is required by the current project.

## Local setup with XAMPP

1. Download and extract the repository into your XAMPP web directory, or clone it after publication. Replace `YOUR_USERNAME` with the repository owner's username:

   ```powershell
   cd C:\xampp\htdocs
   git clone https://github.com/YOUR_USERNAME/processflow.git processflow
   ```

   Use the resulting folder name in the URLs below. For example:

   ```text
   C:\xampp\htdocs\processflow
   ```

2. Start **Apache** and **MySQL** in the XAMPP Control Panel.

3. Open phpMyAdmin at `http://localhost/phpmyadmin`, create an empty database named `processflowdb` using `utf8mb4`, and import `database/processflowdb.sql` into it.

   **Use an empty database:** the export includes `DROP TABLE` statements and existing records, so importing it into an existing installation can replace its data.

4. Update the connection settings in `config/db.php` for your local database:

   ```php
   $host = '127.0.0.1';
   $dbname = 'processflowdb';
   $username = 'root';
   $password = '';
   $charset = 'utf8mb4';
   ```

   These are the current local development defaults. Ensure PHP's `pdo_mysql` extension is enabled.

5. Ensure the web server can write to the `uploads/` directory for attachments. Adjust PHP's `upload_max_filesize` and `post_max_size` if larger uploads are needed.

6. Open the application:

   | Entry point | Local URL |
   | --- | --- |
   | User sign-in | `http://localhost/processflow/login.php` |
   | Administrator sign-in | `http://localhost/processflow/admin/admin_login.php` |

If you use a different project folder name, replace `processflow` in these URLs.

## Using the application

Regular users enter an existing active account's email address on the shared sign-in page, confirm the account, and access the permitted workspaces. This flow currently uses email lookup and confirmation; it does not verify ownership through an emailed code or password. Administrators sign in separately with a username and password.

1. An administrator configures users, departments, role permissions, form options, and approval rules.
2. An eligible requester completes and submits a project request.
3. The selected approver reviews the request; the project is created after approval.
4. Department heads coordinate assignments and handoffs, and staff submit deliverables.
5. Authorized users track progress, discuss work, and review the approval and activity history.

Use locally provisioned accounts or accounts from an authorized database copy. This README does not provide account credentials.

## Project structure

```text
processflow/
├── admin/                 # Administration, master data, roles, and approval rules
├── approver/              # Approvals, department operations, and monitoring
├── assets/                # Shared styles, scripts, and image assets
├── config/                # Database connection, access rules, and workflow helpers
├── database/              # Database schema and record export
├── staff/                 # Staff workspaces and sign-in redirects
├── tests/                 # PHP command-line checks
├── uploads/               # Uploaded project attachments
├── department_chat.php    # Progress discussions
├── index.php              # Project request form
├── login.php              # Shared user sign-in
├── request_details.php    # Request details
├── tracking.php           # Requester project tracking
└── viewer.php             # Project viewer workspace
```

## Checks

Run the included PHP checks from the project root. For example, in PowerShell with XAMPP:

```powershell
C:\xampp\php\php.exe tests/check_viewer_filters.php
C:\xampp\php\php.exe tests/check_request_access.php
C:\xampp\php\php.exe tests/check_approval_rule_edit.php
C:\xampp\php\php.exe tests/check_admin_pages.php
C:\xampp\php\php.exe tests/check_workspace_pages.php login.php
```

The viewer filter check runs without a database. The access, approval-rule, and page checks require a configured database and suitable existing accounts or records. Run database-dependent checks against a development copy. The workspace rendering check accepts a route argument, such as `viewer.php` or `staff/staff_dashboard.php`.

## Before publishing to GitHub

- Review and sanitize `database/processflowdb.sql`: the current export contains internal records, including account data. Publish a schema and synthetic sample data when sharing publicly.
- Review `uploads/` for internal documents and screenshots before committing attachments.
- Review `config/db.php` for database credentials and keep deployment secrets out of version control.
- Review the organization's branding and other assets for permission to redistribute.

Before an internet-facing deployment, replace the regular-user email confirmation flow with authentication that verifies the user's identity, and review deployment security and upload handling.

### Public demo release checklist

- Create a separate `database/demo.sql` with the schema and synthetic data. Keep the existing internal export private and unchanged.
- Seed fictional departments, form options, approval rules, and active requester, approver, department head, staff, and administrator accounts. Use addresses such as `requester@example.com`.
- Generate the demo administrator's password hash with PHP's `password_hash()` and document its local-only credentials in this README.
- Exclude internal projects, messages, audit records, and attachments. Use fictional examples for the walkthrough.
- Change the import instructions above to `database/demo.sql` and add a table of demo accounts.
- Verify a fresh download with a new database: sign in, submit and approve a request, assign work, and upload a deliverable.
- Add approved screenshots under `docs/screenshots/` and show the main workflow in this README.

The `.gitignore` excludes the current internal SQL export and existing uploaded files. It does not sanitize other code or assets; review those before publication. Keep real database credentials out of the committed `config/db.php`.

### Publish the repository

After completing the checklist, create an empty public GitHub repository named `processflow`. Leave GitHub's options to add a README, license, and `.gitignore` unchecked for these commands. Replace `YOUR_USERNAME` below.

From the project folder in PowerShell:

```powershell
git init
git branch -M main
git add .
git status --short
git diff --cached --stat
```

Review the staged contents before continuing. The internal export and existing uploads must not be staged. Then:

```powershell
git commit -m "Initial ProcessFlow portfolio release"
git remote add origin https://github.com/YOUR_USERNAME/processflow.git
git push -u origin main
```

Authenticate with your own GitHub account when prompted. See [GitHub's instructions for publishing local code](https://docs.github.com/en/migrations/importing-source-code/using-the-command-line-to-import-source-code/adding-locally-hosted-code-to-github).

## Portfolio presentation

Add a ProcessFlow project card to your portfolio with:

- A description: “A PHP application for project requests, role-based approvals, departmental task assignments, and progress tracking.”
- The technologies used and your actual contribution to the project.
- Approved screenshots or a recorded walkthrough using fictional data.
- A **View source** link to `https://github.com/YOUR_USERNAME/processflow`.
- A **Run locally** link to `https://github.com/YOUR_USERNAME/processflow#local-setup-with-xampp` once the public demo setup is complete.

A browser-based **Live demo** needs separate hosting with PHP and a MySQL-compatible database. [GitHub Pages does not run PHP](https://docs.github.com/en/pages/getting-started-with-github-pages/creating-a-github-pages-site). Until a demo deployment exists, portfolio visitors can use the screenshots, source code, and local setup instructions.

## Troubleshooting local setup

- **404:** Check that the extracted project is directly inside `htdocs` and that the folder name matches the URL.
- **Database connection failed:** Start MySQL, create and import the database, and check the settings in `config/db.php`.
- **Could not find driver:** Enable `pdo_mysql` in Apache's PHP configuration, then restart Apache.
- **Cannot sign in:** The database needs active accounts with appropriate roles and permissions. A schema alone is insufficient.
- **Upload fails:** Check write permissions on `uploads/` and PHP's upload size limits.

## License

No license file is currently included. Add an appropriate `LICENSE` file if you intend to grant permission to use, modify, or redistribute this project.
