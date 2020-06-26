<?php


/**
 * Dummy index.
 *
 * This is not used by unit tests. This runs WebDefault under actual
 * HTTP server with SQLite3 backend. Callbacks are provided
 * by RouteDefault. `$_GET['reloaddb']` on any `GET` request will reset
 * the database.
 *
 * Run with `$ php -S 0.0.0.0:9090`.
 */

require __DIR__ .'/../vendor/autoload.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapCore\Router;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapAdmin\Admin;
use BFITech\ZapAdmin\AuthCtrl;
use BFITech\ZapAdmin\AuthManage;
use BFITech\ZapAdmin\RouteDefault;
use BFITech\ZapAdmin\WebDefault;


# Use this router with its simplified abort.
class WebDefaultCore extends Router {

	public function abort_custom($code) {
		self::start_header($code);
		echo "ERROR: $code";
	}

}


function run() {
	$tdir = __DIR__ . '/testdata';
	@mkdir($tdir, 0755);

	$dbname = $tdir . '/zapmin-http.sq3';
	$logfile = $tdir  . '/zapmin-http.log';
	$log = new Logger(Logger::DEBUG, $logfile);

	if (isset($_GET['reloaddb']))
		# remove test database
		@unlink($dbname);

	$core = (new WebDefaultCore)->config('logger', $log);
	$sql = new SQLite3(['dbname' => $dbname], $log);

	$admin = (new Admin($sql, $log))
		->config('check_tables', true)
		->init();
	$ctrl = new AuthCtrl($admin, $log);
	$manage = new AuthManage($admin, $log);

	new WebDefault(new RouteDefault($core, $ctrl, $manage));
}
run();
