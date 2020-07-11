<?php



namespace Demo;

require __DIR__ .'/../vendor/autoload.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapCore\Router;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapAdmin\Admin;
use BFITech\ZapAdmin\AuthCtrl;
use BFITech\ZapAdmin\AuthManage;
use BFITech\ZapAdmin\RouteDefault;
use BFITech\ZapAdmin\WebDefault;


/** Router with simplified abort. */
class DemoRouter extends Router {

	/**
	 * Custom abort.
	 */
	public function abort_custom($code) {
		self::start_header($code);
		self::halt("ERROR: $code");
	}

}

/**
 * Demo app.
 *
 * This runs BFITech.ZapAdmin.WebDefault under actual HTTP server with
 * SQLite3 backend and no Redis. Callbacks are provided by
 * BFITech.ZapAdmin.RouteDefault. $_GET['reloaddb'] on any `GET`
 * request will reset the database.
 *
 * Run with:
 *
 * @code
 * $ php -S 0.0.0.0:9090
 * @endcode
 *
 * Interact with it via cURL or the like. Use `root:admin` as default
 * credential.
 *
 * @code
 * $ curl localhost:9090/login -F uname=root -F upass=admin -s | jq .
 * {
 *   "errno": 0,
 *   "data": {
 *     "uid": "1",
 *     "uname": "root",
 *     "token": "knnuahfGuQtafBcax0iZ4pdYkonjH7ebf1VZDUJkLc"
 *   }
 * }
 * $ TOKEN="knnuahfGuQtafBcax0iZ4pdYkonjH7ebf1VZDUJkLc"
 * $ curl localhost:9090/userlist \
 * > -H "Authorization: zapmin $TOKEN" -s | jq .
 * {
 *   "errno": 0,
 *   "data": [
 *     {
 *       "uid": "1",
 *       "uname": "root",
 *       "fname": null,
 *       "site": null,
 *       "since": "2020-07-11 10:04:17"
 *     }
 *   ]
 * }
 * @endcode
 */
class DemoRun {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$tdir = __DIR__ . '/data';
		if (!is_dir($tdir))
			mkdir($tdir, 0755);

		$dbname = $tdir . '/demo.sq3';
		$logfile = $tdir  . '/demo.log';
		$log = new Logger(Logger::DEBUG, $logfile);

		if (isset($_GET['reloaddb']))
			# remove test database
			unlink($dbname);

		$core = (new DemoRouter)->config('logger', $log);
		$sql = new SQLite3(['dbname' => $dbname], $log);

		$admin = (new Admin($sql, $log))
			->config('check_tables', true)
			->init();
		$ctrl = new AuthCtrl($admin, $log);
		$manage = new AuthManage($admin, $log);

		new WebDefault(new RouteDefault($core, $ctrl, $manage));
	}

}

new DemoRun;
