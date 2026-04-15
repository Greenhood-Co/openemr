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
$marker = $siteDir . '/.greenhood_training_users_complete';

if ($siteDir === '' || !is_dir($siteDir)) {
    fwrite(STDERR, "greenhood provision_training_users: missing site directory.\n");
    exit(1);
}

if (is_file($marker)) {
    exit(0);
}

$plain = getenv('TRAINING_ACCOUNT_PASSWORD');
if ($plain === false || $plain === '') {
    fwrite(STDERR, "greenhood provision_training_users: TRAINING_ACCOUNT_PASSWORD is not set; skipping.\n");
    exit(0);
}

$accounts = [
    ['email' => 'alphonsusemmanuella28@gmail.com', 'fname' => 'Emmanuella', 'lname' => 'Alphonsus', 'acl' => 'Physicians'],
    ['email' => 'gesierefrancess2000@gmail.com', 'fname' => 'Frances', 'lname' => 'Gesiere', 'acl' => 'Nursing'],
    ['email' => 'adeprincess2000@gmail.com', 'fname' => 'Princess', 'lname' => 'Ade', 'acl' => 'Front Office'],
    ['email' => 'judithaduma@gmail.com', 'fname' => 'Judith', 'lname' => 'Aduma', 'acl' => 'Administrators'],
    ['email' => 'Ifeoluwaabiona05@gmail.com', 'fname' => 'Ifeoluwa', 'lname' => 'Abiona', 'acl' => 'Accounting'],
    ['email' => 'morenikejiaina51@gmail.com', 'fname' => 'Morenikeji', 'lname' => 'Aina', 'acl' => 'Clinicians'],
    ['email' => 'christydamilola1@gmail.com', 'fname' => 'Damilola', 'lname' => 'Christy', 'acl' => 'Physicians'],
    ['email' => 'torhuovwe@gmail.com', 'fname' => 'Ovwe', 'lname' => 'Torhu', 'acl' => 'Nursing'],
    ['email' => 'atuanyasuccess81@gmail.com', 'fname' => 'Success', 'lname' => 'Atuanya', 'acl' => 'Front Office'],
    ['email' => 'kaluhannah57@gmail.com', 'fname' => 'Hannah', 'lname' => 'Kalu', 'acl' => 'Physicians'],
    ['email' => 'peacevictorwoha@gmail.com', 'fname' => 'Peace', 'lname' => 'Woha', 'acl' => 'Clinicians'],
    ['email' => 'talk2adele123@gmail.com', 'fname' => 'Adele', 'lname' => 'Trainee', 'acl' => 'Accounting'],
    ['email' => 'Chinecheremmary2004@gmail.com', 'fname' => 'Mary', 'lname' => 'Chinecherem', 'acl' => 'Nursing'],
    ['email' => 'Preyeemakpo@gmail.com', 'fname' => 'Preye', 'lname' => 'Emakpo', 'acl' => 'Front Office'],
    ['email' => 'Olakunoriseun@gmail.com', 'fname' => 'Olakunori', 'lname' => 'Seun', 'acl' => 'Physicians'],
    ['email' => 'sulaimanjelilat25@gmail.com', 'fname' => 'Jelilat', 'lname' => 'Sulaiman', 'acl' => 'Clinicians'],
    ['email' => 'estheranjorin2020@gmail.com', 'fname' => 'Esther', 'lname' => 'Anjorin', 'acl' => 'Nursing'],
    ['email' => 'beeworld103@gmail.com', 'fname' => 'Bee', 'lname' => 'World', 'acl' => 'Administrators'],
    ['email' => 'Bolutife.medaiyese@gmail.com', 'fname' => 'Bolutife', 'lname' => 'Medaiyese', 'acl' => 'Physicians'],
    ['email' => 'okirachel98@gmail.com', 'fname' => 'Rachel', 'lname' => 'Oki', 'acl' => 'Front Office'],
];

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

touch($marker);
fwrite(STDOUT, "greenhood provision_training_users: completed.\n");
exit(0);
