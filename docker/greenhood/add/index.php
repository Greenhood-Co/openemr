<?php

/**
 * Add Greenhood training users without rebuilding the container image.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/interface/globals.php';

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Acl\AclExtended;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Auth\AuthHash;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Core\Header;

if (!AclMain::aclCheckCore('admin', 'users')) {
    AccessDeniedHelper::denyWithTemplate(
        'ACL check failed for admin/users: Add Training Users',
        xl('Add Training Users')
    );
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$isSuperAdministrator = AclMain::aclCheckCore('admin', 'super');
$passwordEnvironmentValue = getenv('TRAINING_ACCOUNT_PASSWORD');
$trainingPassword = is_string($passwordEnvironmentValue) ? $passwordEnvironmentValue : '';
$passwordConfigured = $trainingPassword !== '';

/** @var list<string> $preferredRoles */
$preferredRoles = ['Physicians', 'Nursing', 'Front Office', 'Administrators', 'Accounting', 'Clinicians'];
/** @var list<string> $availableRoles */
$availableRoles = array_values(AclExtended::aclGetGroupTitleList(true));
$roles = [];
foreach ($preferredRoles as $preferredRole) {
    foreach ($availableRoles as $availableRole) {
        if (
            strcasecmp($availableRole, $preferredRole) === 0
            && ($isSuperAdministrator || !AclExtended::isGroupIncludeSuperuser($availableRole))
        ) {
            $roles[] = $availableRole;
            break;
        }
    }
}

/** @var list<string> $randomRoles */
$randomRoles = array_values(array_filter(
    $roles,
    static fn(string $role): bool => !AclExtended::isGroupIncludeSuperuser($role)
));

$rawUsernames = '';
/** @var list<array{username: string, role?: string, status: string, message: string}> $results */
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token_form'] ?? '';
    if (!is_string($csrfToken) || !CsrfUtils::verifyCsrfToken($csrfToken, session: $session)) {
        CsrfUtils::csrfNotVerified();
    }

    $rawInput = $_POST['usernames'] ?? '';
    $rawUsernames = is_string($rawInput) ? trim($rawInput) : '';
    $tokens = preg_split('/[\s,]+/', $rawUsernames, -1, PREG_SPLIT_NO_EMPTY);
    $submittedRoles = $_POST['roles'] ?? [];
    $submittedRoles = is_array($submittedRoles) ? $submittedRoles : [];

    /** @var list<string> $usernames */
    $usernames = [];
    /** @var array<string, true> $seen */
    $seen = [];
    foreach ($tokens === false ? [] : $tokens as $token) {
        $username = strtoupper(trim($token));
        if (isset($seen[$username])) {
            continue;
        }
        $seen[$username] = true;
        $usernames[] = $username;
    }

    if (!$passwordConfigured) {
        $results[] = [
            'username' => '',
            'status' => 'error',
            'message' => xl('TRAINING_ACCOUNT_PASSWORD is not configured on the server.'),
        ];
    } elseif ($roles === []) {
        $results[] = [
            'username' => '',
            'status' => 'error',
            'message' => xl('No permitted training roles are available.'),
        ];
    } elseif (count($usernames) > 500) {
        $results[] = [
            'username' => '',
            'status' => 'error',
            'message' => xl('A maximum of 500 usernames can be added at once.'),
        ];
    } else {
        $facility = sqlQuery('SELECT id FROM facility ORDER BY id ASC LIMIT 1');
        $facilityId = (int) ($facility['id'] ?? 1);
        $group = sqlQuery('SELECT `name` FROM `groups` ORDER BY `id` ASC LIMIT 1');
        $groupName = (string) ($group['name'] ?? 'Default');

        foreach ($usernames as $username) {
            if (preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,49}\z/', $username) !== 1) {
                $results[] = [
                    'username' => $username,
                    'status' => 'error',
                    'message' => xl('Invalid username. Use 1–50 letters, numbers, dots, underscores, or hyphens.'),
                ];
                continue;
            }

            $selectedRole = $submittedRoles[$username] ?? '';
            $selectedRole = is_string($selectedRole) ? $selectedRole : '';
            if (!in_array($selectedRole, $roles, true)) {
                $results[] = [
                    'username' => $username,
                    'status' => 'error',
                    'message' => xl('Select a permitted role for this username.'),
                ];
                continue;
            }

            if (!empty(sqlQuery('SELECT id FROM users WHERE BINARY `username` = ? LIMIT 1', [$username])['id'])) {
                $results[] = [
                    'username' => $username,
                    'role' => $selectedRole,
                    'status' => 'skipped',
                    'message' => xl('Username already exists.'),
                ];
                continue;
            }

            try {
                QueryUtils::inTransaction(static function () use (
                    $username,
                    $selectedRole,
                    $trainingPassword,
                    $facilityId,
                    $groupName
                ): void {
                    $userData = [
                        'username' => $username,
                        'password' => 'NoLongerUsed',
                        'fname' => $username,
                        'mname' => '',
                        'lname' => 'Trainee',
                        'suffix' => '',
                        'authorized' => 1,
                        'info' => 'Greenhood training account (fictional role)',
                        'federaltaxid' => '',
                        'federaldrugid' => '',
                        'upin' => '',
                        'facility' => '',
                        'facility_id' => $facilityId,
                        'see_auth' => 1,
                        'active' => 1,
                        'npi' => '',
                        'title' => '',
                        'taxonomy' => '',
                        'specialty' => '',
                        'email' => strtolower($username) . '@training.greenhood.local',
                        'email_direct' => '',
                        'billing_facility_id' => 0,
                        'calendar' => 1,
                        'portal_user' => 0,
                        'main_menu_role' => 'standard',
                        'patient_menu_role' => 'standard',
                    ];

                    $columns = array_map(
                        static fn(string $column): string => '`' . $column . '`',
                        array_keys($userData)
                    );
                    $placeholders = array_fill(0, count($userData), '?');
                    $newUserId = QueryUtils::sqlInsert(
                        'INSERT INTO `users` (' . implode(', ', $columns) . ') VALUES ('
                            . implode(', ', $placeholders) . ')',
                        array_values($userData)
                    );

                    $plainPassword = $trainingPassword;
                    $hash = (new AuthHash())->passwordHash($plainPassword);
                    QueryUtils::sqlInsert(
                        'INSERT INTO `users_secure` (`id`, `username`, `password`, `last_update_password`) '
                            . 'VALUES (?, ?, ?, NOW())',
                        [$newUserId, $username, $hash]
                    );

                    $uuid = UuidRegistry::getRegistryForTable('users')->createUuid();
                    sqlStatement(
                        'UPDATE users, facility SET users.facility = facility.name, users.uuid = ? '
                            . 'WHERE facility.id = ? AND users.username = ?',
                        [$uuid, $facilityId, $username]
                    );
                    sqlStatement(
                        'INSERT INTO `groups` SET `name` = ?, `user` = ?',
                        [$groupName, $username]
                    );
                    AclExtended::setUserAro([$selectedRole], $username, $username, '', 'Trainee');
                });

                $results[] = [
                    'username' => $username,
                    'role' => $selectedRole,
                    'status' => 'created',
                    'message' => xl('Created successfully.'),
                ];
            } catch (\Throwable $exception) {
                ServiceContainer::getLogger()->error('Greenhood training user creation failed', [
                    'username' => $username,
                    'exception' => $exception,
                ]);
                $results[] = [
                    'username' => $username,
                    'role' => $selectedRole,
                    'status' => 'error',
                    'message' => xl('Creation failed. Check the server error log.'),
                ];
            }
        }
    }
}

$roleJson = json_encode($roles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$randomRoleJson = json_encode($randomRoles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo xlt('Add Training Users'); ?></title>
    <?php Header::setupHeader(); ?>
</head>
<body class="bg-light">
    <main class="container py-4" style="max-width: 960px;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h1 class="h3 mb-1"><?php echo xlt('Add Training Users'); ?></h1>
                <p class="text-muted mb-0">
                    <?php echo xlt('Enter usernames, assign roles, and create all accounts with the configured training password.'); ?>
                </p>
            </div>
        </div>

        <?php if (!$passwordConfigured) { ?>
            <div class="alert alert-danger">
                <?php echo xlt('TRAINING_ACCOUNT_PASSWORD is not configured. Add it to the server environment before creating users.'); ?>
            </div>
        <?php } ?>

        <?php if ($results !== []) { ?>
            <section class="card mb-4">
                <div class="card-header"><?php echo xlt('Results'); ?></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th><?php echo xlt('Username'); ?></th>
                                <th><?php echo xlt('Role'); ?></th>
                                <th><?php echo xlt('Status'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $result) { ?>
                                <?php
                                $statusClass = match ($result['status']) {
                                    'created' => 'text-success',
                                    'skipped' => 'text-warning',
                                    default => 'text-danger',
                                };
                                ?>
                                <tr>
                                    <td><?php echo text($result['username']); ?></td>
                                    <td><?php echo text($result['role'] ?? ''); ?></td>
                                    <td class="<?php echo attr($statusClass); ?>"><?php echo text($result['message']); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php } ?>

        <form method="post" id="add-users-form" class="card">
            <input
                type="hidden"
                name="csrf_token_form"
                value="<?php echo attr(CsrfUtils::collectCsrfToken(session: $session)); ?>"
            >
            <div class="card-body">
                <div class="form-group">
                    <label for="usernames"><?php echo xlt('Student usernames'); ?></label>
                    <textarea
                        class="form-control font-monospace"
                        id="usernames"
                        name="usernames"
                        rows="7"
                        required
                        placeholder="AMN-00586, AMN-00587, AMN-00588"
                    ><?php echo text($rawUsernames); ?></textarea>
                    <small class="form-text text-muted">
                        <?php echo xlt('Separate usernames with commas, spaces, or new lines. Duplicates are removed automatically.'); ?>
                    </small>
                </div>

                <div class="d-flex flex-wrap align-items-end mb-3" style="gap: .75rem;">
                    <button type="button" class="btn btn-secondary" id="preview-users">
                        <?php echo xlt('Autofill Students'); ?>
                    </button>
                    <button type="button" class="btn btn-outline-primary" id="randomize-roles">
                        <?php echo xlt('Assign Random Roles'); ?>
                    </button>
                    <div>
                        <label for="role-for-all" class="mb-1"><?php echo xlt('Specific role for all'); ?></label>
                        <div class="input-group">
                            <select class="form-control" id="role-for-all">
                                <?php foreach ($roles as $role) { ?>
                                    <option value="<?php echo attr($role); ?>"><?php echo text($role); ?></option>
                                <?php } ?>
                            </select>
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-primary" id="apply-role">
                                    <?php echo xlt('Apply'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="student-list" class="table-responsive d-none">
                    <table class="table table-bordered table-sm">
                        <thead>
                            <tr>
                                <th><?php echo xlt('Username'); ?></th>
                                <th><?php echo xlt('Email (automatic)'); ?></th>
                                <th style="min-width: 220px;"><?php echo xlt('Role'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="student-rows"></tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer text-right">
                <button
                    type="submit"
                    class="btn btn-primary"
                    id="create-users"
                    <?php echo !$passwordConfigured || $roles === [] ? 'disabled' : ''; ?>
                >
                    <?php echo xlt('Create Students'); ?>
                </button>
            </div>
        </form>
    </main>

    <script>
        (() => {
            const roles = <?php echo $roleJson === false ? '[]' : $roleJson; ?>;
            const randomRoles = <?php echo $randomRoleJson === false ? '[]' : $randomRoleJson; ?>;
            const defaultRoles = randomRoles.length > 0 ? randomRoles : roles;
            const textarea = document.getElementById('usernames');
            const list = document.getElementById('student-list');
            const rows = document.getElementById('student-rows');

            const parseUsernames = () => {
                const seen = new Set();
                return textarea.value
                    .split(/[\s,]+/)
                    .map((value) => value.trim().toUpperCase())
                    .filter((value) => value !== '' && !seen.has(value) && seen.add(value));
            };

            const addRoleOptions = (select, selectedRole) => {
                roles.forEach((role) => {
                    const option = document.createElement('option');
                    option.value = role;
                    option.textContent = role;
                    option.selected = role === selectedRole;
                    select.appendChild(option);
                });
            };

            const renderStudents = () => {
                const previousRoles = new Map(
                    Array.from(rows.querySelectorAll('select')).map((select) => [select.dataset.username, select.value])
                );
                rows.replaceChildren();

                parseUsernames().forEach((username, index) => {
                    const row = document.createElement('tr');
                    const usernameCell = document.createElement('td');
                    const emailCell = document.createElement('td');
                    const roleCell = document.createElement('td');
                    const roleSelect = document.createElement('select');

                    usernameCell.textContent = username;
                    emailCell.textContent = `${username.toLowerCase()}@training.greenhood.local`;
                    roleSelect.className = 'form-control form-control-sm';
                    roleSelect.name = `roles[${username}]`;
                    roleSelect.dataset.username = username;
                    addRoleOptions(
                        roleSelect,
                        previousRoles.get(username) ?? defaultRoles[index % defaultRoles.length]
                    );

                    roleCell.appendChild(roleSelect);
                    row.append(usernameCell, emailCell, roleCell);
                    rows.appendChild(row);
                });

                list.classList.toggle('d-none', rows.children.length === 0);
            };

            document.getElementById('preview-users').addEventListener('click', renderStudents);
            document.getElementById('randomize-roles').addEventListener('click', () => {
                renderStudents();
                if (randomRoles.length === 0) {
                    return;
                }
                rows.querySelectorAll('select').forEach((select) => {
                    select.value = randomRoles[Math.floor(Math.random() * randomRoles.length)];
                });
            });
            document.getElementById('apply-role').addEventListener('click', () => {
                renderStudents();
                const role = document.getElementById('role-for-all').value;
                rows.querySelectorAll('select').forEach((select) => {
                    select.value = role;
                });
            });
            document.getElementById('add-users-form').addEventListener('submit', (event) => {
                renderStudents();
                if (rows.children.length === 0) {
                    event.preventDefault();
                    textarea.focus();
                }
            });

            if (textarea.value.trim() !== '') {
                renderStudents();
            }
        })();
    </script>
</body>
</html>
