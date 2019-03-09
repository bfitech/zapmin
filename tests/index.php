<?php


/**
 * Dummy index.
 *
 * This is not used by unit tests but may be useful when we are to
 * develop authentication proxy via some other languages, which
 * in this case, running `$ php -S 0.0.0.0:9090` will generally
 * suffice. All routings are using methods provided by
 * AdminRouteDefault.
 */

require __DIR__ .'/../vendor/autoload.php';


use BFITech\ZapCommonDev\CommonDev;
use BFITech\ZapCore\Logger;
use BFITech\ZapCore\Router as DefaultRouter;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapAdmin\AdminRouteDefault;


CommonDev::testdir(__FILE__);
$dbname = __TESTDIR__ . '/zapmin-http.sq3';
$logfile = __TESTDIR__ . '/zapmin-http.log';
$logger = new Logger(Logger::DEBUG, $logfile);

# Remote test database. Use this on teardown.
if (isset($_GET['reloaddb'])) {
	unlink($dbname);
	die();
}

# Use this router with its simplified abort.
class Router extends DefaultRouter {

	public function abort_custom($code) {
		self::start_header($code);
		echo "ERROR: $code";
	}

}

$core = (new Router)->config('logger', $logger);
$store = new SQLite3(['dbname' => $dbname], $logger);

$adm = new AdminRouteDefault($store, $logger, null, $core);

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
