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

/** @var list<string> $trainingEmails */
$trainingEmails = [
    'solotanoluwatomilola@gmail.com',
    'jjummy72@gmail.com',
    'adejorinjulius001@gmail.com',
    'peterreneh@gmail.com',
    'oliviachukwudoziemva@gmail.com',
    'seunadeboye84@gmail.com',
    'maytess813@gmail.com',
    'ejerukwam08faith@gmail.com',
    'onyinyechibetty@gmail.com',
    'olanikeokanlawon205@gmail.com',
    'idowuesther69@gmail.com',
    'patiencesimeon6060@gmail.com',
    'adekunleoderinde14@gmail.com',
    'emmanueltemitayodan@gmail.com',
    'amemaben@yahoo.com',
    'chiamakaeze950@gmail.com',
    'hap4sure@gmail.com',
    'onasanyaorachael@gmail.com',
    'benbanji23@gmail.com',
    'sovicky1998@gmail.com',
    'julietogwude@gmail.com',
    'aminaalex28@gmail.com',
    'akpaneunice73@gmail.com',
    'astroglow7725@gmail.com',
    'hepzialale@gmail.com',
    'odubiyifavour@gmail.com',
    'tobbiiee02@gmail.com',
    'tobiarojoajayi@gmail.com',
    'chiamakamaryfides@gmail.com',
    'mavwegrace@gmail.com',
    'michaelapostle4@gmail.com',
    'ismailrumar054@gmail.com',
    'thevictoriatomilola@gmail.com',
    'nunancice@gmail.com',
    'imedudo@gmail.com',
    'nseobongikot@gmail.com',
    'farmmillionaire18@gmail.com',
    'crystalijeoma@gmail.com',
    'wulengmej@gmail.com',
    'tejuosoakorede0@gmail.com',
    'peaceifechukwu50@gmail.com',
    'henryosemedo@gmail.com',
    'ineneni21@gmail.com',
    'osaruguestephanieodiase2@gmail.com',
    'toluawemail@gmail.com',
    'claradian59@gmail.com',
];

/** @var list<string> $aclRotation */
$aclRotation = ['Physicians', 'Nursing', 'Front Office', 'Administrators', 'Accounting', 'Clinicians'];

$accounts = [];
$seenEmails = [];
foreach ($trainingEmails as $index => $rawEmail) {
    $email = strtolower(trim($rawEmail));
    if ($email === '' || isset($seenEmails[$email])) {
        continue;
    }
    $seenEmails[$email] = true;

    $local = explode('@', $email, 2)[0] ?? 'training';
    $namePart = preg_replace('/[0-9]+/', '', $local) ?? $local;
    $namePart = trim($namePart, '._-');
    if ($namePart === '') {
        $namePart = 'training';
    }

    $accounts[] = [
        'email' => $email,
        'fname' => ucfirst($namePart),
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
    $email = strtolower($acc['email']);
    $fname = $acc['fname'];
    $lname = $acc['lname'];
    $aclPreferred = $acc['acl'];

    $dup = sqlQuery(
        "SELECT id FROM users WHERE LOWER(`email`) = ? LIMIT 1",
        [$email]
    );
    if (!empty($dup['id'])) {
        continue;
    }

    $parts = explode('@', $email, 2);
    $local = strtolower($parts[0] ?? '');
    $local = preg_replace('/[^a-z0-9._-]/', '', $local) ?? '';
    $local = substr($local, 0, 50);
    if ($local === '') {
        $local = 'user';
    }
    $username = $local;
    $n = 0;
    while (!empty(sqlQuery("SELECT id FROM users WHERE `username` = ?", [$username])['id'])) {
        $n++;
        $username = substr($local, 0, 40) . (string) $n;
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
