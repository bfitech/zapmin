<?php


require('../../vendor/autoload.php');
use BFITech\ZapAdmin as za;

# Change this to wherever, preferably in
# a tmpfs partition to gain some speed.
$dbname = '/mnt/ramdisk/zapmin-test.sq3';
if (isset($_GET['reloaddb']))
	@unlink($dbname);
$dbargs = [
	'dbtype' => 'sqlite3',
	'dbname' => $dbname,
];
$route = new za\AdminRouteDefault(null, null, $dbargs, null);
$route->process_routes();

