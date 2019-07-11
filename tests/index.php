<?php


/**
 * Dummy index.
 *
 * This is not used by unit tests but may be useful when we are to
 * develop authentication proxy via some other languages, which,
 * in this case, running `$ php -S 0.0.0.0:9090` will generally
 * suffice. All routings are using methods provided by RouteDefault.
 */

require __DIR__ .'/../vendor/autoload.php';
require __DIR__ .'/Common.php';


use BFITech\ZapCommonDev\CommonDev;
use BFITech\ZapCore\Logger;
use BFITech\ZapCore\Router as DefaultRouter;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapAdmin\Admin;
use BFITech\ZapAdmin\AuthCtrl;
use BFITech\ZapAdmin\AuthManage;
use BFITech\ZapAdmin\RouteDefault;
use BFITech\ZapAdmin\WebDefault;


# Use this router with its simplified abort.
class Router extends DefaultRouter {

	public function abort_custom($code) {
		self::start_header($code);
		echo "ERROR: $code";
	}

}


function run() {
	$dbname = testdir() . '/zapmin-http.sq3';
	$logfile = testdir() . '/zapmin-http.log';
	$log = new Logger(Logger::DEBUG, $logfile);

	# Remove test database. Use this to clear up database and start
	# over.
	if (isset($_GET['reloaddb']))
		unlink($dbname);

	$core = (new Router)->config('logger', $log);
	$store = new SQLite3(['dbname' => $dbname], $log);

	$admin = (new Admin($store, $log))
		->config('check_tables', true)
		->init();
	$ctrl = new AuthCtrl($admin, $log);
	$manage = new AuthManage($admin, $log);

	$route = new RouteDefault($core, $ctrl, $manage);
	new WebDefault($route);
}
run();
