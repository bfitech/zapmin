<?php declare(strict_types=1);


namespace BFITech\ZapAdmin;


/**
 * RouteDefault dispatcher.
 *
 * ### Example:
 * @code
 * # index.php
 *
 * namespace BFITech\ZapAdmin;
 *
 * use BFITech\ZapCore\Logger;
 * use BFITech\ZapCore\Router;
 * use BFITech\ZapStore\SQLite3;
 *
 * $log = new Logger(Logger::DEBUG, '/tmp/zapmin.log');
 * $store = new SQLite3(['dbname' => '/tmp/zapmin.sq3'], $log);
 *
 * $admin = new Admin($store, $log);
 * $ctrl = new AuthCtrl($admin, $log);
 * $manage = new AuthManage($admin, $log);
 *
 * $core = new Router($log);
 * $zcore = new RouteDefault($core, $ctrl, $manage);
 *
 * new WebDefault($zcore);
 *
 * # run it with something like `php -S 0.0.0.0:8000`
 * @endcode
 */
class WebDefault {

	/**
	 * Collection of RouteAdmin callbacks with their associated path and
	 * requiest method. Use this if you'd like defer routing execution
	 * and merge routes with other application logic beforehand.
	 */
	public static $zcol;

	private static $zcore;

	/**
	 * Constructor.
	 *
	 * @param RouteAdmin $zcore RouteAdmin instance. Not to be confused
	 *     with zapcore Router which is typically instantiated as $core.
	 * @param bool $run If true, execute routing immediately. Otherwise,
	 *     defer execution. Useful when you want to merge routing with
	 *     other application logic.
	 */
	public function __construct(RouteAdmin $zcore, bool $run=true) {
		self::$zcore = $zcore;
		$zcol = self::$zcol = [
			['/',         'route_home'],
			['/status',   'route_status'],
			['/login',    'route_login',    'POST'],
			['/logout',   'route_logout',   ['GET', 'POST']],
			['/chpasswd', 'route_chpasswd', 'POST'],
			['/chbio',    'route_chbio',    'POST'],
			['/register', 'route_register', 'POST'],
			['/useradd',  'route_useradd',  'POST'],
			['/userdel',  'route_userdel',  'POST'],
			['/userlist', 'route_userlist'],
			['/byway',    'route_byway',    'POST'],
		];

		foreach ($zcol as $zdata) {
			if (count($zdata) < 3)
				$zdata[] = 'GET';
			if (count($zdata) < 4)
				$zdata[] = false;
			if (is_array($zdata[2]))
				$zdata[2][] = 'OPTIONS';
			else
				$zdata[2] = [$zdata[2], 'OPTIONS'];
		}

		// @codeCoverageIgnoreStart
		if ($run)
			$this->run();
		// @codeCoverageIgnoreEnd
	}

	/**
	 * Run all routes.
	 *
	 * @codeCoverageIgnoreStart
	 */
	public function run() {
		$zcore = self::$zcore;
		foreach (self::$zcol as $zdata) {
			list($path, $cbname, $method, $is_raw) = $zdata;
			$zcore::$core->route(
				$path, [$zcore, $cbname], $method, $is_raw);
		}
	}

}
