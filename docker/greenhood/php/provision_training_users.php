<?php

/**
 * Create training logins from a fixed list (idempotent). Password from TRAINING_ACCOUNT_PASSWORD.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use OpenEMR\Common\Acl\AclExtended;
use OpenEMR\Common\Auth\AuthHash;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Core\OEGlobalsBag;

$siteDir = OEGlobalsBag::getInstance()->getString('OE_SITE_DIR');

if ($siteDir === '' || !is_dir($siteDir)) {
    fwrite(STDERR, "greenhood provision_training_users: missing site directory.\n");
    exit(1);
}

$plain = getenv('TRAINING_ACCOUNT_PASSWORD');
if ($plain === false || $plain === '') {
    fwrite(STDERR, "greenhood provision_training_users: TRAINING_ACCOUNT_PASSWORD is not set; skipping.\n");
    exit(0);
}

/** @var list<string> $trainingUsernames */
$trainingUsernames = [
    'AMN-00493',
    'AMN-00525',
    'AMN-00513',
    'AMN-00521',
    'AMN-00549',
    'AMN-00498',
    'AMN-00491',
    'AMN-00545',
    'AMN-00510',
    'AMN-00486',
    'AMN-00515',
    'AMN-00490',
    'AMN-00517',
    'AMN-00502',
    'AMN-00504',
    'AMN-00537',
    'AMN-00520',
    'AMN-00553',
    'AMN-00511',
    'AMN-00497',
    'AMN-00557',
    'AMN-00487',
    'AMN-00509',
    'AMN-00492',
    'AMN-00519',
    'AMN-00528',
    'AMN-00558',
    'AMN-00522',
    'AMN-00495',
    'AMN-00499',
    'AMN-00523',
    'AMN-00530',
    'AMN-00544',
    'AMN-00542',
    'AMN-00534',
    'AMN-00501',
    'AMN-00560',
    'AMN-00529',
    'AMN-00561',
    'AMN-00538',
    'AMN-00562',
    'AMN-00563',
    'AMN-00539',
    'AMN-00518',
    'AMN-00535',
    'AMN-00496',
    'AMN-00546',
    'AMN-00564',
    'AMN-00566',
    'AMN-00489',
    'AMN-00512',
    'AMN-00488',
    'AMN-00508',
    'AMN-00567',
    'AMN-00569',
    'AMN-00552',
    'AMN-00568',
    'AMN-00494',
    'AMN-00524',
    'AMN-00527',
    'AMN-00551',
    'AMN-00571',
    'AMN-00001',
    'AMN-00565',
    'AMN-00507',
    'AMN-00514',
    'AMN-00554',
    'AMN-00572',
    'AMN-00574',
    'AMN-00556',
    'AMN-00550',
    'AMN-00547',
    'AMN-00536',
    'AMN-00576',
    'AMN-00575',
    'AMN-00577',
    'AMN-00579',
    'AMN-00503',
    'AMN-00555',
    'AMN-00580',
    'AMN-00531',
    'AMN-00573',
    'AMN-00533',
    'AMN-00559',
    'AMN-00582',
    'AMN-00541',
    'AMN-00500',
    'AMN-00548',
    'AMN-86915',
    'AMN-00583',
    'AMN-00584',
];

/** @var list<string> $aclRotation */
$aclRotation = ['Physicians', 'Nursing', 'Front Office', 'Administrators', 'Accounting', 'Clinicians'];

$accounts = [];
$seenUsernames = [];
foreach ($trainingUsernames as $index => $rawUsername) {
    $username = strtoupper(trim($rawUsername));
    if ($username === '' || isset($seenUsernames[$username])) {
        continue;
    }
    $seenUsernames[$username] = true;

    $accounts[] = [
        'username' => $username,
        'email' => strtolower($username) . '@training.greenhood.local',
        'fname' => $username,
        'lname' => 'Trainee',
        'acl' => $aclRotation[$index % count($aclRotation)],
    ];
}

$availableTitles = AclExtended::aclGetGroupTitleList(true);
if ($availableTitles === []) {
    fwrite(STDERR, "greenhood provision_training_users: no ACL groups found.\n");
    exit(1);
}
/** @var list<string> $titleList */
$titleList = array_values($availableTitles);

$resolveAcl = static function (string $preferred) use ($titleList): string {
    foreach ($titleList as $t) {
        if (strcasecmp($t, $preferred) === 0) {
            return $t;
        }
    }
    foreach ($titleList as $t) {
        if (stripos($t, $preferred) !== false) {
            return $t;
        }
    }
    $fallbacks = ['Clinicians', 'Front Office', 'Physicians', 'Nursing', 'Administrators', 'Accounting'];
    foreach ($fallbacks as $fb) {
        foreach ($titleList as $t) {
            if (strcasecmp($t, $fb) === 0) {
                return $t;
            }
        }
    }
    return $titleList[0];
};

$facRow = sqlQuery("SELECT id, name FROM facility ORDER BY id ASC LIMIT 1");
$facilityId = (int) ($facRow['id'] ?? 1);

$groupNameRow = sqlQuery("SELECT `name` FROM `groups` LIMIT 1");
$groupName = (string) ($groupNameRow['name'] ?? 'Default');

foreach ($accounts as $acc) {
    $username = $acc['username'];
    $email = strtolower($acc['email']);
    $fname = $acc['fname'];
    $lname = $acc['lname'];
    $aclPreferred = $acc['acl'];

    $dup = sqlQuery(
        "SELECT id FROM users WHERE `username` = ? LIMIT 1",
        [$username]
    );
    if (!empty($dup['id'])) {
        continue;
    }

    $userData = [
        'username' => $username,
        'password' => 'NoLongerUsed',
        'fname' => $fname,
        'mname' => '',
        'lname' => $lname,
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
        'email' => $email,
        'email_direct' => '',
        'billing_facility_id' => 0,
        'calendar' => 1,
        'portal_user' => 0,
        'main_menu_role' => 'standard',
        'patient_menu_role' => 'standard',
    ];

    $columns = array_map(static fn ($col): string => '`' . $col . '`', array_keys($userData));
    $placeholders = array_fill(0, count($userData), '?');
    $insertSql = 'INSERT INTO `users` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $newUserId = QueryUtils::sqlInsert($insertSql, array_values($userData));

    $pw = $plain;
    $hash = (new AuthHash())->passwordHash($pw);
    QueryUtils::sqlInsert(
        "INSERT INTO `users_secure` (`id`,`username`,`password`,`last_update_password`) VALUES (?,?,?,NOW())",
        [$newUserId, $username, $hash]
    );

    $uuid = UuidRegistry::getRegistryForTable('users')->createUuid();
    sqlStatement(
        "UPDATE users, facility SET users.facility = facility.name, users.uuid = ? WHERE facility.id = ? AND users.username = ?",
        [$uuid, $facilityId, $username]
    );

    sqlStatement(
        "INSERT INTO `groups` SET `name` = ?, `user` = ?",
        [$groupName, $username]
    );

    $aclTitle = $resolveAcl($aclPreferred);
    AclExtended::setUserAro([$aclTitle], $username, $fname, '', $lname);
}

fwrite(STDOUT, "greenhood provision_training_users: completed.\n");
exit(0);
