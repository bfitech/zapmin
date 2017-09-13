<?php


require __DIR__ .'/../vendor/autoload.php';


use BFITech\ZapCoreDev\CoreDev;
use BFITech\ZapCore as zc;
use BFITech\ZapStore as zs;
use BFITech\ZapAdmin as za;


CoreDev::testdir(__FILE__);
$dbname = __TESTDIR__ . '/zapmin-http.sq3';
$logfile = __TESTDIR__ . '/zapmin-http.log';
$logger = new zc\Logger(zc\Logger::DEBUG, $logfile);

# Remote test database. Use this on teardown.
if (isset($_GET['reloaddb'])) {
	unlink($dbname);
	die();
}

# Use this router with its simplified abort.
class Router extends zc\Router {

	public function abort_custom($code) {
		self::start_header($code);
		echo "ERROR: $code";
	}

}

$core = (new Router)->config('logger', $logger);
$store = new zs\SQLite3(['dbname' => $dbname], $logger);

$adm = new za\AdminRouteDefault($store, $logger, null, $core);

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