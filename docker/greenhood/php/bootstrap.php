<?php

/**
 * CLI bootstrap for Greenhood maintenance scripts (contrib/greenhood).
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$_GET['site'] = getenv('OE_SITE') ?: 'default';
$ignoreAuth = true;
$_SERVER['DOCUMENT_ROOT'] = '/var/www/localhost/htdocs';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_SCHEME'] = 'http';

require_once dirname(__DIR__, 2) . '/interface/globals.php';
