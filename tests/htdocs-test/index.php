<?php


require('../../vendor/autoload.php');
require(__DIR__ . '/config.php');

use BFITech\ZapAdmin as za;
use BFITech\ZapCore as zc;

# Change this to wherever, preferably in a tmpfs partition
# to gain some speed.
$dbname = TMPDIR . '/zapmin-test.sq3';

# Remote test database. Use this on teardown.
if (isset($_GET['reloaddb']))
	@unlink($dbname);

# Use this router with its simplified abort.
class ExtRouter extends zc\Router {
	public function abort($code) {
		$this->send_header(0, 0, 0, $code);
		echo "ERROR: $code";
	}
}
$ext = new ExtRouter();

$dbargs = [
	'dbtype' => 'sqlite3',
	'dbname' => $dbname,
];
$adm = new za\AdminRoute([
	'dbargs' => $dbargs,
	'core_instance' => $ext,
]);

$adm->route('/',         [$adm, 'route_home'],     'GET');
$adm->route('/status',   [$adm, 'route_status'],   'GET');
$adm->route('/login',    [$adm, 'route_login'],    'POST');
$adm->route('/logout',   [$adm, 'route_logout'],   ['GET', 'POST']);
$adm->route('/chpasswd', [$adm, 'route_chpasswd'], 'POST');
$adm->route('/chbio',    [$adm, 'route_chbio'],    'POST');
$adm->route('/register', [$adm, 'route_register'], 'POST');
$adm->route('/useradd',  [$adm, 'route_useradd'],  'POST');
$adm->route('/userdel',  [$adm, 'route_userdel'],  'POST');
$adm->route('/userlist', [$adm, 'route_userlist'], 'POST');
$adm->route('/byway',    [$adm, 'route_byway'],    ['GET', 'POST']);

