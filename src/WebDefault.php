<?php declare(strict_types=1);


namespace BFITech\ZapAdmin;


/**
 * Default routing dispatcher.
 *
 * ### Example:
 * @code
 * # index.php
 *
 * namespace BFITech\ZapAdmin;
 *
 * use BFITech\ZapCore\Logger;
 * use BFITech\ZapCore\Router as Core;
 * use BFITech\ZapStore\SQLite3;
 *
 * $log = new Logger(Logger::DEBUG, '/tmp/zapmin.log');
 * $store = new SQLite3(['dbname' => '/tmp/zapmin.sq3'], $log);
 *
 * $admin = new Admin($store, $log);
 * $ctrl = new AuthCtrl($admin, $log);
 * $manage = new AuthManage($admin, $log);
 *
 * $core = new Core($log);
 * $route = new RouteDefault($core, $ctrl, $manage);
 *
 * new WebDefault($route);
 *
 * # run it with something like `php -S 0.0.0.0:8000`
 * @endcode
 */
class WebDefault {

	/**
	 * Collection of Route instances, each of which has Route::route
	 * method to execute. Use this if you defer routing execution and
	 * merge routes with other application logic.
	 */
	public static $routes;

	/**
	 * Constructor.
	 *
	 * @param Route $route zapmin Route instance. Not to be confused
	 *     with zapcore Router which is typically instantiated as
	 *     $core.
	 * @param bool $run If true, execute routing immediately. Otherwise,
	 *     defer execution, useful when you want to merge routing with
	 *     other application logic.
	 */
	public function __construct(Route $route, bool $run=true) {

		$this->r = $route;
		$routes = self::$routes = [
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

		foreach ($routes as $rtn) {
			$len = count($rtn);
			if ($len < 3)
				$rtn[] = 'GET';
			if ($len < 4)
				$rtn[] = false;
			if (is_array($rtn[2]))
				$rtn[2][] = 'OPTIONS';
			else
				$rtn[2] = [$rtn[2], 'OPTIONS'];
			// @codeCoverageIgnoreStart
			if ($run)
				$route->route(
					$rtn[0], [$route, $rtn[1]], $rtn[2], $rtn[3]);
			// @codeCoverageIgnoreEnd
		}
	}

}
