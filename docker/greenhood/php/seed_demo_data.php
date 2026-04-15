<?php

/**
 * Idempotent training/demo seed: fictional patients, encounters, appointments, one problem list row.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Core\OEGlobalsBag;

$siteDir = OEGlobalsBag::getInstance()->getString('OE_SITE_DIR');
$marker = $siteDir . '/.greenhood_demo_seed_complete';

if ($siteDir === '' || !is_dir($siteDir)) {
    fwrite(STDERR, "greenhood seed_demo_data: missing site directory.\n");
    exit(1);
}

if (is_file($marker)) {
    exit(0);
}

$check = sqlQuery(
    "SELECT COUNT(*) AS c FROM patient_data WHERE pubpid LIKE ?",
    ['GH-DEMO-%']
);
if (($check['c'] ?? 0) > 0) {
    touch($marker);
    exit(0);
}

$fac = sqlQuery("SELECT id, name FROM facility ORDER BY id ASC LIMIT 1");
$facilityId = (int) ($fac['id'] ?? 1);
$facilityName = (string) ($fac['name'] ?? 'Primary');

$oeUser = getenv('OE_USER') ?: 'owner';
$admin = sqlQuery(
    "SELECT id FROM users WHERE username = ? AND active = 1",
    [$oeUser]
);
$providerId = (int) ($admin['id'] ?? 1);

$patients = [
    ['Morgan', 'Vale', '1980-04-12', 'Male', 'GH-DEMO-001', 'Lagos', 'Training demo: annual check-in.'],
    ['Amina', 'Okonkwo', '1992-11-03', 'Female', 'GH-DEMO-002', 'Abuja', 'Training demo: follow-up visit.'],
    ['Chidi', 'Eze', '1975-08-21', 'Male', 'GH-DEMO-003', 'Port Harcourt', 'Training demo: hypertension review.'],
    ['Zara', 'Bello', '2001-01-30', 'Female', 'GH-DEMO-004', 'Ibadan', 'Training demo: new patient intake.'],
    ['Emeka', 'Nwosu', '1968-09-14', 'Male', 'GH-DEMO-005', 'Enugu', 'Training demo: lab review.'],
];

$pids = [];

foreach ($patients as $row) {
    [$fname, $lname, $dob, $sex, $pubpid, $city, $note] = $row;
    $uuidStr = UuidRegistry::uuidToString(UuidRegistry::getRegistryForTable('patient_data')->createUuid());
    $pidRow = sqlQuery("SELECT COALESCE(MAX(pid),0)+1 AS p FROM patient_data");
    $pid = (int) ($pidRow['p'] ?? 1);

    $sql = "INSERT INTO patient_data (pid, uuid, fname, lname, DOB, sex, pubpid, city, country_code, phone_home, email, notes, providerID) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    QueryUtils::sqlStatementThrowException($sql, [
        $pid,
        UuidRegistry::uuidToBytes($uuidStr),
        $fname,
        $lname,
        $dob,
        $sex,
        $pubpid,
        $city,
        'NG',
        '+234-800-555-0100',
        strtolower($pubpid) . '@example.invalid',
        $note,
        $providerId,
    ]);
    $pids[] = $pid;
}

$uuidList = UuidRegistry::uuidToString(UuidRegistry::getRegistryForTable('lists')->createUuid());
QueryUtils::sqlStatementThrowException(
    "INSERT INTO lists (uuid, type, subtype, title, begdate, pid, user, activity) VALUES (?, ?, '', ?, ?, ?, ?, ?)",
    [
        UuidRegistry::uuidToBytes($uuidList),
        'medical_problem',
        'Essential hypertension (training demo — fictional)',
        date('Y-m-d H:i:s'),
        $pids[0],
        (string) $providerId,
        1,
    ]
);

foreach (array_slice($pids, 0, 3) as $idx => $pid) {
    $encounter = QueryUtils::generateId();
    $eu = UuidRegistry::uuidToString(UuidRegistry::getRegistryForTable('form_encounter')->createUuid());
    $when = date('Y-m-d H:i:s', strtotime('-' . (3 - $idx) . ' days'));
    QueryUtils::sqlStatementThrowException(
        "INSERT INTO form_encounter (uuid, pid, encounter, date, reason, facility_id, facility, provider_id, pc_catid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 5)",
        [
            UuidRegistry::uuidToBytes($eu),
            $pid,
            $encounter,
            $when,
            'Training demo encounter — routine visit (fictional)',
            $facilityId,
            $facilityName,
            $providerId,
        ]
    );
}

$cat = sqlQuery("SELECT pc_catid FROM openemr_postcalendar_categories WHERE pc_constant_id = 'office_visit' LIMIT 1");
$pcCat = (int) ($cat['pc_catid'] ?? 5);

for ($i = 0; $i < 2; $i++) {
    $pid = $pids[$i + 1];
    $eventDate = date('Y-m-d', strtotime('+' . (2 + $i) . ' days'));
    $eu = UuidRegistry::uuidToString(UuidRegistry::getRegistryForTable('openemr_postcalendar_events')->createUuid());
    QueryUtils::sqlStatementThrowException(
        "INSERT INTO openemr_postcalendar_events (pc_catid, pc_multiple, pc_aid, pc_pid, pc_title, pc_time, pc_eventDate, pc_endDate, pc_duration, pc_facility, uuid) VALUES (?, 0, ?, ?, ?, ?, ?, ?, 1800, ?, ?)",
        [
            $pcCat,
            (string) $providerId,
            (string) $pid,
            'Training demo appointment (fictional)',
            $eventDate . ' 10:00:00',
            $eventDate,
            $eventDate,
            $facilityId,
            UuidRegistry::uuidToBytes($eu),
        ]
    );
}

touch($marker);
fwrite(STDOUT, "greenhood seed_demo_data: completed (marker + " . count($pids) . " patients).\n");
exit(0);
