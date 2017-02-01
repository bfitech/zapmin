<?php


require('../../vendor/autoload.php');

use BFITech\ZapAdmin as za;

# Change this to wherever, preferably in a tmpfs partition
# to gain some speed.
$dbname = '/mnt/ramdisk/zapmin-test.sq3';

# Remote test database. Use this on teardown.
if (isset($_GET['reloaddb']))
	@unlink($dbname);

$dbargs = [
	'dbtype' => 'sqlite3',
	'dbname' => $dbname,
];
$adm = new za\AdminRoute(['dbargs' => $dbargs]);

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

